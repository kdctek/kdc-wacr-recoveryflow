<?php
/**
 * What the plugin needs from whatever runs its background work.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The contract both schedulers satisfy, so nothing above them knows which is in use.
 *
 * There are two because there are two kinds of site. Where WooCommerce is
 * installed, Action Scheduler is present and is far better at this: it queues,
 * it retries, it has a UI. Where it is not, WP-Cron is all there is. Neither
 * can be assumed -- WooCommerce can be deactivated on a live site while
 * journeys are in flight -- so the choice is made at runtime and can change.
 *
 * A continuation is asked for by stage rather than globally. A stage that ran
 * out of budget with rows still waiting needs to run again in seconds, not at
 * its next interval; a hundred thousand due rows must drain across successive
 * runs rather than sit until tomorrow.
 */
interface Scheduler_Interface {

	/**
	 * The group every action this plugin schedules belongs to.
	 *
	 * Group rather than hook is what makes a clean uninstall possible: the
	 * hook form of Action Scheduler's bulk unschedule matches only actions with
	 * empty arguments, so the group is the only handle on all of them.
	 */
	public const GROUP = 'recoveryflow';

	/**
	 * The five stages, in the order a single tick runs them.
	 */
	public const EVALUATE  = 'evaluate';
	public const DISPATCH  = 'dispatch';
	public const POLL      = 'poll';
	public const EXPIRE    = 'expire';
	public const RETENTION = 'retention';

	/**
	 * Every stage key, in run order.
	 */
	public const STAGES = array( self::EVALUATE, self::DISPATCH, self::POLL, self::EXPIRE, self::RETENTION );

	/**
	 * Hook name prefixes.
	 *
	 * The recurring hooks and the continuation hooks are deliberately
	 * different names. Action Scheduler's uniqueness test keys on hook and
	 * group and ignores the arguments, so one shared continuation hook
	 * carrying a stage argument would let a single stage's backlog block the
	 * continuation of the other four.
	 */
	public const HOOK_PREFIX = 'recoveryflow/';
	public const RUN_PREFIX  = 'recoveryflow/run/';

	/**
	 * Whether this driver can run on this site right now.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * The stored identifier for this driver.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Register the recurring work. Must be safe to call on every request.
	 *
	 * @return void
	 */
	public function schedule_all(): void;

	/**
	 * Remove everything this driver scheduled.
	 *
	 * @return void
	 */
	public function unschedule_all(): void;

	/**
	 * Ask for one more run of a stage as soon as possible.
	 *
	 * @param string $stage One of the stage constants.
	 * @return void
	 */
	public function enqueue_continuation( string $stage ): void;

	/**
	 * When a stage is next due.
	 *
	 * @param string $stage One of the stage constants.
	 * @return int Unix timestamp, or 0 when nothing is scheduled.
	 */
	public function next_run( string $stage ): int;
}
