<?php
/**
 * Request throttling for the public endpoint.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Security;

use WAcr\RecoveryFlow\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * A fixed-window counter, so one caller cannot hammer the recovery endpoint.
 *
 * The recovery link is the plugin's only unauthenticated surface, and each hit
 * on it costs an indexed query. A 256-bit token is not going to be guessed, so
 * this is not there to protect the secret: it is there so a scanner walking the
 * endpoint cannot turn a shop's database into its own load generator, and so a
 * single leaked link cannot be replayed thousands of times.
 *
 * The window is fixed rather than sliding, and the window number is part of the
 * cache key. That makes each window an independent counter which simply falls
 * out of the cache when it ends -- no expiry to renew, no read-modify-write
 * that could resurrect a stale count. The cost is the usual fixed-window edge:
 * a caller can spend a full allowance either side of a boundary. At thirty
 * requests per ten minutes that is sixty requests in an instant, which is
 * nothing, and it buys an implementation with no way to leak counters.
 *
 * The key is hashed here, never stored as given. Callers pass things like an IP
 * address or a token digest, and an option name is a place personal data must
 * never end up: the transient key is visible to every plugin on the site and,
 * without a persistent object cache, is written to wp_options in plaintext.
 *
 * Best effort by design. With an external object cache a counter can be evicted
 * early under memory pressure, and a limiter that failed closed when its cache
 * blinked would lock real customers out of their own recovery links. It fails
 * open, and the correctness of the endpoint never depends on it.
 */
final class Rate_Limiter {

	/**
	 * Transient key prefix. Short on purpose: an option name is capped at 191
	 * characters and WordPress prepends '_transient_timeout_' to it.
	 */
	private const PREFIX = 'rf_rl_';

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Clock.
	 */
	public function __construct( Clock $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Count one request against a key.
	 *
	 * A refused request is deliberately not counted. Counting it would let a
	 * flood keep rewriting the same option for as long as it lasted, which is
	 * the load this class exists to avoid.
	 *
	 * @param string $key    What is being limited, e.g. an IP hash or a token hash.
	 * @param int    $limit  Requests allowed in one window.
	 * @param int    $window Window length in seconds.
	 * @return bool Whether this request is within the allowance.
	 */
	public function hit( string $key, int $limit, int $window ): bool {
		$limit  = max( 1, $limit );
		$window = max( 1, $window );
		$name   = $this->transient_name( $key, $window );
		$used   = (int) get_transient( $name );

		if ( $used >= $limit ) {
			return false;
		}

		// The window number is in the key, so re-setting the expiry cannot
		// extend a window: the next window is a different transient entirely.
		set_transient( $name, $used + 1, $window + 60 );

		return true;
	}

	/**
	 * Forget the hits recorded against a key in the current window.
	 *
	 * @param string $key    The key to clear.
	 * @param int    $window Window length in seconds, which must match the one used to count.
	 * @return void
	 */
	public function reset( string $key, int $window = 600 ): void {
		delete_transient( $this->transient_name( $key, max( 1, $window ) ) );
	}

	/**
	 * The cache key for one key in the window that contains now.
	 *
	 * @param string $key    Caller's key.
	 * @param int    $window Window length in seconds.
	 * @return string
	 */
	private function transient_name( string $key, int $window ): string {
		$slot = intdiv( $this->clock->timestamp(), $window );

		return self::PREFIX . substr( hash( 'sha256', $key ), 0, 32 ) . '_' . $slot;
	}
}
