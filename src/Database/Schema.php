<?php
/**
 * Table definitions and migrations.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 *
 * Recovery events are high volume -- a busy store writes one row per shopping
 * session -- so they live in dedicated, indexed tables rather than in post meta.
 * Every processor query is served by one of the indexes declared here; none of
 * them scans the table.
 *
 * The DDL is written in dbDelta's dialect, which is stricter than MySQL's: one
 * field per line, two spaces after PRIMARY KEY, KEY rather than INDEX, and the
 * same key names on every run. Deviating makes dbDelta re-run ALTER statements
 * on every upgrade.
 */
final class Schema {

	public const VERSION        = 1;
	public const VERSION_OPTION = 'recoveryflow_db_version';

	/**
	 * Create or update every table.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::statements() as $sql ) {
			dbDelta( $sql );
		}

		self::seed_locks();

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Run the installer when the stored schema version is behind.
	 *
	 * @return bool Whether anything was done.
	 */
	public static function maybe_upgrade(): bool {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::VERSION ) {
			return false;
		}

		self::install();

		return true;
	}

	/**
	 * Drop every table. Only ever called from uninstall.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( Table_Names::all() as $table ) {
			$name = Table_Names::get( $table );

			// The name comes from a class constant, never from input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Insert the rows the stage locks are held on.
	 *
	 * A lock is acquired with a conditional UPDATE, which needs the row to
	 * exist. add_option() cannot be used as a mutex -- WordPress writes it with
	 * INSERT ... ON DUPLICATE KEY UPDATE, so a second caller overwrites the
	 * first and is told it succeeded.
	 *
	 * @return void
	 */
	private static function seed_locks(): void {
		global $wpdb;

		$table = Table_Names::get( Table_Names::LOCKS );

		foreach ( array( 'evaluate', 'dispatch', 'poll', 'expire', 'retention', 'wacr_rate' ) as $key ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$table}` (lock_key, owner, expires_at) VALUES (%s, NULL, NULL)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$key
				)
			);
		}
	}

	/**
	 * The CREATE TABLE statements, in dependency order.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$sql = array();

		/*
		 * A customer is a person we might message. phone_hash is the identity
		 * key and the only UNIQUE one: the phone number is what a recovery
		 * message is addressed to, so two records with the same number are the
		 * same person. Email is a fallback match, never a merge key -- shared
		 * family addresses and role addresses would merge strangers.
		 *
		 * Hashes are keyed HMACs, not bare SHA-256: a plain digest of a phone
		 * number is trivially reversed by enumerating the ten-digit space, and
		 * these outlive erasure as the suppression list.
		 */
		$sql[] = "CREATE TABLE {$p}recoveryflow_customers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NULL,
			email varchar(191) NULL,
			email_hash char(64) NULL,
			phone_e164 varchar(20) NULL,
			phone_raw varchar(32) NULL,
			phone_status varchar(12) NOT NULL DEFAULT 'unknown',
			phone_hash char(64) NULL,
			first_name varchar(100) NULL,
			last_name varchar(100) NULL,
			country_iso2 char(2) NULL,
			wacr_contact_id varchar(64) NULL,
			wacr_synced_at datetime NULL,
			consent_status varchar(12) NOT NULL DEFAULT 'unknown',
			opted_out_at datetime NULL,
			anonymized_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY phone_hash (phone_hash),
			KEY wp_user_id (wp_user_id),
			KEY email_hash (email_hash),
			KEY anonymized_at (anonymized_at)
		) {$charset_collate};";

		/*
		 * Consent is append-only: the latest row for a phone hash wins, and the
		 * history is the evidence that a message was lawful. A 'suppressed' row
		 * survives erasure, because forgetting that somebody opted out is the
		 * one thing an erasure must not do.
		 */
		$sql[] = "CREATE TABLE {$p}recoveryflow_consents (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone_hash char(64) NOT NULL,
			customer_id bigint(20) unsigned NULL,
			status varchar(12) NOT NULL,
			source varchar(32) NOT NULL,
			text_version varchar(16) NULL,
			ip_hash char(64) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY phone_latest (phone_hash,id),
			KEY customer_id (customer_id)
		) {$charset_collate};";

		/*
		 * One row per open journey, not one per change: the cart is upserted on
		 * (source_id, dedupe_key) as it changes. When a row closes its
		 * dedupe_key is renamed, which frees the key for the session's next
		 * cart and keeps the unique index meaningful.
		 */
		$sql[] = "CREATE TABLE {$p}recoveryflow_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_uid char(36) NOT NULL,
			source_id varchar(32) NOT NULL,
			source_type varchar(32) NOT NULL,
			dedupe_key varchar(191) NOT NULL,
			customer_id bigint(20) unsigned NULL,
			journey_id bigint(20) unsigned NULL,
			session_key varchar(191) NULL,
			external_id varchar(191) NULL,
			currency char(3) NULL,
			amount decimal(19,4) NOT NULL DEFAULT 0,
			item_count int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(12) NOT NULL DEFAULT 'open',
			status_reason varchar(32) NULL,
			items_json longtext NULL,
			metadata_json longtext NULL,
			last_activity_at datetime NOT NULL,
			completed_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_uid (event_uid),
			UNIQUE KEY source_dedupe (source_id,dedupe_key),
			KEY evaluate (status,journey_id,last_activity_at),
			KEY session_key (session_key(64)),
			KEY customer_id (customer_id),
			KEY external_id (source_id,external_id(64)),
			KEY retention (status,updated_at)
		) {$charset_collate};";

		/*
		 * The journey is the state machine. next_action_at drives the workflow,
		 * poll_at drives engagement polling: they move on different clocks, so
		 * they get separate columns and separate indexes. claim_token and
		 * claimed_until are the lease a background run takes on a batch.
		 */
		$sql[] = "CREATE TABLE {$p}recoveryflow_journeys (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			journey_uid char(36) NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned NOT NULL,
			source_id varchar(32) NOT NULL,
			workflow_id bigint(20) unsigned NOT NULL,
			workflow_version int(10) unsigned NOT NULL DEFAULT 1,
			status varchar(24) NOT NULL,
			status_reason varchar(32) NULL,
			current_step smallint(5) unsigned NOT NULL DEFAULT 0,
			next_action_at datetime NULL,
			poll_at datetime NULL,
			poll_count smallint(5) unsigned NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			claim_token char(36) NULL,
			claimed_until datetime NULL,
			attempts_count smallint(5) unsigned NOT NULL DEFAULT 0,
			resume_count tinyint(3) unsigned NOT NULL DEFAULT 0,
			first_sent_at datetime NULL,
			clicks_count smallint(5) unsigned NOT NULL DEFAULT 0,
			last_click_at datetime NULL,
			engaged_at datetime NULL,
			engaged_via varchar(12) NULL,
			recovered_at datetime NULL,
			recovered_external_id varchar(191) NULL,
			recovered_amount decimal(19,4) NULL,
			attribution varchar(12) NULL,
			last_error_code varchar(32) NULL,
			last_error_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY journey_uid (journey_uid),
			UNIQUE KEY event_id (event_id),
			KEY due (status,next_action_at),
			KEY poll (status,poll_at),
			KEY expiry (status,expires_at),
			KEY claim_token (claim_token),
			KEY customer_status (customer_id,status),
			KEY source_status (source_id,status,created_at)
		) {$charset_collate};";

		/*
		 * One row per action execution, reserved under idempotency_key BEFORE
		 * the API call. The messaging API has no idempotency key of its own, so
		 * a retried send would deliver twice and bill twice; this row is what
		 * makes a retry safe. Each row also owns exactly one recovery token, so
		 * a link that has already been messaged keeps working after a retry.
		 */
		$sql[] = "CREATE TABLE {$p}recoveryflow_attempts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			journey_id bigint(20) unsigned NOT NULL,
			step_index smallint(5) unsigned NOT NULL,
			attempt_no tinyint(3) unsigned NOT NULL DEFAULT 1,
			idempotency_key varchar(191) NOT NULL,
			action_type varchar(32) NOT NULL,
			channel varchar(16) NOT NULL DEFAULT 'whatsapp',
			template_name varchar(191) NULL,
			language_code varchar(12) NULL,
			token_hash char(64) NULL,
			token_expires_at datetime NULL,
			token_revoked_at datetime NULL,
			wacr_message_id varchar(64) NULL,
			provider_message_id varchar(128) NULL,
			status varchar(12) NOT NULL DEFAULT 'pending',
			error_code varchar(32) NULL,
			error_message varchar(255) NULL,
			scheduled_at datetime NOT NULL,
			sending_started_at datetime NULL,
			sent_at datetime NULL,
			reconcile_after datetime NULL,
			status_checked_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			UNIQUE KEY token_hash (token_hash),
			KEY journey_step (journey_id,step_index,attempt_no),
			KEY status_reconcile (status,reconcile_after),
			KEY wacr_message_id (wacr_message_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}recoveryflow_workflows (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			source_id varchar(32) NULL,
			status varchar(12) NOT NULL DEFAULT 'draft',
			definition_json longtext NOT NULL,
			definition_hash char(64) NOT NULL,
			version int(10) unsigned NOT NULL DEFAULT 1,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY source_status (source_id,status,is_default)
		) {$charset_collate};";

		/*
		 * Immutable snapshots. A journey pins the version it started under, so
		 * editing a workflow never rewrites what a running journey will do --
		 * a customer cannot receive step 2 of a sequence they were never
		 * enrolled in.
		 */
		$sql[] = "CREATE TABLE {$p}recoveryflow_workflow_versions (
			workflow_id bigint(20) unsigned NOT NULL,
			version int(10) unsigned NOT NULL,
			definition_json longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (workflow_id,version)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}recoveryflow_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(8) NOT NULL,
			category varchar(32) NOT NULL,
			journey_id bigint(20) unsigned NULL,
			message varchar(255) NOT NULL,
			context_json text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY journey (journey_id,created_at),
			KEY level_created (level,created_at),
			KEY created_at (created_at)
		) {$charset_collate};";

		/*
		 * A generic "have I already handled this?" ledger, claimed with INSERT
		 * IGNORE: webhook deliveries, order status events, and reveal audits all
		 * key into it. One table rather than a flag per subsystem, because the
		 * question is always the same shape.
		 */
		$sql[] = "CREATE TABLE {$p}recoveryflow_receipts (
			receipt_key varchar(128) NOT NULL,
			kind varchar(24) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (receipt_key),
			KEY kind_created (kind,created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}recoveryflow_locks (
			lock_key varchar(64) NOT NULL,
			owner char(36) NULL,
			expires_at datetime NULL,
			PRIMARY KEY  (lock_key)
		) {$charset_collate};";

		return $sql;
	}
}
