<?php
/**
 * Asking for a contact detail before the shopper reaches the checkout.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Security\Rate_Limiter;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The basket page and the add-to-cart button, each behind its own switch.
 *
 * The checkout is where contact details are normally learned, and that is the
 * problem this class exists for: most shoppers who abandon never reach it, so
 * the plugin knows nothing about the majority of the baskets it records. These
 * two points ask earlier.
 *
 * **Both are off by default and that is a product decision, not caution.** Each
 * puts a field in front of somebody trying to get through a page. A merchant
 * decides whether that trade is worth making; a plugin does not get to make it
 * for them by shipping it switched on.
 *
 * THE TWO POINTS ARE NOT THE SAME SHAPE, and the difference is the whole reason
 * this class is worth reading.
 *
 * **Add-to-cart carries no request of its own.** The field is rendered inside
 * WooCommerce's own add-to-cart form, so it travels with WooCommerce's own POST
 * and is read from `woocommerce_add_to_cart`. Nothing is opened, nothing extra
 * is sent, and a shopper who leaves it blank adds the product exactly as
 * before. This is the same reasoning that kept the checkout capture free of an
 * endpoint: use the round trip that is already happening.
 *
 * **The basket page has no such round trip**, so this is the one place in the
 * plugin that accepts a post from a member of the public. It is therefore the
 * one place that needs all three of the defences below, and they are not
 * optional:
 *
 * 1. **A nonce**, so the form cannot be submitted from another site.
 * 2. **A rate limit per address**, because a nonce is public to anybody who can
 *    load the basket page and proves nothing about volume. Without it this is a
 *    free way to write to the session store of a shop, once per request,
 *    forever.
 * 3. **A redirect back to the basket**, so the result is a GET. A refresh must
 *    not re-post, and the shopper must not be left on an address that is not a
 *    page.
 *
 * It writes NOTHING but the shopper's own contact snapshot and their own
 * consent answer, into their own session. It creates no journey, reads no other
 * customer, and answers identically whether or not the details it was given
 * were already known -- so it cannot be used to ask whether an address is
 * enrolled.
 */
final class Early_Capture {

	/**
	 * The admin-post action the basket form submits to.
	 */
	public const ACTION = 'recoveryflow_capture';

	/**
	 * The nonce action.
	 */
	public const NONCE = 'recoveryflow_capture';

	/**
	 * The phone field's name, on both points.
	 */
	public const FIELD_PHONE = 'recoveryflow_phone';

	/**
	 * The email field's name, on both points.
	 */
	public const FIELD_EMAIL = 'recoveryflow_email';

	/**
	 * How many basket-page posts one address may make in a window.
	 */
	private const RATE_LIMIT = 10;

	/**
	 * The window, in seconds.
	 */
	private const RATE_WINDOW = 300;

	/**
	 * The shopper's session.
	 *
	 * @var Session
	 */
	private Session $session;

	/**
	 * Where a contact detail is written.
	 *
	 * @var Contact_Snapshot
	 */
	private Contact_Snapshot $contact;

	/**
	 * The consent question.
	 *
	 * @var Consent_Field
	 */
	private Consent_Field $consent;

	/**
	 * Throttling for the one public post.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $limiter;

	/**
	 * Logging.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Session          $session The session adapter.
	 * @param Contact_Snapshot $contact Contact storage.
	 * @param Consent_Field    $consent The consent question.
	 * @param Rate_Limiter     $limiter Throttling.
	 * @param Logger           $logger  Logging.
	 */
	public function __construct(
		Session $session,
		Contact_Snapshot $contact,
		Consent_Field $consent,
		Rate_Limiter $limiter,
		Logger $logger
	) {
		$this->session = $session;
		$this->contact = $contact;
		$this->consent = $consent;
		$this->limiter = $limiter;
		$this->logger  = $logger;
	}

	/**
	 * Attach to WordPress.
	 *
	 * The handler is registered whenever the basket point is on, for logged-in
	 * and logged-out shoppers alike -- a guest basket is the ordinary case, and
	 * registering only the `nopriv` half would make the form silently fail for
	 * anybody with an account.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( self::wants_cart() ) {
			add_action( 'woocommerce_after_cart_table', array( $this, 'render_cart_form' ) );
			add_action( 'woocommerce_after_cart', array( $this, 'render_cart_form_for_block' ) );
			add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_cart_post' ) );
			add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_cart_post' ) );
		}

		if ( self::wants_add_to_cart() ) {
			add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_product_field' ) );
			add_action( 'woocommerce_add_to_cart', array( $this, 'capture_add_to_cart' ) );
		}
	}

	/**
	 * Whether the basket point is switched on for a shop that is recovering.
	 *
	 * @return bool
	 */
	public static function wants_cart(): bool {
		return (bool) Options::get( 'capture_at_cart', false ) && Rule_Set::for_source()->is_enabled();
	}

	/**
	 * Whether the add-to-cart point is switched on for a shop that is recovering.
	 *
	 * @return bool
	 */
	public static function wants_add_to_cart(): bool {
		return (bool) Options::get( 'capture_at_add_to_cart', false ) && Rule_Set::for_source()->is_enabled();
	}

	/**
	 * The field that rides along with WooCommerce's add-to-cart form.
	 *
	 * @return void
	 */
	public function render_product_field(): void {
		echo $this->fields_markup( 'recoveryflow-atc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in fields_markup().
	}

	/**
	 * Read the field out of WooCommerce's own add-to-cart request.
	 *
	 * No nonce of ours: this is WooCommerce's form, WooCommerce's request and
	 * WooCommerce's own protection, and the only thing being written is the
	 * shopper's own session. Adding a second nonce here would refuse every
	 * add-to-cart made from a theme that renders its own button.
	 *
	 * @return void
	 */
	public function capture_add_to_cart(): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's own add-to-cart request; see above.
			$this->take( $_POST );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_capture', 'Could not read the add-to-cart contact field.' );
		}
	}

	/**
	 * The basket page's own small form.
	 *
	 * @return void
	 */
	public function render_cart_form(): void {
		$markup = $this->fields_markup( 'recoveryflow-cart' );

		if ( '' === $markup ) {
			return;
		}

		printf(
			'<form method="post" action="%1$s" class="recoveryflow-capture"><h2 class="recoveryflow-capture__title">%2$s</h2><p class="recoveryflow-capture__intro">%3$s</p>%4$s%5$s<input type="hidden" name="action" value="%6$s" /><input type="hidden" name="redirect_to" value="%7$s" /><p><button type="submit" class="button">%8$s</button></p></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Save this basket', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'Leave a way to reach you and we can send you a link back to this basket if you do not finish. You can stop the reminders at any time.', 'kdc-wacr-recoveryflow' ),
			$markup, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in fields_markup().
			wp_nonce_field( self::NONCE, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
			esc_attr( self::ACTION ),
			esc_url( $this->cart_url() ),
			esc_html__( 'Save my basket', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * The same form, for a basket page built from the Cart block.
	 *
	 * `woocommerce_after_cart_table` is part of the shortcode basket's own
	 * template and never fires on a block basket, which would have made this
	 * setting silently do nothing on any shop built in the last few years.
	 * `woocommerce_after_cart` fires on both, so the shortcode path is guarded
	 * against rendering the form twice.
	 *
	 * @return void
	 */
	public function render_cart_form_for_block(): void {
		if ( did_action( 'woocommerce_after_cart_table' ) > 0 ) {
			return;
		}

		$this->render_cart_form();
	}

	/**
	 * Take the basket form's post, then send the shopper back to the basket.
	 *
	 * @return void
	 */
	public function handle_cart_post(): void {
		$back = $this->cart_url();

		try {
			if ( ! self::wants_cart() ) {
				wp_safe_redirect( $back );

				exit;
			}

			check_admin_referer( self::NONCE );

			/*
			 * The nonce is on a page anybody can load, so it says where the
			 * post came from and nothing about how often. This is the only
			 * public write in the plugin and it is the only thing standing
			 * between it and being a free session-writer.
			 */
			if ( ! $this->limiter->hit( 'capture:' . $this->caller(), self::RATE_LIMIT, self::RATE_WINDOW ) ) {
				wp_safe_redirect( $back );

				exit;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
			$this->take( $_POST );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_capture', 'Could not read the basket contact form.' );
		}//end try

		wp_safe_redirect( $back );

		exit;
	}

	/**
	 * Read whichever of the two fields are present, and the consent answer.
	 *
	 * Shared by both points on purpose. They post the same field names, so the
	 * rule for what a blank means, what is stored and how consent is recorded
	 * is written once -- which is the difference between two capture points and
	 * two capture implementations.
	 *
	 * @param array<string,mixed> $source The request array.
	 * @return void
	 */
	private function take( array $source ): void {
		if ( ! $this->session->is_ready() ) {
			return;
		}

		$phone = isset( $source[ self::FIELD_PHONE ] ) && is_scalar( $source[ self::FIELD_PHONE ] )
			? Contact_Snapshot::clean( (string) wp_unslash( $source[ self::FIELD_PHONE ] ) )
			: '';
		$email = isset( $source[ self::FIELD_EMAIL ] ) && is_scalar( $source[ self::FIELD_EMAIL ] )
			? Contact_Snapshot::clean( (string) wp_unslash( $source[ self::FIELD_EMAIL ] ) )
			: '';

		if ( '' === $phone && '' === $email ) {
			// Nothing was offered. Recording a refusal of consent here would
			// turn "I ignored your field" into "I said no", which is a
			// different thing and would suppress a shopper who never answered.
			return;
		}

		$this->contact->remember(
			array(
				'phone' => $phone,
				'email' => is_email( $email ) ? $email : '',
			)
		);

		if ( ! $this->consent->is_asked() ) {
			return;
		}

		/*
		 * Only recorded when something was given. On a form the shopper HAS
		 * filled in, an unticked box is an answer -- the same rule the checkout
		 * uses, for the same reason.
		 */
		$this->consent->record(
			isset( $source[ Consent_Field::FIELD_ID ] ) && '' !== (string) $source[ Consent_Field::FIELD_ID ],
			Consent_Field::SOURCE_EARLY
		);
	}

	/**
	 * The two inputs, plus the consent box where consent is being asked for.
	 *
	 * @param string $prefix A unique id prefix, because both points can render on one page.
	 * @return string
	 */
	private function fields_markup( string $prefix ): string {
		$stored = $this->session->get( Session::KEY_CONTACT, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$markup = sprintf(
			'<p class="recoveryflow-capture__field"><label for="%1$s-phone">%2$s</label> <input type="tel" name="%3$s" id="%1$s-phone" value="%4$s" autocomplete="tel" /></p>',
			esc_attr( $prefix ),
			esc_html__( 'Mobile number', 'kdc-wacr-recoveryflow' ),
			esc_attr( self::FIELD_PHONE ),
			esc_attr( (string) ( $stored['phone'] ?? '' ) )
		);

		$markup .= sprintf(
			'<p class="recoveryflow-capture__field"><label for="%1$s-email">%2$s</label> <input type="email" name="%3$s" id="%1$s-email" value="%4$s" autocomplete="email" /></p>',
			esc_attr( $prefix ),
			esc_html__( 'Email address', 'kdc-wacr-recoveryflow' ),
			esc_attr( self::FIELD_EMAIL ),
			esc_attr( (string) ( $stored['email'] ?? '' ) )
		);

		if ( ! $this->consent->is_asked() ) {
			return $markup;
		}

		return $markup . sprintf(
			'<p class="recoveryflow-capture__consent"><label for="%1$s-consent"><input type="checkbox" name="%2$s" id="%1$s-consent" value="1" /> %3$s</label></p>',
			esc_attr( $prefix ),
			esc_attr( Consent_Field::FIELD_ID ),
			esc_html( $this->consent->label() )
		);
	}

	/**
	 * Where to send somebody back to.
	 *
	 * @return string
	 */
	private function cart_url(): string {
		$url = function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '';

		return '' === $url ? home_url( '/' ) : $url;
	}

	/**
	 * A stable, non-identifying key for the address making the request.
	 *
	 * Hashed, because this becomes a transient name and a transient name is
	 * readable by anything else on the site. The address itself is not stored.
	 *
	 * @return string
	 */
	private function caller(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return hash( 'sha256', $ip . '|' . wp_salt( 'nonce' ) );
	}
}
