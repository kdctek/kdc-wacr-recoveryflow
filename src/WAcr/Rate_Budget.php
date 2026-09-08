<?php
/**
 * Outbound request budget.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

use WAcr\RecoveryFlow\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the plugin under WA.cr's per-credential rate limit.
 *
 * The limit is shared by everything using that credential -- the merchant's own
 * scripts, another integration, the console. Spending all of it on recovery
 * messages would break the merchant's other tooling, so the plugin claims a
 * deliberately smaller slice and yields rather than racing to the ceiling.
 *
 * When WA.cr does answer 429, its Retry-After is honoured exactly: guessing a
 * shorter wait is how a rate limit becomes a rate-limit loop.
 */
final class Rate_Budget {

	public const DEFAULT_PER_MINUTE = 60;
	public const OPTION             = 'recoveryflow_rate_budget';

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Requests allowed per minute.
	 *
	 * @var int
	 */
	private int $per_minute;

	/**
	 * Constructor.
	 *
	 * @param Clock $clock      Clock.
	 * @param int   $per_minute Requests allowed per minute.
	 */
	public function __construct( Clock $clock, int $per_minute = self::DEFAULT_PER_MINUTE ) {
		$this->clock      = $clock;
		$this->per_minute = max( 1, $per_minute );
	}

	/**
	 * The stored window and pause.
	 *
	 * @return array{window:int,count:int,paused_until:int}
	 */
	private function state(): array {
		$state = get_option( self::OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'window'       => isset( $state['window'] ) ? (int) $state['window'] : 0,
			'count'        => isset( $state['count'] ) ? (int) $state['count'] : 0,
			'paused_until' => isset( $state['paused_until'] ) ? (int) $state['paused_until'] : 0,
		);
	}

	/**
	 * Persist the window.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Whether sending is currently paused, and until when.
	 *
	 * @return int Unix timestamp, 0 when not paused.
	 */
	public function paused_until(): int {
		$state = $this->state();

		return $state['paused_until'] > $this->clock->timestamp() ? $state['paused_until'] : 0;
	}

	/**
	 * Stop sending for a while, because WA.cr said so.
	 *
	 * @param int $seconds How long to wait.
	 * @return void
	 */
	public function pause( int $seconds ): void {
		$state                 = $this->state();
		$state['paused_until'] = $this->clock->timestamp() + max( 1, $seconds );

		$this->save( $state );
	}

	/**
	 * How many requests are left in this minute.
	 *
	 * @return int
	 */
	public function remaining(): int {
		if ( $this->paused_until() > 0 ) {
			return 0;
		}

		$state  = $this->state();
		$window = (int) floor( $this->clock->timestamp() / 60 );

		if ( $state['window'] !== $window ) {
			return $this->per_minute;
		}

		return max( 0, $this->per_minute - $state['count'] );
	}

	/**
	 * Claim one request.
	 *
	 * @return bool Whether there was budget for it.
	 */
	public function take(): bool {
		if ( $this->paused_until() > 0 ) {
			return false;
		}

		$state  = $this->state();
		$window = (int) floor( $this->clock->timestamp() / 60 );

		if ( $state['window'] !== $window ) {
			$state['window'] = $window;
			$state['count']  = 0;
		}

		if ( $state['count'] >= $this->per_minute ) {
			return false;
		}

		++$state['count'];
		$this->save( $state );

		return true;
	}

	/**
	 * Clear the pause. Used when a connection is re-tested successfully.
	 *
	 * @return void
	 */
	public function resume(): void {
		$state                 = $this->state();
		$state['paused_until'] = 0;

		$this->save( $state );
	}
}
