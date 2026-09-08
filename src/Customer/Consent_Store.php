<?php
/**
 * Recording and reading permission to message.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

use WAcr\RecoveryFlow\Recovery\Channel;
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
	 * Constructor.
	 *
	 * @param Consent_Repository $consents  Consent ledger.
	 */
	public function __construct( Consent_Repository $consents ) {
		$this->consents = $consents;
	}

	/**
	 * Record what a visitor answered, for one channel.
	 *
	 * The channel is required rather than defaulted, because the commonest way
	 * to get this wrong is to record a WhatsApp tick-box as blanket permission
	 * and then use it to justify an email. Whoever asked the question knows
	 * which channel they asked about; nothing else does.
	 *
	 * @param Customer $customer     The person.
	 * @param string   $channel      Channel they were asked about.
	 * @param bool     $granted      Whether they agreed.
	 * @param string   $source       Where they were asked, e.g. 'checkout_classic'.
	 * @param string   $text_version Which wording they saw.
	 * @param string   $ip           Their IP address, hashed before storage.
	 * @return void
	 */
	public function record( Customer $customer, string $channel, bool $granted, string $source, string $text_version = '', string $ip = '' ): void {
		$kind = Channel::identity_kind( $channel );
		$hash = $this->hash_for( $customer, $channel );

		if ( '' === $kind || '' === $hash ) {
			return;
		}

		$current = $this->consents->status( $kind, $hash, $channel );

		// A grant cannot quietly overturn a withdrawal.
		if ( $granted && in_array( $current, Consent_Repository::BLOCKING, true ) ) {
			return;
		}

		$status = $granted ? Consent_Repository::GRANTED : Consent_Repository::DENIED;

		if ( $status === $current ) {
			return;
		}

		$this->consents->record(
			$kind,
			$hash,
			$channel,
			$customer->id,
			$status,
			$source,
			$text_version,
			'' === $ip ? '' : Hash_Key::hash( $ip )
		);
	}

	/**
	 * The identity hash a decision about this channel is keyed on.
	 *
	 * @param Customer $customer The person.
	 * @param string   $channel  Channel being decided.
	 * @return string Keyed hash, or '' when they are not reachable there.
	 */
	private function hash_for( Customer $customer, string $channel ): string {
		if ( Channel::WHATSAPP === $channel ) {
			return $customer->phone_hash;
		}

		return Channel::EMAIL === $channel ? $customer->email_hash : '';
	}

	/**
	 * Record that somebody asked not to be messaged again.
	 *
	 * Keyed on the identity hash rather than the customer, so it survives
	 * erasure and applies even if the customer record is later removed.
	 *
	 * Defaults to Channel::ALL because that is what somebody means when they
	 * say stop. A per-channel suppression is a narrower thing and the caller
	 * has to ask for it: an unsubscribe link at the foot of an email should
	 * silence the email, while a STOP keyword on WhatsApp, an admin acting on a
	 * complaint, or a privacy erasure should silence everything.
	 *
	 * @param string   $identity_kind Identity kind the request is about.
	 * @param string   $identity_hash Keyed hash of the identity value.
	 * @param int|null $customer_id   Customer, when one is known.
	 * @param string   $source        Where the request came from.
	 * @param string   $channel       Channel to silence, or Channel::ALL.
	 * @return void
	 */
	public function suppress( string $identity_kind, string $identity_hash, ?int $customer_id = null, string $source = 'link', string $channel = Channel::ALL ): void {
		if ( '' === $identity_kind || '' === $identity_hash ) {
			return;
		}

		$this->consents->record( $identity_kind, $identity_hash, $channel, $customer_id, Consent_Repository::SUPPRESSED, $source );
	}

	/**
	 * The decision that currently applies to an identity on a channel.
	 *
	 * @param string $identity_kind Identity kind.
	 * @param string $identity_hash Keyed hash of the identity value.
	 * @param string $channel       Channel being decided.
	 * @return string One of the Consent_Repository constants, or 'unknown'.
	 */
	public function status( string $identity_kind, string $identity_hash, string $channel ): string {
		return $this->consents->status( $identity_kind, $identity_hash, $channel );
	}

	/**
	 * Whether this identity must not be messaged on this channel.
	 *
	 * @param string $identity_kind Identity kind.
	 * @param string $identity_hash Keyed hash of the identity value.
	 * @param string $channel       Channel being decided.
	 * @return bool
	 */
	public function is_suppressed( string $identity_kind, string $identity_hash, string $channel ): bool {
		return $this->consents->is_suppressed( $identity_kind, $identity_hash, $channel );
	}

	/**
	 * Whether a positive, current agreement exists for this channel.
	 *
	 * @param string $identity_kind Identity kind.
	 * @param string $identity_hash Keyed hash of the identity value.
	 * @param string $channel       Channel being decided.
	 * @return bool
	 */
	public function has_granted( string $identity_kind, string $identity_hash, string $channel ): bool {
		return Consent_Repository::GRANTED === $this->status( $identity_kind, $identity_hash, $channel );
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
