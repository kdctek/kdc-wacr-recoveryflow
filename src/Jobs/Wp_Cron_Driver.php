<?php
/**
 * The WP-Cron background driver.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

use WAcr\RecoveryFlow\Core\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Runs every stage from one five-minute tick, for sites without Action Scheduler.
 *
 * WP-Cron is not a scheduler, it is a hope: nothing runs until somebody visits
 * the site, its own duplicate-run guard expires after sixty seconds, and a host
 * that sets DISABLE_WP_CRON without wiring a system cron stops it entirely. So
 * this driver is deliberately modest. One recurring event runs all five stages
 * in order under a single time budget, which is the only honest thing to do
 * when a request may be killed at any moment, and a stage that still has work
 * left asks for one more pass a few seconds later rather than waiting five
 * minutes.
 *
 * needs_system_cron() exists so the System Status screen can say the one thing
 * a merchant in this position needs to hear: nothing is running, and no amount
 * of waiting will change that.
 */
final class Wp_Cron_Driver implements Scheduler_Interface {

	/**
	 * The value stored in the active-driver option.
	 */
	public const ID = 'wp_cron';

	/**
	 * The hook the tick runs on. Continuations reuse it with the stage as an argument.
	 */
	public const TICK = 'recoveryflow/tick';

	/**
	 * The name of the custom cron interval this driver registers.
	 */
	public const SCHEDULE = 'recoveryflow_tick';

	/**
	 * How long after a backlog the next pass is asked for.
	 */
	private const CONTINUE_DELAY = 5;

	/**
	 * Register the custom interval.
	 *
	 * This runs whichever driver is active, so that switching to WP-Cron after
	 * WooCommerce is deactivated does not have to wait for a page load in the
	 * right order for the interval to exist.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval -- five minutes is the product requirement: a recovery message that is fifteen minutes late is a message about a basket the shopper has already forgotten. The work itself is bounded by a time budget and a row lock, so a short interval costs a query, not a worker.
	}

	/**
	 * Add the plugin's interval to the cron schedule list.
	 *
	 * @param mixed $schedules Existing schedules, keyed by name.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function add_interval( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::SCHEDULE ] = array(
			'interval' => $this->interval(),
			'display'  => __( 'Every five minutes (RecoveryFlow)', 'kdc-wacr-recoveryflow' ),
		);

		return $schedules;
	}

	/**
	 * How often the tick runs.
	 *
	 * Floored at a minute: below that the tick would spend more time taking
	 * and releasing locks than doing work, and WP-Cron cannot honour it anyway.
	 *
	 * @return int Seconds.
	 */
	public function interval(): int {
		/**
		 * Filters how often the background tick runs, in seconds.
		 *
		 * @param int $seconds Interval in seconds.
		 */
		$seconds = (int) apply_filters( Hooks::FILTER_TICK_INTERVAL, 5 * MINUTE_IN_SECONDS );

		return max( MINUTE_IN_SECONDS, min( HOUR_IN_SECONDS, $seconds ) );
	}

	/**
	 * WP-Cron is always present, even when a host has disabled its own trigger.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * The stored identifier for this driver.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Whether this site needs a real cron job wired up for anything to happen.
	 *
	 * @return bool
	 */
	public function needs_system_cron(): bool {
		return defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' );
	}

	/**
	 * Schedule the recurring tick, replacing it if its interval has changed.
	 *
	 * Only the argument-less event is inspected, so a continuation waiting in
	 * the queue is never mistaken for the recurring event and cleared.
	 *
	 * @return void
	 */
	public function schedule_all(): void {
		$event = wp_get_scheduled_event( self::TICK );

		if ( false !== $event && ( self::SCHEDULE !== $event->schedule || (int) $event->interval !== $this->interval() ) ) {
			wp_clear_scheduled_hook( self::TICK );

			$event = false;
		}

		if ( false === $event ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::TICK );
		}
	}

	/**
	 * Remove the tick and every continuation waiting behind it.
	 *
	 * @return void
	 */
	public function unschedule_all(): void {
		wp_unschedule_hook( self::TICK );
	}

	/**
	 * Ask for one more pass over a stage in a few seconds.
	 *
	 * Scheduling alone is not running: without spawn_cron() the event waits for
	 * the next visitor, which on a quiet site out of hours can be several hours.
	 * spawn_cron() refuses politely when cron is already running or has run in
	 * the last minute, which is the behaviour we want.
	 *
	 * @param string $stage One of the stage constants.
	 * @return void
	 */
	public function enqueue_continuation( string $stage ): void {
		if ( ! in_array( $stage, self::STAGES, true ) ) {
			return;
		}

		$args = array( $stage );

		if ( false === wp_next_scheduled( self::TICK, $args ) ) {
			wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::TICK, $args );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * When this stage next runs: the recurring tick, or a continuation if one is sooner.
	 *
	 * @param string $stage One of the stage constants.
	 * @return int Unix timestamp, or 0 when nothing is scheduled.
	 */
	public function next_run( string $stage ): int {
		$tick    = (int) wp_next_scheduled( self::TICK );
		$pending = (int) wp_next_scheduled( self::TICK, array( $stage ) );

		if ( 0 === $tick ) {
			return $pending;
		}

		return 0 === $pending ? $tick : min( $tick, $pending );
	}
}
