<?php
/**
 * Uninstall routine.
 *
 * @package WAcr\RecoveryFlow
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Core/Autoloader.php';

( new WAcr\RecoveryFlow\Core\Autoloader( __DIR__ . '/src/' ) )->register();

/**
 * Remove this site's data.
 *
 * Credentials, capabilities and scheduled work always go: leaving an API key in
 * the database of a site that no longer has the plugin would be indefensible.
 * Customer data and journeys are only dropped when the merchant asked for that
 * on the Privacy settings screen, because uninstalling to move hosts must not
 * silently destroy a recovery history.
 *
 * @return void
 */
function kdc_wacr_recoveryflow_uninstall_site() {
	$settings = get_option( \WAcr\RecoveryFlow\Support\Options::SETTINGS, array() );
	$purge    = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	// Background work first: an orphaned scheduled action would fire against
	// tables that are about to disappear.
	foreach ( array( 'evaluate', 'dispatch', 'poll', 'expire', 'retention', 'tick' ) as $stage ) {
		$hook = 'recoveryflow/' . $stage;

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), 'recoveryflow' );
		}

		wp_clear_scheduled_hook( $hook );
	}

	\WAcr\RecoveryFlow\Security\Capabilities::uninstall();

	delete_option( \WAcr\RecoveryFlow\Support\Options::API_KEY );
	delete_option( \WAcr\RecoveryFlow\Support\Options::HOOK_SECRET );
	delete_option( \WAcr\RecoveryFlow\Support\Options::WEBHOOK_SECRET );
	delete_option( \WAcr\RecoveryFlow\Support\Options::ME_SNAPSHOT );

	if ( ! $purge ) {
		return;
	}

	\WAcr\RecoveryFlow\Database\Schema::drop();

	foreach ( \WAcr\RecoveryFlow\Support\Options::all_option_names() as $option ) {
		delete_option( $option );
	}

	delete_option( \WAcr\RecoveryFlow\Core\Upgrader::OPTION );
}

if ( is_multisite() ) {
	$kdc_wacr_recoveryflow_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $kdc_wacr_recoveryflow_sites as $kdc_wacr_recoveryflow_site_id ) {
		switch_to_blog( (int) $kdc_wacr_recoveryflow_site_id );
		kdc_wacr_recoveryflow_uninstall_site();
		restore_current_blog();
	}
} else {
	kdc_wacr_recoveryflow_uninstall_site();
}
