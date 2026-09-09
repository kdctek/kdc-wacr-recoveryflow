<?php
/**
 * Throwing away what there is no longer a reason to keep.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs\Stages;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Database\Receipt_Repository;
use WAcr\RecoveryFlow\Database\Table_Names;
use WAcr\RecoveryFlow\Jobs\Scheduler_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Runner;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Jobs\Time_Budget;
use WAcr\RecoveryFlow\Recovery\Attempt_Repository;
use WAcr\RecoveryFlow\Privacy\Anonymizer;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The daily clear-out, done in small enough pieces to finish.
 *
 * Keeping a shopper's basket contents for ever is not caution, it is a
 * liability: an abandoned cart says what somebody was about to buy, and there
 * is no business reason to know that six months later. So the item snapshot,
 * the metadata and the session key are removed from finished journeys once the
 * retention period is up, while the amounts and dates stay, because those are
 * the merchant's own trading record rather than anything about a person.
 *
 * The floor of a week is deliberate. A retention period set to a day would
 * throw away the evidence a merchant needs the moment somebody asks why they
 * were messaged, and that question always arrives after the fact.
 *
 * Everything is paged by primary key rather than by offset. An offset walk over
 * a table that is being deleted from skips rows, which on a clear-out means
 * data quietly surviving its retention period for ever.
 */
final class Retention implements Stage_Interface {

	/**
	 * How many rows are handled per statement.
	 */
	private const BATCH = 500;

	/**
	 * How long an event that never identified anybody is kept.
	 */
	private const UNIDENTIFIED_DAYS = 7;

	/**
	 * How long a duplicate-suppression receipt is kept.
	 */
	private const RECEIPT_DAYS = 30;

	/**
	 * The shortest retention period this plugin will honour.
	 */
	private const MINIMUM_DAYS = 7;

	/**
	 * Event storage.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Attempt ledger.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

	/**
	 * Receipt ledger.
	 *
	 * @var Receipt_Repository
	 */
	private Receipt_Repository $receipts;

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * The erasure.
	 *
	 * @var Anonymizer
	 */
	private Anonymizer $anonymizer;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository    $events     Event storage.
	 * @param Attempt_Repository  $attempts   Attempt ledger.
	 * @param Receipt_Repository  $receipts   Receipt ledger.
	 * @param Customer_Repository $customers  Customer storage.
	 * @param Anonymizer          $anonymizer The erasure.
	 * @param Clock               $clock      Clock.
	 */
	public function __construct(
		Event_Repository $events,
		Attempt_Repository $attempts,
		Receipt_Repository $receipts,
		Customer_Repository $customers,
		Anonymizer $anonymizer,
		Clock $clock
	) {
		$this->events     = $events;
		$this->attempts   = $attempts;
		$this->receipts   = $receipts;
		$this->customers  = $customers;
		$this->anonymizer = $anonymizer;
		$this->clock      = $clock;
	}

	/**
	 * The stage's key.
	 *
	 * @return string
	 */
	public function key(): string {
		return Scheduler_Interface::RETENTION;
	}

	/**
	 * The row in the locks table this stage runs under.
	 *
	 * @return string
	 */
	public function lock_key(): string {
		return Scheduler_Interface::RETENTION;
	}

	/**
	 * Run each clear-out in turn, stopping when the budget is spent.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @return Stage_Stats
	 */
	public function run( Time_Budget $budget ): Stage_Stats {
		$stats = new Stage_Stats( $this->key() );
		$limit = Stage_Runner::batch_size( $this->key(), self::BATCH );

		$this->strip_finished_baskets( $budget, $stats, $limit );
		$this->anonymize_finished_customers( $budget, $stats, $limit );
		$this->prune_unidentified_events( $budget, $stats, $limit );
		$this->purge_expired_tokens( $budget, $stats, $limit );
		$this->prune_receipts( $budget, $stats, $limit );
		$this->prune_logs( $budget, $stats, $limit );

		return $stats;
	}

	/**
	 * How long finished recovery data is kept.
	 *
	 * @return int Days.
	 */
	private function retention_days(): int {
		$days = (int) Options::get( 'retention_days', 90 );

		/**
		 * Filters how many days finished recovery data is kept for.
		 *
		 * @param int $days Retention period in days.
		 */
		$days = (int) apply_filters( Hooks::FILTER_RETENTION_DAYS, $days );

		return max( self::MINIMUM_DAYS, $days );
	}

	/**
	 * Remove what a finished journey's basket contained.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @param int         $limit  Rows per batch.
	 * @return void
	 */
	private function strip_finished_baskets( Time_Budget $budget, Stage_Stats $stats, int $limit ): void {
		$before = $this->clock->offset( -$this->retention_days() * DAY_IN_SECONDS );
		$after  = 0;

		while ( $budget->has_time( 2.0 ) ) {
			$ids = $this->finished_event_ids( $before, $after, $limit );

			if ( array() === $ids ) {
				return;
			}

			foreach ( $ids as $row ) {
				$after = max( $after, $row['journey_id'] );

				$this->events->strip_items( $row['event_id'] );

				++$stats->processed;
			}

			if ( count( $ids ) < $limit ) {
				return;
			}
		}

		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * Forget the people whose recovery work finished long ago.
	 *
	 * Stripping the basket is not enough on its own: the customer row still
	 * carries a name, and the identity rows still carry a readable phone number
	 * and email address. Retention that removed what somebody was buying while
	 * keeping who they were would be the wrong half.
	 *
	 * Only customers whose every journey is finished are touched, so somebody
	 * who came back after six months is not anonymised in the middle of being
	 * messaged -- the query enforces that, and it is the condition most easily
	 * left out of one like it.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @param int         $limit  Rows per batch.
	 * @return void
	 */
	private function anonymize_finished_customers( Time_Budget $budget, Stage_Stats $stats, int $limit ): void {
		$before = $this->clock->offset( -$this->retention_days() * DAY_IN_SECONDS );
		$after  = 0;

		while ( $budget->has_time( 2.0 ) ) {
			$ids = $this->customers->due_for_anonymization( $before, $after, $limit );

			if ( array() === $ids ) {
				return;
			}

			foreach ( $ids as $customer_id ) {
				$after = max( $after, $customer_id );

				$this->anonymizer->anonymize_customer( $customer_id );

				++$stats->processed;
			}

			if ( count( $ids ) < $limit ) {
				return;
			}
		}

		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * Delete events that never identified anybody.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @param int         $limit  Rows per batch.
	 * @return void
	 */
	private function prune_unidentified_events( Time_Budget $budget, Stage_Stats $stats, int $limit ): void {
		$before = $this->clock->offset( -self::UNIDENTIFIED_DAYS * DAY_IN_SECONDS );

		while ( $budget->has_time( 2.0 ) ) {
			$removed = $this->events->prune_closed( $before, $limit );

			$stats->processed += $removed;

			if ( $removed < $limit ) {
				return;
			}
		}

		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * Remove the hashes of recovery links that no longer work.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @param int         $limit  Rows per batch.
	 * @return void
	 */
	private function purge_expired_tokens( Time_Budget $budget, Stage_Stats $stats, int $limit ): void {
		while ( $budget->has_time( 2.0 ) ) {
			$purged = $this->attempts->purge_expired_tokens( $limit );

			$stats->processed += $purged;

			if ( $purged < $limit ) {
				return;
			}
		}

		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * Remove duplicate-suppression receipts nothing will ask about again.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @param int         $limit  Rows per batch.
	 * @return void
	 */
	private function prune_receipts( Time_Budget $budget, Stage_Stats $stats, int $limit ): void {
		$before = $this->clock->offset( -self::RECEIPT_DAYS * DAY_IN_SECONDS );

		while ( $budget->has_time( 2.0 ) ) {
			$removed = $this->receipts->prune( $before, $limit );

			$stats->processed += $removed;

			if ( $removed < $limit ) {
				return;
			}
		}

		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * Trim the plugin's own log table.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @param int         $limit  Rows per batch.
	 * @return void
	 */
	private function prune_logs( Time_Budget $budget, Stage_Stats $stats, int $limit ): void {
		$days   = max( 1, (int) Options::get( 'log_retention_days', 14 ) );
		$before = $this->clock->offset( -$days * DAY_IN_SECONDS );

		while ( $budget->has_time( 2.0 ) ) {
			$removed = $this->delete_old_logs( $before, $limit );

			$stats->processed += $removed;

			if ( $removed < $limit ) {
				return;
			}
		}

		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * The events behind finished journeys that still hold basket data.
	 *
	 * Reading this here rather than through a repository is deliberate: it
	 * spans two tables and exists only to drive this clear-out. The predicate
	 * on the payload columns is what makes the pass self-limiting -- once a row
	 * has been stripped it stops matching, so tomorrow's run does not walk the
	 * whole history again.
	 *
	 * @param string $before UTC datetime; journeys finished before this qualify.
	 * @param int    $after  Journey id to continue after.
	 * @param int    $limit  Maximum rows.
	 * @return array<int,array{journey_id:int,event_id:int}>
	 */
	private function finished_event_ids( string $before, int $after, int $limit ): array {
		global $wpdb;

		$journeys = Table_Names::get( Table_Names::JOURNEYS );
		$events   = Table_Names::get( Table_Names::EVENTS );
		$statuses = Journey_State::terminal();
		$slots    = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT j.id AS journey_id, j.event_id AS event_id
			FROM `{$journeys}` AS j
			INNER JOIN `{$events}` AS e ON e.id = j.event_id
			WHERE j.status IN ({$slots})
				AND j.updated_at < %s
				AND j.id > %d
				AND (e.items_json IS NOT NULL OR e.metadata_json IS NOT NULL OR e.session_key IS NOT NULL)
			ORDER BY j.id ASC
			LIMIT %d";

		$args = array_merge( $statuses, array( $before, $after, max( 1, $limit ) ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql interpolates only Table_Names::get() on class constants and a %s list sized by count(); a per-batch paging query has nothing to cache.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names come from class constants, the placeholder list is generated from a count, and every value is bound.
			$wpdb->prepare( $sql, $args ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			$out[] = array(
				'journey_id' => (int) $row['journey_id'],
				'event_id'   => (int) $row['event_id'],
			);
		}

		return $out;
	}

	/**
	 * Delete one batch of log rows past their retention date.
	 *
	 * Ordered by primary key so a big clear-out makes steady progress instead
	 * of deleting whichever rows the index happened to reach first.
	 *
	 * @param string $before UTC datetime; rows older than this go.
	 * @param int    $limit  Maximum rows.
	 * @return int Rows removed.
	 */
	private function delete_old_logs( string $before, int $limit ): int {
		global $wpdb;

		$logs = Table_Names::get( Table_Names::LOGS );
		$sql  = "DELETE FROM `{$logs}` WHERE created_at < %s ORDER BY id ASC LIMIT %d";

		$removed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql interpolates only Table_Names::get() on a class constant; a DELETE has nothing to cache.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from a class constant; every value is bound.
			$wpdb->prepare( $sql, $before, max( 1, $limit ) )
		);

		return is_int( $removed ) ? $removed : 0;
	}
}
