<?php
/**
 * The consent ledger.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Database\Table_Names;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only evidence that a message was allowed.
 *
 * Nothing here is ever updated or deleted. The question a regulator asks is not
 * "may you message this person" but "could you show that you were allowed to,
 * on the day you did", and only a history answers that. The latest row wins for
 * decisions; the rows behind it are the record.
 *
 * Rows are keyed on the phone hash rather than the customer id, so the ledger
 * outlives the customer record. That is what lets an erased customer stay
 * suppressed: the person is forgotten, the refusal is not.
 */
final class Consent_Repository extends Repository {

	public const GRANTED    = 'granted';
	public const DENIED     = 'denied';
	public const WITHDRAWN  = 'withdrawn';
	public const SUPPRESSED = 'suppressed';

	/**
	 * Statuses that mean "do not message, and do not ask again".
	 */
	public const BLOCKING = array( self::WITHDRAWN, self::SUPPRESSED );

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::CONSENTS;
	}

	/**
	 * Append a consent decision.
	 *
	 * @param string   $phone_hash   Keyed hash of the number the decision is about.
	 * @param int|null $customer_id  Customer, when one is known.
	 * @param string   $status       One of the class constants.
	 * @param string   $source       Where the decision came from, e.g. 'checkout_classic'.
	 * @param string   $text_version Which wording was shown.
	 * @param string   $ip_hash      Keyed hash of the IP address, or empty.
	 * @return int New row id.
	 */
	public function record( string $phone_hash, ?int $customer_id, string $status, string $source, string $text_version = '', string $ip_hash = '' ): int {
		if ( '' === $phone_hash ) {
			return 0;
		}

		return $this->insert(
			array(
				'phone_hash'   => $phone_hash,
				'customer_id'  => $customer_id,
				'status'       => substr( $status, 0, 12 ),
				'source'       => substr( $source, 0, 32 ),
				'text_version' => '' === $text_version ? null : substr( $text_version, 0, 16 ),
				'ip_hash'      => '' === $ip_hash ? null : $ip_hash,
				'created_at'   => $this->clock->now(),
			),
			array()
		);
	}

	/**
	 * The decision that currently applies to a number.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @return array<string,mixed>|null
	 */
	public function latest( string $phone_hash ): ?array {
		if ( '' === $phone_hash ) {
			return null;
		}

		$table = $this->table();

		return $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE phone_hash = %s ORDER BY id DESC LIMIT 1",
				$phone_hash
			)
		);
	}

	/**
	 * The current status for a number, or 'unknown' if it was never asked.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @return string
	 */
	public function status( string $phone_hash ): string {
		$row = $this->latest( $phone_hash );

		return null === $row ? Customer::CONSENT_UNKNOWN : (string) $row['status'];
	}

	/**
	 * Whether this number must not be messaged, whatever else is true.
	 *
	 * A suppression is checked before every send and is never overridden by a
	 * later checkout tick-box: somebody who has said stop has to say start
	 * again deliberately, not by filling in a form that happens to be pre-filled.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @return bool
	 */
	public function is_suppressed( string $phone_hash ): bool {
		return in_array( $this->status( $phone_hash ), self::BLOCKING, true );
	}

	/**
	 * Every decision recorded for a number, newest first.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @param int    $limit      Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function history( string $phone_hash, int $limit = 50 ): array {
		if ( '' === $phone_hash ) {
			return array();
		}

		$table = $this->table();

		return $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE phone_hash = %s ORDER BY id DESC LIMIT %d",
				$phone_hash,
				max( 1, $limit )
			)
		);
	}

	/**
	 * Detach a customer id from the ledger without losing the decisions.
	 *
	 * Called during erasure: the rows stay so the suppression keeps working,
	 * but they stop pointing at a person.
	 *
	 * @param int $customer_id Customer id.
	 * @return int Rows changed.
	 */
	public function detach_customer( int $customer_id ): int {
		return $this->update( array( 'customer_id' => null ), array( 'customer_id' => $customer_id ) );
	}
}
