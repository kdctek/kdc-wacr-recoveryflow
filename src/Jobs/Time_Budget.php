<?php
/**
 * How long a background run may take.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

use WAcr\RecoveryFlow\Core\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * The clock a stage watches so it stops before somebody else stops it.
 *
 * A background run that is killed mid-batch is not merely slow: the row lock it
 * holds stays held until its lease expires, the claims it took stay claimed,
 * and the work it half did has to be worked out from the database afterwards.
 * So a stage never starts a piece of work it does not expect to finish, and the
 * budget is set well inside whatever limit will actually be enforced --
 * PHP's max_execution_time, and Action Scheduler's own queue time limit, which
 * defaults to thirty seconds.
 *
 * The lock's lease is always longer than the budget, which is the invariant
 * that makes overlapping runs safe: a run cannot still be working when another
 * run is allowed to take its lock.
 */
final class Time_Budget {

	/**
	 * The longest a stage runs when nothing else constrains it.
	 */
	public const DEFAULT_SECONDS = 20;

	/**
	 * The shortest budget worth starting a batch with.
	 */
	public const FLOOR_SECONDS = 5;

	/**
	 * Seconds left unspent below PHP's own execution limit.
	 */
	public const HEADROOM = 5;

	/**
	 * Seconds the lock's lease exceeds the budget by, at a minimum.
	 */
	public const LOCK_MARGIN = 30;

	/**
	 * How long this run may take.
	 *
	 * @var float
	 */
	private float $seconds;

	/**
	 * When the run started, from microtime(). Zero until start().
	 *
	 * @var float
	 */
	private float $started_at = 0.0;

	/**
	 * Constructor.
	 *
	 * @param float $seconds How long this run may take.
	 */
	public function __construct( float $seconds ) {
		$this->seconds = max( (float) self::FLOOR_SECONDS, $seconds );
	}

	/**
	 * Work out what this site can afford.
	 *
	 * A max_execution_time of 0 means no limit, which is normal on the command
	 * line; it is not a licence to run for ever, so the default still applies.
	 *
	 * @return Time_Budget
	 */
	public static function create(): self {
		$max     = (int) ini_get( 'max_execution_time' );
		$allowed = $max > 0 ? (float) ( $max - self::HEADROOM ) : (float) self::DEFAULT_SECONDS;
		$seconds = min( (float) self::DEFAULT_SECONDS, $allowed );

		/**
		 * Filters how many seconds one background stage may run for.
		 *
		 * @param float $seconds Budget in seconds.
		 */
		$seconds = (float) apply_filters( Hooks::FILTER_TIME_BUDGET, $seconds );

		return new self( $seconds );
	}

	/**
	 * Start the clock.
	 *
	 * @return Time_Budget
	 */
	public function start(): self {
		$this->started_at = microtime( true );

		return $this;
	}

	/**
	 * How long this run is allowed to take in total.
	 *
	 * @return float Seconds.
	 */
	public function seconds(): float {
		return $this->seconds;
	}

	/**
	 * How long the run has been going.
	 *
	 * @return float Seconds; zero before start().
	 */
	public function elapsed(): float {
		if ( 0.0 === $this->started_at ) {
			return 0.0;
		}

		return microtime( true ) - $this->started_at;
	}

	/**
	 * How much of the budget is left.
	 *
	 * @return float Seconds, never below zero.
	 */
	public function remaining(): float {
		return max( 0.0, $this->seconds - $this->elapsed() );
	}

	/**
	 * Whether there is room for one more piece of work.
	 *
	 * The default of a second is what a batch of database writes costs; a stage
	 * about to make an HTTP call should ask for more.
	 *
	 * @param float $needed Seconds the next piece of work is expected to take.
	 * @return bool
	 */
	public function has_time( float $needed = 1.0 ): bool {
		return $this->remaining() >= $needed;
	}

	/**
	 * Whether the budget is spent.
	 *
	 * @return bool
	 */
	public function expired(): bool {
		return $this->remaining() <= 0.0;
	}

	/**
	 * How long the stage lock should be leased for.
	 *
	 * Always longer than the budget, so that a run still working cannot have
	 * its lock taken from underneath it by the next run.
	 *
	 * @return int Seconds.
	 */
	public function lock_ttl(): int {
		return max( Lock::DEFAULT_TTL, (int) ceil( $this->seconds ) + self::LOCK_MARGIN );
	}
}
