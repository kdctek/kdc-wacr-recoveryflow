<?php
/**
 * Learning who the shopper is while they fill the checkout in.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Contact details, taken server-side, without a line of JavaScript.
 *
 * A basket is worth nothing to a recovery plugin until it knows a phone number,
 * and a shopper who abandons the checkout never presses Place Order. So the
 * details are taken as they are typed, from hooks WooCommerce fires anyway.
 *
 * On the classic checkout that hook is woocommerce_checkout_update_order_review,
 * and two things about it decide the whole design of this class. Its argument
 * is not an array: it is the raw, URL-encoded serialisation of the checkout
 * form, so every value has to be parsed out and sanitised here rather than
 * trusted. And it does not fire when the shopper types a phone number or an
 * email address -- WooCommerce's checkout.js binds the update to
 * ".address-field input.input-text" and ".update_totals_on_change input.input-text",
 * and neither billing_phone nor billing_email carries those classes. It fires
 * on address, country, postcode, shipping-method and coupon changes, and the
 * serialised form then carries whatever is in the phone and email fields at
 * that moment. Capture is therefore opportunistic: take the value every time
 * the hook fires, and keep the best one seen.
 *
 * Nothing is written to the database here. The details go into the WooCommerce
 * session and the shutdown flush in Cart_Tracker picks them up, so a shopper
 * changing their address six times still costs one write.
 */
final class Checkout_Capture {

	/**
	 * Guarded access to the WooCommerce session.
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
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Session          $session Guarded session access.
	 * @param Contact_Snapshot $contact Where a contact detail is written.
	 * @param Logger           $logger  Logger.
	 */
	public function __construct( Session $session, Contact_Snapshot $contact, Logger $logger ) {
		$this->session = $session;
		$this->contact = $contact;
		$this->logger  = $logger;
	}

	/**
	 * Attach to WooCommerce.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_order_review' ), 10, 1 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( $this, 'capture_store_api' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'capture_store_api' ) );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'capture_signed_in' ) );
	}

	/**
	 * Read the classic checkout form as the shopper edits it.
	 *
	 * @param mixed $post_data Raw, URL-encoded serialisation of the checkout form.
	 * @return void
	 */
	public function capture_order_review( $post_data ): void {
		try {
			$parsed = $this->parse( $post_data );

			if ( array() === $parsed ) {
				return;
			}

			$this->remember(
				array(
					'phone'            => $this->field( $parsed, 'billing_phone' ),
					'email'            => $this->field( $parsed, 'billing_email' ),
					'first_name'       => $this->field( $parsed, 'billing_first_name' ),
					'last_name'        => $this->field( $parsed, 'billing_last_name' ),
					'country'          => $this->country( $this->field( $parsed, 'billing_country' ) ),
					'shipping_country' => $this->country( $this->field( $parsed, 'shipping_country' ) ),
				)
			);
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_checkout', 'Could not read the classic checkout fields.' );
		}
	}

	/**
	 * Read the block checkout's customer object after the Store API updates it.
	 *
	 * Both Store API hooks hand over different first arguments -- a customer on
	 * one, an order on the other -- so neither is declared: by the time either
	 * fires, WooCommerce has already written the shopper's details onto
	 * WC()->customer, which is the one place both agree on.
	 *
	 * @return void
	 */
	public function capture_store_api(): void {
		try {
			$billing = $this->session->billing();

			$this->remember(
				array(
					'phone'            => $billing['phone'],
					'email'            => $billing['email'],
					'first_name'       => $billing['first_name'],
					'last_name'        => $billing['last_name'],
					'country'          => $billing['country'],
					'shipping_country' => $billing['shipping_country'],
				)
			);
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_checkout', 'Could not read the block checkout customer.' );
		}
	}

	/**
	 * Seed a signed-in shopper's details from their profile, once per session.
	 *
	 * A returning customer's phone number is already on their account, so there
	 * is no reason to wait for them to reach the checkout before a basket of
	 * theirs becomes recoverable. Once per session is enough: the profile does
	 * not change while they shop, and the checkout hooks above will overwrite
	 * anything they edit.
	 *
	 * @return void
	 */
	public function capture_signed_in(): void {
		try {
			if ( ! $this->session->is_logged_in_session() ) {
				return;
			}

			$user_id = (string) get_current_user_id();

			if ( $user_id === (string) $this->session->get( Session::KEY_SEEDED, '' ) ) {
				return;
			}

			$this->session->set( Session::KEY_SEEDED, $user_id );

			$this->capture_store_api();
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_checkout', 'Could not read the signed-in customer profile.' );
		}
	}

	/**
	 * Turn the serialised checkout form into an array.
	 *
	 * @param mixed $post_data Raw, URL-encoded serialisation of the form.
	 * @return array<string,mixed>
	 */
	private function parse( $post_data ): array {
		if ( ! is_string( $post_data ) || '' === $post_data ) {
			return array();
		}

		$parsed = array();

		wp_parse_str( $post_data, $parsed );

		return is_array( $parsed ) ? $parsed : array();
	}

	/**
	 * Merge what was seen into the session snapshot.
	 *
	 * The rules -- a blank never overwrites something known, an unchanged value
	 * is not a write, everything sanitised and capped -- moved to
	 * Contact_Snapshot when the basket page became a second place a detail can
	 * be typed. They were correct here while this was the only caller and would
	 * have been copied on the next one.
	 *
	 * @param array<string,string> $fields Freshly read values.
	 * @return void
	 */
	private function remember( array $fields ): void {
		$this->contact->remember( $fields );
	}

	/**
	 * Read one field out of the parsed form.
	 *
	 * @param array<string,mixed> $parsed Parsed form values.
	 * @param string              $key    Field name.
	 * @return string
	 */
	private function field( array $parsed, string $key ): string {
		if ( ! isset( $parsed[ $key ] ) || ! is_scalar( $parsed[ $key ] ) ) {
			return '';
		}

		return Contact_Snapshot::clean( (string) $parsed[ $key ] );
	}

	/**
	 * Normalise a country code, or discard it.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function country( string $value ): string {
		return Contact_Snapshot::country( $value );
	}
}
