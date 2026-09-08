<?php
/**
 * Entries that were submitted and never paid for.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\GravityForms;

use WAcr\RecoveryFlow\Database\Receipt_Repository;
use WAcr\RecoveryFlow\Recovery\Conversion_Tracker;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The half of Gravity Forms that behaves like a shop.
 *
 * A form with a payment add-on on it produces an entry the moment it is
 * submitted, and that entry may sit at Pending or Processing for ever because
 * the card was refused, the gateway tab was closed, or the bank transfer never
 * arrived. Nobody hears about it. The merchant sees an entry; the customer
 * believes they have bought something or has forgotten they did not.
 *
 * Which statuses count as paid is the whole of the correctness here, and
 * getting it wrong is expensive in both directions: treat Paid as unpaid and a
 * paying customer is chased for money they have handed over, treat Failed as
 * paid and the recovery this adapter exists for never happens. So the paid set
 * is written down as a list, filterable, rather than inferred from a negative
 * -- "not failed" would silently classify every status a future add-on invents
 * as a completed sale.
 *
 * Every handler claims a receipt first. Gravity Forms fires a payment hook
 * once, and then a gateway redelivers its callback, an admin edits the entry,
 * or an add-on marks the same payment complete a second time on its own
 * schedule. Without the receipt, one payment would be counted as two
 * recoveries.
 */
final class Entry_Watcher {

	/**
	 * The event type an unpaid entry produces.
	 */
	public const TYPE = 'payment';

	/**
	 * The adapter key prefix for an entry.
	 */
	public const KEY = 'entry:';

	/**
	 * Event ingestion.
	 *
	 * @var Event_Ingest
	 */
	private Event_Ingest $ingest;

	/**
	 * Journey conversion decisions.
	 *
	 * @var Conversion_Tracker
	 */
	private Conversion_Tracker $conversions;

	/**
	 * The "have I already handled this?" ledger.
	 *
	 * @var Receipt_Repository
	 */
	private Receipt_Repository $receipts;

	/**
	 * Reading a person out of a form.
	 *
	 * @var Field_Map
	 */
	private Field_Map $fields;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Event_Ingest       $ingest      Event ingestion.
	 * @param Conversion_Tracker $conversions Conversion decisions.
	 * @param Receipt_Repository $receipts    Idempotency ledger.
	 * @param Field_Map          $fields      Field reading.
	 * @param Logger             $logger      Logger.
	 */
	public function __construct(
		Event_Ingest $ingest,
		Conversion_Tracker $conversions,
		Receipt_Repository $receipts,
		Field_Map $fields,
		Logger $logger
	) {
		$this->ingest      = $ingest;
		$this->conversions = $conversions;
		$this->receipts    = $receipts;
		$this->fields      = $fields;
		$this->logger      = $logger;
	}

	/**
	 * Attach to Gravity Forms.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'gform_after_submission', array( $this, 'on_submitted' ), 20, 2 );
		add_action( 'gform_post_payment_completed', array( $this, 'on_paid' ), 10, 2 );
		add_action( 'gform_post_payment_action', array( $this, 'on_payment_action' ), 10, 2 );
	}

	/**
	 * A form was submitted. Record it only if the money has not arrived.
	 *
	 * @param mixed $entry The entry.
	 * @param mixed $form  The form.
	 * @return void
	 */
	public function on_submitted( $entry, $form ): void {
		if ( ! is_array( $entry ) || ! is_array( $form ) ) {
			return;
		}

		$this->record( $entry, $form );
	}

	/**
	 * The money arrived.
	 *
	 * @param mixed $entry  The entry.
	 * @param mixed $action The payment action.
	 * @return void
	 */
	public function on_paid( $entry, $action ): void {
		if ( ! is_array( $entry ) ) {
			return;
		}

		$id     = (int) ( $entry['id'] ?? 0 );
		$amount = (string) ( is_array( $action ) ? ( $action['amount'] ?? '' ) : '' );

		if ( 0 === $id || ! $this->receipts->claim( 'gf_entry:' . $id . ':paid', 'gf_payment' ) ) {
			return;
		}

		$this->complete( $id, '' === $amount ? (string) ( $entry['payment_amount'] ?? '0' ) : $amount );
	}

	/**
	 * Some payment action happened; only a completing one matters here.
	 *
	 * @param mixed $entry  The entry.
	 * @param mixed $action The payment action.
	 * @return void
	 */
	public function on_payment_action( $entry, $action ): void {
		if ( ! is_array( $entry ) || ! is_array( $action ) ) {
			return;
		}

		if ( ! Unpaid_Entry::is_paid( (string) ( $action['payment_status'] ?? '' ) ) ) {
			return;
		}

		$this->on_paid( $entry, $action );
	}

	/**
	 * Write down an entry that is waiting for money.
	 *
	 * Every rule about which entries qualify lives in Unpaid_Entry, because the
	 * poll applies the same one and two copies would eventually differ.
	 *
	 * @param array<string,mixed> $entry The entry.
	 * @param array<string,mixed> $form  The form.
	 * @return void
	 */
	public function record( array $entry, array $form ): void {
		$draft = Unpaid_Entry::draft( $entry, $form, $this->fields, 'hook' );

		if ( null === $draft ) {
			// Said out loud, at debug level, because "why is my form not being
			// recovered?" is otherwise a question with no evidence attached.
			$this->logger->debug(
				'gravityforms',
				'An entry was not recorded: paid, moderated, unwatched, or on a form nobody could be messaged from.',
				array(
					'form'  => (int) ( $form['id'] ?? 0 ),
					'entry' => (int) ( $entry['id'] ?? 0 ),
				)
			);

			return;
		}

		$this->ingest->ingest( $draft );
	}

	/**
	 * Close an entry's event and credit whatever journey was chasing it.
	 *
	 * @param int    $entry_id The entry.
	 * @param string $amount   What was paid.
	 * @return void
	 */
	private function complete( int $entry_id, string $amount ): void {
		$key   = self::KEY . $entry_id;
		$event = $this->ingest->events()->find_open( Source::ID, $key );

		$this->ingest->close( Source::ID, $key, Recovery_Event::COMPLETED, 'payment_completed' );

		if ( null === $event ) {
			return;
		}

		$match = $this->conversions->attribute( null, $event->event_uid, $key, $event->customer_id, Source::ID );

		if ( null === $match ) {
			return;
		}

		$this->conversions->recovered( $match['journey']->id, (string) $entry_id, $amount, (string) $match['attribution'] );
	}
}
