<?php
/**
 * Environment gate.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses to boot on an environment the plugin cannot run on.
 *
 * The plugin degrades rather than fatals: an unmet requirement leaves
 * WordPress entirely usable and explains itself in an admin notice.
 */
final class Requirements {

	/**
	 * Whether the environment can run the plugin.
	 *
	 * @return bool
	 */
	public static function met(): bool {
		return array() === self::failures();
	}

	/**
	 * The unmet requirements, as translated sentences.
	 *
	 * @return string[]
	 */
	public static function failures(): array {
		$failures = array();

		if ( version_compare( PHP_VERSION, KDC_WACR_RECOVERYFLOW_MIN_PHP, '<' ) ) {
			$failures[] = sprintf(
				/* translators: 1: required PHP version, 2: the PHP version running now. */
				__( 'RecoveryFlow needs PHP %1$s or newer. This site runs PHP %2$s.', 'kdc-wacr-recoveryflow' ),
				KDC_WACR_RECOVERYFLOW_MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), KDC_WACR_RECOVERYFLOW_MIN_WP, '<' ) ) {
			$failures[] = sprintf(
				/* translators: 1: required WordPress version, 2: the WordPress version running now. */
				__( 'RecoveryFlow needs WordPress %1$s or newer. This site runs WordPress %2$s.', 'kdc-wacr-recoveryflow' ),
				KDC_WACR_RECOVERYFLOW_MIN_WP,
				get_bloginfo( 'version' )
			);
		}

		return $failures;
	}

	/**
	 * Tell an administrator what is missing.
	 *
	 * @return void
	 */
	public static function show_notice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				foreach ( self::failures() as $failure ) {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html( $failure )
					);
				}
			}
		);
	}
}
