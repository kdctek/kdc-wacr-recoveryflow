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
			// An erased customer has no identity left to suppress, and that is
			// correct rather than broken: the hashes ARE the suppression list,
			// and erasure deliberately keeps them. Reaching here means there
			// was never a contact detail, so there is nothing that could be
			// messaged and nothing to record.
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
}
