<?php
/**
 * Deciding who a visitor is.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Security\Hash_Key;
use WAcr\RecoveryFlow\Jobs\Lock;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Turns whatever a source noticed into one customer record.
 *
 * The order of the checks is the whole design, and it is deliberate.
 *
 * The phone number outranks everything except an explicit WordPress account,
 * because the phone number is the thing a message is delivered to. Two records
 * with the same number are the same recipient no matter what else differs, and
 * treating them as separate people is how somebody receives two reminders for
 * one basket.
 *
 * Email matches but never merges. Households share an address, and so do
 * orders@ and info@ inboxes; merging on email would eventually attach one
 * person's abandoned basket to another person's phone number. So an email match
 * is accepted only when there is no phone number to go on, and a customer found
 * by email is never rewritten to carry a phone that arrived with a different
 * identity.
 *
 * Names are never keys. Two customers called the same thing are not the same
 * person, and no amount of fuzzy matching makes that safe when the consequence
 * is a message.
 */
final class Identity_Resolver {

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * The ways of reaching people.
	 *
	 * @var Identity_Repository
	 */
	private Identity_Repository $identities;

	/**
	 * Serialises the create path where no unique index can.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Customer_Repository $customers  Customer storage.
	 * @param Identity_Repository $identities Identity storage.
	 * @param Lock                $lock       Lock for the create path.
	 * @param Logger              $logger     Logger.
	 */
	public function __construct( Customer_Repository $customers, Identity_Repository $identities, Lock $lock, Logger $logger ) {
		$this->customers  = $customers;
		$this->identities = $identities;
		$this->lock       = $lock;
		$this->logger     = $logger;
	}

	/**
	 * Find or create the customer a set of hints describes.
	 *
	 * @param Identity_Hints $hints What the source noticed.
	 * @return Customer|null The customer, or null when there is nothing to go on.
	 */
	public function resolve( Identity_Hints $hints ): ?Customer {
		if ( ! $hints->has_identity() ) {
			return null;
		}

		$phone = $this->normalize_phone( $hints );

		$existing = $this->match( $hints, $phone['hash'] );

		if ( null === $existing ) {
			return $this->create( $hints, $phone );
		}

		return $this->reconcile( $existing, $hints, $phone );
	}

	/**
	 * Normalise the phone number and derive its hash.
	 *
	 * @param Identity_Hints $hints Hints.
	 * @return array{e164:string,status:string,hash:string,raw:string}
	 */
	private function normalize_phone( Identity_Hints $hints ): array {
		if ( '' === $hints->phone_raw ) {
			return array(
				'e164'   => '',
				'status' => Customer::PHONE_UNKNOWN,
				'hash'   => '',
				'raw'    => '',
			);
		}

		$result = Phone_Normalizer::normalize( $hints->phone_raw, $hints->country );
		$e164   = (string) $result['e164'];
		$valid  = Phone_Normalizer::VALID === $result['status'] && '' !== $e164;

		return array(
			'e164'   => $valid ? $e164 : '',
			'status' => $valid ? Customer::PHONE_VALID : Customer::PHONE_INVALID,
			'hash'   => $valid ? Hash_Key::hash( $e164 ) : '',
			'raw'    => $hints->phone_raw,
		);
	}

	/**
	 * Find an existing customer, in order of how much the match can be trusted.
	 *
	 * @param Identity_Hints $hints      Hints.
	 * @param string         $phone_hash Hash of the normalised number, or empty.
	 * @return Customer|null
	 */
	private function match( Identity_Hints $hints, string $phone_hash ): ?Customer {
		if ( '' !== $phone_hash ) {
			$by_phone = $this->customers->find_by_phone_hash( $phone_hash );

			if ( null !== $by_phone ) {
				return $by_phone;
			}
		}

		if ( null !== $hints->wp_user_id ) {
			$by_user = $this->customers->find_by_user( $hints->wp_user_id );

			if ( null !== $by_user ) {
				return $by_user;
			}
		}

		// Email only decides when there is no number to decide with. A phone
		// that did not match is a new recipient, not the email-matched person
		// with a new number.
		if ( '' === $phone_hash && '' !== $hints->email ) {
			return $this->customers->find_by_email_hash( Hash_Key::email( $hints->email ) );
		}

		return null;
	}

	/**
	 * Create a customer from hints, and attach the ways of reaching them.
	 *
	 * Two requests can arrive at this at the same time for the same person --
	 * a checkout that fires twice, two tabs, a retried request -- and what stops
	 * that becoming two customers differs by identity kind.
	 *
	 * For a strong kind the UNIQUE index does it: attaching the identity is an
	 * INSERT IGNORE, the loser of the race is handed the winner's customer, and
	 * the customer row it created for itself is abandoned rather than used.
	 *
	 * An email has no such index, deliberately, because two people may share
	 * one. That leaves nothing for the database to arbitrate with, so the
	 * email-only path is serialised on a bucketed lock instead and re-checks for
	 * an existing customer once it holds it. The check has to be inside the
	 * lock: outside it, it is the same race with extra steps.
	 *
	 * @param Identity_Hints                                          $hints Hints.
	 * @param array{e164:string,status:string,hash:string,raw:string} $phone Normalised phone.
	 * @return Customer|null
	 */
	private function create( Identity_Hints $hints, array $phone ): ?Customer {
		if ( '' !== $phone['hash'] || null !== $hints->wp_user_id ) {
			return $this->create_now( $hints, $phone );
		}

		if ( '' === $hints->email ) {
			return $this->create_now( $hints, $phone );
		}

		$email_hash = Identity_Repository::hash_for( Identity::EMAIL, $hints->email );

		$created = $this->lock->with(
			Identity_Repository::lock_key( $email_hash ),
			function () use ( $hints, $phone, $email_hash ): ?Customer {
				$existing = $this->customers->find_by_identity( Identity::EMAIL, $email_hash );

				return null === $existing ? $this->create_now( $hints, $phone ) : $existing;
			}
		);

		if ( $created instanceof Customer ) {
			return $created;
		}

		/*
		 * The lock was not granted -- another request is enrolling this address
		 * right now. Creating anyway is the one thing that must not happen, so
		 * take whatever they created, and only fall back to creating if they
		 * failed too.
		 */
		$existing = $this->customers->find_by_identity( Identity::EMAIL, $email_hash );

		return null === $existing ? $this->create_now( $hints, $phone ) : $existing;
	}

	/**
	 * Insert the customer and attach every identity the hints carry.
	 *
	 * @param Identity_Hints                                          $hints Hints.
	 * @param array{e164:string,status:string,hash:string,raw:string} $phone Normalised phone.
	 * @return Customer|null
	 */
	private function create_now( Identity_Hints $hints, array $phone ): ?Customer {
		$id = $this->customers->create(
			array(
				'wp_user_id'   => $hints->wp_user_id,
				'first_name'   => '' === $hints->first_name ? null : $hints->first_name,
				'last_name'    => '' === $hints->last_name ? null : $hints->last_name,
				'country_iso2' => '' === $hints->country ? null : $hints->country,
			)
		);

		if ( 0 === $id ) {
			$this->logger->warning( 'identity', 'Could not create a customer record.' );

			return null;
		}

		$owner = $this->attach_all( $id, $hints, $phone );

		return $this->customers->find( $owner );
	}

	/**
	 * Attach the hinted identities, and report who ends up holding them.
	 *
	 * A strong identity that already belongs to somebody else wins: the number
	 * is what a message is addressed to, so the journey belongs to whoever owns
	 * it, not to the row we happened to have in hand.
	 *
	 * @param int                                                     $customer_id Customer to attach to.
	 * @param Identity_Hints                                          $hints       Hints.
	 * @param array{e164:string,status:string,hash:string,raw:string} $phone       Normalised phone.
	 * @return int Customer id that holds the identities.
	 */
	private function attach_all( int $customer_id, Identity_Hints $hints, array $phone ): int {
		$owner = $customer_id;

		if ( '' !== $phone['e164'] ) {
			$owner = $this->identities->attach( $customer_id, Identity::E164, $phone['e164'], $phone['status'], 'resolver' );
		}

		if ( '' !== $hints->email ) {
			$this->identities->attach( $owner, Identity::EMAIL, $hints->email, Identity::STATUS_VALID, 'resolver' );
		}

		if ( null !== $hints->wp_user_id ) {
			$this->identities->attach( $owner, Identity::WP_USER, (string) $hints->wp_user_id, Identity::STATUS_VALID, 'resolver' );
		}

		return $owner;
	}

	/**
	 * Bring an existing record up to date with what was just seen.
	 *
	 * @param Customer                                                $customer Existing record.
	 * @param Identity_Hints                                          $hints    Hints.
	 * @param array{e164:string,status:string,hash:string,raw:string} $phone    Normalised phone.
	 * @return Customer
	 */
	private function reconcile( Customer $customer, Identity_Hints $hints, array $phone ): Customer {
		$patch = array();

		// A record that was erased stays erased. Re-filling it from a later
		// visit would undo the erasure the person asked for.
		if ( $customer->is_anonymized() ) {
			return $customer;
		}

		if ( null !== $hints->wp_user_id && $customer->wp_user_id !== $hints->wp_user_id && null === $customer->wp_user_id ) {
			$patch['wp_user_id'] = $hints->wp_user_id;
		}

		// New ways of reaching them are added, never overwritten: somebody who
		// gives a second address has two, and dropping the first would lose the
		// consent recorded against it.
		if ( '' !== $hints->email && $hints->email !== $customer->email ) {
			$this->identities->attach( $customer->id, Identity::EMAIL, $hints->email, Identity::STATUS_VALID, 'resolver' );
		}

		if ( '' !== $phone['hash'] && $phone['hash'] !== $customer->phone_hash ) {
			// The number we matched on cannot have changed, so this is a record
			// found by user id or email that has now produced a number. The
			// number decides: attach() hands back whoever holds it, which is
			// them if nobody did and the existing owner if somebody did.
			$owner = $this->identities->attach( $customer->id, Identity::E164, $phone['e164'], $phone['status'], 'resolver' );

			if ( $owner !== $customer->id ) {
				$this->logger->info(
					'identity',
					'A recognised visitor supplied a number that belongs to another record; the number decided.',
					array( 'customer_id' => $owner )
				);

				$owner_customer = $this->customers->find( $owner );

				if ( null !== $owner_customer ) {
					return $this->reconcile( $owner_customer, $hints, $phone );
				}
			}
		}//end if

		if ( '' !== $hints->first_name && '' === $customer->first_name ) {
			$patch['first_name'] = $hints->first_name;
		}

		if ( '' !== $hints->last_name && '' === $customer->last_name ) {
			$patch['last_name'] = $hints->last_name;
		}

		if ( '' !== $hints->country && '' === $customer->country_iso2 ) {
			$patch['country_iso2'] = $hints->country;
		}

		if ( array() === $patch ) {
			return $customer;
		}

		$this->customers->update_customer( $customer->id, $patch );

		$refreshed = $this->customers->find( $customer->id );

		return null === $refreshed ? $customer : $refreshed;
	}

	/**
	 * Normalise a raw number the way the resolver does, for callers that only
	 * need the E.164 form.
	 *
	 * @param string $raw     Number as entered.
	 * @param string $country ISO 3166-1 alpha-2 hint.
	 * @return string E.164 number, or empty when it could not be normalised.
	 */
	public static function to_e164( string $raw, string $country = '' ): string {
		/**
		 * Filters the normalised form of a phone number.
		 *
		 * @param string $e164    The normalised number, or empty.
		 * @param string $raw     The number as entered.
		 * @param string $country ISO 3166-1 alpha-2 hint.
		 */
		return (string) apply_filters( Hooks::FILTER_NORMALIZE_PHONE, Phone_Normalizer::to_e164( $raw, $country ), $raw, $country );
	}
}
