<?php
/**
 * Send-attempt storage.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Database\Table_Names;

defined( 'ABSPATH' ) || exit;

/**
 * The ledger that stands in for the messaging API's missing idempotency key.
 *
 * Reserving is the whole mechanism: the row goes in under a UNIQUE key BEFORE
 * the HTTP call, and the insert reports whether this caller is the one that got it.
 * A caller that did not get the row must not send, however certain it is that
 * the message has not gone out -- it is looking at a row somebody else created
 * moments ago, and the only safe reading is that a send is already in flight.
 *
 * The inverse matters too: a row that is stuck in 'sending' is not evidence
 * that nothing was sent. It is evidence that a request was started and its
 * outcome was never learned, which is resolved by reading the conversation
 * back, never by sending again.
 */
final class Attempt_Repository extends Repository {

	/**
	 * How long a 'sending' row may sit before it is treated as unknown.
	 */
	public const STALE_SENDING_SECONDS = 120;

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::ATTEMPTS;
	}

	/**
	 * Claim the right to perform one send.
	 *
	 * @param array<string,mixed> $data Column values, including idempotency_key.
	 * @return Attempt|null The reserved row, or null if somebody else already holds it.
	 */
	public function reserve( array $data ): ?Attempt {
		$now = $this->clock->now();

		$data['created_at']         = $now;
		$data['sending_started_at'] = $now;
		$data['status']             = $data['status'] ?? Attempt::SENDING;

		if ( ! isset( $data['scheduled_at'] ) ) {
			$data['scheduled_at'] = $now;
		}

		$id = $this->insert_ignore( $data );

		if ( 0 === $id ) {
			return null;
		}

		return $this->find( $id );
	}

	/**
	 * Fetch by row id.
	 *
	 * @param int $id Attempt id.
	 * @return Attempt|null
	 */
	public function find( int $id ): ?Attempt {
		$row = $this->find_by_id( $id );

		return null === $row ? null : Attempt::from_row( $row );
	}

	/**
	 * Fetch by idempotency key.
	 *
	 * @param string $key Idempotency key.
	 * @return Attempt|null
	 */
	public function find_by_key( string $key ): ?Attempt {
		$row = $this->find_one_by( 'idempotency_key', $key );

		return null === $row ? null : Attempt::from_row( $row );
	}

	/**
	 * Fetch by the hash of a recovery token.
	 *
	 * The plaintext token never reaches the database: the public endpoint
	 * hashes what it was given and looks the hash up, so a leaked database
	 * yields no working links.
	 *
	 * @param string $token_hash Hash of the token.
	 * @return Attempt|null
	 */
	public function find_by_token_hash( string $token_hash ): ?Attempt {
		if ( '' === $token_hash ) {
			return null;
		}

		$row = $this->find_one_by( 'token_hash', $token_hash );

		return null === $row ? null : Attempt::from_row( $row );
	}

	/**
	 * Every attempt on a journey, oldest first.
	 *
	 * @param int $journey_id Journey id.
	 * @param int $limit      Maximum rows.
	 * @return array<int,Attempt>
	 */
	public function for_journey( int $journey_id, int $limit = 50 ): array {
		$table = $this->table();

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE journey_id = %d ORDER BY id ASC LIMIT %d",
				$journey_id,
				max( 1, $limit )
			)
		);

		return array_map( array( Attempt::class, 'from_row' ), $rows );
	}

	/**
	 * How many tries a given step has already had.
	 *
	 * @param int $journey_id Journey id.
	 * @param int $step_index Step.
	 * @return int
	 */
	public function attempts_for_step( int $journey_id, int $step_index ): int {
		$table = $this->table();

		return (int) $this->scalar(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE journey_id = %d AND step_index = %d",
				$journey_id,
				$step_index
			)
		);
	}

	/**
	 * Record that the message was accepted.
	 *
	 * @param int                 $id    Attempt id.
	 * @param array<string,mixed> $patch Ids returned by the API.
	 * @return bool
	 */
	public function mark_sent( int $id, array $patch = array() ): bool {
		$patch['status']          = Attempt::SENT;
		$patch['sent_at']         = $this->clock->now();
		$patch['reconcile_after'] = null;

		return 0 !== $this->update( $patch, array( 'id' => $id ) );
	}

	/**
	 * Record that the message definitely did not go out.
	 *
	 * @param int    $id      Attempt id.
	 * @param string $code    Machine-readable failure code.
	 * @param string $message Administrator-facing message. Must contain no customer data.
	 * @return bool
	 */
	public function mark_failed( int $id, string $code, string $message = '' ): bool {
		return 0 !== $this->update(
			array(
				'status'           => Attempt::FAILED,
				'error_code'       => substr( $code, 0, 32 ),
				'error_message'    => '' === $message ? null : substr( $message, 0, 255 ),
				'token_revoked_at' => $this->clock->now(),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Record that the outcome of the request is not known.
	 *
	 * The token is deliberately left alive. If the message did go out, the
	 * customer has a working link; revoking it here would break the one thing
	 * that might still recover the sale.
	 *
	 * @param int    $id      Attempt id.
	 * @param string $code    Machine-readable code.
	 * @param int    $seconds How long to wait before finding out.
	 * @return bool
	 */
	public function mark_unknown( int $id, string $code, int $seconds = 120 ): bool {
		return 0 !== $this->update(
			array(
				'status'          => Attempt::UNKNOWN,
				'error_code'      => substr( $code, 0, 32 ),
				'reconcile_after' => $this->clock->offset( $seconds ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Record a delivery status learned from reading the conversation back.
	 *
	 * @param int    $id     Attempt id.
	 * @param string $status One of the Attempt constants.
	 * @return bool
	 */
	public function mark_status( int $id, string $status ): bool {
		return 0 !== $this->update(
			array(
				'status'            => $status,
				'status_checked_at' => $this->clock->now(),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Attempts whose outcome is still unresolved and due to be looked into.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,Attempt>
	 */
	public function unresolved( int $limit = 50 ): array {
		$table = $this->table();
		$now   = $this->clock->now();

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}`
				WHERE (status = %s AND reconcile_after IS NOT NULL AND reconcile_after <= %s)
					OR (status = %s AND sending_started_at IS NOT NULL AND sending_started_at < %s)
				ORDER BY id ASC LIMIT %d",
				Attempt::UNKNOWN,
				$now,
				Attempt::SENDING,
				$this->clock->offset( -self::STALE_SENDING_SECONDS ),
				max( 1, $limit )
			)
		);

		return array_map( array( Attempt::class, 'from_row' ), $rows );
	}

	/**
	 * When a customer was last messaged, across every journey.
	 *
	 * Backs the frequency cap. It has to span journeys: a shopper who abandons
	 * two baskets an hour apart is one person, and they should not receive two
	 * reminders because the limit was counted per journey.
	 *
	 * @param int $customer_id Customer id.
	 * @return string|null UTC datetime, or null if never.
	 */
	public function last_sent_for_customer( int $customer_id ): ?string {
		$attempts = $this->table();
		$journeys = Table_Names::get( Table_Names::JOURNEYS );

		return $this->scalar(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- both table names from class constants.
			$this->db()->prepare(
				"SELECT a.sent_at FROM `{$attempts}` AS a
				INNER JOIN `{$journeys}` AS j ON j.id = a.journey_id
				WHERE j.customer_id = %d AND a.sent_at IS NOT NULL
				ORDER BY a.sent_at DESC LIMIT 1",
				$customer_id
			)
		);
	}

	/**
	 * Withdraw every recovery link on a journey.
	 *
	 * @param int $journey_id Journey id.
	 * @return int Rows changed.
	 */
	public function revoke_tokens( int $journey_id ): int {
		$table = $this->table();

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}` SET token_revoked_at = %s WHERE journey_id = %d AND token_hash IS NOT NULL AND token_revoked_at IS NULL",
				$this->clock->now(),
				$journey_id
			)
		);
	}

	/**
	 * Remove the token hashes on expired links.
	 *
	 * @param int $limit Maximum rows per batch.
	 * @return int Rows changed.
	 */
	public function purge_expired_tokens( int $limit = 500 ): int {
		$table = $this->table();

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}` SET token_hash = NULL
				WHERE token_hash IS NOT NULL AND token_expires_at IS NOT NULL AND token_expires_at < %s
				LIMIT %d",
				$this->clock->now(),
				max( 1, $limit )
			)
		);
	}

	/**
	 * Find the attempt an inbound message is a reply to.
	 *
	 * @param int    $journey_id Journey id.
	 * @param string $message_id WA.cr message id.
	 * @return Attempt|null
	 */
	public function find_by_message_id( int $journey_id, string $message_id ): ?Attempt {
		if ( '' === $message_id ) {
			return null;
		}

		$table = $this->table();

		$row = $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE journey_id = %d AND wacr_message_id = %s LIMIT 1",
				$journey_id,
				$message_id
			)
		);

		return null === $row ? null : Attempt::from_row( $row );
	}
}
