<?php
/**
 * Which features this workspace can use.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\WAcr\Credentials;

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
 *
 * ! EVERY FEATURE NAMED HERE MUST BE ONE THIS PLUGIN CANNOT PERFORM ON ITS OWN.
 *
 * WordPress.org guideline 5 forbids restricting functionality that is
 * implemented in the plugin's own code, and guideline 6 permits requiring a
 * paid third-party service for what genuinely needs one. Sending a WhatsApp
 * template and reading its delivery status are calls to WA.cr; without a
 * credential the plugin cannot make them, so refusing is a fact rather than a
 * restriction.
 *
 * `extra_sources` used to be here and was neither. It switched off every
 * recovery source but one -- adapters that ship in this plugin, watching local
 * tables and calling WA.cr for nothing, plus anything a third party registered
 * through the filter -- on any workspace below Scale. That is a paywall on the
 * plugin's own behaviour, and it was removed rather than reworded.
 * tests/smoke.php asserts the list below stays API-only.
 */
final class Feature_Gate {

	public const WORKFLOW_EDITOR = 'workflow_editor';
	public const DIRECT_SEND     = 'direct_send';
	public const ENGAGEMENT_POLL = 'engagement_polling';

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
	 * What the Auto Flow hand-off itself needs, which is not nothing.
	 *
	 * The hook is not gated on the developer API, and that was read for a
	 * whole release as "so no plan gates it". It does not. Running an Auto
	 * Flow needs a plan whose Auto Flow allowance is above zero, which starts
	 * at Growth -- so on free, trial and starter RecoveryFlow can dispatch
	 * nothing to WA.cr at all, by either route.
	 *
	 * The plugin cannot check this from here and does not pretend to: the
	 * endpoint that would answer is itself part of the developer API, so a
	 * workspace on Growth -- exactly the one this sentence is for -- cannot
	 * call it. The truthful runtime signal is the hook's own answer, which
	 * Flow_Status carries to the Connection screen the first time a hand-off
	 * is refused.
	 *
	 * @return string
	 */
	public static function auto_flow_requirement(): string {
		return __( 'Handing journeys to a WA.cr Auto Flow needs a WA.cr plan that can run one, which is Growth and above.', 'kdc-wacr-recoveryflow' );
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
				return __( 'Authoring recovery workflows in WordPress is included with the WA.cr Scale plan and above. Your workspace can instead hand recovery journeys to a WA.cr Auto Flow.', 'kdc-wacr-recoveryflow' ) . ' ' . self::auto_flow_requirement();

			case 'invalid_key':
			case 'key_revoked':
				return __( 'The saved WA.cr API key was refused. Create a new one in the WA.cr console and save it again.', 'kdc-wacr-recoveryflow' );

			case 'undecryptable':
				return __( 'The saved WA.cr API key can no longer be read, which happens when a site\'s security keys are rotated. Enter the key again.', 'kdc-wacr-recoveryflow' );

			case '':
				return self::unchecked_reason();

			default:
				return __( 'WA.cr could not confirm this connection. Check the connection on the Settings screen.', 'kdc-wacr-recoveryflow' );
		}
	}

	/**
	 * Why the API is unavailable when nothing has ever been checked.
	 *
	 * An empty snapshot is not the same fact as an absent key, and reading it
	 * as one is how the settings screen came to say "A key is saved" and "No
	 * WA.cr API key is connected yet" one line apart. The snapshot is written
	 * only by a connection check, so a key that has been saved and never
	 * checked produces exactly the same empty option as no key at all. The
	 * difference is visible only by asking whether a key is stored, which is
	 * what this does.
	 *
	 * @return string
	 */
	private static function unchecked_reason(): string {
		$credentials = new Credentials();

		if ( $credentials->is_key_unreadable() ) {
			return __( 'The saved WA.cr API key can no longer be read, which happens when a site\'s security keys are rotated. Enter the key again.', 'kdc-wacr-recoveryflow' );
		}

		if ( ! $credentials->has_api_key() ) {
			return __( 'No WA.cr API key is connected yet.', 'kdc-wacr-recoveryflow' );
		}

		return __( 'The saved WA.cr API key has not been checked yet. Use "Test connection" to check it against WA.cr.', 'kdc-wacr-recoveryflow' );
	}
}
