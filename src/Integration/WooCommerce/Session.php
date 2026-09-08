<?php
/**
 * A safe way to touch the WooCommerce session.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The only place this adapter is allowed to reach for WC()->session.
 *
 * WC()->session is not always a WC_Session_Handler. On any Store API cart or
 * checkout request carrying a Cart-Token header, WooCommerce swaps in
 * Automattic\WooCommerce\StoreApi\SessionHandler, which defines five methods
 * and nothing else. has_session(), get_customer_unique_id() and
 * set_customer_session_cookie() do not exist on it, so the obvious
 * "is there a session?" test is a fatal error on the block checkout -- and a
 * fatal error there is a checkout the shopper cannot complete, which costs the
 * merchant far more than the basket this plugin was trying to save.
 *
 * get_customer_id() is declared on the abstract WC_Session, so it is the one
 * question that can always be asked. A non-empty answer is this adapter's
 * definition of "trackable": there is an identity to key a cart against,
 * whether it is a guest hash or a user id.
 *
 * Every method here is safe to call on any request -- admin, REST, cron, CLI,
 * or a front-end page load where WooCommerce never started a session at all.
 */
final class Session {

	/**
	 * Contact details captured as the shopper fills in the checkout.
	 */
	public const KEY_CONTACT = 'recoveryflow_contact';

	/**
	 * The consent answer and the wording it was given against.
	 */
	public const KEY_CONSENT = 'recoveryflow_consent';

	/**
	 * Fingerprint of the last snapshot written for this session.
	 */
	public const KEY_FINGERPRINT = 'recoveryflow_fingerprint';

	/**
	 * Fingerprint of the contact details at the last write.
	 */
	public const KEY_IDENTITY = 'recoveryflow_identity';

	/**
	 * Unix time of the last write, for the throttle.
	 */
	public const KEY_WRITTEN_AT = 'recoveryflow_written_at';

	/**
	 * Journey the shopper arrived on, set when a recovery link is followed.
	 */
	public const KEY_JOURNEY_UID = 'recoveryflow_journey_uid';

	/**
	 * Which user this session already read profile details for.
	 */
	public const KEY_SEEDED = 'recoveryflow_seeded';

	/**
	 * Whether WooCommerce is loaded and has a session object on this request.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$woocommerce = WC();

		return is_object( $woocommerce ) && isset( $woocommerce->session ) && is_object( $woocommerce->session );
	}

	/**
	 * The session's customer identifier: a user id, or a guest hash.
	 *
	 * This is the only safe test for "can this shopper be tracked?". Anything
	 * that asks the session handler a richer question risks calling a method
	 * the Store API handler does not have.
	 *
	 * @return string Empty when there is no session on this request.
	 */
	public function customer_id(): string {
		if ( ! $this->is_ready() ) {
			return '';
		}

		$session = WC()->session;

		if ( ! method_exists( $session, 'get_customer_id' ) ) {
			return '';
		}

		try {
			$id = $session->get_customer_id();
		} catch ( \Throwable $e ) {
			return '';
		}

		return is_scalar( $id ) ? (string) $id : '';
	}

	/**
	 * Read one value out of the session.
	 *
	 * @param string $key      Session key.
	 * @param mixed  $fallback Value to return when there is nothing to read.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		if ( ! $this->is_ready() ) {
			return $fallback;
		}

		$session = WC()->session;

		if ( ! method_exists( $session, 'get' ) ) {
			return $fallback;
		}

		try {
			return $session->get( $key, $fallback );
		} catch ( \Throwable $e ) {
			return $fallback;
		}
	}

	/**
	 * Write one value into the session.
	 *
	 * @param string $key   Session key.
	 * @param mixed  $value Value to store.
	 * @return void
	 */
	public function set( string $key, $value ): void {
		if ( ! $this->is_ready() ) {
			return;
		}

		$session = WC()->session;

		if ( ! method_exists( $session, 'set' ) ) {
			return;
		}

		try {
			$session->set( $key, $value );
		} catch ( \Throwable $e ) {
			// A session that cannot be written to is not worth a broken page.
			return;
		}
	}

	/**
	 * Whether this session belongs to a signed-in WordPress user.
	 *
	 * @return bool
	 */
	public function is_logged_in_session(): bool {
		$id = $this->customer_id();

		return '' !== $id && is_user_logged_in() && (string) get_current_user_id() === $id;
	}

	/**
	 * The WooCommerce customer object, when there is one.
	 *
	 * @return \WC_Customer|null
	 */
	public function customer(): ?\WC_Customer {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) || ! isset( $woocommerce->customer ) ) {
			return null;
		}

		return $woocommerce->customer instanceof \WC_Customer ? $woocommerce->customer : null;
	}

	/**
	 * The contact details WooCommerce currently holds for this shopper.
	 *
	 * Read through method_exists rather than called directly: WC_Customer is
	 * one of the objects site owners replace, and a missing getter must be an
	 * empty string rather than a fatal in somebody's checkout.
	 *
	 * @return array{phone:string,email:string,first_name:string,last_name:string,country:string,shipping_country:string}
	 */
	public function billing(): array {
		$empty = array(
			'phone'            => '',
			'email'            => '',
			'first_name'       => '',
			'last_name'        => '',
			'country'          => '',
			'shipping_country' => '',
		);

		$customer = $this->customer();

		if ( null === $customer ) {
			return $empty;
		}

		try {
			return array(
				'phone'            => $this->read( $customer, 'get_billing_phone', 32 ),
				'email'            => $this->read( $customer, 'get_billing_email', 191 ),
				'first_name'       => $this->read( $customer, 'get_billing_first_name', 100 ),
				'last_name'        => $this->read( $customer, 'get_billing_last_name', 100 ),
				'country'          => strtoupper( $this->read( $customer, 'get_billing_country', 2 ) ),
				'shipping_country' => strtoupper( $this->read( $customer, 'get_shipping_country', 2 ) ),
			);
		} catch ( \Throwable $e ) {
			return $empty;
		}
	}

	/**
	 * Read one getter off the customer, sanitised and capped.
	 *
	 * @param \WC_Customer $customer The customer object.
	 * @param string       $method   Getter name.
	 * @param int          $length   Maximum characters to keep.
	 * @return string
	 */
	private function read( \WC_Customer $customer, string $method, int $length ): string {
		if ( ! method_exists( $customer, $method ) ) {
			return '';
		}

		$value = $customer->{$method}();

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return substr( sanitize_text_field( (string) $value ), 0, $length );
	}
}
