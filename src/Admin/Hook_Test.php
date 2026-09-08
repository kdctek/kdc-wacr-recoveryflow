<?php
/**
 * The "Send a test push" button beside the Auto Flow hook address.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\WAcr\Client;
use WAcr\RecoveryFlow\WAcr\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Proving a hook address before a real basket depends on it.
 *
 * The hand-off path has one setting a merchant can get wrong in a way nothing
 * reveals: the hook address. A wrong one fails at dispatch, hours after the
 * basket was left, in a log nobody is reading, and the only visible symptom is
 * that recovery quietly does not happen. So the address gets the same treatment
 * the API key got -- a button that asks the question now, in front of the
 * person who just pasted it.
 *
 * **A test push really does hit the flow.** WA.cr's webhook trigger fires on
 * any request that reaches it; there is no test mode to ask for. Pretending
 * otherwise would be the more dangerous design, so the button says so before it
 * is pressed and the payload is built to be harmless if the flow runs anyway:
 * the event names itself a test, `test` is true, and the phone number is empty.
 * A flow that goes on to send has nobody to send to and fails at the send
 * rather than messaging a real customer with sample text.
 *
 * The payload is otherwise shaped exactly like the real one -- the same keys,
 * always present -- because a test that exercises a different shape than the
 * thing it is testing proves very little. WA.cr seeds each top-level scalar as
 * `hook_<key>`, so the same keys are what an Auto Flow author will find waiting
 * for them.
 */
final class Hook_Test {

	/**
	 * The admin-post action name.
	 */
	public const ACTION = 'recoveryflow_test_hook';

	/**
	 * The event a test push announces itself as.
	 *
	 * Deliberately NOT the real `recovery.journey_eligible`: a flow that
	 * branches on the event can ignore this one, and a flow that does not is
	 * still safe because of the empty phone number.
	 */
	public const EVENT = 'recovery.test_push';

	/**
	 * How long the result waits to be shown, in seconds.
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
	 * Push once, remember what came back, and go back to the tab.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to test the Auto Flow hook on this site.', 'kdc-wacr-recoveryflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		set_transient( self::transient_key(), $this->push(), self::RESULT_TTL );

		wp_safe_redirect( Screen::settings_url( 'wacr', 'dispatch', 'wacr_hook_url' ) );

		exit;
	}

	/**
	 * Send the test push.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function push(): array {
		if ( ! $this->credentials->has_hook() ) {
			return array(
				'ok'      => false,
				'message' => __( 'There is no Auto Flow hook address saved to test. Paste one and save, then test it.', 'kdc-wacr-recoveryflow' ),
			);
		}

		$result = $this->client->start_flow( self::payload() );

		if ( ! $result->ok ) {
			return array(
				'ok'      => false,
				// WA.cr explains its own refusals -- a hook that was deleted, a
				// signature that did not match, a flow that is switched off --
				// and each needs a different fix. Replacing that with one
				// sentence of our own would tell the merchant less.
				'message' => null === $result->error
					? __( 'The hook address could not be reached. Check the address, and that this site can make outbound requests.', 'kdc-wacr-recoveryflow' )
					: $result->error->message,
			);
		}

		return array(
			'ok'      => true,
			'message' => '' === $this->credentials->hook_secret()
				? __( 'WA.cr accepted the push. Nothing is signing these yet, so anybody who learns the address could trigger your flow -- add a signing secret in WA.cr and paste it here.', 'kdc-wacr-recoveryflow' )
				: __( 'WA.cr accepted the push, and accepted its signature. The Auto Flow has now run once with a test payload.', 'kdc-wacr-recoveryflow' ),
		);
	}

	/**
	 * A payload shaped like the real one, carrying nobody.
	 *
	 * @return array<string,mixed>
	 */
	public static function payload(): array {
		return array(
			'event'         => self::EVENT,
			'test'          => true,
			'journey_uid'   => 'test-' . gmdate( 'YmdHis' ),
			'source'        => 'recoveryflow',
			'source_type'   => 'test',
			'phone'         => '',
			'first_name'    => __( 'Test', 'kdc-wacr-recoveryflow' ),
			'currency'      => 'GBP',
			'total'         => '0.00',
			'item_count'    => 1,
			'items_summary' => __( 'A test push from RecoveryFlow', 'kdc-wacr-recoveryflow' ),
			'recovery_url'  => home_url( '/' ),
			'opt_out_url'   => home_url( '/' ),
			'site_name'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'      => home_url( '/' ),
			'abandoned_at'  => gmdate( 'c' ),
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
			'<form method="post" action="%1$s" class="recoveryflow-hook-test">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		Nonce_Field::render( self::ACTION );

		printf(
			'<input type="hidden" name="action" value="%1$s" />
			<button type="submit" class="button">%2$s</button>
			<span class="description">%3$s</span>
			</form>',
			esc_attr( self::ACTION ),
			esc_html__( 'Send a test push', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'This really runs your Auto Flow, because a webhook trigger fires on anything that reaches it. The test carries no phone number, so a flow that goes on to send has nobody to send to.', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * Show the result of the last push, once.
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
					? __( 'Test push delivered.', 'kdc-wacr-recoveryflow' )
					: __( 'Test push failed.', 'kdc-wacr-recoveryflow' )
			),
			esc_html( isset( $result['message'] ) ? (string) $result['message'] : '' )
		);
	}

	/**
	 * Where this user's result waits.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return 'recoveryflow_hook_test_' . get_current_user_id();
	}
}
