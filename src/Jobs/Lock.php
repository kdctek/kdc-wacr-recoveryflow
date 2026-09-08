<?php
/**
 * Running a stage under a mutual-exclusion lock.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

use WAcr\RecoveryFlow\Database\Lock_Repository;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Take the lock, do the work, give the lock back -- whatever happens.
 *
 * This is correctness, not caution. Neither scheduler promises that a stage
 * never runs alongside itself: Action Scheduler claims individual action rows
 * rather than hooks, so two rows on the same hook run in parallel, and a fatal
 * or a timeout releases the claim for a retry. WP-Cron is worse -- its own
 * guard expires after sixty seconds and a system cron hitting wp-cron.php takes
 * no guard at all. Two dispatch runs over the same journey would race to send
 * the same message.
 *
 * The release is in a finally block for the same reason. A stage that throws
 * and leaves its lock held would block every later run until the lease expired,
 * which on a ninety-second lease means the site quietly stops recovering carts
 * for a minute and a half after every error.
 */
final class Lock {

	/**
	 * How long a lock is held before another run may take it.
	 */
	public const DEFAULT_TTL = Lock_Repository::DEFAULT_TTL;

	/**
	 * Lock storage.
	 *
	 * @var Lock_Repository
	 */
	private Lock_Repository $locks;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Lock_Repository $locks  Lock storage.
	 * @param Logger          $logger Logger.
	 */
	public function __construct( Lock_Repository $locks, Logger $logger ) {
		$this->locks  = $locks;
		$this->logger = $logger;
	}

	/**
	 * Take a lock.
	 *
	 * @param string $key Lock name.
	 * @param int    $ttl Seconds before another run may take it.
	 * @return string|null Ownership token, or null when somebody else holds it.
	 */
	public function acquire( string $key, int $ttl = self::DEFAULT_TTL ): ?string {
		return $this->locks->acquire( $key, max( 1, $ttl ) );
	}

	/**
	 * Give a lock back.
	 *
	 * @param string $key   Lock name.
	 * @param string $owner The token acquire() returned.
	 * @return bool Whether the lock was still ours to release.
	 */
	public function release( string $key, string $owner ): bool {
		$released = $this->locks->release( $key, $owner );

		if ( ! $released ) {
			// Somebody else holds it, which means this run overran its lease
			// and another run has been working the same rows alongside it.
			$this->logger->warning( 'jobs', sprintf( 'Lock %s expired before the run finished.', $key ) );
		}

		return $released;
	}

	/**
	 * Push a held lock's expiry back while work is still going on.
	 *
	 * @param string $key   Lock name.
	 * @param string $owner Ownership token.
	 * @param int    $ttl   Seconds from now.
	 * @return bool Whether the lock was still ours to extend.
	 */
	public function renew( string $key, string $owner, int $ttl = self::DEFAULT_TTL ): bool {
		return $this->locks->renew( $key, $owner, max( 1, $ttl ) );
	}

	/**
	 * Whether a lock is currently held by anybody. Diagnostics only.
	 *
	 * @param string $key Lock name.
	 * @return bool
	 */
	public function is_held( string $key ): bool {
		return $this->locks->is_held( $key );
	}

	/**
	 * Run something under a lock, releasing it however the work ends.
	 *
	 * The callback is handed the ownership token so that long work can renew
	 * its own lease.
	 *
	 * @param string   $key  Lock name.
	 * @param callable $work Receives the ownership token.
	 * @param int      $ttl  Seconds before another run may take the lock.
	 * @return mixed The callback's return value, or null when the lock was not free.
	 */
	public function with( string $key, callable $work, int $ttl = self::DEFAULT_TTL ) {
		$owner = $this->acquire( $key, $ttl );

		if ( null === $owner ) {
			return null;
		}

		try {
			return $work( $owner );
		} finally {
			$this->release( $key, $owner );
		}
	}
}
