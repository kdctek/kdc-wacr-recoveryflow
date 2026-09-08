<?php
/**
 * Watching a WooCommerce cart without slowing it down.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Customer\Identity_Hints;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * One write per shopper per minute, at the very end of the request.
 *
 * The woocommerce_cart_updated hook is not the event it sounds like. It fires
 * once per coupon change, once per quantity change, and again on a plain cart-page view
 * where nothing changed at all, and it keeps firing after the cart is emptied.
 * Treating it as "write the cart now" would put several queries into every page
 * a shopper looks at. So it only sets a flag, and the one write happens on
 * shutdown.
 *
 * Shutdown runs at priority 21 because WooCommerce saves its own session at 20
 * -- twice, since the Store API registers a second save_data at the same
 * priority. Running after both means the customer id this class reads is the
 * final one for the request. It does not mean a session row exists:
 * maybe_set_cart_cookies bails when headers have already been sent, so nothing
 * here may assume it can read back what it wrote.
 *
 * Three things stop pointless writes, in order of cheapness: the flag, the
 * snapshot fingerprint, and a sixty-second throttle. The throttle is skipped
 * when the shopper's contact details changed or they are on the checkout,
 * because those are the two moments where a few seconds of staleness is the
 * difference between recovering a basket and messaging nobody.
 *
 * Nothing here may throw. A shopper's cart page breaking because this plugin's
 * table is missing is a far worse outcome than the basket it failed to record.
 */
final class Cart_Tracker {

	/**
	 * Prefix for the adapter key a cart is tracked under.
	 */
	public const DEDUPE_PREFIX = 'wc_cart:';

	/**
	 * How long to wait between writes for one session.
	 */
	private const THROTTLE_SECONDS = 60;

	/**
	 * Event ingestion.
	 *
	 * @var Event_Ingest
	 */
	private Event_Ingest $ingest;

	/**
	 * Guarded access to the WooCommerce session.
	 *
	 * @var Session
	 */
	private Session $session;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Whether anything in this request touched the cart.
	 *
	 * @var bool
	 */
	private bool $dirty = false;

	/**
	 * Whether the cart was emptied in this request.
	 *
	 * @var bool
	 */
	private bool $emptied = false;

	/**
	 * Constructor.
	 *
	 * @param Event_Ingest $ingest  Event ingestion.
	 * @param Session      $session Guarded session access.
	 * @param Logger       $logger  Logger.
	 */
	public function __construct( Event_Ingest $ingest, Session $session, Logger $logger ) {
		$this->ingest  = $ingest;
		$this->session = $session;
		$this->logger  = $logger;
	}

	/**
	 * Attach to WooCommerce.
	 *
	 * The woocommerce_guest_session_to_user_id action is fired from
	 * init_session_cookie(), which runs before wp_loaded, so this must be called
	 * from plugins_loaded or earlier or the re-key is missed entirely.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_cart_updated', array( $this, 'mark_dirty' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'mark_emptied' ) );
		add_action( 'woocommerce_guest_session_to_user_id', array( $this, 'migrate_guest_session' ) );
		add_action( 'shutdown', array( $this, 'flush' ), 21 );
	}

	/**
	 * Note that a request touched the cart.
	 *
	 * @return void
	 */
	public function mark_dirty(): void {
		$this->dirty = true;
	}

	/**
	 * Note that the cart was emptied.
	 *
	 * @return void
	 */
	public function mark_emptied(): void {
		$this->emptied = true;
		$this->dirty   = true;
	}

	/**
	 * Write at most one row, at the end of the request.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( ! $this->dirty ) {
			return;
		}

		$emptied = $this->emptied;

		$this->dirty   = false;
		$this->emptied = false;

		try {
			$this->write( $emptied );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_cart', 'Could not record cart activity.', array( 'source' => Source::ID ) );
		}
	}

	/**
	 * Move an open event from a guest key onto the user's key.
	 *
	 * Without this a guest who signs in halfway through a visit is tracked
	 * twice: the guest row and the user row are both open, both are evaluated,
	 * and one basket produces two WhatsApp messages.
	 *
	 * @param mixed $guest_session_id The session id the shopper had as a guest.
	 * @return void
	 */
	public function migrate_guest_session( $guest_session_id ): void {
		try {
			$guest = is_scalar( $guest_session_id ) ? (string) $guest_session_id : '';
			$user  = get_current_user_id();

			if ( '' === $guest || $user < 1 || (string) $user === $guest ) {
				return;
			}

			$this->ingest->rekey(
				Source::ID,
				self::DEDUPE_PREFIX . $guest,
				self::DEDUPE_PREFIX . $user,
				(string) $user
			);
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_cart', 'Could not move a cart event onto the signed-in session.' );
		}
	}

	/**
	 * Do the work flush() guards.
	 *
	 * @param bool $emptied Whether the cart was emptied in this request.
	 * @return void
	 */
	private function write( bool $emptied ): void {
		$customer_id = $this->session->customer_id();

		if ( '' === $customer_id ) {
			return;
		}

		// A JSON request that is not the Store API is somebody else's endpoint
		// -- a heartbeat, a page builder, another plugin's AJAX -- and the cart
		// it happens to load is not a shopper doing anything.
		if ( wp_is_json_request() && ! $this->is_store_api_request() ) {
			return;
		}

		$rules = Rule_Set::for_source();
		$user  = wp_get_current_user();

		// Staff test their own checkout constantly. A shop whose manager is
		// chased for every trial basket stops trusting the plugin.
		if ( $rules->excludes_admins() && $user instanceof \WP_User && $user->has_cap( 'manage_woocommerce' ) ) {
			return;
		}

		$dedupe = self::DEDUPE_PREFIX . $customer_id;
		$cart   = $this->cart();
		$items  = null === $cart ? array() : $this->snapshot_items( $cart );

		if ( $emptied || null === $cart || array() === $items ) {
			$this->close( $dedupe );

			return;
		}

		$draft = new Event_Draft( Source::ID, $this->event_type(), $dedupe );

		$draft->session_key = $customer_id;
		$draft->metadata    = $this->metadata( $cart );

		$draft->with_items( $items );
		$draft->with_value( $this->cart_amount( $cart ), $this->currency() );
		$draft->with_identity( $this->identity() );

		if ( $draft->is_empty() ) {
			$this->close( $dedupe );

			return;
		}

		$fingerprint = $draft->fingerprint();
		$identity    = $draft->identity->fingerprint();

		if ( $fingerprint === (string) $this->session->get( Session::KEY_FINGERPRINT, '' ) ) {
			return;
		}

		$identity_changed = $identity !== (string) $this->session->get( Session::KEY_IDENTITY, '' );
		$written_at       = (int) $this->session->get( Session::KEY_WRITTEN_AT, 0 );

		if ( ! $identity_changed && ! $this->on_checkout() && ( time() - $written_at ) < self::THROTTLE_SECONDS ) {
			return;
		}

		if ( 0 === $this->ingest->ingest( $draft ) ) {
			return;
		}

		$this->session->set( Session::KEY_FINGERPRINT, $fingerprint );
		$this->session->set( Session::KEY_IDENTITY, $identity );
		$this->session->set( Session::KEY_WRITTEN_AT, time() );
	}

	/**
	 * Close the open event behind a key, if this session ever opened one.
	 *
	 * The stored fingerprint is the cheap proof that there is something to
	 * close: without it, every visitor who looks at an empty cart page would
	 * cost a query for a row that was never written.
	 *
	 * @param string $dedupe The adapter key.
	 * @return void
	 */
	private function close( string $dedupe ): void {
		if ( '' === (string) $this->session->get( Session::KEY_FINGERPRINT, '' ) ) {
			return;
		}

		$this->ingest->close( Source::ID, $dedupe, Recovery_Event::INVALID, 'cart_emptied' );

		$this->session->set( Session::KEY_FINGERPRINT, '' );
		$this->session->set( Session::KEY_IDENTITY, '' );
		$this->session->set( Session::KEY_WRITTEN_AT, 0 );
	}

	/**
	 * The cart, when this request has one.
	 *
	 * @return \WC_Cart|null
	 */
	private function cart(): ?\WC_Cart {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) || ! isset( $woocommerce->cart ) ) {
			return null;
		}

		return $woocommerce->cart instanceof \WC_Cart ? $woocommerce->cart : null;
	}

	/**
	 * Snapshot the cart lines.
	 *
	 * @param \WC_Cart $cart The cart.
	 * @return array<int,array<string,mixed>>
	 */
	private function snapshot_items( \WC_Cart $cart ): array {
		$items   = array();
		$allowed = $this->allowed_item_data();

		foreach ( $cart->get_cart() as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$product  = isset( $line['data'] ) && $line['data'] instanceof \WC_Product ? $line['data'] : null;
			$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 0;

			if ( null === $product || $quantity < 1 ) {
				continue;
			}

			$line_amount = (float) ( $line['line_total'] ?? 0 ) + (float) ( $line['line_tax'] ?? 0 );

			$ref = array(
				'product_id'   => isset( $line['product_id'] ) ? (int) $line['product_id'] : 0,
				'variation_id' => isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0,
				'attrs'        => isset( $line['variation'] ) && is_array( $line['variation'] ) ? $line['variation'] : array(),
			);

			$data = $this->pick_item_data( $line, $allowed );

			if ( array() !== $data ) {
				$ref['data'] = $data;
			}

			$items[] = array(
				'sku'         => (string) $product->get_sku(),
				'name'        => (string) $product->get_name(),
				'qty'         => $quantity,
				'unit_amount' => $this->decimal( $line_amount / $quantity ),
				'line_amount' => $this->decimal( $line_amount ),
				'ref'         => $ref,
			);
		}//end foreach

		return $items;
	}

	/**
	 * The cart item data keys a site has asked to have restored.
	 *
	 * Nothing is snapshotted by default. Custom cart item data is written by
	 * other plugins and can hold anything at all, including personal data, so
	 * a site opts in per key rather than having the plugin guess.
	 *
	 * @return string[]
	 */
	private function allowed_item_data(): array {
		/**
		 * Filters which custom cart item data keys survive a restore.
		 *
		 * The same list is used when the cart is snapshotted and when it is put
		 * back, so a key that is not listed is never written down in the first
		 * place.
		 *
		 * @param string[] $keys Cart item data keys to keep. Default none.
		 */
		$allowed = apply_filters( Hooks::FILTER_RESTORE_ITEM_DATA, array() );

		if ( ! is_array( $allowed ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $allowed ) ) );
	}

	/**
	 * Keep only the allow-listed custom keys from one cart line.
	 *
	 * @param array<string,mixed> $line    The cart line.
	 * @param string[]            $allowed Allow-listed keys.
	 * @return array<string,mixed>
	 */
	private function pick_item_data( array $line, array $allowed ): array {
		$data = array();

		foreach ( $allowed as $key ) {
			if ( isset( $line[ $key ] ) && is_scalar( $line[ $key ] ) ) {
				$data[ $key ] = $line[ $key ];
			}
		}

		return $data;
	}

	/**
	 * What the cart is worth.
	 *
	 * @param \WC_Cart $cart The cart.
	 * @return string Decimal string in major units.
	 */
	private function cart_amount( \WC_Cart $cart ): string {
		if ( method_exists( $cart, 'get_total' ) ) {
			// 'edit' returns the raw total; the default context runs it through
			// the price formatter and hands back markup.
			$total = $cart->get_total( 'edit' );

			if ( is_scalar( $total ) && '' !== (string) $total ) {
				return $this->decimal( (float) $total );
			}
		}

		return $this->decimal( (float) $cart->get_cart_contents_total() );
	}

	/**
	 * Source-specific detail worth keeping. Never personal data.
	 *
	 * @param \WC_Cart $cart The cart.
	 * @return array<string,mixed>
	 */
	private function metadata( \WC_Cart $cart ): array {
		$metadata = array();

		if ( ! method_exists( $cart, 'get_applied_coupons' ) ) {
			return $metadata;
		}

		$coupons = $cart->get_applied_coupons();

		if ( is_array( $coupons ) && array() !== $coupons ) {
			$metadata['coupons'] = array_values( array_map( 'sanitize_text_field', array_map( 'strval', $coupons ) ) );
		}

		return $metadata;
	}

	/**
	 * What this adapter knows about who is shopping.
	 *
	 * The checkout snapshot wins over the customer object because on the
	 * classic checkout it is the fresher of the two: WooCommerce only copies
	 * the posted fields onto the customer when the order is placed.
	 *
	 * @return Identity_Hints
	 */
	private function identity(): Identity_Hints {
		$billing = $this->session->billing();
		$contact = $this->session->get( Session::KEY_CONTACT, array() );
		$contact = is_array( $contact ) ? $contact : array();
		$consent = $this->session->get( Session::KEY_CONSENT, array() );
		$consent = is_array( $consent ) ? $consent : array();
		$user_id = get_current_user_id();

		$data = array(
			'wp_user_id' => $user_id > 0 ? $user_id : null,
			'phone_raw'  => $this->prefer( $contact['phone'] ?? '', $billing['phone'] ),
			'email'      => $this->prefer( $contact['email'] ?? '', $billing['email'] ),
			'first_name' => $this->prefer( $contact['first_name'] ?? '', $billing['first_name'] ),
			'last_name'  => $this->prefer( $contact['last_name'] ?? '', $billing['last_name'] ),
			'country'    => $this->prefer(
				$contact['country'] ?? '',
				$this->prefer( $billing['country'], $billing['shipping_country'] )
			),
		);

		// Null and false mean different things to the eligibility rules: null
		// is "never asked", false is a refusal. Only set it when there is an
		// answer to report.
		if ( array_key_exists( 'granted', $consent ) ) {
			$data['consent']              = (bool) $consent['granted'];
			$data['consent_source']       = (string) ( $consent['source'] ?? '' );
			$data['consent_text_version'] = (string) ( $consent['text_version'] ?? '' );
		}

		return Identity_Hints::from_array( $data );
	}

	/**
	 * The first of two values that is not empty.
	 *
	 * @param mixed $first  Preferred value.
	 * @param mixed $second Fallback value.
	 * @return string
	 */
	private function prefer( $first, $second ): string {
		$first = is_scalar( $first ) ? trim( (string) $first ) : '';

		if ( '' !== $first ) {
			return $first;
		}

		return is_scalar( $second ) ? trim( (string) $second ) : '';
	}

	/**
	 * Format a value the way WooCommerce formats money.
	 *
	 * @param float $value Raw value.
	 * @return string
	 */
	private function decimal( float $value ): string {
		if ( function_exists( 'wc_format_decimal' ) && function_exists( 'wc_get_price_decimals' ) ) {
			return (string) wc_format_decimal( $value, wc_get_price_decimals() );
		}

		return number_format( $value, 2, '.', '' );
	}

	/**
	 * The shop's currency.
	 *
	 * @return string
	 */
	private function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}

	/**
	 * Whether the shopper is at the checkout rather than the cart.
	 *
	 * @return bool
	 */
	private function on_checkout(): bool {
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		return str_contains( $this->request_uri(), '/wc/store/v1/checkout' );
	}

	/**
	 * What kind of thing this event describes.
	 *
	 * @return string
	 */
	private function event_type(): string {
		return $this->on_checkout() ? 'checkout' : 'cart';
	}

	/**
	 * Whether this request is a Store API call.
	 *
	 * @return bool
	 */
	private function is_store_api_request(): bool {
		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'is_store_api_request' ) ) {
			return (bool) WC()->is_store_api_request();
		}

		return str_contains( $this->request_uri(), '/wc/store/' );
	}

	/**
	 * The requested path, sanitised.
	 *
	 * @return string
	 */
	private function request_uri(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
	}
}
