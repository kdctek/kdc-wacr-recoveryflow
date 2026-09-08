<?php
/**
 * Choosing and keeping the background driver.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the scheduler this site can actually use, and cleans up after a change.
 *
 * The choice is not made once at install. Action Scheduler arrives with
 * WooCommerce and leaves with it, and a merchant deactivating WooCommerce on a
 * Tuesday afternoon must not silently stop every recovery journey in flight.
 * So the active driver is recorded, re-checked on every request, and when it
 * changes the work belonging to the driver that is going away is unscheduled
 * before the new one is set up. Skipping that leaves a WP-Cron tick firing
 * alongside five Action Scheduler actions, all doing the same work at once --
 * safe, because of the locks, but pure waste.
 *
 * Everything happens on init at priority 20. Action Scheduler's data store is
 * not ready before init 1, and scheduling into it early returns 0 and schedules
 * nothing, without an error anybody would see.
 */
final class Scheduler_Factory {

	/**
	 * Where the active driver's id is recorded.
	 */
	public const OPTION = 'recoveryflow_scheduler_driver';

	/**
	 * The Action Scheduler driver.
	 *
	 * @var Action_Scheduler_Driver
	 */
	private Action_Scheduler_Driver $action_scheduler;

	/**
	 * The WP-Cron driver.
	 *
	 * @var Wp_Cron_Driver
	 */
	private Wp_Cron_Driver $wp_cron;

	/**
	 * The driver in use this request, once resolved.
	 *
	 * @var Scheduler_Interface|null
	 */
	private ?Scheduler_Interface $active = null;

	/**
	 * Constructor.
	 *
	 * @param Action_Scheduler_Driver|null $action_scheduler Action Scheduler driver.
	 * @param Wp_Cron_Driver|null          $wp_cron          WP-Cron driver.
	 */
	public function __construct( ?Action_Scheduler_Driver $action_scheduler = null, ?Wp_Cron_Driver $wp_cron = null ) {
		$this->action_scheduler = $action_scheduler ?? new Action_Scheduler_Driver();
		$this->wp_cron          = $wp_cron ?? new Wp_Cron_Driver();
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		// The custom cron interval is registered whichever driver wins, so that
		// falling back to WP-Cron mid-request does not depend on the filter
		// having been added earlier in the same page load.
		$this->wp_cron->hooks();

		add_action( 'init', array( $this, 'sync' ), 20 );
	}

	/**
	 * The driver this site can use.
	 *
	 * @return Scheduler_Interface
	 */
	public function scheduler(): Scheduler_Interface {
		if ( null === $this->active ) {
			$this->active = $this->action_scheduler->is_available() ? $this->action_scheduler : $this->wp_cron;
		}

		return $this->active;
	}

	/**
	 * Both drivers, for the status screen and for cleaning up after a change.
	 *
	 * @return array<int,Scheduler_Interface>
	 */
	public function drivers(): array {
		return array( $this->action_scheduler, $this->wp_cron );
	}

	/**
	 * The WP-Cron driver, whether or not it is the active one.
	 *
	 * The status screen needs it even when Action Scheduler is in charge, to
	 * answer "is a system cron required here?".
	 *
	 * @return Wp_Cron_Driver
	 */
	public function wp_cron(): Wp_Cron_Driver {
		return $this->wp_cron;
	}

	/**
	 * The id of the driver recorded on the last sync.
	 *
	 * @return string Empty before the first sync.
	 */
	public function stored_id(): string {
		return (string) get_option( self::OPTION, '' );
	}

	/**
	 * Make the scheduled work match the driver this site can use.
	 *
	 * @return void
	 */
	public function sync(): void {
		$active = $this->scheduler();

		if ( $this->stored_id() !== $active->id() ) {
			foreach ( $this->drivers() as $driver ) {
				if ( $driver->id() !== $active->id() ) {
					$driver->unschedule_all();
				}
			}

			update_option( self::OPTION, $active->id(), false );
		}

		$active->schedule_all();
	}

	/**
	 * Remove every scheduled run, from both drivers. Called on deactivation.
	 *
	 * Both are cleared rather than only the active one: a site that lost
	 * WooCommerce between the last sync and this deactivation would otherwise
	 * leave its Action Scheduler rows behind.
	 *
	 * @return void
	 */
	public function unschedule_everything(): void {
		foreach ( $this->drivers() as $driver ) {
			$driver->unschedule_all();
		}

		delete_option( self::OPTION );
	}
}
