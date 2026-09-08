<?php
/**
 * Admin stylesheet and scripts.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Admin\Settings\Page as Settings_Page;
use WAcr\RecoveryFlow\REST\Routes;
use WAcr\RecoveryFlow\REST\Settings_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the admin assets, on this plugin's screens and nowhere else.
 *
 * There is no build step and no framework. The stylesheet adjusts core's own
 * components rather than replacing them, and the script only enhances markup
 * the server has already sent: collapse a panel, move focus to a deeplinked
 * control, copy a section link. Every one of those is something the screen does
 * without it, more slowly.
 *
 * The script carries no user-facing strings of its own. Everything it might
 * announce is passed in from PHP, already translated, which keeps the whole
 * plugin's translatable text in the .pot the generator produces from PHP -- and
 * avoids a JavaScript i18n build that would otherwise be needed for one
 * sentence.
 */
final class Assets {

	/**
	 * The menu, which knows which screens are ours.
	 *
	 * @var Menu
	 */
	private Menu $menu;

	/**
	 * Constructor.
	 *
	 * @param Menu $menu The menu.
	 */
	public function __construct( Menu $menu ) {
		$this->menu = $menu;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue on a RecoveryFlow screen.
	 *
	 * @param string $hook Current screen's hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( ! $this->menu->owns( $hook ) ) {
			return;
		}

		$base    = KDC_WACR_RECOVERYFLOW_URL;
		$version = KDC_WACR_RECOVERYFLOW_VERSION;

		wp_enqueue_style(
			'recoveryflow-admin',
			$base . 'assets/css/admin.css',
			array( 'common' ),
			$version
		);

		wp_enqueue_script(
			'recoveryflow-admin',
			$base . 'assets/js/admin.js',
			array( 'wp-a11y' ),
			$version,
			true
		);

		wp_add_inline_script(
			'recoveryflow-admin',
			'window.recoveryFlowAdmin = ' . wp_json_encode( $this->script_data() ) . ';',
			'before'
		);
	}

	/**
	 * Which expandable panels this person left open.
	 *
	 * Read here rather than fetched by the script, so a panel that should be
	 * open is open in the first paint instead of springing open a moment later.
	 *
	 * @return string[]
	 */
	private function open_panels(): array {
		$state = get_user_meta( get_current_user_id(), Settings_Controller::UI_META, true );

		return is_array( $state ) ? array_keys( $state ) : array();
	}

	/**
	 * What the script needs to know, none of it personal.
	 *
	 * @return array<string,mixed>
	 */
	private function script_data(): array {
		return array(
			'focusField'  => Settings_Page::focused_field(),
			'fieldPrefix' => Screen::field_anchor( '' ),
			'uiStateUrl'  => Routes::url( 'ui-state' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'openPanels'  => $this->open_panels(),
			'strings'     => array(
				'focused'    => __( 'Moved to the setting you followed the link for.', 'kdc-wacr-recoveryflow' ),
				'linkCopied' => __( 'Link to this section copied.', 'kdc-wacr-recoveryflow' ),
				'linkFailed' => __( 'The link could not be copied. Copy it from the address bar instead.', 'kdc-wacr-recoveryflow' ),
			),
		);
	}
}
