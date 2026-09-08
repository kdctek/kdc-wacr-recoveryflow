<?php
/**
 * Generating the secret an Auto Flow presents when it calls this site.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\REST\Routes;
use WAcr\RecoveryFlow\REST\Webhook_Controller;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Security\Webhook_Secret;

defined( 'ABSPATH' ) || exit;

/**
 * The address and the secret, together, because neither is any use alone.
 *
 * A merchant building the flow end of this needs three things and has to type
 * all of them into WA.cr by hand: where to send the request, what header to put
 * the secret in, and the secret itself. Giving them one and leaving the others
 * to the documentation is how a feature ends up configured wrongly and reported
 * as broken -- so all three are on the screen, next to each other, with the
 * events written out beneath them.
 *
 * **The secret is shown once and then never again**, because only its hash is
 * stored. That is stated before the button is pressed rather than discovered
 * afterwards by somebody who navigated away. Losing it costs a new secret and
 * an edit to the flow, which is the same bargain WordPress makes with
 * application passwords.
 *
 * **Generating a second secret invalidates the first**, and says so, because
 * the flow will start being refused the moment it happens. That is the whole
 * point when a secret has leaked, and an unwelcome surprise otherwise.
 */
final class Webhook_Setup {

	/**
	 * The admin-post action name.
	 */
	public const ACTION = 'recoveryflow_webhook_secret';

	/**
	 * How long the newly generated secret waits to be shown, in seconds.
	 *
	 * Short. It is the plaintext of a credential sitting in the options table
	 * until it is displayed, and the only thing it has to survive is one
	 * redirect.
	 */
	private const RESULT_TTL = 60;

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Generate a secret and go back to the tab to show it.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change this site\'s WA.cr settings.', 'kdc-wacr-recoveryflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		set_transient( self::transient_key(), Webhook_Secret::generate(), self::RESULT_TTL );

		wp_safe_redirect( Screen::settings_url( 'wacr', 'callback' ) );

		exit;
	}

	/**
	 * The address, the secret, and what a flow may send here.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'A WA.cr Auto Flow can tell this site something, using a webhook step pointed back here. The one worth building is an unsubscribe: a customer who replies STOP inside your flow is then stopped here immediately, rather than whenever the next background pass happens to look.', 'kdc-wacr-recoveryflow' )
		);

		printf(
			'<p><strong>%1$s</strong><br /><code>%2$s</code></p>',
			esc_html__( 'Send the request to', 'kdc-wacr-recoveryflow' ),
			esc_url( Routes::url( 'webhooks/wacr' ) )
		);

		printf(
			'<p><strong>%1$s</strong><br /><code>%2$s</code></p>',
			esc_html__( 'Put the secret in this header', 'kdc-wacr-recoveryflow' ),
			esc_html( Webhook_Secret::HEADER )
		);

		printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'It accepts these events:', 'kdc-wacr-recoveryflow' ),
			esc_html( implode( ', ', Webhook_Controller::EVENTS ) )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Send it as JSON with an "event" key, the customer\'s "phone", and an "id" of your own that is different every time -- the id is what lets this site recognise the same event arriving twice and do nothing the second time.', 'kdc-wacr-recoveryflow' )
		);

		self::reveal();

		printf(
			'<form method="post" action="%1$s" class="recoveryflow-webhook-secret">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( self::ACTION );

		printf(
			'<input type="hidden" name="action" value="%1$s" />
			<p><button type="submit" class="button">%2$s</button></p>
			<p class="description">%3$s</p>
			</form>',
			esc_attr( self::ACTION ),
			esc_html(
				Webhook_Secret::exists()
					? __( 'Generate a new secret', 'kdc-wacr-recoveryflow' )
					: __( 'Generate a secret', 'kdc-wacr-recoveryflow' )
			),
			esc_html(
				Webhook_Secret::exists()
					? __( 'A secret already exists. Generating another replaces it, and your flow will start being refused until you paste the new one in. Only the secret\'s fingerprint is stored here, so the existing one cannot be shown again.', 'kdc-wacr-recoveryflow' )
					: __( 'The secret is shown once, here, and never again -- only its fingerprint is stored. Copy it into your Auto Flow before leaving this page. Until one exists, this site refuses every request to the address above.', 'kdc-wacr-recoveryflow' )
			)
		);
	}

	/**
	 * Show a newly generated secret, once.
	 *
	 * @return void
	 */
	private static function reveal(): void {
		$secret = get_transient( self::transient_key() );

		if ( ! is_string( $secret ) || '' === $secret ) {
			return;
		}

		delete_transient( self::transient_key() );

		printf(
			'<div class="notice notice-success" role="status"><p><strong>%1$s</strong></p><p><code>%2$s</code></p><p>%3$s</p></div>',
			esc_html__( 'Here is the secret. This is the only time it will be shown.', 'kdc-wacr-recoveryflow' ),
			esc_html( $secret ),
			esc_html__( 'Copy it into the webhook step of your Auto Flow now. If you lose it, generate another and update the flow.', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * Where one user's freshly generated secret waits.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return 'recoveryflow_webhook_secret_shown_' . get_current_user_id();
	}
}
