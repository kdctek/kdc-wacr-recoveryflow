<?php
/**
 * The WooCommerce recovery source.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

use WAcr\RecoveryFlow\Integration\Abstract_Source;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce translated into the core's vocabulary, and nothing more.
 *
 * This class holds no behaviour of its own. The write path, the identity
 * capture, the consent question, the conversion watch and the restore each live
 * in their own class, because each of them is a separate set of WooCommerce
 * hooks with a separate set of traps in it, and a single "WooCommerce
 * integration" class would be four thousand lines that nobody could review.
 * What is left here is the interface the core asked for.
 *
 * is_available() is asked on every page load, so it is one class_exists and
 * nothing else. Anything heavier -- a version check, an option read, a database
 * query -- would be paid for by every request on the site whether or not
 * anybody is shopping.
 *
 * register() must run at plugins_loaded or earlier. WooCommerce fires
 * woocommerce_guest_session_to_user_id from init_session_cookie(), before
 * wp_loaded, and a guest who signs in mid-visit without that hook attached
 * becomes two open events and two WhatsApp messages for one basket.
 */
final class Source extends Abstract_Source {

	/**
	 * Stable machine identifier, written on every row this adapter produces.
	 */
	public const ID = 'woocommerce';

	/**
	 * The cart write path.
	 *
	 * @var Cart_Tracker
	 */
	private Cart_Tracker $cart;

	/**
	 * Identity capture at the checkout.
	 *
	 * @var Checkout_Capture
	 */
	private Checkout_Capture $checkout;

	/**
	 * The consent tick-box.
	 *
	 * @var Consent_Field
	 */
	private Consent_Field $consent;

	/**
	 * Conversion detection.
	 *
	 * @var Order_Observer
	 */
	private Order_Observer $orders;

	/**
	 * The restore path.
	 *
	 * @var Cart_Restorer
	 */
	private Cart_Restorer $restorer;

	/**
	 * The classic checkout's capture script.
	 *
	 * @var Checkout_Script
	 */
	private Checkout_Script $script;

	/**
	 * Constructor.
	 *
	 * @param Event_Ingest     $ingest   Event ingestion.
	 * @param Cart_Tracker     $cart     The cart write path.
	 * @param Checkout_Capture $checkout Identity capture at the checkout.
	 * @param Consent_Field    $consent  The consent tick-box.
	 * @param Order_Observer   $orders   Conversion detection.
	 * @param Cart_Restorer    $restorer The restore path.
	 * @param Checkout_Script  $script   The classic checkout's capture script.
	 */
	public function __construct(
		Event_Ingest $ingest,
		Cart_Tracker $cart,
		Checkout_Capture $checkout,
		Consent_Field $consent,
		Order_Observer $orders,
		Cart_Restorer $restorer,
		Checkout_Script $script
	) {
		parent::__construct( $ingest );

		$this->cart     = $cart;
		$this->checkout = $checkout;
		$this->consent  = $consent;
		$this->orders   = $orders;
		$this->restorer = $restorer;
		$this->script   = $script;
	}

	/**
	 * Stable machine identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * Human name for the admin screens.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'WooCommerce', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * One sentence on what this source recovers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Recovers abandoned carts and checkouts, on both the classic and the block checkout.', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * Whether WooCommerce is present.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Attach every hook this adapter needs.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->cart->register();
		$this->checkout->register();
		$this->consent->register();
		$this->orders->register();
		$this->script->register();
	}

	/**
	 * The kinds of event this source produces.
	 *
	 * @return string[]
	 */
	public function get_event_types(): array {
		return array( 'cart', 'checkout' );
	}

	/**
	 * Rule overrides that suit a shop, merged under the site's settings.
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_rules(): array {
		return array(
			'inactivity_minutes' => 30,
			'max_age_days'       => 7,
			'min_amount'         => 0,
		);
	}

	/**
	 * Whether the basket behind this event has since been bought.
	 *
	 * Answered from live WooCommerce state every time, because it is asked
	 * again immediately before each send: a shopper who finished the order in
	 * another tab an hour after the reminder was scheduled must not then be
	 * asked to finish it.
	 *
	 * @param Recovery_Event $event The event.
	 * @return bool
	 */
	public function is_conversion_complete( Recovery_Event $event ): bool {
		if ( ! $event->is_open() ) {
			return true;
		}

		$external = null === $event->external_id ? '' : trim( $event->external_id );

		if ( '' === $external || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( absint( $external ) );

		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		// A refused or abandoned order is not a purchase, and the basket behind
		// it is still worth recovering -- which is the whole reason a declined
		// card sends a journey back to work.
		return ! in_array( $order->get_status(), array( 'checkout-draft', 'failed', 'cancelled' ), true );
	}

	/**
	 * Put the shopper's basket back and say where to send them.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param Recovery_Event   $event   The event being recovered.
	 * @return string|\WP_Error Redirect URL, or an error to show the generic page for.
	 */
	public function restore( Recovery_Journey $journey, Recovery_Event $event ) {
		return $this->restorer->restore( $journey, $event );
	}
}
