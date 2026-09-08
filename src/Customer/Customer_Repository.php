<?php
/**
 * Customer storage.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Database\Table_Names;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes customer records.
 *
 * Lookups are by hash, never by plaintext: the indexes are on phone_hash and
 * email_hash, and a query that searched the plaintext columns would both scan
 * the table and put a phone number into the slow query log.
 */
final class Customer_Repository extends Repository {

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::CUSTOMERS;
	}

	/**
	 * Fetch by row id.
	 *
	 * @param int $id Customer id.
	 * @return Customer|null
	 */
	public function find( int $id ): ?Customer {
		$row = $this->find_by_id( $id );

		return null === $row ? null : Customer::from_row( $row );
	}

	/**
	 * Fetch by the messaging identity key.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @return Customer|null
	 */
	public function find_by_phone_hash( string $phone_hash ): ?Customer {
		if ( '' === $phone_hash ) {
			return null;
		}

		$row = $this->find_one_by( 'phone_hash', $phone_hash );

		return null === $row ? null : Customer::from_row( $row );
	}

	/**
	 * Fetch by email hash.
	 *
	 * Email is a matching key, never a merge key, so this can legitimately
	 * return one of several people who share an address. The caller decides
	 * whether that is good enough, and it never is when a phone number is
	 * available.
	 *
	 * @param string $email_hash Keyed hash of the address.
	 * @return Customer|null
	 */
	public function find_by_email_hash( string $email_hash ): ?Customer {
		if ( '' === $email_hash ) {
			return null;
		}

		$table = $this->table();

		$row = $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE email_hash = %s ORDER BY id ASC LIMIT 1",
				$email_hash
			)
		);

		return null === $row ? null : Customer::from_row( $row );
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

		$row = $this->find_one_by( 'wp_user_id', $wp_user_id );

		return null === $row ? null : Customer::from_row( $row );
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
	 * Create a customer, or return the one that already holds this phone hash.
	 *
	 * The UNIQUE index on phone_hash is the arbiter: two requests racing to
	 * enrol the same number produce one record, and the loser is handed the
	 * winner's row rather than an error.
	 *
	 * @param array<string,mixed> $data Column values, including phone_hash.
	 * @return Customer|null
	 */
	public function create_or_get( array $data ): ?Customer {
		$now = $this->clock->now();

		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$id = $this->insert_ignore( $data );

		if ( 0 !== $id ) {
			return $this->find( $id );
		}

		$phone_hash = isset( $data['phone_hash'] ) ? (string) $data['phone_hash'] : '';

		return '' === $phone_hash ? null : $this->find_by_phone_hash( $phone_hash );
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
	 * Record that the customer opted out.
	 *
	 * @param int $id Customer id.
	 * @return bool
	 */
	public function mark_opted_out( int $id ): bool {
		return $this->update_customer(
			$id,
			array(
				'opted_out_at'   => $this->clock->now(),
				'consent_status' => Customer::CONSENT_SUPPRESSED,
			)
		);
	}

	/**
	 * Cache the consent verdict on the customer row.
	 *
	 * @param int    $id     Customer id.
	 * @param string $status One of the Customer CONSENT_* constants.
	 * @return bool
	 */
	public function set_consent_status( int $id, string $status ): bool {
		return $this->update_customer( $id, array( 'consent_status' => $status ) );
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
	 * The hashes stay on purpose. They are what the suppression list is made
	 * of, and an erasure that also forgot the opt-out would start messaging the
	 * person again on their next visit.
	 *
	 * @param int $id Customer id.
	 * @return bool
	 */
	public function anonymize( int $id ): bool {
		$now = $this->clock->now();

		return 0 !== $this->update(
			array(
				'email'           => null,
				'phone_e164'      => null,
				'phone_raw'       => null,
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

		$table = $this->table();

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare( "SELECT * FROM `{$table}` WHERE email_hash = %s ORDER BY id ASC LIMIT 100", $email_hash )
		);

		return array_map( array( Customer::class, 'from_row' ), $rows );
	}
}
