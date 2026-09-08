<?php
/**
 * The RecoveryFlow admin menu.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Admin\Settings\Page as Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's screens and remembers which ones are ours.
 *
 * Each entry names the capability WordPress should check before showing it,
 * taken from Screen rather than repeated here, so the menu and the screen it
 * opens can never disagree about who is allowed in.
 *
 * The hook suffixes WordPress hands back are kept, because they are the only
 * reliable way to tell "this request is on a RecoveryFlow screen" later. Asset
 * loading depends on that answer: a plugin that enqueues its stylesheet on
 * every admin page is a plugin that breaks somebody else's.
 */
final class Menu {

	/**
	 * Hook suffixes of the screens this plugin registered.
	 *
	 * @var string[]
	 */
	private array $hooks = array();

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_init', array( Settings_Page::class, 'register' ) );
	}

	/**
	 * Register the menu and its screens.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->hooks = array();

		$top = add_menu_page(
			__( 'RecoveryFlow', 'kdc-wacr-recoveryflow' ),
			__( 'RecoveryFlow', 'kdc-wacr-recoveryflow' ),
			Screen::capability( Screen::SETTINGS ),
			Screen::SETTINGS,
			array( Settings_Page::class, 'render' ),
			'dashicons-cart',
			57
		);

		if ( is_string( $top ) ) {
			$this->hooks[] = $top;
		}

		$settings = add_submenu_page(
			Screen::SETTINGS,
			__( 'RecoveryFlow settings', 'kdc-wacr-recoveryflow' ),
			__( 'Settings', 'kdc-wacr-recoveryflow' ),
			Screen::capability( Screen::SETTINGS ),
			Screen::SETTINGS,
			array( Settings_Page::class, 'render' )
		);

		if ( is_string( $settings ) ) {
			$this->hooks[] = $settings;
		}
	}

	/**
	 * The hook suffixes of this plugin's screens.
	 *
	 * @return string[]
	 */
	public function hook_suffixes(): array {
		return $this->hooks;
	}

	/**
	 * Whether a hook suffix belongs to one of this plugin's screens.
	 *
	 * @param string $hook Hook suffix WordPress passed to admin_enqueue_scripts.
	 * @return bool
	 */
	public function owns( string $hook ): bool {
		return in_array( $hook, $this->hooks, true );
	}
}
