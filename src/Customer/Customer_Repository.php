<?php
/**
 * Customer storage.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Database\Table_Names;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes customer records.
 *
 * Lookups are by hash, never by plaintext: the identity index is on the hash,
 * and a query that searched a plaintext column would both scan the table and
 * put a phone number into the slow query log.
 *
 * Every customer returned from here is hydrated with their identity rows, so
 * callers keep reading $customer->phone_e164 and ->email as before even though
 * neither is a column any more.
 */
final class Customer_Repository extends Repository {

	/**
	 * The identities that say how to reach these people.
	 *
	 * @var Identity_Repository
	 */
	private Identity_Repository $identities;

	/**
	 * Constructor.
	 *
	 * @param Clock               $clock      Clock.
	 * @param Identity_Repository $identities Identity storage.
	 */
	public function __construct( Clock $clock, Identity_Repository $identities ) {
		parent::__construct( $clock );

		$this->identities = $identities;
	}

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::CUSTOMERS;
	}

	/**
	 * Build a customer from a row, with their identities attached.
	 *
	 * @param array<string,mixed>|null $row Customer row.
	 * @return Customer|null
	 */
	private function hydrate( ?array $row ): ?Customer {
		if ( null === $row ) {
			return null;
		}

		$customer = Customer::from_row( $row );
		$customer->with_identities( $this->identities->for_customer( $customer->id ) );

		return $customer;
	}

	/**
	 * Fetch by row id.
	 *
	 * @param int $id Customer id.
	 * @return Customer|null
	 */
	public function find( int $id ): ?Customer {
		return $this->hydrate( $this->find_by_id( $id ) );
	}

	/**
	 * Fetch the person reachable on this phone number.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @return Customer|null
	 */
	public function find_by_phone_hash( string $phone_hash ): ?Customer {
		return $this->find_by_identity( Identity::E164, $phone_hash );
	}

	/**
	 * Fetch the person reachable at this email address.
	 *
	 * Email is a matching key, never a merge key. Where a number identifies one
	 * person, an address may belong to several, so an ambiguous match returns
	 * nothing rather than picking the oldest row: guessing which of two people
	 * sharing an inbox is checking out would attach one person's cart to
	 * another's, which is worse than not recognising a returning customer.
	 *
	 * @param string $email_hash Keyed hash of the address.
	 * @return Customer|null
	 */
	public function find_by_email_hash( string $email_hash ): ?Customer {
		return $this->find_by_identity( Identity::EMAIL, $email_hash );
	}

	/**
	 * Fetch the person holding an identity, when exactly one holds it.
	 *
	 * @param string $kind       Identity kind.
	 * @param string $value_hash Keyed hash of the value.
	 * @return Customer|null
	 */
	public function find_by_identity( string $kind, string $value_hash ): ?Customer {
		$customer_id = $this->identities->find_customer_id( $kind, $value_hash );

		return null === $customer_id ? null : $this->find( $customer_id );
	}

	/**
	 * Fetch by WordPress user.
	 *
	 * @param int $wp_user_id User id.
	 * @return Customer|null
	 */
	public function find_by_user( int $wp_user_id ): ?Customer {
		if ( $wp_user_id < 1 ) {
			return null;
		}

		return $this->hydrate( $this->find_one_by( 'wp_user_id', $wp_user_id ) );
	}

	/**
	 * Create a customer.
	 *
	 * @param array<string,mixed> $data Column values.
	 * @return int New id, or 0 on failure.
	 */
	public function create( array $data ): int {
		$now = $this->clock->now();

		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		return $this->insert( $data, array() );
	}

	/**
	 * Write columns on an existing customer.
	 *
	 * @param int                 $id   Customer id.
	 * @param array<string,mixed> $data Column values.
	 * @return bool Whether anything changed.
	 */
	public function update_customer( int $id, array $data ): bool {
		if ( array() === $data ) {
			return false;
		}

		$data['updated_at'] = $this->clock->now();

		return 0 !== $this->update( $data, array( 'id' => $id ) );
	}

	/**
	 * Remember which WA.cr contact this person is.
	 *
	 * @param int    $id         Customer id.
	 * @param string $contact_id WA.cr contact identifier.
	 * @return bool
	 */
	public function set_wacr_contact( int $id, string $contact_id ): bool {
		return $this->update_customer(
			$id,
			array(
				'wacr_contact_id' => substr( $contact_id, 0, 64 ),
				'wacr_synced_at'  => $this->clock->now(),
			)
		);
	}

	/**
	 * Strip the identifying columns, keeping the hashes.
	 *
	 * The hashes stay on purpose, in the identity rows this delegates to. They
	 * are what the suppression list is made of, and an erasure that also forgot
	 * the opt-out would start messaging the person again on their next visit.
	 *
	 * @param int $id Customer id.
	 * @return bool
	 */
	public function anonymize( int $id ): bool {
		$now = $this->clock->now();

		$this->identities->anonymize( $id );

		return 0 !== $this->update(
			array(
				'first_name'      => null,
				'last_name'       => null,
				'wacr_contact_id' => null,
				'anonymized_at'   => $now,
				'updated_at'      => $now,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Find the customers behind an email address, for privacy requests.
	 *
	 * @param string $email_hash Keyed hash of the address.
	 * @return array<int,Customer>
	 */
	public function find_all_by_email_hash( string $email_hash ): array {
		if ( '' === $email_hash ) {
			return array();
		}

		$table      = $this->table();
		$identities = $this->identities->table_name();

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from class constants.
			$this->db()->prepare(
				"SELECT c.* FROM `{$table}` c INNER JOIN `{$identities}` i ON i.customer_id = c.id WHERE i.kind = %s AND i.value_hash = %s ORDER BY c.id ASC LIMIT 100",
				Identity::EMAIL,
				$email_hash
			)
		);

		return array_values(
			array_filter(
				array_map( fn ( array $row ): ?Customer => $this->hydrate( $row ), $rows )
			)
		);
	}
}
