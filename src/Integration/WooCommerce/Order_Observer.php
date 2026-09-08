<?php
/**
 * Noticing that a recovered basket turned into money.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Database\Receipt_Repository;
use WAcr\RecoveryFlow\Recovery\Conversion_Tracker;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Security\Hash_Key;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Where an order stops a journey, and where it credits one.
 *
 * Order hooks fire more than once. A gateway calls back twice, an admin saves
 * an order that is already complete, a webhook is redelivered, a status is set
 * and then set again by a plugin further down the chain. Every handler here
 * therefore claims a receipt keyed on the order and the status it is reacting
 * to, and only the first caller does anything. Without that, one payment could
 * be counted as two recoveries and a customer could be messaged about a basket
 * they have already paid for.
 *
 * The order is stamped at creation rather than looked up later. Both stamping
 * hooks run before WooCommerce's own save(), so three meta values cost nothing
 * at all, and they turn attribution from a guess into a fact: the journey and
 * the event that produced this order are written on it, and the session key is
 * there as the fallback for an order that was never opened from a message.
 *
 * PENDING_PAYMENT is not a conversion. An order exists, so messaging must stop
 * immediately, but the money has not arrived. A paid status -- or on-hold,
 * which is how bank transfer and cheque orders sit while a merchant waits --
 * is what makes it a recovery, and a failed or cancelled payment hands the
 * journey back to the workflow exactly once.
 */
final class Order_Observer {

	/**
	 * Meta key holding the WooCommerce session the order was placed from.
	 */
	public const META_SESSION = '_recoveryflow_session_key';

	/**
	 * Meta key holding the recovery event the order came out of.
	 */
	public const META_EVENT = '_recoveryflow_event_uid';

	/**
	 * Meta key holding the journey the shopper arrived on.
	 */
	public const META_JOURNEY = '_recoveryflow_journey_uid';

	/**
	 * Journey conversion decisions.
	 *
	 * @var Conversion_Tracker
	 */
	private Conversion_Tracker $conversions;

	/**
	 * Event ingestion.
	 *
	 * @var Event_Ingest
	 */
	private Event_Ingest $ingest;

	/**
	 * The "have I already handled this?" ledger.
	 *
	 * @var Receipt_Repository
	 */
	private Receipt_Repository $receipts;

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

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
	 * The open event for this request's session, looked up at most once.
	 *
	 * @var Recovery_Event|null
	 */
	private ?Recovery_Event $event = null;

	/**
	 * Whether the open-event lookup has been done.
	 *
	 * @var bool
	 */
	private bool $event_resolved = false;

	/**
	 * Resolved customer ids, by order id, for this request only.
	 *
	 * @var array<int,int|null>
	 */
	private array $customer_ids = array();

	/**
	 * Constructor.
	 *
	 * @param Conversion_Tracker  $conversions Journey conversion decisions.
	 * @param Event_Ingest        $ingest      Event ingestion.
	 * @param Receipt_Repository  $receipts    Duplicate-handling ledger.
	 * @param Customer_Repository $customers   Customer storage.
	 * @param Session             $session     Guarded session access.
	 * @param Logger              $logger      Logger.
	 */
	public function __construct(
		Conversion_Tracker $conversions,
		Event_Ingest $ingest,
		Receipt_Repository $receipts,
		Customer_Repository $customers,
		Session $session,
		Logger $logger
	) {
		$this->conversions = $conversions;
		$this->ingest      = $ingest;
		$this->receipts    = $receipts;
		$this->customers   = $customers;
		$this->session     = $session;
		$this->logger      = $logger;
	}

	/**
	 * Attach to WooCommerce.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_classic_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'stamp_store_api_order' ), 10, 1 );
		add_action( 'woocommerce_new_order', array( $this, 'on_order_created' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * Stamp the classic checkout's order before WooCommerce saves it.
	 *
	 * @param mixed $order The order being created.
	 * @return void
	 */
	public function stamp_classic_order( $order ): void {
		$this->stamp_order( $order );
	}

	/**
	 * Stamp the block checkout's order before WooCommerce saves it.
	 *
	 * @param mixed $order The order being created.
	 * @return void
	 */
	public function stamp_store_api_order( $order ): void {
		$this->stamp_order( $order );
	}

	/**
	 * Stop messaging as soon as an order exists.
	 *
	 * @param mixed $order_id The new order's id.
	 * @param mixed $order    The new order.
	 * @return void
	 */
	public function on_order_created( $order_id, $order = null ): void {
		try {
			$order = $this->as_order( $order, $order_id );

			if ( null === $order ) {
				return;
			}

			// A block checkout opens a draft order the moment the shopper
			// reaches the checkout, long before they commit to anything. Acting
			// on it would end the journey of somebody who is still deciding.
			if ( 'checkout-draft' === $order->get_status() ) {
				return;
			}

			if ( ! $this->claim( $order->get_id(), 'created' ) ) {
				return;
			}

			$this->on_order_open( $order );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_order', 'Could not react to a new order.' );
		}//end try
	}

	/**
	 * React to a payment the gateway has confirmed.
	 *
	 * @param mixed $order_id The order's id.
	 * @return void
	 */
	public function on_payment_complete( $order_id ): void {
		try {
			$order = $this->as_order( null, $order_id );

			if ( null === $order || ! in_array( $order->get_status(), $this->recovered_statuses(), true ) ) {
				return;
			}

			// Keyed on the status rather than on "payment_complete", so that
			// the status change this call already fired does not do the work a
			// second time.
			if ( ! $this->claim( $order->get_id(), $order->get_status() ) ) {
				return;
			}

			$this->on_order_paid( $order );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_order', 'Could not react to a completed payment.' );
		}
	}

	/**
	 * React to an order changing status.
	 *
	 * @param mixed $order_id The order's id.
	 * @param mixed $from     Status it left.
	 * @param mixed $to       Status it moved to.
	 * @param mixed $order    The order.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from = '', $to = '', $order = null ): void {
		try {
			$to = is_scalar( $to ) ? (string) $to : '';

			if ( '' === $to || 'checkout-draft' === $to ) {
				return;
			}

			$paid   = in_array( $to, $this->recovered_statuses(), true );
			$failed = in_array( $to, array( 'failed', 'cancelled' ), true );

			// Every order in the shop passes through here, including ones this
			// plugin has never heard of, so uninteresting statuses are dropped
			// before anything is written down.
			if ( ! $paid && ! $failed && 'pending' !== $to ) {
				return;
			}

			$order = $this->as_order( $order, $order_id );

			if ( null === $order || ! $this->claim( $order->get_id(), $to ) ) {
				return;
			}

			if ( $paid ) {
				$this->on_order_paid( $order );

				return;
			}

			if ( $failed ) {
				$this->on_payment_failed( $order );

				return;
			}

			$this->on_order_open( $order );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_order', 'Could not react to an order status change.' );
		}//end try
	}

	/**
	 * Write the attribution trail onto an order that has not been saved yet.
	 *
	 * @param mixed $order The order.
	 * @return void
	 */
	private function stamp_order( $order ): void {
		try {
			if ( ! $order instanceof \WC_Order || ! method_exists( $order, 'update_meta_data' ) ) {
				return;
			}

			$session_key = $this->session->customer_id();

			if ( '' !== $session_key && '' === (string) $order->get_meta( self::META_SESSION ) ) {
				$order->update_meta_data( self::META_SESSION, $session_key );
			}

			$journey_uid = (string) $this->session->get( Session::KEY_JOURNEY_UID, '' );

			if ( '' !== $journey_uid && '' === (string) $order->get_meta( self::META_JOURNEY ) ) {
				$order->update_meta_data( self::META_JOURNEY, $journey_uid );
			}

			$event = $this->open_event( $session_key );

			if ( null !== $event && '' === (string) $order->get_meta( self::META_EVENT ) ) {
				$order->update_meta_data( self::META_EVENT, $event->event_uid );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wc_order', 'Could not stamp the recovery trail onto an order.' );
		}//end try
	}

	/**
	 * An order exists: stop messaging and close the basket it came from.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	private function on_order_open( \WC_Order $order ): void {
		$match = $this->match( $order );

		if ( null !== $match ) {
			$this->conversions->pending_payment( $match->id, (string) $order->get_id() );
		}

		$this->close_event( $order );
	}

	/**
	 * Close the basket the order came out of.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	private function close_event( \WC_Order $order ): void {
		$session_key = $this->session_key_for( $order );

		if ( '' === $session_key ) {
			return;
		}

		$this->ingest->close(
			Source::ID,
			Cart_Tracker::DEDUPE_PREFIX . $session_key,
			Recovery_Event::COMPLETED,
			'order_placed'
		);
	}

	/**
	 * The money arrived.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	private function on_order_paid( \WC_Order $order ): void {
		$attribution = '';
		$match       = $this->match( $order, $attribution );

		if ( null !== $match ) {
			$this->conversions->recovered(
				$match->id,
				(string) $order->get_id(),
				(string) $order->get_total(),
				'' === $attribution ? Recovery_Journey::ATTRIBUTION_CUSTOMER : $attribution
			);
		}

		// Somebody who has just bought must not be chased about anything else
		// they left behind, whether or not this order was credited to a
		// journey.
		$customer_id = $this->customer_id_for( $order );

		if ( null !== $customer_id ) {
			$this->conversions->stop_for_customer( $customer_id, 'order_placed' );
		}

		$this->close_event( $order );
	}

	/**
	 * The payment was refused or the order was cancelled.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	private function on_payment_failed( \WC_Order $order ): void {
		$match = $this->match( $order );

		// Only a journey that stopped because of this order may be resumed.
		// Anything else is a journey that had already finished for its own
		// reasons, and restarting it would message somebody twice.
		if ( null !== $match && Journey_State::PENDING_PAYMENT === $match->status ) {
			$this->conversions->payment_failed( $match->id );
		}
	}

	/**
	 * Which journey, if any, this order belongs to.
	 *
	 * @param \WC_Order $order       The order.
	 * @param string    $attribution Filled in with how the match was made.
	 * @return Recovery_Journey|null
	 */
	private function match( \WC_Order $order, string &$attribution = '' ): ?Recovery_Journey {
		$journey_uid = (string) $order->get_meta( self::META_JOURNEY );
		$event_uid   = (string) $order->get_meta( self::META_EVENT );
		$session_key = $this->session_key_for( $order );

		$result = $this->conversions->attribute(
			'' === $journey_uid ? null : $journey_uid,
			'' === $event_uid ? null : $event_uid,
			'' === $session_key ? null : $session_key,
			$this->customer_id_for( $order ),
			Source::ID
		);

		if ( ! is_array( $result ) || ! $result['journey'] instanceof Recovery_Journey ) {
			return null;
		}

		$attribution = (string) $result['attribution'];

		return $result['journey'];
	}

	/**
	 * This plugin's customer id for whoever placed the order.
	 *
	 * The WordPress user id is deliberately not passed through as if it were a
	 * customer id: the two are unrelated numbers, and treating one as the other
	 * would credit a recovery to a stranger.
	 *
	 * @param \WC_Order $order The order.
	 * @return int|null
	 */
	private function customer_id_for( \WC_Order $order ): ?int {
		$order_id = $order->get_id();

		if ( array_key_exists( $order_id, $this->customer_ids ) ) {
			return $this->customer_ids[ $order_id ];
		}

		$this->customer_ids[ $order_id ] = $this->look_up_customer( $order );

		return $this->customer_ids[ $order_id ];
	}

	/**
	 * Ask storage who this order belongs to.
	 *
	 * @param \WC_Order $order The order.
	 * @return int|null
	 */
	private function look_up_customer( \WC_Order $order ): ?int {
		$user_id = (int) $order->get_customer_id();

		if ( $user_id > 0 ) {
			$customer = $this->customers->find_by_user( $user_id );

			if ( null !== $customer ) {
				return $customer->id;
			}
		}

		$email = (string) $order->get_billing_email();

		if ( '' === $email ) {
			return null;
		}

		$customer = $this->customers->find_by_email_hash( Hash_Key::email( $email ) );

		return null === $customer ? null : $customer->id;
	}

	/**
	 * The session the order was placed from.
	 *
	 * @param \WC_Order $order The order.
	 * @return string
	 */
	private function session_key_for( \WC_Order $order ): string {
		$stamped = (string) $order->get_meta( self::META_SESSION );

		return '' === $stamped ? $this->session->customer_id() : $stamped;
	}

	/**
	 * The open event behind this request's session, looked up at most once.
	 *
	 * @param string $session_key The WooCommerce session's customer id.
	 * @return Recovery_Event|null
	 */
	private function open_event( string $session_key ): ?Recovery_Event {
		if ( $this->event_resolved ) {
			return $this->event;
		}

		$this->event_resolved = true;

		if ( '' === $session_key ) {
			return null;
		}

		$this->event = $this->ingest->events()->find_open( Source::ID, Cart_Tracker::DEDUPE_PREFIX . $session_key );

		return $this->event;
	}

	/**
	 * Take the right to handle one order event, once.
	 *
	 * @param int    $order_id Order id.
	 * @param string $status   What is being reacted to.
	 * @return bool True only for the first caller.
	 */
	private function claim( int $order_id, string $status ): bool {
		if ( $order_id < 1 ) {
			return false;
		}

		return $this->receipts->claim( 'wc_order:' . $order_id . ':' . $status, Receipt_Repository::KIND_ORDER );
	}

	/**
	 * Resolve whatever the hook handed over into an order.
	 *
	 * @param mixed $order    The order, if the hook passed one.
	 * @param mixed $order_id The order id.
	 * @return \WC_Order|null
	 */
	private function as_order( $order, $order_id ): ?\WC_Order {
		if ( $order instanceof \WC_Order ) {
			return $order;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( absint( is_scalar( $order_id ) ? $order_id : 0 ) );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * The statuses this site treats as a recovered sale.
	 *
	 * The on-hold status is included because bank transfer and cheque orders sit
	 * there while the merchant waits for money that has, in every practical
	 * sense, been committed -- and chasing that basket reads as a mistake.
	 *
	 * @return string[]
	 */
	private function recovered_statuses(): array {
		$statuses = function_exists( 'wc_get_is_paid_statuses' )
			? wc_get_is_paid_statuses()
			: array( 'processing', 'completed' );

		$statuses = is_array( $statuses ) ? $statuses : array();

		$statuses[] = 'on-hold';

		/**
		 * Filters which WooCommerce order statuses count as a recovered sale.
		 *
		 * @param string[] $statuses Status slugs, without the wc- prefix.
		 */
		$filtered = apply_filters( Hooks::FILTER_RECOVERED_STATES, array_values( array_unique( $statuses ) ) );

		return is_array( $filtered ) ? array_map( 'strval', $filtered ) : $statuses;
	}
}
