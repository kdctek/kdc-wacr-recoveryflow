<?php
/**
 * Recovery event storage.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Database\Table_Names;
use WAcr\RecoveryFlow\Support\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the one row that represents an unfinished conversion.
 *
 * The write path is a single upsert. A shopper adding four things to a basket
 * must not produce four rows, and the adapter runs inside their page request,
 * so the cost of tracking has to be one indexed statement -- INSERT ... ON
 * DUPLICATE KEY UPDATE against the (source_id, dedupe_key) index, with
 * LAST_INSERT_ID(id) so the caller learns the row id either way.
 *
 * Closing a row renames its dedupe key. That is what keeps the unique index
 * honest: the shopper's next basket needs a fresh open row, and without the
 * rename the upsert would keep reviving a cart that was already bought.
 */
final class Event_Repository extends Repository {

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::EVENTS;
	}

	/**
	 * Record what an adapter saw, creating or updating the open row.
	 *
	 * @param Event_Draft $draft What the adapter reported.
	 * @return int The event row id, or 0 if the write failed.
	 */
	public function upsert( Event_Draft $draft ): int {
		$now      = $this->clock->now();
		$activity = '' === $draft->last_activity_at ? $now : $draft->last_activity_at;
		$table    = $this->table();

		$items    = array() === $draft->items ? null : wp_json_encode( $draft->items );
		$metadata = array() === $draft->metadata ? null : wp_json_encode( $draft->metadata );

		$sql = "INSERT INTO `{$table}`
			(event_uid, source_id, source_type, dedupe_key, session_key, external_id,
			 currency, amount, item_count, status, items_json, metadata_json,
			 last_activity_at, created_at, updated_at)
			VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				session_key = VALUES(session_key),
				external_id = VALUES(external_id),
				currency = VALUES(currency),
				amount = VALUES(amount),
				item_count = VALUES(item_count),
				items_json = VALUES(items_json),
				metadata_json = VALUES(metadata_json),
				last_activity_at = VALUES(last_activity_at),
				updated_at = VALUES(updated_at),
				id = LAST_INSERT_ID(id)";

		$rows = $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				$sql,
				Uuid::v4(),
				substr( $draft->source_id, 0, 32 ),
				substr( $draft->source_type, 0, 32 ),
				substr( $draft->dedupe_key, 0, 191 ),
				'' === $draft->session_key ? null : substr( $draft->session_key, 0, 191 ),
				'' === $draft->external_id ? null : substr( $draft->external_id, 0, 191 ),
				'' === $draft->currency ? null : $draft->currency,
				$draft->amount,
				$draft->item_count,
				Recovery_Event::OPEN,
				$items,
				$metadata,
				$activity,
				$now,
				$now
			)
		);

		return 0 === $rows ? 0 : (int) $this->db()->insert_id;
	}

	/**
	 * Fetch by row id.
	 *
	 * @param int $id Event id.
	 * @return Recovery_Event|null
	 */
	public function find( int $id ): ?Recovery_Event {
		$row = $this->find_by_id( $id );

		return null === $row ? null : Recovery_Event::from_row( $row );
	}

	/**
	 * Fetch by public identifier.
	 *
	 * @param string $uid Event uid.
	 * @return Recovery_Event|null
	 */
	public function find_by_uid( string $uid ): ?Recovery_Event {
		$row = $this->find_one_by( 'event_uid', $uid );

		return null === $row ? null : Recovery_Event::from_row( $row );
	}

	/**
	 * Fetch the open row for an adapter's key.
	 *
	 * @param string $source_id  Adapter id.
	 * @param string $dedupe_key Adapter key.
	 * @return Recovery_Event|null
	 */
	public function find_open( string $source_id, string $dedupe_key ): ?Recovery_Event {
		$table = $this->table();

		$row = $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE source_id = %s AND dedupe_key = %s LIMIT 1",
				$source_id,
				$dedupe_key
			)
		);

		return null === $row ? null : Recovery_Event::from_row( $row );
	}

	/**
	 * Fetch the most recent open row for a session.
	 *
	 * @param string $source_id   Adapter id.
	 * @param string $session_key Session identifier.
	 * @return Recovery_Event|null
	 */
	public function find_open_by_session( string $source_id, string $session_key ): ?Recovery_Event {
		if ( '' === $session_key ) {
			return null;
		}

		$table = $this->table();

		$row = $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}`
				WHERE source_id = %s AND session_key = %s AND status = %s
				ORDER BY id DESC LIMIT 1",
				$source_id,
				$session_key,
				Recovery_Event::OPEN
			)
		);

		return null === $row ? null : Recovery_Event::from_row( $row );
	}

	/**
	 * Move an open event onto a new key, keeping its contents.
	 *
	 * A guest who logs in mid-visit is re-keyed by the source they came from,
	 * and without this the next write would create a second open row: the same
	 * basket, tracked twice, messaged twice. The update is conditional on the
	 * old key so two requests racing through a login produce one move.
	 *
	 * @param string $source_id   Adapter id.
	 * @param string $old_key     The key the event currently has.
	 * @param string $new_key     The key it should have.
	 * @param string $new_session Session identifier to record, or empty to leave it.
	 * @return bool Whether this caller moved it.
	 */
	public function rekey( string $source_id, string $old_key, string $new_key, string $new_session = '' ): bool {
		if ( '' === $old_key || '' === $new_key || $old_key === $new_key ) {
			return false;
		}

		// If the destination key already has an open row, the visitor has two
		// baskets and merging them is the source's business, not ours; the old
		// row is closed instead so it stops being messaged.
		if ( null !== $this->find_open( $source_id, $new_key ) ) {
			$existing = $this->find_open( $source_id, $old_key );

			return null !== $existing && $this->close( $existing->id, Recovery_Event::INVALID, 'superseded' );
		}

		$table = $this->table();

		return 1 === $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}`
				SET dedupe_key = %s,
					session_key = CASE WHEN %s = '' THEN session_key ELSE %s END,
					updated_at = %s
				WHERE source_id = %s AND dedupe_key = %s AND status = %s",
				substr( $new_key, 0, 191 ),
				$new_session,
				$new_session,
				$this->clock->now(),
				$source_id,
				$old_key,
				Recovery_Event::OPEN
			)
		);
	}

	/**
	 * Attach a resolved customer to an event.
	 *
	 * @param int $event_id    Event id.
	 * @param int $customer_id Customer id.
	 * @return bool
	 */
	public function attach_customer( int $event_id, int $customer_id ): bool {
		return 0 !== $this->update(
			array(
				'customer_id' => $customer_id,
				'updated_at'  => $this->clock->now(),
			),
			array( 'id' => $event_id )
		);
	}

	/**
	 * Attach a journey, but only if the event does not already have one.
	 *
	 * The conditional is the point: two evaluation runs may both have created a
	 * journey row for this event, and only one of them may claim it.
	 *
	 * @param int $event_id   Event id.
	 * @param int $journey_id Journey id.
	 * @return bool Whether this caller attached it.
	 */
	public function attach_journey( int $event_id, int $journey_id ): bool {
		$table = $this->table();

		return 1 === $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}` SET journey_id = %d, updated_at = %s WHERE id = %d AND journey_id IS NULL",
				$journey_id,
				$this->clock->now(),
				$event_id
			)
		);
	}

	/**
	 * Close an event and free its dedupe key for the next one.
	 *
	 * @param int    $event_id Event id.
	 * @param string $status   One of the Recovery_Event constants.
	 * @param string $reason   Short machine-readable reason.
	 * @return bool
	 */
	public function close( int $event_id, string $status, string $reason = '' ): bool {
		$table = $this->table();
		$now   = $this->clock->now();

		// The key is renamed rather than cleared so it stays unique and still
		// says what it used to be, and it is truncated first so the suffix
		// cannot push the column over its length.
		$completed = Recovery_Event::COMPLETED === $status ? $now : null;

		return 1 === $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}`
				SET status = %s,
					status_reason = %s,
					dedupe_key = CONCAT(LEFT(dedupe_key, 160), '#', id),
					completed_at = %s,
					updated_at = %s
				WHERE id = %d AND status = %s",
				substr( $status, 0, 12 ),
				'' === $reason ? null : substr( $reason, 0, 32 ),
				$completed,
				$now,
				$event_id,
				Recovery_Event::OPEN
			)
		);
	}

	/**
	 * Events that have gone quiet long enough to be worth evaluating.
	 *
	 * Only rows that already have a customer are returned: an event nobody can
	 * be messaged about is not a candidate, and filtering here keeps the
	 * evaluate stage from loading rows it would immediately discard.
	 *
	 * @param string $inactive_since UTC datetime; rows quieter than this qualify.
	 * @param int    $limit          Maximum rows.
	 * @return array<int,Recovery_Event>
	 */
	public function due_for_evaluation( string $inactive_since, int $limit = 200 ): array {
		$table = $this->table();

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}`
				WHERE status = %s AND journey_id IS NULL AND customer_id IS NOT NULL AND last_activity_at <= %s
				ORDER BY last_activity_at ASC
				LIMIT %d",
				Recovery_Event::OPEN,
				$inactive_since,
				max( 1, $limit )
			)
		);

		return array_map( array( Recovery_Event::class, 'from_row' ), $rows );
	}

	/**
	 * Expire open events that were never identified.
	 *
	 * A busy store produces far more anonymous baskets than identified ones and
	 * none of them can ever be messaged, so they are closed in bulk by one
	 * indexed statement rather than examined one at a time.
	 *
	 * @param string $older_than UTC datetime.
	 * @param int    $limit      Maximum rows per batch.
	 * @return int Rows closed.
	 */
	public function expire_unidentified( string $older_than, int $limit = 500 ): int {
		$table = $this->table();

		/*
		 * Chosen rather than written as one UPDATE ... LIMIT, measured against
		 * a hundred thousand events. The predicate here is the exact shape of
		 * the `evaluate` index -- status, then journey_id, then
		 * last_activity_at -- but MySQL would not use it for the UPDATE. It
		 * chose `retention` (status, updated_at) instead, which answers only
		 * the first of the three conditions, and examined seventy thousand rows
		 * every pass to close five hundred. As a SELECT it reads the right
		 * index and stops at the limit; the UPDATE is then addressed by primary
		 * key.
		 *
		 * It removes an UPDATE ... LIMIT with no ORDER BY as a side effect,
		 * which MySQL flags as unsafe for statement-based replication because
		 * two servers may pick different rows.
		 */
		$ids = $this->ids(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT id FROM `{$table}`
				WHERE status = %s AND journey_id IS NULL AND last_activity_at < %s
				ORDER BY last_activity_at ASC
				LIMIT %d",
				Recovery_Event::OPEN,
				$older_than,
				max( 1, $limit )
			)
		);

		if ( array() === $ids ) {
			return 0;
		}

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; ids are integers cast above.
			$this->db()->prepare(
				"UPDATE `{$table}`
				SET status = %s,
					status_reason = %s,
					dedupe_key = CONCAT(LEFT(dedupe_key, 160), '#', id),
					updated_at = %s
				WHERE id IN (" . implode( ',', $ids ) . ')
					AND status = %s AND journey_id IS NULL',
				Recovery_Event::EXPIRED,
				'max_age',
				$this->clock->now(),
				Recovery_Event::OPEN
			)
		);
	}

	/**
	 * How many open events there are, for the status screen.
	 *
	 * @return int
	 */
	public function count_open(): int {
		return $this->count_by( 'status', Recovery_Event::OPEN );
	}

	/**
	 * Delete closed events that are past their retention date.
	 *
	 * @param string $before UTC datetime.
	 * @param int    $limit  Maximum rows per batch.
	 * @return int Rows removed.
	 */
	public function prune_closed( string $before, int $limit = 500 ): int {
		$table = $this->table();

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"DELETE FROM `{$table}` WHERE status <> %s AND journey_id IS NULL AND updated_at < %s LIMIT %d",
				Recovery_Event::OPEN,
				$before,
				max( 1, $limit )
			)
		);
	}

	/**
	 * Empty the item snapshot on an event, keeping the totals.
	 *
	 * Used by erasure and retention: what somebody bought is personal, what it
	 * was worth is a business record.
	 *
	 * @param int $event_id Event id.
	 * @return bool
	 */
	public function strip_items( int $event_id ): bool {
		return 0 !== $this->update(
			array(
				'items_json'    => null,
				'metadata_json' => null,
				'session_key'   => null,
				'updated_at'    => $this->clock->now(),
			),
			array( 'id' => $event_id )
		);
	}
}
