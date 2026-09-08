<?php
/**
 * Fills a site with enough recovery data to find out what is slow.
 *
 * Load it into WP-CLI, which is the only way to run it:
 *
 *     wp --require=tests/perf/seed.php recoveryflow-perf seed --events=100000
 *     wp --require=tests/perf/seed.php recoveryflow-perf explain
 *     wp --require=tests/perf/seed.php recoveryflow-perf clear --yes
 *
 * It lives in tests/ and is excluded from the distributed plugin on purpose. A
 * command that writes a hundred thousand rows of invented customers is a
 * benchmarking tool, not a feature, and shipping one means a production site can
 * run it. There is no version of this that belongs on a merchant's server.
 *
 * Everything it writes is obviously fake and obviously ours: phone numbers come
 * from Ofcom's reserved drama range (+4470009xxxxx, which can never be
 * allocated to a real person), emails are on example.test, and every row is
 * tagged so clear() can find them again without a guess.
 *
 * @package WAcr\RecoveryFlow
 */

use WAcr\RecoveryFlow\Core\Plugin;
use WAcr\RecoveryFlow\Database\Table_Names;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Benchmarking commands. Never shipped.
 */
final class RecoveryFlow_Perf_Command {

	/**
	 * What every seeded row is marked with, so clear() never has to guess.
	 */
	private const TAG = 'perfseed';

	/**
	 * Where the id ranges this seeder wrote are remembered.
	 */
	private const RANGES_OPTION = 'recoveryflow_perf_customer_ranges';

	/**
	 * How many rows go in one INSERT.
	 *
	 * Large enough that a hundred thousand rows is a few hundred statements
	 * rather than a hundred thousand, small enough to stay well under
	 * max_allowed_packet on a default MySQL.
	 */
	private const CHUNK = 500;

	/**
	 * Writes fake recovery data.
	 *
	 * ## OPTIONS
	 *
	 * [--events=<number>]
	 * : How many recovery events to write. Default 100000.
	 *
	 * [--customers=<number>]
	 * : How many customers to spread them across. Default one per twenty events.
	 *
	 * [--journeys=<percent>]
	 * : What percentage of events also get a journey. Default 20.
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function seed( array $args, array $assoc_args ): void {
		unset( $args );

		global $wpdb;

		$events    = max( 1, (int) ( $assoc_args['events'] ?? 100000 ) );
		$customers = max( 1, (int) ( $assoc_args['customers'] ?? (int) ceil( $events / 20 ) ) );
		$percent   = min( 100, max( 0, (int) ( $assoc_args['journeys'] ?? 20 ) ) );

		WP_CLI::confirm( sprintf( 'Write %d events and %d customers of invented data into this database?', $events, $customers ), $assoc_args );

		$started = microtime( true );

		$this->seed_customers( $customers );
		WP_CLI::log( sprintf( 'Customers: %d in %.1fs', $customers, microtime( true ) - $started ) );

		$this->seed_events( $events, $customers );
		WP_CLI::log( sprintf( 'Events: %d in %.1fs', $events, microtime( true ) - $started ) );

		$this->seed_journeys( (int) round( $events * $percent / 100 ), $customers );

		WP_CLI::success( sprintf( 'Seeded in %.1fs. Tables: %s', microtime( true ) - $started, $this->counts() ) );

		unset( $wpdb );
	}

	/**
	 * Runs EXPLAIN over the queries the background passes actually make.
	 *
	 * The queries are not written out here. Every one of them is captured by
	 * calling the real repository method and reading $wpdb->last_query, because
	 * a benchmark that EXPLAINs a hand-copied approximation of a query reports
	 * the plan of a statement the plugin never runs -- and it reports it
	 * confidently, which is worse than reporting nothing. The first version of
	 * this command did exactly that and made the evaluate pass look like a
	 * primary-key scan it is not.
	 *
	 * The whole thing runs inside a transaction that is rolled back, because
	 * three of these methods write: claiming a batch stamps claim tokens, and
	 * expiring closes rows. Reading a plan should not change the data whose
	 * plan is being read.
	 *
	 * What to look at is type and rows. ALL means a full table scan. A rows
	 * estimate near the size of the table means the index is being walked
	 * rather than seeked into, which is fine at a thousand rows and is why a
	 * site stops sending at a hundred thousand.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function explain( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		global $wpdb;

		$plugin   = Plugin::instance();
		$events   = $plugin->events();
		$journeys = $plugin->journeys();
		$attempts = $plugin->attempts();
		$past     = gmdate( 'Y-m-d H:i:s', time() - ( 30 * MINUTE_IN_SECONDS ) );
		$token    = 'perf-explain-token';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
		$wpdb->query( 'START TRANSACTION' );

		$captured = array(
			'evaluate: events due'         => static fn () => $events->due_for_evaluation( $past, 200 ),
			'evaluate: expire unidentified' => static fn () => $events->expire_unidentified( $past, 500 ),
			'dispatch: claim due'          => static fn () => $journeys->claim_due( $token, 50 ),
			'dispatch: read the claim'     => static fn () => $journeys->claimed( $token, 50 ),
			'poll: claim pollable'         => static fn () => $journeys->claim_pollable( $token, 50 ),
			'poll: unresolved attempts'    => static fn () => $attempts->unresolved( 25 ),
			'expire: journeys due'         => static fn () => $journeys->expire_due( 500 ),
			'admin: one page of the queue' => static fn () => $journeys->query( array( 'per_page' => 20 ) ),
			'admin: counts by status'      => static fn () => $journeys->counts_by_status(),
		);

		$rows = array();

		foreach ( $captured as $label => $run ) {
			// Every statement, not just the last one. query() runs a SELECT and
			// then a COUNT, and reading $wpdb->last_query afterwards reports
			// the plan of the count while labelling it the page -- which is how
			// the first version of this command described the queue screen as a
			// covering index read when the covered statement was a different
			// one entirely.
			$GLOBALS['recoveryflow_perf_sql'] = array();

			add_filter( 'query', array( $this, 'capture' ) );
			$run();
			remove_filter( 'query', array( $this, 'capture' ) );

			$statements = $GLOBALS['recoveryflow_perf_sql'];
			$many       = count( $statements ) > 1;

			foreach ( $statements as $index => $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
				$plan = $wpdb->get_row( 'EXPLAIN ' . $sql, ARRAY_A );

				$rows[] = array(
					'query'    => $many ? $label . ' [' . ( $index + 1 ) . '] ' . $this->shape( $sql ) : $label,
					'type'     => (string) ( $plan['type'] ?? '?' ),
					'key'      => (string) ( $plan['key'] ?? 'NONE' ),
					'rows'     => (string) ( $plan['rows'] ?? '?' ),
					'filtered' => (string) ( $plan['filtered'] ?? '' ),
					'extra'    => (string) ( $plan['Extra'] ?? '' ),
				);
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
		$wpdb->query( 'ROLLBACK' );

		WP_CLI\Utils\format_items( 'table', $rows, array( 'query', 'type', 'key', 'rows', 'filtered', 'extra' ) );

		$bad = 0;

		foreach ( $rows as $row ) {
			if ( 'ALL' === $row['type'] || 'NONE' === $row['key'] ) {
				WP_CLI::warning( sprintf( '%s is a full table scan.', $row['query'] ) );
				++$bad;
			}

			if ( false !== strpos( $row['extra'], 'filesort' ) ) {
				WP_CLI::warning( sprintf( '%s sorts in memory rather than reading an index in order.', $row['query'] ) );
				++$bad;
			}
		}

		if ( 0 === $bad ) {
			WP_CLI::success( 'Every background query reads an index, and none of them sorts by hand.' );
		}
	}

	/**
	 * Times one round of every background pass against whatever is in the tables.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function time( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$rows = array();

		foreach ( Plugin::instance()->runner()->stages() as $stage ) {
			$started = microtime( true );
			$stats   = Plugin::instance()->runner()->run( $stage->key() );

			$rows[] = array(
				'pass'    => $stage->key(),
				'seconds' => round( microtime( true ) - $started, 3 ),
				'handled' => $stats->processed,
				'skipped' => $stats->skipped,
				'backlog' => $stats->backlog > 0 ? 'yes' : 'no',
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'pass', 'seconds', 'handled', 'skipped', 'backlog' ) );
	}

	/**
	 * Deletes everything this command wrote, and nothing else.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function clear( array $args, array $assoc_args ): void {
		unset( $args );

		global $wpdb;

		WP_CLI::confirm( 'Delete every row this seeder wrote?', $assoc_args );

		$identities = $this->table( Table_Names::IDENTITIES );
		$customers  = $this->table( Table_Names::CUSTOMERS );
		$deleted    = 0;

		// The customer ids have to be read before the identities that name them
		// are deleted, or there is nothing left to say which customers were
		// ours -- and a seeder that guessed at that would delete a real one.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
		$customer_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT customer_id FROM {$identities} WHERE source = %s", self::TAG ) );

		// Order matters: journeys reference events, events reference customers.
		foreach ( array( $this->table( Table_Names::JOURNEYS ), $this->table( Table_Names::EVENTS ) ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_id = %s", self::TAG ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
		$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$identities} WHERE source = %s", self::TAG ) );

		foreach ( array_chunk( array_map( 'intval', $customer_ids ), self::CHUNK ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
			$deleted += (int) $wpdb->query( "DELETE FROM {$customers} WHERE id IN (" . implode( ',', $chunk ) . ')' );
		}

		// And the ones whose identities the retention pass has already
		// anonymised away, which no longer name themselves as ours.
		foreach ( get_option( self::RANGES_OPTION, array() ) as $range ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$customers} WHERE id BETWEEN %d AND %d", (int) $range[0], (int) $range[1] ) );
		}

		delete_option( self::RANGES_OPTION );

		WP_CLI::success( sprintf( 'Deleted %d rows. Tables: %s', $deleted, $this->counts() ) );
	}

	/**
	 * Write the customers, and the identities that make them messageable.
	 *
	 * Contact details do not live on the customer row -- they are identity
	 * rows, one per way of reaching somebody -- so a seeder that filled in the
	 * customers table alone would produce a hundred thousand people nobody can
	 * message, and every stage would refuse them for the same reason. The
	 * benchmark would then measure the refusal path and nothing else.
	 *
	 * @param int $count How many.
	 * @return void
	 */
	private function seed_customers( int $count ): void {
		global $wpdb;

		$customers = $this->table( Table_Names::CUSTOMERS );
		$before    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$customers}" ); // phpcs:ignore
		$now       = gmdate( 'Y-m-d H:i:s' );
		$progress  = WP_CLI\Utils\make_progress_bar( 'Customers', $count );

		for ( $offset = 0; $offset < $count; $offset += self::CHUNK ) {
			$values = array();

			for ( $i = $offset; $i < min( $count, $offset + self::CHUNK ); $i++ ) {
				$values[] = $wpdb->prepare( '(%s,%s,%s,%s,%s)', 'Perf', 'Person' . $i, 'GB', $now, $now );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
			$wpdb->query( "INSERT INTO {$customers} (first_name, last_name, country_iso2, created_at, updated_at) VALUES " . implode( ',', $values ) );

			$progress->tick( count( $values ) );
		}

		$progress->finish();

		// The id range is written down because the identities that would
		// otherwise identify these rows do not survive: the retention pass
		// anonymises people, deleting their identity rows, and a clear() that
		// looked for them afterwards left five thousand orphaned customers
		// behind while reporting that it had deleted everything.
		$after  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$customers}" ); // phpcs:ignore
		$ranges = get_option( self::RANGES_OPTION, array() );

		$ranges[] = array( $before + 1, $after );

		update_option( self::RANGES_OPTION, $ranges, false );

		$this->seed_identities( $count );
	}

	/**
	 * One phone and one email per seeded customer.
	 *
	 * Numbers come from Ofcom's reserved drama range, which can never be
	 * allocated to a real person. A benchmark that seeded plausible numbers
	 * would be one bad WHERE clause away from messaging a stranger.
	 *
	 * @param int $count How many customers were written.
	 * @return void
	 */
	private function seed_identities( int $count ): void {
		global $wpdb;

		$identities = $this->table( Table_Names::IDENTITIES );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$first      = $this->first_customer_id( $count );
		$progress   = WP_CLI\Utils\make_progress_bar( 'Identities', $count );

		for ( $offset = 0; $offset < $count; $offset += self::CHUNK ) {
			$values = array();

			for ( $i = $offset; $i < min( $count, $offset + self::CHUNK ); $i++ ) {
				$phone = '+447000900' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT );
				$email = 'perf' . $i . '@example.test';

				$values[] = $wpdb->prepare(
					'(%d,%s,%s,%s,%s,%s,%s,%s,%s)',
					$first + $i,
					'e164',
					hash( 'sha256', $phone ),
					hash( 'sha256', $phone ),
					$phone,
					'valid',
					self::TAG,
					$now,
					$now
				);

				$values[] = $wpdb->prepare(
					'(%d,%s,%s,%s,%s,%s,%s,%s,%s)',
					$first + $i,
					'email',
					hash( 'sha256', $email ),
					null,
					$email,
					'valid',
					self::TAG,
					$now,
					$now
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
			$wpdb->query( "INSERT IGNORE INTO {$identities} (customer_id, kind, value_hash, unique_value_hash, value_raw, status, source, created_at, updated_at) VALUES " . implode( ',', $values ) );

			$progress->tick( min( self::CHUNK, $count - $offset ) );
		}

		$progress->finish();
	}

	/**
	 * The id of the first customer this run wrote.
	 *
	 * @param int $count How many were written.
	 * @return int
	 */
	private function first_customer_id( int $count ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
		$last = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . $this->table( Table_Names::CUSTOMERS ) );

		return max( 1, $last - $count + 1 );
	}

	/**
	 * Write the events.
	 *
	 * Spread across the last thirty days so the maximum-age rule has something
	 * to refuse: a table where every row is due is not the table a real site
	 * has, and it would make the evaluate pass look far worse than it is.
	 *
	 * @param int $count     How many.
	 * @param int $customers How many customers to spread them across.
	 * @return void
	 */
	private function seed_events( int $count, int $customers ): void {
		global $wpdb;

		$table     = $this->table( Table_Names::EVENTS );
		$now       = time();
		// (source_id, dedupe_key) is UNIQUE, so a second run of the seeder
		// collided with the first on every row: MySQL logged a hundred
		// thousand duplicate-key errors and the run quietly wrote far fewer
		// events than it was asked for, which would have been read as the
		// insert path getting slower.
		$run       = wp_generate_password( 6, false );
		$first_cid = $this->first_customer_id( $customers );
		$progress  = WP_CLI\Utils\make_progress_bar( 'Events', $count );

		for ( $offset = 0; $offset < $count; $offset += self::CHUNK ) {
			$values = array();

			for ( $i = $offset; $i < min( $count, $offset + self::CHUNK ); $i++ ) {
				$age  = gmdate( 'Y-m-d H:i:s', $now - ( ( $i % ( 30 * 24 ) ) * HOUR_IN_SECONDS ) );
				$open = 0 === $i % 3;

				$values[] = $wpdb->prepare(
					'(%s,%s,%s,%s,%d,%s,%s,%d,%s,%s,%s,%s)',
					'perf-' . $i . '-' . wp_generate_password( 8, false ),
					self::TAG,
					'cart',
					self::TAG . ':' . $run . ':' . $i,
					$first_cid + ( $i % $customers ),
					'GBP',
					number_format( 10 + ( $i % 500 ), 2, '.', '' ),
					1 + ( $i % 4 ),
					$open ? 'open' : 'expired',
					$age,
					$age,
					$age
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
			$wpdb->query( "INSERT INTO {$table} (event_uid, source_id, source_type, dedupe_key, customer_id, currency, amount, item_count, status, last_activity_at, created_at, updated_at) VALUES " . implode( ',', $values ) );

			$progress->tick( count( $values ) );
		}

		$progress->finish();
	}

	/**
	 * Write the journeys, one per event, for the first N events.
	 *
	 * @param int $count     How many.
	 * @param int $customers How many customers to spread them across.
	 * @return void
	 */
	private function seed_journeys( int $count, int $customers ): void {
		global $wpdb;

		if ( $count < 1 ) {
			return;
		}

		$table     = $this->table( Table_Names::JOURNEYS );
		$now       = time();
		$first_cid = $this->first_customer_id( $customers );
		$progress  = WP_CLI\Utils\make_progress_bar( 'Journeys', $count );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
		$event_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $this->table( Table_Names::EVENTS ) . ' WHERE source_id = %s ORDER BY id ASC LIMIT %d', self::TAG, $count ) );

		foreach ( array_chunk( $event_ids, self::CHUNK ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $index => $event_id ) {
				$due      = gmdate( 'Y-m-d H:i:s', $now + ( ( $index % 48 ) * HOUR_IN_SECONDS ) - DAY_IN_SECONDS );
				$statuses = array( 'scheduled', 'sent', 'recovered', 'expired', 'scheduled' );

				$values[] = $wpdb->prepare(
					'(%s,%d,%d,%s,%d,%d,%s,%s,%s,%s,%s,%s)',
					'perfj-' . $event_id . '-' . wp_generate_password( 8, false ),
					(int) $event_id,
					$first_cid + ( (int) $event_id % $customers ),
					self::TAG,
					1,
					1,
					$statuses[ $index % 5 ],
					$due,
					$due,
					gmdate( 'Y-m-d H:i:s', $now + WEEK_IN_SECONDS ),
					gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ),
					gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS )
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
			$wpdb->query( "INSERT IGNORE INTO {$table} (journey_uid, event_id, customer_id, source_id, workflow_id, workflow_version, status, next_action_at, poll_at, expires_at, created_at, updated_at) VALUES " . implode( ',', $values ) );

			$progress->tick( count( $values ) );
		}

		$progress->finish();
	}

	/**
	 * Collect a statement on its way to MySQL. Used only by explain().
	 *
	 * @param string $sql The statement.
	 * @return string The statement, unchanged.
	 */
	public function capture( $sql ) {
		$trimmed = ltrim( (string) $sql );

		if ( 1 === preg_match( '/^(SELECT|UPDATE|DELETE)\b/i', $trimmed ) ) {
			$GLOBALS['recoveryflow_perf_sql'][] = $trimmed;
		}

		return $sql;
	}

	/**
	 * What a statement is, in one word, for the label.
	 *
	 * @param string $sql The statement.
	 * @return string
	 */
	private function shape( string $sql ): string {
		if ( 1 === preg_match( '/^SELECT\s+COUNT/i', $sql ) ) {
			return 'count';
		}

		return strtolower( strtok( $sql, ' ' ) ?: '?' );
	}

	/**
	 * One of the plugin's tables, prefixed.
	 *
	 * @param string $table Unprefixed name.
	 * @return string
	 */
	private function table( string $table ): string {
		return Table_Names::get( $table );
	}

	/**
	 * Row counts, for the summary line.
	 *
	 * @return string
	 */
	private function counts(): string {
		global $wpdb;

		$parts = array();

		foreach ( array( 'customers' => $this->table( Table_Names::CUSTOMERS ), 'events' => $this->table( Table_Names::EVENTS ), 'journeys' => $this->table( Table_Names::JOURNEYS ) ) as $label => $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- a benchmarking tool, not shipped.
			$parts[] = $label . ' ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return implode( ', ', $parts );
	}
}

WP_CLI::add_command( 'recoveryflow-perf', 'RecoveryFlow_Perf_Command' );
