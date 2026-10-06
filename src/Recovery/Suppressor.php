<?php
/**
 * Stopping every recovery for one person.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Customer\Consent_Store;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Customer\Identity;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\WAcr\Opt_Out_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * "Stop messaging me", however it arrived.
 *
 * There are now three ways this can be said -- the unsubscribe link in a
 * message, a shopkeeper acting on a phone call, and the same act over REST --
 * and there must be exactly one implementation of what happens next. This one.
 *
 * **Suppression is stored per identity, and a person is more than one.** That is
 * not a detail; it is the bug this code already shipped. Suppression used to be
 * written against the phone number alone, so a shopper who had given an address
 * and no number was told their reminders had stopped while nothing at all was
 * recorded. Every identity the customer has is suppressed here, together.
 *
 * **The order is deliberate and must not be rearranged.** The suppression is
 * recorded first. Only then is the push to WA.cr queued, and only then are the
 * open journeys closed. Whether the opt-out also reaches WA.cr is a merchant
 * setting and a network call, and neither may stand between somebody asking to
 * be left alone and being left alone.
 *
 * **The links die with the journey.** One of them is very often the link the
 * person just used to ask.
 *
 * What differs between the three callers is only where the request came from,
 * which is recorded on the consent row as its source -- `link`, `admin` or
 * whatever a later caller names itself. The behaviour does not differ, because
 * a customer who asked to be left alone by telephone has asked for exactly what
 * a customer who clicked has asked for.
 */
final class Suppressor {

	/**
	 * Where an opt-out taken by a shop worker is recorded as coming from.
	 */
	public const SOURCE_ADMIN = 'admin';

	/**
	 * Where an opt-out from the unsubscribe link is recorded as coming from.
	 */
	public const SOURCE_LINK = 'link';

	/**
	 * Where an opt-out reported by a WA.cr Auto Flow is recorded as coming from.
	 *
	 * A customer who replies STOP inside a flow has said it to the shop, not to
	 * a plugin, and is suppressed exactly as if they had used the link.
	 */
	public const SOURCE_FLOW = 'flow';

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * The consent ledger.
	 *
	 * @var Consent_Store
	 */
	private Consent_Store $consent;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * The attempt ledger, which owns the recovery links.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

	/**
	 * The log.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Customer_Repository $customers Customer storage.
	 * @param Consent_Store       $consent   The consent ledger.
	 * @param Journey_Repository  $journeys  Journey storage.
	 * @param Attempt_Repository  $attempts  The attempt ledger.
	 * @param Logger              $logger    The log.
	 */
	public function __construct(
		Customer_Repository $customers,
		Consent_Store $consent,
		Journey_Repository $journeys,
		Attempt_Repository $attempts,
		Logger $logger
	) {
		$this->customers = $customers;
		$this->consent   = $consent;
		$this->journeys  = $journeys;
		$this->attempts  = $attempts;
		$this->logger    = $logger;
	}

	/**
	 * Stop messaging this customer, on every channel, for good.
	 *
	 * @param int    $customer_id The customer.
	 * @param string $source      Where the request came from, for the consent row.
	 * @return array{ok:bool,identities:int,journeys:int,reason:string}
	 */
	public function suppress( int $customer_id, string $source = self::SOURCE_LINK ): array {
		$customer = $customer_id > 0 ? $this->customers->find( $customer_id ) : null;

		$identities = null === $customer
			? array()
			: array_filter(
				array(
					Identity::E164  => $customer->phone_hash,
					Identity::EMAIL => $customer->email_hash,
				)
			);

		if ( null === $customer || array() === $identities ) {
			// Reaching here means this record holds no contact detail: there
			// never was one, or the retention clear-out let it go. Either way
			// nothing could be messaged through this record and there is no
			// hash here to record against. An erasure on request keeps its
			// hashes, so it suppresses normally above.
			$this->logger->warning(
				'recovery',
				'An opt-out arrived for a customer with no contact left to suppress.',
				array( 'source' => $source )
			);

			return array(
				'ok'         => false,
				'identities' => 0,
				'journeys'   => 0,
				'reason'     => __( 'There is no phone number or email address recorded for this customer, so there is nothing to opt out. Nothing was being sent to them.', 'kdc-wacr-recoveryflow' ),
			);
		}

		foreach ( $identities as $kind => $hash ) {
			$this->consent->suppress( (string) $kind, (string) $hash, $customer->id, $source );
		}

		/*
		 * Queued after the suppression above is recorded, and never before it.
		 * "Stop messaging me" is answered here first; whether it also reaches
		 * WA.cr is a merchant setting and a network call, and neither may stand
		 * between a customer and being left alone.
		 */
		Opt_Out_Sync::queue( $customer->id );

		$closed = 0;

		foreach ( $this->journeys->active_for_customer( $customer->id, 100 ) as $active ) {
			if ( ! $this->journeys->transition( $active->id, $active->status, Journey_State::OPTED_OUT, array(), $source ) ) {
				continue;
			}

			// The links die with the journey. One of them is very often the
			// link this person just used to ask to be left alone.
			$this->attempts->revoke_tokens( $active->id );

			++$closed;
		}

		return array(
			'ok'         => true,
			'identities' => count( $identities ),
			'journeys'   => $closed,
			'reason'     => '',
		);
	}

	/**
	 * Stop messaging a contact detail that no customer here holds.
	 *
	 * A STOP can arrive for a number nobody here holds today: one this site
	 * never recorded, or one the retention clear-out let go of. It is recorded
	 * anyway, against the hash, because the consent ledger outlives customers
	 * and the next basket left with that number must find the refusal waiting.
	 * WA.cr does not refuse a send to somebody who opted out, so nothing else
	 * would stop that message.
	 *
	 * There is no journey to close and nothing to sync: both need a customer.
	 *
	 * @param string $identity_kind An Identity kind.
	 * @param string $identity_hash Keyed hash of the value.
	 * @param string $source        Where the request came from, for the consent row.
	 * @return bool Whether a refusal was recorded.
	 */
	public function suppress_unmatched( string $identity_kind, string $identity_hash, string $source = self::SOURCE_FLOW ): bool {
		if ( '' === $identity_kind || '' === $identity_hash ) {
			return false;
		}

		$this->consent->suppress( $identity_kind, $identity_hash, null, $source );

		return true;
	}
}
