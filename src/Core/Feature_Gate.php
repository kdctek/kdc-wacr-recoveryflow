<?php
/**
 * Which features this workspace can use.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the WordPress-authored workflow features are available.
 *
 * RecoveryFlow works two ways. Every workspace can hand a journey to WA.cr and
 * let an Auto Flow do the messaging -- that path needs no developer credential
 * and is complete on its own. Authoring the sequence inside WordPress instead,
 * and sending directly, needs the WA.cr developer API, which is included from
 * the Scale plan upwards.
 *
 * The gate reads what the connection actually reported rather than asking the
 * merchant which plan they are on: a credential that can call the API is the
 * definition of eligible, and a plan change takes effect on the next check
 * without anybody re-entering anything.
 */
final class Feature_Gate {

	public const WORKFLOW_EDITOR = 'workflow_editor';
	public const DIRECT_SEND     = 'direct_send';
	public const ENGAGEMENT_POLL = 'engagement_polling';
	public const EXTRA_SOURCES   = 'extra_sources';

	/**
	 * Whether a feature is available on this site.
	 *
	 * @param string $feature One of the class constants.
	 * @return bool
	 */
	public static function is_enabled( string $feature ): bool {
		$enabled = self::has_developer_api();

		if ( self::ENGAGEMENT_POLL === $feature ) {
			$enabled = $enabled && self::has_scope( 'messages:read' );
		}

		if ( self::DIRECT_SEND === $feature ) {
			$enabled = $enabled && self::has_scope( 'messages:send' );
		}

		if ( defined( 'KDC_WACR_RECOVERYFLOW_UNLOCK_ALL' ) && constant( 'KDC_WACR_RECOVERYFLOW_UNLOCK_ALL' ) ) {
			$enabled = true;
		}

		/**
		 * Filter whether a RecoveryFlow feature is available.
		 *
		 * @param bool   $enabled Whether the feature is on.
		 * @param string $feature Feature name.
		 */
		return (bool) apply_filters( Hooks::FILTER_FEATURE_ENABLED, $enabled, $feature );
	}

	/**
	 * Whether the stored credential can call the developer API.
	 *
	 * @return bool
	 */
	public static function has_developer_api(): bool {
		$snapshot = self::snapshot();

		return ! empty( $snapshot['ok'] );
	}

	/**
	 * Whether the credential holds a scope.
	 *
	 * @param string $scope Scope name, e.g. messages:send.
	 * @return bool
	 */
	public static function has_scope( string $scope ): bool {
		$snapshot = self::snapshot();
		$scopes   = isset( $snapshot['scopes'] ) && is_array( $snapshot['scopes'] ) ? $snapshot['scopes'] : array();

		return in_array( $scope, $scopes, true );
	}

	/**
	 * The cached description of the connected credential.
	 *
	 * Shape: ok, tenant_name, mode, scopes[], reason, checked_at.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array {
		$snapshot = get_option( Options::ME_SNAPSHOT, array() );

		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Why the developer API is unavailable, in words an operator can act on.
	 *
	 * @return string Empty when it is available.
	 */
	public static function unavailable_reason(): string {
		$snapshot = self::snapshot();

		if ( ! empty( $snapshot['ok'] ) ) {
			return '';
		}

		$reason = isset( $snapshot['reason'] ) ? (string) $snapshot['reason'] : '';

		switch ( $reason ) {
			case 'plan_upgrade_required':
				return __( 'Authoring recovery workflows in WordPress is included with the WA.cr Scale plan and above. Your workspace can still hand recovery journeys to a WA.cr Auto Flow.', 'kdc-wacr-recoveryflow' );

			case 'invalid_key':
			case 'key_revoked':
				return __( 'The saved WA.cr API key was refused. Create a new one in the WA.cr console and save it again.', 'kdc-wacr-recoveryflow' );

			case 'undecryptable':
				return __( 'The saved WA.cr API key can no longer be read, which happens when a site\'s security keys are rotated. Enter the key again.', 'kdc-wacr-recoveryflow' );

			case '':
				return __( 'No WA.cr API key is connected yet.', 'kdc-wacr-recoveryflow' );

			default:
				return __( 'WA.cr could not confirm this connection. Check the connection on the Settings screen.', 'kdc-wacr-recoveryflow' );
		}
	}
}
