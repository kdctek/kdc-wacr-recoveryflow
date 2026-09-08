<?php
/**
 * Table name resolution.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a table name is spelled out.
 *
 * Nothing else in the plugin interpolates $wpdb->prefix into SQL, so a table
 * name can never come from user input.
 */
final class Table_Names {

	public const CUSTOMERS         = 'recoveryflow_customers';
	public const IDENTITIES        = 'recoveryflow_identities';
	public const CONSENTS          = 'recoveryflow_consents';
	public const EVENTS            = 'recoveryflow_events';
	public const JOURNEYS          = 'recoveryflow_journeys';
	public const ATTEMPTS          = 'recoveryflow_attempts';
	public const WORKFLOWS         = 'recoveryflow_workflows';
	public const WORKFLOW_VERSIONS = 'recoveryflow_workflow_versions';
	public const LOGS              = 'recoveryflow_logs';
	public const RECEIPTS          = 'recoveryflow_receipts';
	public const LOCKS             = 'recoveryflow_locks';

	/**
	 * Every table this plugin owns, unprefixed.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::CUSTOMERS,
			self::IDENTITIES,
			self::CONSENTS,
			self::EVENTS,
			self::JOURNEYS,
			self::ATTEMPTS,
			self::WORKFLOWS,
			self::WORKFLOW_VERSIONS,
			self::LOGS,
			self::RECEIPTS,
			self::LOCKS,
		);
	}

	/**
	 * Prefix one table name for the current site.
	 *
	 * @param string $table One of the class constants.
	 * @return string
	 */
	public static function get( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . $table;
	}
}
