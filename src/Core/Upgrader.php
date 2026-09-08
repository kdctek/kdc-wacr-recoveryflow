<?php
/**
 * Version migrations.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

use WAcr\RecoveryFlow\Database\Schema;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Security\Hash_Key;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Brings an already-installed site up to the running version.
 *
 * Activation hooks do not fire when a plugin is updated in place, so the check
 * has to happen on a normal request. It is a single option read in the common
 * case, which is cheap enough to do on every load.
 */
final class Upgrader {

	public const OPTION = 'recoveryflow_version';

	/**
	 * Run pending migrations.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::OPTION, '' );

		if ( KDC_WACR_RECOVERYFLOW_VERSION === $installed ) {
			return;
		}

		Schema::maybe_upgrade();
		Capabilities::maybe_install();
		Hash_Key::install();
		Options::install();

		// Everything activation installs has to be installed here too, because
		// updating a plugin does not run its activation hook. A site that had
		// RecoveryFlow switched on before this version would otherwise come out
		// of the update with the tables and the schedule but no workflows -- and
		// a journey cannot be created without one, so the plugin would sit there
		// detecting abandoned carts and silently recovering none of them.
		$plugin = Plugin::instance();

		$plugin->workflows()->seed_defaults();
		$plugin->scheduler()->sync();

		update_option( self::OPTION, KDC_WACR_RECOVERYFLOW_VERSION, false );

		/**
		 * Fires after the plugin has migrated itself to a new version.
		 *
		 * @param string $to   Version now installed.
		 * @param string $from Version previously installed, '' on a fresh install.
		 */
		do_action( Hooks::UPGRADED, KDC_WACR_RECOVERYFLOW_VERSION, $installed );
	}
}
