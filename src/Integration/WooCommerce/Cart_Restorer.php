<?php
/**
 * Putting a basket back without taking one away.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A merge, never a replacement.
 *
 * The person following a recovery link may already have a basket, and it may be
 * a better one than the snapshot -- they came back, added two more things, and
 * only then noticed the message. Emptying the cart to reinstate the snapshot
 * would destroy the very conversion this is trying to recover, so nothing is
 * ever removed and a line that is already there is left exactly as it is.
 *
 * Every line is re-checked against live WooCommerce state rather than trusted.
 * A snapshot is a record of what was true when it was taken; by the time a
 * message is read the product may be gone, unpurchasable, out of stock, or
 * limited to one per customer, and adding it anyway would produce a checkout
 * that cannot be completed.
 *
 * The refusal at the top is the important one. A recovery link is a URL, and
 * URLs get forwarded, pasted into chats and opened on shared machines. If the
 * person opening it is signed in as somebody else, restoring would drop a
 * stranger's shopping into their cart. That is refused outright rather than
 * quietly working, because a link that "mostly" belongs to you is not a thing.
 */
final class Cart_Restorer {

	/**
	 * Guarded access to the WooCommerce session.
	 *
	 * @var Session
	 */
	private Session $session;

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Session             $session   Guarded session access.
	 * @param Customer_Repository $customers Customer storage.
	 * @param Logger              $logger    Logger.
	 */
	public function __construct( Session $session, Customer_Repository $customers, Logger $logger ) {
		$this->session   = $session;
		$this->customers = $customers;
		$this->logger    = $logger;
	}

	/**
	 * Merge the snapshot into the clicker's own cart.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param Recovery_Event   $event   The event being recovered.
	 * @return string|\WP_Error Redirect URL, or an error to show the generic page for.
	 */
	public function restore( Recovery_Journey $journey, Recovery_Event $event ) {
		try {
			$owner = $this->owner( $journey );

			if ( is_wp_error( $owner ) ) {
				return $owner;
			}

			$cart = $this->cart();

			if ( null === $cart ) {
				return new \WP_Error(
					'recoveryflow_no_cart',
					__( 'The shop is not available right now.', 'kdc-wacr-recoveryflow' )
				);
			}

			$this->add_items( $cart, $event );
			$this->apply_coupons( $cart, $event );
			$this->prefill( $owner );

			// Stamped on the order the moment one is created, so a purchase
			// that follows this click is credited to the right journey even if
			// the shopper takes a week over it.
			$this->session->set( Session::KEY_JOURNEY_UID, $journey->journey_uid );

			return $this->destination( $cart );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'wc_restore',
				'Could not restore a cart from a recovery link.',
				array( 'event' => $event->id ),
				$journey->id
			);

			return new \WP_Error(
				'recoveryflow_restore_failed',
				__( 'We could not rebuild your basket. Please try again.', 'kdc-wacr-recoveryflow' )
			);
		}//end try
	}

	/**
	 * Whose basket this is, and whether the clicker may have it.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @return Customer|null|\WP_Error The journey's customer, or an error.
	 */
	private function owner( Recovery_Journey $journey ) {
		$customer = $journey->customer_id > 0 ? $this->customers->find( $journey->customer_id ) : null;
		$current  = get_current_user_id();

		if ( null !== $customer && $current > 0 && null !== $customer->wp_user_id && $customer->wp_user_id !== $current ) {
			return new \WP_Error(
				'recoveryflow_wrong_user',
				__( 'This recovery link belongs to a different account.', 'kdc-wacr-recoveryflow' )
			);
		}

		return $customer;
	}

	/**
	 * The cart, loading it if this request has not got one yet.
	 *
	 * @return \WC_Cart|null
	 */
	private function cart(): ?\WC_Cart {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		if ( ( ! isset( WC()->cart ) || ! WC()->cart instanceof \WC_Cart ) && function_exists( 'wc_load_cart' ) ) {
			// A recovery link can land on any request, including one where
			// WooCommerce decided no cart was needed.
			wc_load_cart();
		}

		return isset( WC()->cart ) && WC()->cart instanceof \WC_Cart ? WC()->cart : null;
	}

	/**
	 * Add whatever is still buyable, without disturbing what is already there.
	 *
	 * @param \WC_Cart       $cart  The cart.
	 * @param Recovery_Event $event The event being recovered.
	 * @return void
	 */
	private function add_items( \WC_Cart $cart, Recovery_Event $event ): void {
		$allowed = $this->allowed_item_data();

		foreach ( $event->items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$ref          = isset( $item['ref'] ) && is_array( $item['ref'] ) ? $item['ref'] : array();
			$product_id   = isset( $ref['product_id'] ) ? absint( $ref['product_id'] ) : 0;
			$variation_id = isset( $ref['variation_id'] ) ? absint( $ref['variation_id'] ) : 0;
			$attributes   = isset( $ref['attrs'] ) && is_array( $ref['attrs'] ) ? $ref['attrs'] : array();
			$item_data    = $this->item_data( $ref, $allowed );

			$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );

			if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			$wanted = $this->quantity( $product, isset( $item['qty'] ) ? (int) $item['qty'] : 1 );

			if ( $wanted < 1 ) {
				continue;
			}

			$missing = $wanted - $this->already_in_cart( $cart, $product_id, $variation_id, $attributes, $item_data );

			if ( $missing < 1 ) {
				continue;
			}

			if ( ! $product->has_enough_stock( $missing ) ) {
				continue;
			}

			$cart->add_to_cart( $product_id, $missing, $variation_id, $attributes, $item_data );
		}//end foreach
	}

	/**
	 * How many of this product the shopper may end up with.
	 *
	 * @param \WC_Product $product  The product.
	 * @param int         $snapshot Quantity recorded in the snapshot.
	 * @return int
	 */
	private function quantity( \WC_Product $product, int $snapshot ): int {
		$wanted = max( 1, $snapshot );

		if ( $product->is_sold_individually() ) {
			return 1;
		}

		$max = (int) $product->get_max_purchase_quantity();

		if ( 0 === $max ) {
			// WooCommerce says none may be bought at all.
			return 0;
		}

		return $max > 0 ? min( $wanted, $max ) : $wanted;
	}

	/**
	 * How many of this exact line the cart already holds.
	 *
	 * @param \WC_Cart            $cart         The cart.
	 * @param int                 $product_id   Product id.
	 * @param int                 $variation_id Variation id.
	 * @param array<string,mixed> $attributes   Chosen variation attributes.
	 * @param array<string,mixed> $item_data    Restored custom cart item data.
	 * @return int
	 */
	private function already_in_cart( \WC_Cart $cart, int $product_id, int $variation_id, array $attributes, array $item_data ): int {
		if ( ! method_exists( $cart, 'generate_cart_id' ) || ! method_exists( $cart, 'find_product_in_cart' ) ) {
			return 0;
		}

		$cart_id = $cart->generate_cart_id( $product_id, $variation_id, $attributes, $item_data );
		$found   = $cart->find_product_in_cart( $cart_id );

		if ( ! is_string( $found ) || '' === $found || ! isset( $cart->cart_contents[ $found ]['quantity'] ) ) {
			return 0;
		}

		return (int) $cart->cart_contents[ $found ]['quantity'];
	}

	/**
	 * Put back the discounts the basket had.
	 *
	 * WooCommerce's own notices are left to speak for a coupon that has since
	 * expired: silently dropping it would leave the shopper looking at a total
	 * they did not expect and no explanation for it.
	 *
	 * @param \WC_Cart       $cart  The cart.
	 * @param Recovery_Event $event The event being recovered.
	 * @return void
	 */
	private function apply_coupons( \WC_Cart $cart, Recovery_Event $event ): void {
		$coupons = isset( $event->metadata['coupons'] ) ? $event->metadata['coupons'] : array();

		if ( ! is_array( $coupons ) || ! method_exists( $cart, 'apply_coupon' ) ) {
			return;
		}

		foreach ( $coupons as $code ) {
			if ( ! is_scalar( $code ) ) {
				continue;
			}

			$code = sanitize_text_field( (string) $code );

			if ( '' === $code || $cart->has_discount( $code ) ) {
				continue;
			}

			$cart->apply_coupon( $code );
		}
	}

	/**
	 * Fill in a guest's details so they do not type them twice.
	 *
	 * Never for a signed-in shopper: WooCommerce already knows their details,
	 * and writing over them from a snapshot would overwrite an address they
	 * changed in the meantime. Never over something already filled in, for the
	 * same reason.
	 *
	 * @param Customer|null $customer The journey's customer.
	 * @return void
	 */
	private function prefill( ?Customer $customer ): void {
		if ( null === $customer || is_user_logged_in() || ! Options::get( 'prefill_guest_checkout', true ) ) {
			return;
		}

		$wc_customer = $this->session->customer();

		if ( null === $wc_customer ) {
			return;
		}

		$phone = '' !== $customer->phone_e164 ? $customer->phone_e164 : $customer->phone_raw;

		$this->fill( $wc_customer, 'billing_first_name', $customer->first_name );
		$this->fill( $wc_customer, 'billing_last_name', $customer->last_name );
		$this->fill( $wc_customer, 'billing_email', $customer->email );
		$this->fill( $wc_customer, 'billing_phone', $phone );
		$this->fill( $wc_customer, 'billing_country', $customer->country_iso2 );
	}

	/**
	 * Set one customer field, if it has no value yet.
	 *
	 * @param \WC_Customer $wc_customer The WooCommerce customer.
	 * @param string       $field       Field name, e.g. billing_phone.
	 * @param string       $value       Value to write.
	 * @return void
	 */
	private function fill( \WC_Customer $wc_customer, string $field, string $value ): void {
		$getter = 'get_' . $field;
		$setter = 'set_' . $field;

		if ( '' === $value || ! method_exists( $wc_customer, $getter ) || ! method_exists( $wc_customer, $setter ) ) {
			return;
		}

		$existing = $wc_customer->{$getter}();

		if ( is_scalar( $existing ) && '' !== trim( (string) $existing ) ) {
			return;
		}

		$wc_customer->{$setter}( $value );
	}

	/**
	 * The custom cart item data keys a site has asked to have restored.
	 *
	 * @return string[]
	 */
	private function allowed_item_data(): array {
		/** This filter is documented in src/Integration/WooCommerce/Cart_Tracker.php */
		$allowed = apply_filters( Hooks::FILTER_RESTORE_ITEM_DATA, array() );

		if ( ! is_array( $allowed ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $allowed ) ) );
	}

	/**
	 * Keep only the allow-listed custom keys from one snapshot line.
	 *
	 * Re-filtered on the way back in as well as on the way out, so removing a
	 * key from the allow-list immediately stops it being restored from
	 * snapshots that were taken while it was still listed.
	 *
	 * @param array<string,mixed> $ref     The line's reference block.
	 * @param string[]            $allowed Allow-listed keys.
	 * @return array<string,mixed>
	 */
	private function item_data( array $ref, array $allowed ): array {
		$stored = isset( $ref['data'] ) && is_array( $ref['data'] ) ? $ref['data'] : array();
		$data   = array();

		foreach ( $allowed as $key ) {
			if ( isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ) {
				$data[ $key ] = $stored[ $key ];
			}
		}

		return $data;
	}

	/**
	 * Where to send the shopper next.
	 *
	 * @param \WC_Cart $cart The cart.
	 * @return string
	 */
	private function destination( \WC_Cart $cart ): string {
		if ( $cart->is_empty() ) {
			// Nothing survived: the checkout would be a dead end, and the cart
			// page at least explains itself.
			return wc_get_cart_url();
		}

		return wc_get_checkout_url();
	}
}
