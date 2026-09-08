<?php
/**
 * Ending journeys that are past saving.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs\Stages;

use WAcr\RecoveryFlow\Database\Table_Names;
use WAcr\RecoveryFlow\Jobs\Scheduler_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Runner;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Jobs\Time_Budget;
use WAcr\RecoveryFlow\Recovery\Attempt_Repository;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Closes off journeys nobody is going to convert, and everything hanging from them.
 *
 * Expiring the journey row is the easy half and is done in bulk, because a
 * store that has been away for a fortnight can come back to tens of thousands
 * of them and one indexed statement is the only way that finishes.
 *
 * The half that matters is the tidying. An expired journey with a live recovery
 * link is a link that still restores a basket and still credits a sale to a
 * campaign that gave up on it, weeks later. An expired journey with its event
 * still open is a basket that gets evaluated again and messaged again. So the
 * tidy pass looks for exactly those, and it looks for them by their present
 * state rather than by remembering which rows this run expired -- which means
 * it also cleans up after a run that was killed halfway through, and after any
 * other code path that expires a journey.
 */
final class Expire implements Stage_Interface {

	/**
	 * How many journeys are expired per statement.
	 */
	private const BATCH = 500;

	/**
	 * How many expired journeys are tidied up per query.
	 */
	private const TIDY_BATCH = 200;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

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
	 * Constructor.
	 *
	 * @param Journey_Repository $journeys Journey storage.
	 * @param Event_Repository   $events   Event storage.
	 * @param Attempt_Repository $attempts Attempt ledger.
	 */
	public function __construct( Journey_Repository $journeys, Event_Repository $events, Attempt_Repository $attempts ) {
		$this->journeys = $journeys;
		$this->events   = $events;
		$this->attempts = $attempts;
	}

	/**
	 * The stage's key.
	 *
	 * @return string
	 */
	public function key(): string {
		return Scheduler_Interface::EXPIRE;
	}

	/**
	 * The row in the locks table this stage runs under.
	 *
	 * @return string
	 */
	public function lock_key(): string {
		return Scheduler_Interface::EXPIRE;
	}

	/**
	 * Expire what is due, then tidy up behind it.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @return Stage_Stats
	 */
	public function run( Time_Budget $budget ): Stage_Stats {
		$stats = new Stage_Stats( $this->key() );

		$this->expire_due( $budget, $stats );
		$this->tidy( $budget, $stats );

		return $stats;
	}

	/**
	 * Move every journey past its useful life into the expired state.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @return void
	 */
	private function expire_due( Time_Budget $budget, Stage_Stats $stats ): void {
		$limit = Stage_Runner::batch_size( $this->key(), self::BATCH );

		while ( $budget->has_time( 2.0 ) ) {
			$expired = $this->journeys->expire_due( $limit );

			$stats->processed += $expired;

			if ( $expired < $limit ) {
				return;
			}
		}

		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * Withdraw the links and close the baskets behind expired journeys.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @return void
	 */
	private function tidy( Time_Budget $budget, Stage_Stats $stats ): void {
		$limit = Stage_Runner::batch_size( $this->key(), self::TIDY_BATCH );
		$after = 0;

		while ( $budget->has_time( 2.0 ) ) {
			$rows = $this->untidied( $after, $limit );

			if ( array() === $rows ) {
				return;
			}

			foreach ( $rows as $row ) {
				$after = max( $after, $row['journey_id'] );

				$this->attempts->revoke_tokens( $row['journey_id'] );

				// Closing the event frees its dedupe key, so the shopper's next
				// basket is tracked as a new one rather than reviving this one.
				$this->events->close( $row['event_id'], Recovery_Event::EXPIRED, 'max_age' );

				++$stats->skipped;
			}

			if ( count( $rows ) < $limit ) {
				return;
			}
		}//end while

		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * Expired journeys whose basket is still open.
	 *
	 * Read here rather than through Journey_Repository because it spans two
	 * tables and exists only to serve this stage; src/Jobs owns the queries
	 * that are about running the plugin rather than about one entity. Every
	 * value is bound; both table names come from class constants.
	 *
	 * @param int $after Journey id to continue after, for primary-key paging.
	 * @param int $limit Maximum rows.
	 * @return array<int,array{journey_id:int,event_id:int}>
	 */
	private function untidied( int $after, int $limit ): array {
		global $wpdb;

		$journeys = Table_Names::get( Table_Names::JOURNEYS );
		$events   = Table_Names::get( Table_Names::EVENTS );

		$sql = "SELECT j.id AS journey_id, j.event_id AS event_id
			FROM `{$journeys}` AS j
			INNER JOIN `{$events}` AS e ON e.id = j.event_id
			WHERE j.status = %s AND e.status = %s AND j.id > %d
			ORDER BY j.id ASC
			LIMIT %d";

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- both table names come from class constants; every value is bound.
			$wpdb->prepare( $sql, Journey_State::EXPIRED, Recovery_Event::OPEN, $after, max( 1, $limit ) ),
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
}
