<?php
/**
 * Activation.
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
 * Prepares the site the first time the plugin is switched on.
 *
 * Everything here is idempotent, because activation runs again after every
 * update and on every site of a network.
 */
final class Activator {

	/**
	 * Run the installer.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! Requirements::met() ) {
			return;
		}

		Schema::install();
		Capabilities::install();
		Hash_Key::install();
		Options::install();

		// The default workflows and the background schedule are what make the
		// plugin do anything at all. Both are idempotent: seeding checks for an
		// existing slug, and scheduling checks for an existing action.
		$plugin = Plugin::instance();

		$plugin->workflows()->seed_defaults();
		$plugin->scheduler()->sync();

		// Activation runs on an ordinary admin request, so init has fired and
		// $wp_rewrite exists: the rule can be added directly and flushed. Boot
		// cannot do this, which is why Rewrites::hooks() defers to init.
		Rewrites::register();
		flush_rewrite_rules();

		/**
		 * Fires once the plugin has finished installing itself.
		 */
		do_action( Hooks::ACTIVATED );
	}
}
