<?php
/**
 * The plugin's admin screens, and how to link to them.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Page slugs, the capability each one needs, and URL building.
 *
 * Every link into the admin is built here rather than by concatenating query
 * strings at the call site. That matters more than it looks: a deeplink is a
 * three-part address -- page, tab, section, and optionally the individual field
 * to put the cursor in -- and those parts are quoted in notices, in the system
 * status checks, in docs and in support replies. One builder means a renamed
 * section is a single edit, and a typo in a link is a fatal in development
 * rather than a page that silently opens on the wrong tab.
 */
final class Screen {

	/**
	 * The top-level menu, which is the overview.
	 */
	public const OVERVIEW = 'recoveryflow';

	/**
	 * The journey queue.
	 */
	public const JOURNEYS = 'recoveryflow-journeys';

	/**
	 * One journey in full.
	 */
	public const JOURNEY = 'recoveryflow-journey';

	/**
	 * The integrations screen.
	 */
	public const INTEGRATIONS = 'recoveryflow-integrations';

	/**
	 * The settings screen.
	 */
	public const SETTINGS = 'recoveryflow-settings';

	/**
	 * The system status screen.
	 */
	public const STATUS = 'recoveryflow-status';

	/**
	 * Which capability each screen needs.
	 *
	 * @return array<string,string> Page slug => capability.
	 */
	public static function capabilities(): array {
		return array(
			self::OVERVIEW     => Capabilities::VIEW_STATUS,
			self::JOURNEYS     => Capabilities::VIEW_JOURNEYS,
			self::JOURNEY      => Capabilities::VIEW_JOURNEYS,
			self::INTEGRATIONS => Capabilities::MANAGE_SETTINGS,
			self::SETTINGS     => Capabilities::MANAGE_SETTINGS,
			self::STATUS       => Capabilities::VIEW_STATUS,
		);
	}

	/**
	 * The capability a screen needs.
	 *
	 * An unknown slug falls back to the settings capability, which is the most
	 * restrictive one: a screen someone forgot to list is closed, not open.
	 *
	 * @param string $page Page slug.
	 * @return string
	 */
	public static function capability( string $page ): string {
		$caps = self::capabilities();

		return $caps[ $page ] ?? Capabilities::MANAGE_SETTINGS;
	}

	/**
	 * Whether a page slug is one of this plugin's.
	 *
	 * @param string $page Page slug.
	 * @return bool
	 */
	public static function is_ours( string $page ): bool {
		return array_key_exists( $page, self::capabilities() );
	}

	/**
	 * The URL of one of the plugin's screens.
	 *
	 * @param string               $page Page slug.
	 * @param array<string,scalar> $args Extra query arguments.
	 * @return string
	 */
	public static function url( string $page = self::OVERVIEW, array $args = array() ): string {
		$query = array_merge( array( 'page' => $page ), $args );

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * A deeplink into the settings screen.
	 *
	 * The three arguments narrow in the order a person reads the screen: which
	 * tab, which section of it, and which control. Passing only a tab is a
	 * perfectly good link; passing a field is how a notice says "the thing you
	 * need to fix is this box" without the reader hunting for it.
	 *
	 * @param string $tab     Tab id.
	 * @param string $section Section id within that tab.
	 * @param string $field   Setting key to focus.
	 * @return string
	 */
	public static function settings_url( string $tab = '', string $section = '', string $field = '' ): string {
		$args = array();

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		if ( '' !== $section ) {
			$args['section'] = $section;
		}

		if ( '' !== $field ) {
			$args['field'] = $field;
		}

		$url = self::url( self::SETTINGS, $args );

		// The fragment is the no-JavaScript half of the deeplink: with scripts
		// off the browser still scrolls to the right control, and the target
		// styling in admin.css still marks it.
		return '' === $field ? $url : $url . '#' . self::field_anchor( $field );
	}

	/**
	 * The id a settings control is addressed by, in markup and in a fragment.
	 *
	 * @param string $field Setting key.
	 * @return string
	 */
	public static function field_anchor( string $field ): string {
		return 'recoveryflow-field-' . str_replace( '_', '-', $field );
	}

	/**
	 * The id a settings section heading is addressed by.
	 *
	 * @param string $section Section id.
	 * @return string
	 */
	public static function section_anchor( string $section ): string {
		return 'recoveryflow-section-' . str_replace( '_', '-', $section );
	}

	/**
	 * The URL of one journey.
	 *
	 * @param string $uid Journey uid.
	 * @return string
	 */
	public static function journey_url( string $uid ): string {
		return self::url( self::JOURNEY, array( 'journey' => $uid ) );
	}
}
