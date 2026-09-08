<?php
/**
 * Custom capabilities.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Security;

use WAcr\RecoveryFlow\Core\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Who may do what.
 *
 * Recovery journeys carry customer contact details, so access is granted
 * through named capabilities rather than manage_options. A shop manager should
 * be able to work the queue without also being handed the site's settings.
 */
final class Capabilities {

	public const MANAGE_SETTINGS  = 'recoveryflow_manage_settings';
	public const VIEW_JOURNEYS    = 'recoveryflow_view_journeys';
	public const MANAGE_JOURNEYS  = 'recoveryflow_manage_journeys';
	public const MANAGE_WORKFLOWS = 'recoveryflow_manage_workflows';
	public const VIEW_STATUS      = 'recoveryflow_view_status';
	public const REVEAL_PII       = 'recoveryflow_reveal_pii';

	public const VERSION_OPTION = 'recoveryflow_caps_version';
	public const VERSION        = 1;

	/**
	 * Every capability this plugin defines.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::MANAGE_SETTINGS,
			self::VIEW_JOURNEYS,
			self::MANAGE_JOURNEYS,
			self::MANAGE_WORKFLOWS,
			self::VIEW_STATUS,
			self::REVEAL_PII,
		);
	}

	/**
	 * Which roles get which capabilities.
	 *
	 * A shop manager gets everything except the settings screen: the API
	 * credential and the eligibility rules are the site owner's decisions, and
	 * the number a message is sent from is a billing decision.
	 *
	 * @return array<string,string[]> Role slug => capabilities.
	 */
	public static function map(): array {
		$map = array(
			'administrator' => self::all(),
			'shop_manager'  => array(
				self::VIEW_JOURNEYS,
				self::MANAGE_JOURNEYS,
				self::MANAGE_WORKFLOWS,
				self::VIEW_STATUS,
				self::REVEAL_PII,
			),
		);

		/**
		 * Filter which roles hold which RecoveryFlow capabilities.
		 *
		 * @param array<string,string[]> $map Role slug => capability names.
		 */
		return (array) apply_filters( Hooks::FILTER_CAPABILITY_MAP, $map );
	}

	/**
	 * Grant the capabilities. Idempotent.
	 *
	 * @return void
	 */
	public static function install(): void {
		foreach ( self::map() as $role_name => $caps ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Re-grant when the capability set has changed since the last run.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) !== self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Re-grant after another plugin activates.
	 *
	 * A role that does not exist yet cannot be granted anything. WooCommerce
	 * creates shop_manager when it activates, so a site that installs
	 * RecoveryFlow first would leave its shop managers with no access at all
	 * and no obvious reason why. Re-running on any activation is a handful of
	 * option reads at the one moment the role set can change.
	 *
	 * @return void
	 */
	public static function on_plugin_activated(): void {
		self::install();
	}

	/**
	 * Roles that are missing capabilities they should hold.
	 *
	 * Reported on the status screen, so "my shop managers cannot see the
	 * journeys" is answerable without guessing.
	 *
	 * @return array<string,string[]> Role slug => missing capabilities.
	 */
	public static function missing_grants(): array {
		$missing = array();

		foreach ( self::map() as $role_name => $caps ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			$absent = array();

			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$absent[] = $cap;
				}
			}

			if ( array() !== $absent ) {
				$missing[ $role_name ] = $absent;
			}
		}

		return $missing;
	}

	/**
	 * Remove every capability from every role.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Let manage_options stand in for the two owner capabilities.
	 *
	 * A role plugin that resets the administrator role would otherwise lock the
	 * site owner out of their own settings with no way back in. The fallback is
	 * deliberately narrow: it does not extend to reading customer details.
	 *
	 * @param string[] $caps    Required primitive capabilities.
	 * @param string   $cap     The capability being checked.
	 * @param int      $user_id User being checked.
	 * @param array    $args    Context.
	 * @return string[]
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		unset( $args );

		if ( self::MANAGE_SETTINGS !== $cap && self::VIEW_STATUS !== $cap ) {
			return $caps;
		}

		// A role that holds the capability outright keeps it, so the two can
		// still be delegated to a non-administrator role. allcaps is read
		// directly rather than through user_can(), which would recurse here.
		$user = get_userdata( $user_id );

		if ( $user instanceof \WP_User && ! empty( $user->allcaps[ $cap ] ) ) {
			return $caps;
		}

		return array( 'manage_options' );
	}
}
