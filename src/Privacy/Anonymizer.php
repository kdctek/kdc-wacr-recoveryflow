<?php
/**
 * Forgetting a person while keeping the trading record.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Privacy;

use WAcr\RecoveryFlow\Customer\Consent_Repository;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Recovery\Attempt_Repository;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a person is removed from this plugin's records.
 *
 * Both routes to erasure end here -- the site owner running WordPress's own
 * privacy eraser, and the daily retention clear-out reaching data nobody has a
 * reason to keep. One implementation, because two would eventually disagree
 * about what "erased" means, and the half that forgot something would be the
 * one nobody was looking at.
 *
 * What goes: names, the readable phone number and email address, the basket
 * contents, the notes, the session key, the WA.cr contact id, and every
 * recovery link, which is revoked so a link already sitting in somebody's
 * message history stops working.
 *
 * What stays, and why each is a deliberate decision rather than an oversight:
 *
 * - **The keyed hash of each identity.** This is the one that looks wrong and
 *   is the most important. The consent ledger is keyed by that hash, so
 *   deleting it would delete the record that this person asked not to be
 *   messaged -- and the next time they typed the same number into the checkout
 *   the site would treat them as somebody new and message them again. Erasing
 *   an opt-out is not a privacy improvement; it is the failure the opt-out
 *   exists to prevent. The hash is keyed to this site and is not reversible
 *   into a phone number, so keeping it does not keep the number.
 * - **Amounts, dates and journey outcomes.** What a basket was worth and
 *   whether it was recovered is the merchant's own trading record. It says
 *   nothing about a person once the person is detached from it, and a shop
 *   cannot be asked to forget its own turnover.
 * - **The customer row itself, stamped anonymized_at.** Deleting it would
 *   orphan the journeys that point at it. Stamping it is also what makes the
 *   person permanently unmessageable: eligibility refuses an anonymised
 *   customer outright.
 *
 * Nothing here deletes rows. Everything is a blanking update, which means an
 * erasure that fails halfway leaves records that are already unreadable rather
 * than a hole where the trading history was.
 */
final class Anonymizer {

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

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
	 * Attempt ledger.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

	/**
	 * Consent ledger.
	 *
	 * @var Consent_Repository
	 */
	private Consent_Repository $consents;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Customer_Repository $customers Customer storage.
	 * @param Journey_Repository  $journeys  Journey storage.
	 * @param Event_Repository    $events    Event storage.
	 * @param Attempt_Repository  $attempts  Attempt ledger.
	 * @param Consent_Repository  $consents  Consent ledger.
	 * @param Logger              $logger    Logger.
	 */
	public function __construct(
		Customer_Repository $customers,
		Journey_Repository $journeys,
		Event_Repository $events,
		Attempt_Repository $attempts,
		Consent_Repository $consents,
		Logger $logger
	) {
		$this->customers = $customers;
		$this->journeys  = $journeys;
		$this->events    = $events;
		$this->attempts  = $attempts;
		$this->consents  = $consents;
		$this->logger    = $logger;
	}

	/**
	 * Forget one customer.
	 *
	 * Ordered so that an interruption leaves less behind rather than more: the
	 * links are revoked first, because a working recovery link is the only part
	 * of this that is reachable from outside the site.
	 *
	 * @param int $customer_id Customer id.
	 * @return bool Whether anything was changed.
	 */
	public function anonymize_customer( int $customer_id ): bool {
		if ( $customer_id <= 0 ) {
			return false;
		}

		$customer = $this->customers->find( $customer_id );

		if ( null === $customer ) {
			return false;
		}

		if ( $customer->is_anonymized() ) {
			// Already done. Saying so rather than repeating the work keeps a
			// second erasure request from writing a fresh anonymized_at over
			// the date the first one is evidenced by.
			return false;
		}

		foreach ( $this->journeys->all_for_customer( $customer_id ) as $journey ) {
			$this->attempts->revoke_tokens( $journey->id );

			if ( $journey->event_id > 0 ) {
				$this->events->strip_items( $journey->event_id );
			}

			/*
			 * A journey still in flight is stopped, not merely stripped.
			 * Eligibility already refuses an anonymised customer, so nothing
			 * would have been sent -- but leaving a scheduled journey sitting
			 * in the queue until it expires means the recovery screen shows
			 * work still being done for somebody who asked to be forgotten,
			 * and every background pass keeps picking it up to decide again
			 * that it must not.
			 */
			if ( ! $journey->is_terminal() ) {
				$this->journeys->transition(
					$journey->id,
					$journey->status,
					Journey_State::CANCELLED,
					array(),
					'erased'
				);
			}
		}//end foreach

		$this->journeys->clear_recovery_details( $customer_id );

		// The consent rows stay, keyed by identity hash; what goes is the link
		// back to the person. A suppression has to outlive the customer record
		// or the opt-out dies with it.
		$this->consents->detach_customer( $customer_id );

		$done = $this->customers->anonymize( $customer_id );

		$this->logger->info(
			'privacy',
			'Anonymised a customer record.',
			array( 'customer_id' => $customer_id )
		);

		return $done;
	}

	/**
	 * What an erasure keeps, in words a customer is entitled to hear.
	 *
	 * WordPress shows this to whoever asked to be forgotten, so it has to be
	 * true and it has to explain itself. "We kept a hash" without the reason
	 * reads as a shop hedging; with the reason it is the answer to the question
	 * they would ask next.
	 *
	 * @return string
	 */
	public static function retained_notice(): string {
		return __( 'Your contact details, name and basket contents have been removed, and any recovery links sent to you no longer work. A one-way code made from your phone number and email address is kept, and cannot be turned back into either: it is how this site continues to recognise that you asked not to be messaged. The value and date of past orders are kept as part of the shop\'s own accounts.', 'kdc-wacr-recoveryflow' );
	}
}
