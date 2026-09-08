<?php
/**
 * Plugin Name:       RecoveryFlow by WA.cr
 * Plugin URI:        https://wa.cr/recoveryflow
 * Description:       Turn lost conversions into conversations that convert. RecoveryFlow detects abandoned journeys across your WordPress commerce, forms, ticketing and booking systems, and recovers them through WA.cr on WhatsApp. Requires an active WA.cr account.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            KDC
 * Author URI:        https://kdc.in
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kdc-wacr-recoveryflow
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package WAcr\RecoveryFlow
 */

defined( 'ABSPATH' ) || exit;

define( 'KDC_WACR_RECOVERYFLOW_VERSION', '0.1.0' );
define( 'KDC_WACR_RECOVERYFLOW_FILE', __FILE__ );
define( 'KDC_WACR_RECOVERYFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'KDC_WACR_RECOVERYFLOW_URL', plugin_dir_url( __FILE__ ) );
define( 'KDC_WACR_RECOVERYFLOW_BASENAME', plugin_basename( __FILE__ ) );
define( 'KDC_WACR_RECOVERYFLOW_MIN_PHP', '8.0' );
define( 'KDC_WACR_RECOVERYFLOW_MIN_WP', '6.5' );

require_once KDC_WACR_RECOVERYFLOW_DIR . 'src/Core/Autoloader.php';

( new WAcr\RecoveryFlow\Core\Autoloader( KDC_WACR_RECOVERYFLOW_DIR . 'src/' ) )->register();

/**
 * The plugin container.
 *
 * @return WAcr\RecoveryFlow\Core\Plugin
 */
function kdc_wacr_recoveryflow(): WAcr\RecoveryFlow\Core\Plugin {
	return WAcr\RecoveryFlow\Core\Plugin::instance();
}

/*
 * HPOS and Blocks compatibility must be declared before WooCommerce boots, which
 * is why it lives here rather than inside the WooCommerce integration. The
 * integration itself stays entirely optional -- this file never assumes
 * WooCommerce is installed.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', KDC_WACR_RECOVERYFLOW_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', KDC_WACR_RECOVERYFLOW_FILE, true );
	}
);

register_activation_hook( __FILE__, array( WAcr\RecoveryFlow\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( WAcr\RecoveryFlow\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! WAcr\RecoveryFlow\Core\Requirements::met() ) {
			WAcr\RecoveryFlow\Core\Requirements::show_notice();

			return;
		}

		kdc_wacr_recoveryflow()->boot();
	},
	5
);
