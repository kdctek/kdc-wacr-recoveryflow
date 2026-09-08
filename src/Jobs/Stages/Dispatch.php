<?php
/**
 * Working the journeys that are due for their next step.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs\Stages;

use WAcr\RecoveryFlow\Jobs\Scheduler_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Runner;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Jobs\Time_Budget;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\Uuid;
use WAcr\RecoveryFlow\WAcr\Rate_Budget;
use WAcr\RecoveryFlow\Workflow\Engine;
use WAcr\RecoveryFlow\Workflow\Step_Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Takes a lease on the journeys that are due, and hands each to the engine.
 *
 * This stage owns no messaging logic at all. It owns the batch: which rows this
 * run is responsible for, and giving them back when it is finished with them.
 * The lease is what makes overlapping runs safe without holding a lock across
 * an HTTP call -- a second run simply finds no unclaimed rows.
 *
 * The claims are released in a finally block, always. A run that died holding
 * them would freeze those journeys until the lease expired, and a run that
 * stopped its batch early must hand back the rows it did not get to rather than
 * sitting on them for a further ninety seconds.
 *
 * The request budget is checked here and never spent here. The WA.cr client
 * takes one unit per call; a stage that also took one would halve the number of
 * messages the merchant can actually send, and the shortfall would look like a
 * WA.cr rate limit rather than a bug in this plugin.
 *
 * A lost race is counted apart from a failure. A shopper completing their order
 * while this batch is deciding to message them about it is the whole system
 * working; reporting it as an error would send somebody looking for a fault.
 */
final class Dispatch implements Stage_Interface {

	/**
	 * How many journeys one run leases.
	 */
	private const BATCH = 50;

	/**
	 * Seconds set aside for a journey that may make an HTTP call.
	 */
	private const COST = 5.0;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * The workflow engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * The outbound request budget.
	 *
	 * @var Rate_Budget
	 */
	private Rate_Budget $rate;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Journey_Repository $journeys Journey storage.
	 * @param Engine             $engine   Workflow engine.
	 * @param Rate_Budget        $rate     Outbound request budget.
	 * @param Logger             $logger   Logger.
	 */
	public function __construct( Journey_Repository $journeys, Engine $engine, Rate_Budget $rate, Logger $logger ) {
		$this->journeys = $journeys;
		$this->engine   = $engine;
		$this->rate     = $rate;
		$this->logger   = $logger;
	}

	/**
	 * The stage's key.
	 *
	 * @return string
	 */
	public function key(): string {
		return Scheduler_Interface::DISPATCH;
	}

	/**
	 * The row in the locks table this stage runs under.
	 *
	 * @return string
	 */
	public function lock_key(): string {
		return Scheduler_Interface::DISPATCH;
	}

	/**
	 * Lease a batch of due journeys and run each one.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @return Stage_Stats
	 */
	public function run( Time_Budget $budget ): Stage_Stats {
		$stats = new Stage_Stats( $this->key() );

		// WA.cr has told us to wait, and it told us how long. Guessing a
		// shorter wait is how a rate limit becomes a rate-limit loop.
		if ( 0 !== $this->rate->paused_until() ) {
			$stats->last_error = 'rate_paused';

			return $stats;
		}

		$limit = Stage_Runner::batch_size( $this->key(), self::BATCH );
		$token = Uuid::v4();

		try {
			$claimed = $this->journeys->claim_due( $token, $limit );

			if ( 0 === $claimed ) {
				return $stats;
			}

			$batch   = $this->journeys->claimed( $token, $limit );
			$handled = 0;
			$stopped = false;

			foreach ( $batch as $journey ) {
				if ( ! $budget->has_time( self::COST ) ) {
					break;
				}

				if ( 0 !== $this->rate->paused_until() ) {
					$stopped = true;

					if ( '' === $stats->last_error ) {
						$stats->last_error = 'rate_paused';
					}

					break;
				}

				++$handled;

				if ( $this->work( $journey, $token, $stats ) ) {
					$stopped = true;

					break;
				}
			}//end foreach

			$left = max( 0, count( $batch ) - $handled );

			// A full batch worked through cleanly almost certainly has another
			// batch behind it, and a continuation drains it in seconds rather
			// than at the next interval.
			if ( 0 === $left && $claimed >= $limit ) {
				$left = 1;
			}

			// A batch stopped by the connection itself is not asked to
			// continue: every remaining journey would fail the same way, and a
			// continuation would only make the same refused call sooner.
			$stats->backlog = $stopped ? 0 : $left;
		} finally {
			$this->journeys->release_claims( $token );
		}//end try

		return $stats;
	}

	/**
	 * Run one journey and record what came of it.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $token   This run's lease.
	 * @param Stage_Stats      $stats   Counters for this run.
	 * @return bool Whether the whole batch should stop here.
	 */
	private function work( Recovery_Journey $journey, string $token, Stage_Stats $stats ): bool {
		try {
			$outcome = $this->engine->run( $journey, $token );
		} catch ( \Throwable $error ) {
			$stats->fail( 'exception' );

			// Class and location only. An exception raised inside a send can
			// carry the recipient's number in its message, and this log is
			// readable by anybody who can reach the admin.
			$this->logger->error(
				'jobs',
				sprintf( 'Dispatching a journey stopped on %s.', get_class( $error ) ),
				array(
					'file' => basename( $error->getFile() ),
					'line' => $error->getLine(),
				),
				$journey->id
			);

			return false;
		}

		$this->tally( $outcome, $stats );

		return $outcome->stop_batch;
	}

	/**
	 * Count one outcome.
	 *
	 * @param Step_Outcome $outcome What the engine did.
	 * @param Stage_Stats  $stats   Counters for this run.
	 * @return void
	 */
	private function tally( Step_Outcome $outcome, Stage_Stats $stats ): void {
		switch ( $outcome->status ) {
			case Step_Outcome::LOST_RACE:
				++$stats->lost_race;
				break;

			case Step_Outcome::FAILED:
				$stats->fail( '' === $outcome->reason ? 'step_failed' : $outcome->reason );
				break;

			case Step_Outcome::SKIPPED:
				++$stats->skipped;
				break;

			default:
				++$stats->processed;
				break;
		}
	}
}
