<?php
/**
 * Storage for the ways of reaching a customer.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Database\Table_Names;
use WAcr\RecoveryFlow\Security\Hash_Key;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes identity rows.
 *
 * The interesting method is attach(). Everything else is a lookup.
 *
 * Uniqueness differs by kind, and the difference is carried by a column rather
 * than by a WHERE clause because MySQL has no partial index: unique_value_hash
 * repeats the value hash for a strong kind and is NULL for an email. MySQL
 * treats NULLs in a UNIQUE index as distinct from one another, so one index
 * enforces both halves of the rule, and for strong kinds the database stays the
 * arbiter of a race rather than the calling code.
 */
final class Identity_Repository extends Repository {

	/**
	 * How many characters of the value hash name the lock bucket.
	 *
	 * Eight hex characters is 65,536 buckets, which is the point of them. A
	 * lock keyed on the whole hash would add a permanent row per person and
	 * grow the lock table with the customer base; a bucket has a hard ceiling,
	 * and two people colliding in one only means that one briefly waits for the
	 * other, which is what a lock is for.
	 */
	private const LOCK_BUCKET_CHARS = 8;

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::IDENTITIES;
	}

	/**
	 * The prefixed table name, for callers that need to join against it.
	 *
	 * @return string
	 */
	public function table_name(): string {
		return $this->table();
	}

	/**
	 * The lock key that guards creating a customer for this identity.
	 *
	 * Deliberately short. recoveryflow_locks.lock_key is varchar(64) and is the
	 * PRIMARY KEY, and a sha256 hash is already 64 characters, so any prefix at
	 * all would overflow the column and be truncated -- silently, on a
	 * connection that is not in strict mode. Two identities sharing a truncated
	 * prefix would then share a lock without anybody noticing.
	 *
	 * @param string $value_hash Keyed hash of the identity value.
	 * @return string
	 */
	public static function lock_key( string $value_hash ): string {
		return 'id:' . substr( $value_hash, 0, self::LOCK_BUCKET_CHARS );
	}

	/**
	 * Hash a value the way this table stores it.
	 *
	 * The kind is deliberately NOT part of the hashed material, even though
	 * that would be the tidier construction. The rest of the plugin already
	 * hashes an identifier as Hash_Key::hash( normalised value ) -- the order
	 * observer looks a buyer up with Hash_Key::email(), and the phone hash is a
	 * plain hash of the E.164 form -- and a hash that quietly disagreed with
	 * those would not error, it would simply never match, which is the kind of
	 * bug that presents as "returning customers are not recognised".
	 *
	 * Nothing is lost by it: every lookup filters on kind as well as hash, so
	 * two kinds sharing a value never collide in practice.
	 *
	 * @param string $kind  Identity kind.
	 * @param string $value Raw value.
	 * @return string Keyed hash, or '' when the value is unusable.
	 */
	public static function hash_for( string $kind, string $value ): string {
		return Hash_Key::hash( Identity::normalize_value( $kind, $value ) );
	}

	/**
	 * Find the customer holding this identity.
	 *
	 * For a weak kind more than one customer may hold it, and that is not an
	 * error: two people can share an inbox. An ambiguous match is treated as no
	 * match, so the caller creates a fresh customer rather than guessing which
	 * of two strangers is checking out. This is the rule the platform applies to
	 * its own contacts.
	 *
	 * @param string $kind       Identity kind.
	 * @param string $value_hash Keyed hash of the value.
	 * @return int|null Customer id, or null when there is no unambiguous one.
	 */
	public function find_customer_id( string $kind, string $value_hash ): ?int {
		if ( '' === $kind || '' === $value_hash ) {
			return null;
		}

		$rows = $this->many(
			$this->db()->prepare(
				'SELECT DISTINCT customer_id FROM `' . $this->table() . '` WHERE kind = %s AND value_hash = %s LIMIT 2', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$kind,
				$value_hash
			)
		);

		if ( 1 !== count( $rows ) ) {
			return null;
		}

		return (int) $rows[0]['customer_id'];
	}

	/**
	 * Every identity belonging to a customer.
	 *
	 * @param int $customer_id Customer id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_customer( int $customer_id ): array {
		if ( $customer_id <= 0 ) {
			return array();
		}

		return $this->many(
			$this->db()->prepare(
				'SELECT * FROM `' . $this->table() . '` WHERE customer_id = %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$customer_id
			)
		);
	}

	/**
	 * Attach an identity to a customer, or return whoever already holds it.
	 *
	 * For a strong kind this is the race guard the old UNIQUE index on the
	 * customer's phone column used to provide: the insert is an INSERT IGNORE,
	 * so two requests enrolling the same number produce one row, and the loser
	 * is handed the winner's customer instead of an error.
	 *
	 * A weak kind has no such index by design, so this can genuinely create a
	 * second row for the same address. That is correct -- it is how two people
	 * sharing an inbox both get stored -- but it means the caller must not rely
	 * on this method to serialise anything for an email. Identity_Resolver takes
	 * a lock around the create path instead.
	 *
	 * @param int    $customer_id Customer to attach to.
	 * @param string $kind        Identity kind.
	 * @param string $value       Raw value.
	 * @param string $status      One of the Identity STATUS_ constants.
	 * @param string $source      Where the value came from.
	 * @return int Customer id now holding this identity, or 0 on failure.
	 */
	public function attach( int $customer_id, string $kind, string $value, string $status = Identity::STATUS_UNKNOWN, string $source = '' ): int {
		if ( $customer_id <= 0 || ! Identity::is_kind( $kind ) ) {
			return 0;
		}

		$normalized = Identity::normalize_value( $kind, $value );
		$value_hash = self::hash_for( $kind, $value );

		if ( '' === $value_hash ) {
			return 0;
		}

		$existing = $this->find_owner_of( $customer_id, $kind, $value_hash );

		if ( null !== $existing ) {
			return $existing;
		}

		$now = $this->clock()->now();

		$this->insert_ignore(
			array(
				'customer_id'       => $customer_id,
				'kind'              => $kind,
				'value_hash'        => $value_hash,
				'unique_value_hash' => Identity::is_strong( $kind ) ? $value_hash : null,
				'value_raw'         => $normalized,
				'status'            => $status,
				'source'            => '' === $source ? null : $source,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);

		/*
		 * The insert may have been ignored because a concurrent request won the
		 * unique index. Re-reading is what turns that loss into the winner's
		 * customer rather than a failure.
		 */
		$owner = $this->find_customer_id( $kind, $value_hash );

		return null === $owner ? $customer_id : $owner;
	}

	/**
	 * Whether this customer, or another, already holds this identity.
	 *
	 * @param int    $customer_id Customer being attached to.
	 * @param string $kind        Identity kind.
	 * @param string $value_hash  Keyed hash of the value.
	 * @return int|null Owning customer id, or null when nobody holds it.
	 */
	private function find_owner_of( int $customer_id, string $kind, string $value_hash ): ?int {
		$mine = $this->one(
			$this->db()->prepare(
				'SELECT customer_id FROM `' . $this->table() . '` WHERE kind = %s AND value_hash = %s AND customer_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$kind,
				$value_hash,
				$customer_id
			)
		);

		if ( null !== $mine ) {
			return $customer_id;
		}

		return Identity::is_strong( $kind ) ? $this->find_customer_id( $kind, $value_hash ) : null;
	}

	/**
	 * Forget the values, keep the hashes.
	 *
	 * An erasure must not resurrect a suppression. The consent ledger is keyed
	 * by the identity hash, so the hash has to survive: what is removed is the
	 * readable value and the link to the person, not the ability to recognise a
	 * refusal if the same number is entered again.
	 *
	 * @param int $customer_id Customer being anonymised.
	 * @return int Rows affected.
	 */
	public function anonymize( int $customer_id ): int {
		if ( $customer_id <= 0 ) {
			return 0;
		}

		return $this->update(
			array(
				'value_raw'  => null,
				'updated_at' => $this->clock()->now(),
			),
			array( 'customer_id' => $customer_id )
		);
	}
}
