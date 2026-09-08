<?php
/**
 * Deactivation.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stops background work when the plugin is switched off.
 *
 * Data is deliberately left alone: deactivating is not uninstalling, and a
 * merchant who toggles the plugin while debugging must not lose their journeys.
 */
final class Deactivator {

	/**
	 * Unschedule everything and tidy rewrites.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::clear_action_scheduler();
		self::clear_wp_cron();

		flush_rewrite_rules();
	}

	/**
	 * Cancel every action this plugin scheduled.
	 *
	 * Cancelling by hook name would miss most of them. Action Scheduler matches
	 * a hook-and-arguments pair, and passing an empty argument list matches only
	 * actions that were themselves scheduled with no arguments -- so the
	 * continuation actions, which carry a stage, would survive deactivation and
	 * keep running against a plugin the merchant has switched off. Cancelling
	 * the whole group is the only form that catches everything.
	 *
	 * @return void
	 */
	private static function clear_action_scheduler(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( '', array(), 'recoveryflow' );
	}

	/**
	 * Clear the fallback scheduler's events.
	 *
	 * @return void
	 */
	private static function clear_wp_cron(): void {
		$hooks = array(
			'recoveryflow/tick',
			'recoveryflow/evaluate',
			'recoveryflow/dispatch',
			'recoveryflow/poll',
			'recoveryflow/expire',
			'recoveryflow/retention',
			'recoveryflow/run/evaluate',
			'recoveryflow/run/dispatch',
			'recoveryflow/run/poll',
			'recoveryflow/run/expire',
			'recoveryflow/run/retention',
		);

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
