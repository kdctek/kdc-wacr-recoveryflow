<?php
/**
 * The Action Scheduler background driver.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the five stages as recurring Action Scheduler actions.
 *
 * Three things about Action Scheduler are load-bearing here, and all three are
 * easy to get wrong from the documentation alone.
 *
 * The as_* functions exist from plugins_loaded, but the data store is not
 * initialised until init priority 1. Scheduling before that does not fail
 * loudly -- it returns 0, exactly as an internal error does -- so the work
 * silently never runs. Everything here is therefore driven from init priority
 * 20 by Scheduler_Factory, never from plugin boot.
 *
 * Uniqueness keys on hook and group and ignores the arguments. One
 * continuation hook with a stage argument would mean the first stage to fall
 * behind blocks the continuations of the other four, so each stage gets its
 * own hook.
 *
 * A scheduled action gives at-least-once delivery, not exactly-once: the claim
 * is per action row rather than per hook, and a fatal or a timeout releases the
 * claim for a retry. Two runs of the same stage overlapping is normal, which is
 * why every stage takes a database lock before it does anything.
 */
final class Action_Scheduler_Driver implements Scheduler_Interface {

	/**
	 * The value stored in the active-driver option.
	 */
	public const ID = 'action_scheduler';

	/**
	 * Whether Action Scheduler is loaded and usable.
	 *
	 * The recurring scheduler is the function tested because it is the one
	 * this driver cannot work without; the others ship alongside it.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'as_schedule_recurring_action' );
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
	 * How often each stage runs, and how far into the first minute it starts.
	 *
	 * The starts are staggered so five stages do not land on the same request
	 * on a quiet site, where every one of them would then be competing for the
	 * same PHP worker and the same time budget.
	 *
	 * @return array<string,array{interval:int,offset:int}>
	 */
	private function plan(): array {
		return array(
			self::EVALUATE  => array(
				'interval' => MINUTE_IN_SECONDS,
				'offset'   => 15,
			),
			self::DISPATCH  => array(
				'interval' => MINUTE_IN_SECONDS,
				'offset'   => 30,
			),
			self::POLL      => array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'offset'   => 45,
			),
			self::EXPIRE    => array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'offset'   => 60,
			),
			self::RETENTION => array(
				'interval' => DAY_IN_SECONDS,
				'offset'   => 75,
			),
		);
	}

	/**
	 * The recurring hook a stage runs on.
	 *
	 * @param string $stage One of the stage constants.
	 * @return string
	 */
	public static function hook( string $stage ): string {
		return self::HOOK_PREFIX . $stage;
	}

	/**
	 * The continuation hook a stage asks for another run on.
	 *
	 * @param string $stage One of the stage constants.
	 * @return string
	 */
	public static function continuation_hook( string $stage ): string {
		return self::RUN_PREFIX . $stage;
	}

	/**
	 * Register the five recurring actions, if they are not already registered.
	 *
	 * @return void
	 */
	public function schedule_all(): void {
		if ( ! $this->is_available() || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$now = time();

		foreach ( $this->plan() as $stage => $timing ) {
			$hook = self::hook( $stage );

			// Passing null rather than an empty array asks "any arguments",
			// which is what makes re-registration a genuine no-op rather than
			// a second identical schedule.
			if ( as_has_scheduled_action( $hook, null, self::GROUP ) ) {
				continue;
			}

			as_schedule_recurring_action(
				$now + $timing['offset'],
				$timing['interval'],
				$hook,
				array(),
				self::GROUP
			);
		}
	}

	/**
	 * Remove every action this plugin scheduled.
	 *
	 * The group-only form is deliberate: the hook form matches only actions
	 * whose arguments are empty, so unscheduling by hook would leave the
	 * continuations behind and a deactivated plugin would keep waking up.
	 *
	 * @return void
	 */
	public function unschedule_all(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( '', array(), self::GROUP );
	}

	/**
	 * Ask for one more run of a stage as soon as a queue worker is free.
	 *
	 * @param string $stage One of the stage constants.
	 * @return void
	 */
	public function enqueue_continuation( string $stage ): void {
		if ( ! in_array( $stage, self::STAGES, true ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		// The fourth argument asks Action Scheduler not to queue a second
		// identical action. It is honoured on hook and group, which is exactly
		// why each stage has its own continuation hook.
		as_enqueue_async_action( self::continuation_hook( $stage ), array(), self::GROUP, true );
	}

	/**
	 * When a stage is next due.
	 *
	 * @param string $stage One of the stage constants.
	 * @return int Unix timestamp, or 0 when nothing is scheduled.
	 */
	public function next_run( string $stage ): int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}

		$next = as_next_scheduled_action( self::hook( $stage ), null, self::GROUP );

		// An async action that is queued but has no due date answers true.
		if ( true === $next ) {
			return time();
		}

		return is_int( $next ) ? $next : 0;
	}
}
