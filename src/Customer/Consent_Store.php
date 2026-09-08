<?php
/**
 * Recording and reading permission to message.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

use WAcr\RecoveryFlow\Security\Hash_Key;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that answers "may this person be messaged?".
 *
 * Consent is written to an append-only ledger and cached on the customer row.
 * The ledger is the truth; the cached column exists only so a list screen can
 * show a badge without a second query per row.
 *
 * Two rules are enforced here rather than left to callers.
 *
 * A withdrawal outranks any later grant that arrives implicitly. Somebody who
 * has said stop does not un-say it by filling in a checkout form whose box
 * happened to be pre-ticked by their browser; they have to ask again
 * deliberately, and only an explicit grant from a form they actually saw counts.
 *
 * A refusal is recorded, not merely not-recorded. "Did not tick the box" and
 * "was never shown the box" are different facts, and only the second one leaves
 * room to ask again.
 */
final class Consent_Store {

	/**
	 * Consent ledger.
	 *
	 * @var Consent_Repository
	 */
	private Consent_Repository $consents;

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * Constructor.
	 *
	 * @param Consent_Repository  $consents  Consent ledger.
	 * @param Customer_Repository $customers Customer storage.
	 */
	public function __construct( Consent_Repository $consents, Customer_Repository $customers ) {
		$this->consents  = $consents;
		$this->customers = $customers;
	}

	/**
	 * Record what a visitor answered.
	 *
	 * @param Customer $customer     The person.
	 * @param bool     $granted      Whether they agreed.
	 * @param string   $source       Where they were asked, e.g. 'checkout_classic'.
	 * @param string   $text_version Which wording they saw.
	 * @param string   $ip           Their IP address, hashed before storage.
	 * @return void
	 */
	public function record( Customer $customer, bool $granted, string $source, string $text_version = '', string $ip = '' ): void {
		if ( '' === $customer->phone_hash ) {
			return;
		}

		$current = $this->consents->status( $customer->phone_hash );

		// A grant cannot quietly overturn a withdrawal.
		if ( $granted && in_array( $current, Consent_Repository::BLOCKING, true ) ) {
			return;
		}

		$status = $granted ? Consent_Repository::GRANTED : Consent_Repository::DENIED;

		if ( $status === $current ) {
			return;
		}

		$this->consents->record(
			$customer->phone_hash,
			$customer->id,
			$status,
			$source,
			$text_version,
			'' === $ip ? '' : Hash_Key::hash( $ip )
		);

		$this->customers->set_consent_status( $customer->id, $status );
	}

	/**
	 * Record that somebody asked not to be messaged again.
	 *
	 * Keyed on the phone hash rather than the customer, so it survives erasure
	 * and applies even if the customer record is later removed.
	 *
	 * @param string   $phone_hash  Keyed hash of the number.
	 * @param int|null $customer_id Customer, when one is known.
	 * @param string   $source      Where the request came from.
	 * @return void
	 */
	public function suppress( string $phone_hash, ?int $customer_id = null, string $source = 'link' ): void {
		if ( '' === $phone_hash ) {
			return;
		}

		$this->consents->record( $phone_hash, $customer_id, Consent_Repository::SUPPRESSED, $source );

		if ( null !== $customer_id ) {
			$this->customers->mark_opted_out( $customer_id );
		}
	}

	/**
	 * The decision that currently applies to a number.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @return string One of the Consent_Repository constants, or 'unknown'.
	 */
	public function status( string $phone_hash ): string {
		return $this->consents->status( $phone_hash );
	}

	/**
	 * Whether this number must not be messaged.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @return bool
	 */
	public function is_suppressed( string $phone_hash ): bool {
		return $this->consents->is_suppressed( $phone_hash );
	}

	/**
	 * Whether a positive, current agreement exists.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @return bool
	 */
	public function has_granted( string $phone_hash ): bool {
		return Consent_Repository::GRANTED === $this->status( $phone_hash );
	}

	/**
	 * The ledger, for the privacy exporter and the journey detail screen.
	 *
	 * @return Consent_Repository
	 */
	public function ledger(): Consent_Repository {
		return $this->consents;
	}
}
