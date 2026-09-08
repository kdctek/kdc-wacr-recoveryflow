<?php
/**
 * Tying a completed conversion back to the journey that chased it.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Stop-on-conversion, and the credit for it.
 *
 * Two jobs live here and they are not equally negotiable.
 *
 * The first is stopping. Somebody who has just placed an order must never
 * receive a message asking them to come back and finish it. That is the worst
 * thing this plugin can do: it is visibly wrong to the customer, it makes the
 * merchant look as though they are not watching their own shop, and it arrives
 * over WhatsApp where there is no spam folder to hide in. So stopping happens
 * on the weakest evidence that will support it, and it is never traded away.
 *
 * The second is credit -- deciding which journey may claim the revenue. That is
 * a commercial claim a merchant will show to somebody, so it happens only on
 * strong evidence. Three grades exist, in strict order:
 *
 * - exact:    the order carries the journey or event identifier that was
 *             stamped on it when the recovery link was followed. Certain.
 * - session:  the order came from the browser session the abandoned event
 *             belongs to. Near certain, and the usual case for a guest who
 *             taps the link and checks out straight away.
 * - customer: the same person bought something inside the attribution window.
 *             Enough to stop messaging them, not enough to claim by default
 *             that a WhatsApp message caused it.
 *
 * attribute() only resolves; it changes nothing. Every state change goes
 * through Journey_Repository::transition(), which is a compare-and-set, and a
 * FALSE from it means somebody else moved the row first -- a poll that found a
 * reply, an expiry sweep, a second order webhook. The row that moved first
 * wins, always, and this class reports the loss rather than overwriting it.
 */
final class Conversion_Tracker {

	/**
	 * How long a resumed journey waits before the workflow may offer help
	 * with the failed payment. Long enough that the customer has finished
	 * arguing with their bank; short enough to still be useful.
	 */
	public const RESUME_DELAY = 1800;

	/**
	 * How many times a declined payment may hand a journey back to the workflow.
	 */
	public const MAX_RESUMES = 1;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * Event storage.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Attempt ledger, which owns the recovery tokens.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

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
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Journey_Repository  $journeys  Journey storage.
	 * @param Event_Repository    $events    Event storage.
	 * @param Attempt_Repository  $attempts  Attempt ledger.
	 * @param Customer_Repository $customers Customer storage.
	 * @param Logger              $logger    Logger.
	 * @param Clock               $clock     Clock.
	 */
	public function __construct(
		Journey_Repository $journeys,
		Event_Repository $events,
		Attempt_Repository $attempts,
		Customer_Repository $customers,
		Logger $logger,
		Clock $clock
	) {
		$this->journeys  = $journeys;
		$this->events    = $events;
		$this->attempts  = $attempts;
		$this->customers = $customers;
		$this->logger    = $logger;
		$this->clock     = $clock;
	}

	/**
	 * Work out which journey a completed conversion belongs to.
	 *
	 * Resolves only. The caller follows a match with pending_payment(), which
	 * is what actually stops the messaging -- including for every other journey
	 * the same person has open.
	 *
	 * @param string|null $journey_uid Journey identifier stamped on the completed record, if any.
	 * @param string|null $event_uid   Event identifier stamped on it, if any.
	 * @param string|null $session_key The source's session identifier, if any.
	 * @param int|null    $customer_id Resolved customer, if any.
	 * @param string      $source_id   Which adapter reported the conversion.
	 * @return array{journey:Recovery_Journey,attribution:string}|null
	 */
	public function attribute( ?string $journey_uid, ?string $event_uid, ?string $session_key, ?int $customer_id, string $source_id ): ?array {
		$exact = $this->by_identifier( $journey_uid, $event_uid );

		if ( null !== $exact ) {
			return $this->match( $exact, Recovery_Journey::ATTRIBUTION_EXACT );
		}

		$session = $this->by_session( $session_key, $source_id );

		if ( null !== $session ) {
			return $this->match( $session, Recovery_Journey::ATTRIBUTION_SESSION );
		}

		$person = $this->by_customer( $customer_id, $source_id );

		if ( null !== $person ) {
			return $this->match( $person, Recovery_Journey::ATTRIBUTION_CUSTOMER );
		}

		return null;
	}

	/**
	 * An order exists: stop messaging at once, and kill the links.
	 *
	 * This is not a conversion. The order may never be paid, so nothing is
	 * credited here -- but the customer is mid-checkout or past it, and a
	 * reminder now would be indefensible. Every other journey the same person
	 * has open stops too: they have bought, whichever basket the order came
	 * from, and a second journey messaging them about a different one is the
	 * same mistake wearing a different row id.
	 *
	 * @param int    $journey_id  Journey to stop.
	 * @param string $external_id The source's id for the order.
	 * @return bool Whether this caller was the one that stopped it.
	 */
	public function pending_payment( int $journey_id, string $external_id ): bool {
		$journey = $this->journeys->find( $journey_id );

		if ( null === $journey ) {
			return false;
		}

		$stopped = $this->stop_pending( $journey, $external_id );

		// Revoked whatever the transition did. An order exists for this
		// journey, so a link that would rebuild the abandoned basket is now
		// actively harmful: following it would overwrite the cart of a
		// customer who is on the payment page.
		$this->attempts->revoke_tokens( $journey->id );

		if ( $journey->customer_id > 0 ) {
			$this->stop_others( $journey->customer_id, $journey->id, 'bought' );
		}

		return $stopped;
	}

	/**
	 * The money arrived. Record the conversion and what it was worth.
	 *
	 * The only place recovered_at, recovered_amount and attribution are ever
	 * written, so "what does the recovered revenue figure actually count?" has
	 * exactly one answer to read.
	 *
	 * @param int    $journey_id  Journey that is being credited.
	 * @param string $external_id The source's id for the order.
	 * @param string $amount      Order total, as a decimal string in major units.
	 * @param string $attribution One of the Recovery_Journey::ATTRIBUTION_* constants.
	 * @return bool Whether this caller was the one that recorded it.
	 */
	public function recovered( int $journey_id, string $external_id, string $amount, string $attribution ): bool {
		$journey = $this->journeys->find( $journey_id );

		if ( null === $journey ) {
			return false;
		}

		$patch = array(
			'recovered_at'          => $this->clock->now(),
			'recovered_external_id' => '' === $external_id ? null : substr( $external_id, 0, 191 ),
			'recovered_amount'      => self::decimal( $amount ),
			'attribution'           => self::grade( $attribution ),
		);

		$recorded = $this->journeys->transition( $journey->id, $journey->status, Journey_State::RECOVERED, $patch, 'converted' );

		if ( ! $recorded ) {
			// Not an error. A second payment notification for the same order,
			// or an opt-out that landed first, both look like this.
			$this->logger->debug(
				'conversion',
				'Journey was already finished when the conversion arrived.',
				array( 'status' => $journey->status ),
				$journey->id
			);

			return false;
		}

		$this->attempts->revoke_tokens( $journey->id );

		$this->logger->info(
			'conversion',
			'Journey recovered.',
			array( 'attribution' => $patch['attribution'] ),
			$journey->id
		);

		return true;
	}

	/**
	 * The payment failed, or the order was cancelled before it was paid.
	 *
	 * The journey is handed back to the workflow exactly once. A customer whose
	 * card was declined may genuinely want help finishing; a customer whose card
	 * has been declined twice is not somebody to keep messaging, and resume_count
	 * is what makes that a rule rather than a hope.
	 *
	 * @param int $journey_id Journey whose order failed.
	 * @return bool Whether this caller changed the journey.
	 */
	public function payment_failed( int $journey_id ): bool {
		$journey = $this->journeys->find( $journey_id );

		if ( null === $journey ) {
			return false;
		}

		if ( Journey_State::PENDING_PAYMENT !== $journey->status ) {
			// Anything else means the journey has already moved on -- it was
			// recovered by another order, expired, or opted out. A failed
			// payment does not reopen any of those.
			return false;
		}

		if ( $journey->resume_count >= self::MAX_RESUMES ) {
			return $this->journeys->transition(
				$journey->id,
				$journey->status,
				Journey_State::CANCELLED,
				array(),
				'payment_failed'
			);
		}

		return $this->journeys->transition(
			$journey->id,
			$journey->status,
			Journey_State::SCHEDULED,
			array(
				'resume_count'   => $journey->resume_count + 1,
				'next_action_at' => $this->clock->offset( self::RESUME_DELAY ),
			),
			'payment_retry'
		);
	}

	/**
	 * End every journey still being worked for one person.
	 *
	 * @param int    $customer_id Customer id.
	 * @param string $reason      Short machine-readable reason, stored on each row.
	 * @return int How many journeys this caller stopped.
	 */
	public function stop_for_customer( int $customer_id, string $reason ): int {
		return $this->stop_others( $customer_id, 0, $reason );
	}

	/**
	 * Move a journey to PENDING_PAYMENT, or failing that simply stop it.
	 *
	 * PENDING_PAYMENT is unreachable from NEW and IDENTIFIED, because a journey
	 * that has not been found eligible has nothing to wait for. WooCommerce
	 * never creates one in those states, but a source that does must not end up
	 * with a journey that keeps its timers running because one transition was
	 * illegal: the fallback ends it outright, which is the safe direction.
	 *
	 * @param Recovery_Journey $journey     The journey.
	 * @param string           $external_id The source's id for the order.
	 * @return bool
	 */
	private function stop_pending( Recovery_Journey $journey, string $external_id ): bool {
		$patch = array( 'recovered_external_id' => '' === $external_id ? null : substr( $external_id, 0, 191 ) );

		if ( Journey_State::can_transition( $journey->status, Journey_State::PENDING_PAYMENT ) ) {
			return $this->journeys->transition(
				$journey->id,
				$journey->status,
				Journey_State::PENDING_PAYMENT,
				$patch,
				'order_placed'
			);
		}

		if ( ! $journey->is_active() ) {
			return false;
		}

		return $this->journeys->transition(
			$journey->id,
			$journey->status,
			Journey_State::CANCELLED,
			$patch,
			'order_placed'
		);
	}

	/**
	 * Cancel a person's active journeys, optionally sparing one.
	 *
	 * @param int    $customer_id Customer id.
	 * @param int    $spare_id    Journey to leave alone, or 0.
	 * @param string $reason      Short machine-readable reason.
	 * @return int How many were stopped.
	 */
	private function stop_others( int $customer_id, int $spare_id, string $reason ): int {
		if ( $customer_id <= 0 ) {
			return 0;
		}

		$stopped = 0;

		foreach ( $this->journeys->active_for_customer( $customer_id, 100 ) as $other ) {
			if ( $other->id === $spare_id ) {
				continue;
			}

			if ( ! $this->journeys->transition( $other->id, $other->status, Journey_State::CANCELLED, array(), $reason ) ) {
				continue;
			}

			$this->attempts->revoke_tokens( $other->id );
			++$stopped;
		}

		return $stopped;
	}

	/**
	 * The journey named directly by an identifier stamped on the conversion.
	 *
	 * @param string|null $journey_uid Journey identifier.
	 * @param string|null $event_uid   Event identifier.
	 * @return Recovery_Journey|null
	 */
	private function by_identifier( ?string $journey_uid, ?string $event_uid ): ?Recovery_Journey {
		if ( null !== $journey_uid && '' !== $journey_uid ) {
			$journey = $this->journeys->find_by_uid( $journey_uid );

			if ( null !== $journey ) {
				return $journey;
			}
		}

		if ( null === $event_uid || '' === $event_uid ) {
			return null;
		}

		$event = $this->events->find_by_uid( $event_uid );

		return null === $event ? null : $this->journeys->find_by_event( $event->id );
	}

	/**
	 * The journey behind the open event for a browser session.
	 *
	 * @param string|null $session_key The source's session identifier.
	 * @param string      $source_id   Which adapter reported the conversion.
	 * @return Recovery_Journey|null
	 */
	private function by_session( ?string $session_key, string $source_id ): ?Recovery_Journey {
		if ( null === $session_key || '' === $session_key || '' === $source_id ) {
			return null;
		}

		$event = $this->events->find_open_by_session( $source_id, $session_key );

		return null === $event ? null : $this->journeys->find_by_event( $event->id );
	}

	/**
	 * The best journey to credit when all that matches is the person.
	 *
	 * Candidates are ranked rather than simply taken newest-first, because this
	 * method is asked twice for one order: once when it is placed, and again
	 * when it is paid. By the second call the journey the first call stopped is
	 * sitting in PENDING_PAYMENT, and picking any other one would credit the
	 * money to a journey that had nothing to do with it.
	 *
	 * @param int|null $customer_id Customer id.
	 * @param string   $source_id   Which adapter reported the conversion.
	 * @return Recovery_Journey|null
	 */
	private function by_customer( ?int $customer_id, string $source_id ): ?Recovery_Journey {
		if ( null === $customer_id || $customer_id <= 0 ) {
			return null;
		}

		$customer = $this->customers->find( $customer_id );

		// An erased customer keeps their row as a husk so the suppression list
		// still works. There is nothing left on it to attribute anything to.
		if ( null === $customer || $customer->is_anonymized() ) {
			return null;
		}

		$cutoff = $this->clock->timestamp() - Rule_Set::for_source()->attribution_window_seconds();
		$best   = null;
		$score  = -1;

		foreach ( $this->journeys->active_for_customer( $customer_id, 100 ) as $candidate ) {
			if ( $this->touched_at( $candidate ) < $cutoff ) {
				continue;
			}

			$rank = 0;

			if ( Journey_State::PENDING_PAYMENT === $candidate->status ) {
				$rank += 2;
			}

			if ( '' !== $source_id && $candidate->source_id === $source_id ) {
				++$rank;
			}

			// active_for_customer() returns newest first, so the first
			// candidate at a given rank is also the most recent one.
			if ( $rank > $score ) {
				$best  = $candidate;
				$score = $rank;
			}
		}//end foreach

		return $best;
	}

	/**
	 * When the attribution clock for a journey starts.
	 *
	 * The window is "how long after a message may an order still be credited",
	 * so it runs from the first message where there is one. A journey that has
	 * not been messaged yet falls back to when it was created.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @return int Unix timestamp.
	 */
	private function touched_at( Recovery_Journey $journey ): int {
		$stamp = null !== $journey->first_sent_at ? $journey->first_sent_at : $journey->created_at;

		return '' === $stamp ? 0 : $this->clock->parse( $stamp );
	}

	/**
	 * Wrap a resolved journey in the shape callers expect.
	 *
	 * @param Recovery_Journey $journey     The journey.
	 * @param string           $attribution How it was matched.
	 * @return array{journey:Recovery_Journey,attribution:string}
	 */
	private function match( Recovery_Journey $journey, string $attribution ): array {
		return array(
			'journey'     => $journey,
			'attribution' => $attribution,
		);
	}

	/**
	 * Reduce an attribution to one the plugin recognises.
	 *
	 * An unrecognised grade becomes the weakest one rather than the strongest.
	 * The grade decides whether an order counts towards the recovered-revenue
	 * figure a merchant reports, so a caller passing rubbish must never be able
	 * to inflate it.
	 *
	 * @param string $attribution Proposed grade.
	 * @return string
	 */
	private static function grade( string $attribution ): string {
		$known = array(
			Recovery_Journey::ATTRIBUTION_EXACT,
			Recovery_Journey::ATTRIBUTION_SESSION,
			Recovery_Journey::ATTRIBUTION_CUSTOMER,
		);

		return in_array( $attribution, $known, true ) ? $attribution : Recovery_Journey::ATTRIBUTION_CUSTOMER;
	}

	/**
	 * The largest value the recovered_amount column can hold: decimal(19,4).
	 */
	private const MAX_AMOUNT = 999999999999999.9999;

	/**
	 * Normalise a money string for a DECIMAL column.
	 *
	 * Callers are contracted to pass a plain decimal, and WooCommerce does. But
	 * this figure ends up in the recovered-revenue total a merchant reports to
	 * somebody, and the ways a formatted amount can go wrong here are not
	 * symmetrical. Handing MySQL "1,234.50" would store 1.0000 -- obviously
	 * broken. Stripping the comma from "1 234,50", which is how most of Europe
	 * writes it, would store 123450.0000: a hundred times too much, in the one
	 * number nobody would think to double-check.
	 *
	 * So separators are read rather than deleted. Where both a dot and a comma
	 * appear the last one is the decimal point; a lone comma with one or two
	 * digits behind it is a decimal comma, and any other comma is grouping.
	 * Anything that still does not parse returns null, because a blank amount
	 * on the journey screen is a question a merchant can answer and an invented
	 * one is not.
	 *
	 * @param string $amount Amount as given.
	 * @return string|null Decimal string, or null when it cannot be read safely.
	 */
	private static function decimal( string $amount ): ?string {
		// Scientific notation is not money. The filter below strips letters so
		// that a currency symbol or code does no harm, which would quietly
		// turn "1.0E+16" into 1.016 and store that as an order total.
		if ( 1 === preg_match( '/\d[eE][+-]?\d/', $amount ) ) {
			return null;
		}

		$clean = preg_replace( '/[^0-9.,\-]/', '', $amount );

		if ( ! is_string( $clean ) || '' === $clean ) {
			return null;
		}

		$clean = self::separators( $clean );

		if ( 1 !== preg_match( '/^-?(?:\d+(?:\.\d*)?|\.\d+)$/', $clean ) ) {
			return null;
		}

		$value = (float) $clean;

		// Out of range for the column. In strict mode MySQL rejects the write,
		// and this runs inside the shopper's own checkout request.
		if ( abs( $value ) > self::MAX_AMOUNT ) {
			return null;
		}

		return number_format( $value, 4, '.', '' );
	}

	/**
	 * Resolve which of a number's dots and commas is the decimal point.
	 *
	 * @param string $clean Digits, dots, commas and minus signs only.
	 * @return string The same number with one dot and no grouping marks.
	 */
	private static function separators( string $clean ): string {
		$dot   = strrpos( $clean, '.' );
		$comma = strrpos( $clean, ',' );

		if ( false === $comma ) {
			return $clean;
		}

		if ( false !== $dot ) {
			return $dot > $comma
				? str_replace( ',', '', $clean )
				: str_replace( ',', '.', str_replace( '.', '', $clean ) );
		}

		// A lone comma with three digits behind it is grouping -- "1,234" is a
		// thousand, not one and a bit -- and anything else at that position is
		// a decimal comma.
		if ( 1 === substr_count( $clean, ',' ) && 1 === preg_match( '/,\d{1,2}$/', $clean ) ) {
			return str_replace( ',', '.', $clean );
		}

		return str_replace( ',', '', $clean );
	}
}
