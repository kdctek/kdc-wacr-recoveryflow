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
		foreach ( array( 'evaluate', 'dispatch', 'poll', 'expire', 'retention' ) as $stage ) {
			$hook = 'recoveryflow/' . $stage;

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'recoveryflow' );
			}

			$timestamp = wp_next_scheduled( $hook );

			while ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}

		wp_clear_scheduled_hook( 'recoveryflow/tick' );

		flush_rewrite_rules();
	}
}
