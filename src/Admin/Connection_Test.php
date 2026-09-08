<?php
/**
 * The "Test connection" button behind the WA.cr settings tab.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Admin\Settings\Schema;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\WAcr\Client;
use WAcr\RecoveryFlow\WAcr\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * The one control that turns a saved key into a connected one.
 *
 * Nothing else in wp-admin can write the connection snapshot. A key is stored
 * encrypted the moment it is saved, but whether it WORKS -- which workspace it
 * addresses, which scopes it holds, whether the plan includes the developer
 * API -- is only ever learned by asking WA.cr, and the plugin asks only when
 * somebody presses this. The health check had told merchants to "use Test
 * connection" since the admin screens shipped, while no such button existed
 * anywhere, so the snapshot could never be written and the settings screen said
 * "A key is saved" directly above "No WA.cr API key is connected yet". Both
 * sentences were true of their own stored option; neither was true of the site.
 *
 * It is a plain form posting to admin-post.php rather than a fetch() against
 * the REST route that does the same job. The REST route stays -- it is the API
 * surface, and something automating a fleet of sites should use it -- but the
 * screen a merchant looks at should not need JavaScript to run to be usable,
 * should degrade to a full page load, and should announce its result the way
 * every other admin notice on the site does. A button that fails silently when
 * a script is blocked is worse than no button on a screen whose entire job is
 * telling somebody whether a thing is working.
 *
 * The outcome is carried across the redirect in a transient scoped to the user
 * who pressed it, not in a query argument: a message in the URL is a message
 * anybody can put there, and this one is read as a statement about a
 * credential.
 */
final class Connection_Test {

	/**
	 * The admin-post action name.
	 */
	public const ACTION = 'recoveryflow_test_connection';

	/**
	 * How long the result of a check waits to be shown, in seconds.
	 *
	 * Long enough to survive the redirect, short enough that a stale answer
	 * cannot reappear on a screen opened much later.
	 */
	private const RESULT_TTL = 60;

	/**
	 * The WA.cr client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * The stored credential.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructor.
	 *
	 * @param Client      $client      The WA.cr client.
	 * @param Credentials $credentials The stored credential.
	 */
	public function __construct( Client $client, Credentials $credentials ) {
		$this->client      = $client;
		$this->credentials = $credentials;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Run the check, remember what it said, and go back to the tab.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to test the WA.cr connection on this site.', 'kdc-wacr-recoveryflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		self::remember( $this->check() );

		wp_safe_redirect( Screen::settings_url( 'wacr', 'connection', Schema::FIELD_API_KEY ) );

		exit;
	}

	/**
	 * Ask WA.cr about the stored key.
	 *
	 * @return array{ok:bool,message:string}
	 */
	private function check(): array {
		if ( ! $this->credentials->has_api_key() ) {
			return array(
				'ok'      => false,
				'message' => __( 'There is no API key saved to test. Enter one and save, then test it.', 'kdc-wacr-recoveryflow' ),
			);
		}

		$result = $this->client->verify();

		if ( ! $result->ok ) {
			return array(
				'ok'      => false,
				// The message comes from WA.cr, which explains the refusal in
				// its own words -- an expired key, a plan that does not include
				// the developer API, a workspace that was suspended. Replacing
				// it with one of our own would tell the merchant less.
				'message' => null === $result->error
					? __( 'WA.cr could not be reached. Check the site can make outbound requests, then test again.', 'kdc-wacr-recoveryflow' )
					: $result->error->message,
			);
		}

		$tenant = (string) $result->get( 'tenant_name', '' );

		return array(
			'ok'      => true,
			'message' => '' === $tenant
				? __( 'Connected to WA.cr.', 'kdc-wacr-recoveryflow' )
				: sprintf(
					/* translators: %s: the name of the connected WA.cr workspace. */
					__( 'Connected to %s.', 'kdc-wacr-recoveryflow' ),
					$tenant
				),
		);
	}

	/**
	 * The button, for the settings screen to place.
	 *
	 * @return void
	 */
	public static function button(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		printf(
			'<form method="post" action="%1$s" class="recoveryflow-connection-test">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		Nonce_Field::render( self::ACTION );

		printf(
			'<input type="hidden" name="action" value="%1$s" />
			<button type="submit" class="button">%2$s</button>
			<span class="description">%3$s</span>
			</form>',
			esc_attr( self::ACTION ),
			esc_html__( 'Test connection', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'Asks WA.cr which workspace this key belongs to and what it may do.', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * Show the result of the last check, once.
	 *
	 * Rendered as a notice with role="status" so a screen reader hears the
	 * outcome after the page loads without the focus being moved out from under
	 * somebody who was already reading.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$result = get_transient( self::transient_key() );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( self::transient_key() );

		$ok = ! empty( $result['ok'] );

		printf(
			'<div class="notice %1$s" role="status"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_attr( $ok ? 'notice-success' : 'notice-error' ),
			esc_html(
				$ok
					? __( 'Connection test passed.', 'kdc-wacr-recoveryflow' )
					: __( 'Connection test failed.', 'kdc-wacr-recoveryflow' )
			),
			esc_html( isset( $result['message'] ) ? (string) $result['message'] : '' )
		);
	}

	/**
	 * Hold on to an outcome across the redirect.
	 *
	 * @param array{ok:bool,message:string} $result What the check found.
	 * @return void
	 */
	private static function remember( array $result ): void {
		set_transient( self::transient_key(), $result, self::RESULT_TTL );
	}

	/**
	 * Where one user's pending result lives.
	 *
	 * Scoped to the user, so two administrators testing at the same moment do
	 * not read each other's answer.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return 'recoveryflow_connection_test_' . get_current_user_id();
	}
}
