<?php
/**
 * Running a background stage safely.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a stage is started, and the only place that has to be careful.
 *
 * Everything a stage needs in order to be safe lives here rather than in the
 * five stages: take the lock, start the clock, run, record what happened, give
 * the lock back, and ask for another pass if there is more to do. Putting it
 * here means a new stage cannot forget any of it.
 *
 * Nothing escapes. A background hook that throws takes the whole cron pass with
 * it on some hosts, and on Action Scheduler it releases the claim and retries,
 * so an exception in one stage would quietly become an exception in that stage
 * for ever. The failure is caught, counted, recorded as a code and left behind.
 *
 * The exception itself is logged by class and location, never by message. An
 * exception thrown from deep in a send path can carry a phone number in its
 * message, and the log is read by anybody with access to the admin.
 */
final class Stage_Runner {

	/**
	 * The scheduler, asked for a continuation when a stage runs out of time.
	 *
	 * @var Scheduler_Interface
	 */
	private Scheduler_Interface $scheduler;

	/**
	 * Stage locks.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Registered stages, by key.
	 *
	 * @var array<string,Stage_Interface>
	 */
	private array $stages = array();

	/**
	 * Constructor.
	 *
	 * @param Scheduler_Interface $scheduler The active scheduler.
	 * @param Lock                $lock      Stage locks.
	 * @param Clock               $clock     Clock.
	 * @param Logger              $logger    Logger.
	 */
	public function __construct( Scheduler_Interface $scheduler, Lock $lock, Clock $clock, Logger $logger ) {
		$this->scheduler = $scheduler;
		$this->lock      = $lock;
		$this->clock     = $clock;
		$this->logger    = $logger;
	}

	/**
	 * Register a stage.
	 *
	 * @param Stage_Interface $stage The stage.
	 * @return Stage_Runner
	 */
	public function add( Stage_Interface $stage ): self {
		$this->stages[ $stage->key() ] = $stage;

		return $this;
	}

	/**
	 * Every registered stage, by key.
	 *
	 * @return array<string,Stage_Interface>
	 */
	public function stages(): array {
		return $this->stages;
	}

	/**
	 * One registered stage.
	 *
	 * @param string $key Stage key.
	 * @return Stage_Interface|null
	 */
	public function stage( string $key ): ?Stage_Interface {
		return $this->stages[ $key ] ?? null;
	}

	/**
	 * Listen on every hook either driver can fire.
	 *
	 * Both the recurring hook and the continuation hook are attached for each
	 * stage, because Action Scheduler's uniqueness test ignores arguments and
	 * so the two cannot be the same name.
	 *
	 * @return void
	 */
	public function hooks(): void {
		foreach ( Scheduler_Interface::STAGES as $key ) {
			$callback = function () use ( $key ): void {
				$this->run( $key );
			};

			add_action( Scheduler_Interface::HOOK_PREFIX . $key, $callback );
			add_action( Scheduler_Interface::RUN_PREFIX . $key, $callback );
		}

		// run_tick() hands its stats back so that "Run now" and WP-CLI can show
		// what happened, but an action callback must return nothing -- WordPress
		// discards it, and returning a value from a hook is how a filter and an
		// action get confused for one another later.
		add_action(
			Wp_Cron_Driver::TICK,
			function ( $stage = '' ): void {
				$this->run_tick( $stage );
			}
		);
	}

	/**
	 * Run one stage on a budget of its own.
	 *
	 * @param string $key Stage key.
	 * @return Stage_Stats
	 */
	public function run( string $key ): Stage_Stats {
		$stage = $this->stage( $key );

		if ( null === $stage ) {
			$stats             = new Stage_Stats( $key );
			$stats->last_error = 'unknown_stage';

			return $stats;
		}

		return $this->execute( $stage, Time_Budget::create()->start() );
	}

	/**
	 * Run every stage in order under one shared budget.
	 *
	 * This is the WP-Cron path: one request has to do all five, so they share
	 * the time between them rather than each assuming it has the whole of it.
	 * A continuation carries the stage it is for, so a backlog re-runs one
	 * stage rather than starting the whole tick again.
	 *
	 * @param mixed $stage Stage key when this is a continuation; anything else runs them all.
	 * @return array<string,Stage_Stats> What each stage that ran did.
	 */
	public function run_tick( $stage = '' ): array {
		$key = is_string( $stage ) ? $stage : '';

		if ( '' !== $key && null !== $this->stage( $key ) ) {
			return array( $key => $this->run( $key ) );
		}

		$budget = Time_Budget::create()->start();
		$done   = array();

		foreach ( Scheduler_Interface::STAGES as $stage_key ) {
			$next = $this->stage( $stage_key );

			if ( null === $next ) {
				continue;
			}

			// Stopping here rather than starting work there is no time to
			// finish: the stages left over are asked for directly, so a slow
			// evaluate cannot starve retention until tomorrow.
			if ( ! $budget->has_time( 2.0 ) ) {
				$this->scheduler->enqueue_continuation( $stage_key );

				break;
			}

			$done[ $stage_key ] = $this->execute( $next, $budget );
		}

		return $done;
	}

	/**
	 * How many rows a stage handles in one batch.
	 *
	 * @param string $stage    Stage key.
	 * @param int    $fallback The stage's own default.
	 * @return int
	 */
	public static function batch_size( string $stage, int $fallback ): int {
		/**
		 * Filters how many rows a background stage handles per batch.
		 *
		 * @param int    $size  Rows per batch.
		 * @param string $stage Stage key.
		 */
		$size = (int) apply_filters( Hooks::FILTER_BATCH_SIZE, $fallback, $stage );

		return max( 1, min( 1000, $size ) );
	}

	/**
	 * Take the lock, run the stage, record the outcome, release the lock.
	 *
	 * @param Stage_Interface $stage  The stage.
	 * @param Time_Budget     $budget How long it has.
	 * @return Stage_Stats
	 */
	private function execute( Stage_Interface $stage, Time_Budget $budget ): Stage_Stats {
		$key     = $stage->key();
		$started = microtime( true );

		$stats = $this->lock->with(
			$stage->lock_key(),
			function () use ( $stage, $budget, $key ): Stage_Stats {
				try {
					return $stage->run( $budget );
				} catch ( \Throwable $error ) {
					$failed = new Stage_Stats( $key );
					$failed->fail( 'exception' );

					$this->logger->error(
						'jobs',
						sprintf( 'The %s stage stopped on %s.', $key, get_class( $error ) ),
						array(
							'file' => basename( $error->getFile() ),
							'line' => $error->getLine(),
						)
					);

					return $failed;
				}
			},
			$budget->lock_ttl()
		);

		if ( ! $stats instanceof Stage_Stats ) {
			// Another run holds the lock, which is ordinary. It is deliberately
			// not recorded: overwriting the last real run with an empty one
			// would hide exactly what the status screen exists to show.
			$held             = new Stage_Stats( $key );
			$held->last_error = 'locked';

			return $held;
		}

		$stats->stage    = $key;
		$stats->duration = round( microtime( true ) - $started, 3 );
		$stats->ran_at   = $this->clock->now();

		$stats->save();

		if ( $stats->has_backlog() ) {
			$this->scheduler->enqueue_continuation( $key );
		}

		return $stats;
	}
}
