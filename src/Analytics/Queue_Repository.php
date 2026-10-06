<?php
/**
 * Journey milestones waiting to be reported to GA4.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Analytics;

use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Database\Table_Names;

defined( 'ABSPATH' ) || exit;

/**
 * The analytics queue: one row per journey per milestone, sent at most once.
 *
 * **At most once, not at least once.** GA4 does not de-duplicate a
 * Measurement Protocol event, so a report sent twice is counted twice -- a
 * recovery reported twice is a recovery that GA4 says happened twice. Every
 * choice here prefers losing a report to doubling one:
 *
 * - the UNIQUE (journey_id, event_name) key means a milestone that fires
 *   twice is queued once;
 * - a row is moved to `sending` before its request goes out, and nothing
 *   ever moves a row out of `sending` except the answer to that request, so
 *   a run that dies mid-request leaves the row where no later run will send
 *   it again;
 * - a failure that may have reached Google -- a timeout, a reset -- is
 *   recorded as `unknown` and never retried.
 *
 * The rows hold no personal data: a journey id, a milestone name and a time.
 * The client id is read from the event row at the moment of sending.
 */
final class Queue_Repository extends Repository {

	/**
	 * Waiting to be sent.
	 */
	public const PENDING = 'pending';

	/**
	 * Its request is in flight, or was when the run that sent it died.
	 */
	public const SENDING = 'sending';

	/**
	 * Google accepted it.
	 */
	public const SENT = 'sent';

	/**
	 * Its request may or may not have arrived; never retried.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Too old to send: GA4 would re-stamp it to the wrong day.
	 */
	public const STALE = 'stale';

	/**
	 * Not sendable: no client id (never captured, or erased), or refused.
	 */
	public const DROPPED = 'dropped';

	/**
	 * How many times a request that certainly did not arrive is retried.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * The table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::ANALYTICS_QUEUE;
	}

	/**
	 * Queue one milestone for one journey.
	 *
	 * @param int    $journey_id  Journey id.
	 * @param string $event_name  GA4 event name.
	 * @param string $occurred_at When the milestone happened, UTC.
	 * @return bool Whether this call queued it; false when it was already queued.
	 */
	public function enqueue( int $journey_id, string $event_name, string $occurred_at ): bool {
		return $this->insert_ignore(
			array(
				'journey_id'  => $journey_id,
				'event_name'  => substr( $event_name, 0, 40 ),
				'occurred_at' => '' === $occurred_at ? $this->clock->now() : $occurred_at,
				'status'      => self::PENDING,
				'attempts'    => 0,
				'created_at'  => $this->clock->now(),
			)
		) > 0;
	}

	/**
	 * Mark pending rows whose milestone is older than a cut-off as stale.
	 *
	 * @param string $before UTC datetime.
	 * @return int Rows marked.
	 */
	public function mark_stale( string $before ): int {
		$table = $this->table();

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}` SET status = %s, claim_token = NULL, claimed_until = NULL WHERE status = %s AND occurred_at < %s",
				self::STALE,
				self::PENDING,
				$before
			)
		);
	}

	/**
	 * Take a lease on a batch of pending rows.
	 *
	 * The stage already runs under a lock; the lease is the second guarantee,
	 * for a run that outlives its lock. Same shape as the journey claim: select
	 * candidates, then a conditional UPDATE that only one run can win.
	 *
	 * @param string $claim_token This run's ownership token.
	 * @param int    $limit       Maximum rows.
	 * @param int    $ttl         Seconds the lease lasts.
	 * @return int Rows claimed.
	 */
	public function claim( string $claim_token, int $limit, int $ttl ): int {
		$table = $this->table();
		$now   = $this->clock->now();

		$ids = $this->ids(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT id FROM `{$table}` WHERE status = %s AND (claimed_until IS NULL OR claimed_until < %s) ORDER BY id ASC LIMIT %d",
				self::PENDING,
				$now,
				max( 1, $limit )
			)
		);

		if ( array() === $ids ) {
			return 0;
		}

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; ids are integers; every value bound.
			$this->db()->prepare(
				"UPDATE `{$table}` SET claim_token = %s, claimed_until = %s
					WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') AND status = %s AND (claimed_until IS NULL OR claimed_until < %s)',
				$claim_token,
				$this->clock->offset( $ttl ),
				self::PENDING,
				$now
			)
		);
	}

	/**
	 * The rows this run holds a lease on.
	 *
	 * @param string $claim_token Ownership token.
	 * @param int    $limit       Maximum rows.
	 * @return array<int,array{id:int,journey_id:int,event_name:string,occurred_at:string,attempts:int}>
	 */
	public function claimed( string $claim_token, int $limit ): array {
		$table = $this->table();

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT id, journey_id, event_name, occurred_at, attempts FROM `{$table}` WHERE claim_token = %s AND status = %s ORDER BY id ASC LIMIT %d",
				$claim_token,
				self::PENDING,
				max( 1, $limit )
			)
		);

		$out = array();

		foreach ( $rows as $row ) {
			$out[] = array(
				'id'          => (int) $row['id'],
				'journey_id'  => (int) $row['journey_id'],
				'event_name'  => (string) $row['event_name'],
				'occurred_at' => (string) $row['occurred_at'],
				'attempts'    => (int) $row['attempts'],
			);
		}

		return $out;
	}

	/**
	 * Move rows to `sending`, but only rows this run still holds.
	 *
	 * @param int[]  $ids         Row ids.
	 * @param string $claim_token Ownership token.
	 * @return int Rows moved. Fewer than asked means another run took some.
	 */
	public function begin_sending( array $ids, string $claim_token ): int {
		if ( array() === $ids ) {
			return 0;
		}

		$table = $this->table();

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; ids are integers; every value bound.
			$this->db()->prepare(
				"UPDATE `{$table}` SET status = %s, attempts = attempts + 1
					WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') AND status = %s AND claim_token = %s',
				self::SENDING,
				self::PENDING,
				$claim_token
			)
		);
	}

	/**
	 * Record the outcome for rows.
	 *
	 * @param int[]  $ids    Row ids.
	 * @param string $status One of the status constants.
	 * @return int Rows written.
	 */
	public function finish( array $ids, string $status ): int {
		if ( array() === $ids ) {
			return 0;
		}

		$table   = $this->table();
		$sent_at = self::SENT === $status ? $this->clock->now() : null;

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; ids are integers; every value bound.
			$this->db()->prepare(
				"UPDATE `{$table}` SET status = %s, sent_at = " . ( null === $sent_at ? 'NULL' : '%s' ) . ', claim_token = NULL, claimed_until = NULL
					WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')',
				null === $sent_at ? array( $status ) : array( $status, $sent_at )
			)
		);
	}

	/**
	 * Put rows whose request certainly did not arrive back in the queue.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows returned to pending.
	 */
	public function retry( array $ids ): int {
		if ( array() === $ids ) {
			return 0;
		}

		$table = $this->table();

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; ids are integers; every value bound.
			$this->db()->prepare(
				"UPDATE `{$table}` SET status = IF(attempts >= %d, %s, %s), claim_token = NULL, claimed_until = NULL
					WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') AND status = %s',
				self::MAX_ATTEMPTS,
				self::DROPPED,
				self::PENDING,
				self::SENDING
			)
		);
	}

	/**
	 * Give back any lease this run still holds.
	 *
	 * @param string $claim_token Ownership token.
	 * @return int Rows released.
	 */
	public function release( string $claim_token ): int {
		return $this->update(
			array(
				'claim_token'   => null,
				'claimed_until' => null,
			),
			array( 'claim_token' => $claim_token )
		);
	}

	/**
	 * How many rows are waiting.
	 *
	 * @return int
	 */
	public function count_pending(): int {
		return $this->count_by( 'status', self::PENDING );
	}

	/**
	 * Delete rows created before a cut-off, whatever became of them.
	 *
	 * Thirty days is far past anything that could still be sent -- GA4 takes
	 * nothing older than 72 hours -- so a row that old is history either way.
	 *
	 * @param string $before UTC datetime.
	 * @param int    $limit  Maximum rows per batch.
	 * @return int Rows removed.
	 */
	public function prune( string $before, int $limit = 500 ): int {
		return $this->delete_older_than( 'created_at', $before, max( 1, $limit ) );
	}
}
