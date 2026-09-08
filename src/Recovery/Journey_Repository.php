<?php
/**
 * Journey storage and state transitions.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Database\Table_Names;
use WAcr\RecoveryFlow\Support\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a journey changes state.
 *
 * Every transition is a conditional UPDATE that names the state it expects to
 * find, and returns whether it was the one that made the change. This is not
 * defensiveness for its own sake: an order can complete while a batch is
 * halfway through deciding to message that customer, and the correct outcome is
 * that the conversion wins and the send is abandoned. A plain UPDATE would
 * overwrite the conversion and message somebody who had already bought.
 *
 * Batches are worked under a lease rather than a global lock, so a long run
 * cannot block a short one and a run that dies mid-batch releases its rows by
 * simply not renewing.
 */
final class Journey_Repository extends Repository {

	/**
	 * How long a batch lease lasts.
	 */
	public const CLAIM_TTL = 90;

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::JOURNEYS;
	}

	/**
	 * Create a journey for an event.
	 *
	 * The UNIQUE index on event_id is the guard: if two evaluation runs both
	 * decide this event deserves a journey, one of them silently loses and no
	 * customer is enrolled twice.
	 *
	 * @param array<string,mixed> $data Column values. journey_uid is generated if absent.
	 * @return int New journey id, or 0 if the event already had one.
	 */
	public function create( array $data ): int {
		$now = $this->clock->now();

		$data['journey_uid'] = isset( $data['journey_uid'] ) ? (string) $data['journey_uid'] : Uuid::v4();
		$data['created_at']  = $now;
		$data['updated_at']  = $now;

		$id = $this->insert_ignore( $data );

		if ( 0 !== $id ) {
			/**
			 * Fires when a recovery journey is created.
			 *
			 * @param int                 $id   Journey id.
			 * @param array<string,mixed> $data The values it was created with.
			 */
			do_action( Hooks::JOURNEY_CREATED, $id, $data );
		}

		return $id;
	}

	/**
	 * Fetch by row id.
	 *
	 * @param int $id Journey id.
	 * @return Recovery_Journey|null
	 */
	public function find( int $id ): ?Recovery_Journey {
		$row = $this->find_by_id( $id );

		return null === $row ? null : Recovery_Journey::from_row( $row );
	}

	/**
	 * Fetch by public identifier.
	 *
	 * @param string $uid Journey uid.
	 * @return Recovery_Journey|null
	 */
	public function find_by_uid( string $uid ): ?Recovery_Journey {
		$row = $this->find_one_by( 'journey_uid', $uid );

		return null === $row ? null : Recovery_Journey::from_row( $row );
	}

	/**
	 * Fetch the journey for an event.
	 *
	 * @param int $event_id Event id.
	 * @return Recovery_Journey|null
	 */
	public function find_by_event( int $event_id ): ?Recovery_Journey {
		$row = $this->find_one_by( 'event_id', $event_id );

		return null === $row ? null : Recovery_Journey::from_row( $row );
	}

	/**
	 * Move a journey from one state to another, if it is still in the first.
	 *
	 * @param int                 $id     Journey id.
	 * @param string              $from   State the caller last saw.
	 * @param string              $to     State to move to.
	 * @param array<string,mixed> $patch  Other columns to write in the same statement.
	 * @param string              $reason Short machine-readable reason.
	 * @return bool Whether this caller made the change.
	 */
	public function transition( int $id, string $from, string $to, array $patch = array(), string $reason = '' ): bool {
		if ( ! Journey_State::can_transition( $from, $to ) ) {
			return false;
		}

		$patch['status']     = $to;
		$patch['updated_at'] = $this->clock->now();

		if ( '' !== $reason ) {
			$patch['status_reason'] = substr( $reason, 0, 32 );
		}

		if ( Journey_State::is_terminal( $to ) ) {
			// A finished journey must stop appearing in every due query, and
			// clearing the lease lets the row be read without a stale claim.
			$patch['next_action_at'] = null;
			$patch['poll_at']        = null;
			$patch['claim_token']    = null;
			$patch['claimed_until']  = null;
		}

		$changed = $this->compare_and_set( $id, $patch, array( 'status' => $from ) );

		if ( ! $changed ) {
			return false;
		}

		/**
		 * Fires whenever a journey changes state.
		 *
		 * @param int    $id   Journey id.
		 * @param string $from Previous state.
		 * @param string $to   New state.
		 */
		do_action( Hooks::JOURNEY_TRANSITION, $id, $from, $to );

		$specific = self::hook_for( $to );

		if ( '' !== $specific ) {
			/**
			 * Fires when a journey reaches a particular state.
			 *
			 * @param int    $id   Journey id.
			 * @param string $from Previous state.
			 */
			do_action( $specific, $id, $from );
		}

		return true;
	}

	/**
	 * The state-specific hook name, where there is one.
	 *
	 * @param string $state State moved to.
	 * @return string Hook name, or empty.
	 */
	private static function hook_for( string $state ): string {
		$map = array(
			Journey_State::SCHEDULED    => Hooks::JOURNEY_SCHEDULED,
			Journey_State::MESSAGE_SENT => Hooks::MESSAGE_SENT,
			Journey_State::ENGAGED      => Hooks::JOURNEY_ENGAGED,
			Journey_State::RECOVERED    => Hooks::JOURNEY_RECOVERED,
			Journey_State::EXPIRED      => Hooks::JOURNEY_EXPIRED,
			Journey_State::CANCELLED    => Hooks::JOURNEY_CANCELLED,
			Journey_State::OPTED_OUT    => Hooks::JOURNEY_OPTED_OUT,
		);

		return $map[ $state ] ?? '';
	}

	/**
	 * Take a lease on the journeys that are due for their next workflow step.
	 *
	 * @param string $claim_token This run's ownership token.
	 * @param int    $limit       Maximum rows to take.
	 * @param int    $ttl         Seconds the lease lasts.
	 * @return int How many rows were claimed.
	 */
	public function claim_due( string $claim_token, int $limit = 50, int $ttl = self::CLAIM_TTL ): int {
		$table    = $this->table();
		$now      = $this->clock->now();
		$statuses = array( Journey_State::ELIGIBLE, Journey_State::SCHEDULED, Journey_State::MESSAGE_SENT );

		return $this->claim( $table, 'next_action_at', $statuses, $claim_token, $now, $ttl, $limit );
	}

	/**
	 * Take a lease on the journeys whose conversation should be re-read.
	 *
	 * @param string $claim_token This run's ownership token.
	 * @param int    $limit       Maximum rows to take.
	 * @param int    $ttl         Seconds the lease lasts.
	 * @return int How many rows were claimed.
	 */
	public function claim_pollable( string $claim_token, int $limit = 50, int $ttl = self::CLAIM_TTL ): int {
		$table    = $this->table();
		$now      = $this->clock->now();
		$statuses = array( Journey_State::MESSAGE_SENT, Journey_State::ENGAGED );

		return $this->claim( $table, 'poll_at', $statuses, $claim_token, $now, $ttl, $limit );
	}

	/**
	 * Take a lease on the oldest due rows, in two statements rather than one.
	 *
	 * The obvious way to write this is a single UPDATE ... ORDER BY ... LIMIT,
	 * and that is how it was written until it was measured against a hundred
	 * thousand journeys. Because the status is an IN of several values, no index
	 * can deliver the rows already ordered by the due column, so MySQL sorted
	 * nine thousand matching rows in memory to take fifty -- every minute, for
	 * ever, growing with the table. Split in two, the SELECT reads the index
	 * built for exactly this question and the UPDATE is addressed by primary key.
	 *
	 * It stays atomic, which is the only thing that matters here. The UPDATE
	 * re-checks the lease condition, so where two runs select the same rows the
	 * second updates none of them, and the caller reads back only what carries
	 * its own token. Two runs cannot work the same journey.
	 *
	 * @param string   $table       Journeys table.
	 * @param string   $column      The due column: next_action_at or poll_at.
	 * @param string[] $statuses    States that qualify.
	 * @param string   $claim_token This run's ownership token.
	 * @param string   $now         Now, UTC.
	 * @param int      $ttl         Seconds the lease lasts.
	 * @param int      $limit       Maximum rows to take.
	 * @return int How many rows were claimed.
	 */
	private function claim( string $table, string $column, array $statuses, string $claim_token, string $now, int $ttl, int $limit ): int {
		$select = "SELECT id FROM `{$table}`
			WHERE status IN (" . $this->placeholders( $statuses ) . ")
				AND {$column} IS NOT NULL
				AND {$column} <= %s
				AND (claimed_until IS NULL OR claimed_until < %s)
			ORDER BY {$column} ASC
			LIMIT %d";

		$ids = $this->ids(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant, column from a private caller; every value bound.
			$this->db()->prepare(
				$select,
				array_merge( $statuses, array( $now, $now, max( 1, $limit ) ) )
			)
		);

		if ( array() === $ids ) {
			return 0;
		}

		$update = "UPDATE `{$table}`
			SET claim_token = %s, claimed_until = %s
			WHERE id IN (" . implode( ',', $ids ) . ')
				AND (claimed_until IS NULL OR claimed_until < %s)';

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; ids are integers cast above; every value bound.
			$this->db()->prepare( $update, $claim_token, $this->clock->offset( $ttl ), $now )
		);
	}

	/**
	 * The rows this run has a lease on.
	 *
	 * @param string $claim_token Ownership token.
	 * @param int    $limit       Maximum rows.
	 * @return array<int,Recovery_Journey>
	 */
	public function claimed( string $claim_token, int $limit = 50 ): array {
		$table = $this->table();

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE claim_token = %s ORDER BY id ASC LIMIT %d",
				$claim_token,
				max( 1, $limit )
			)
		);

		return array_map( array( Recovery_Journey::class, 'from_row' ), $rows );
	}

	/**
	 * Give a lease back, leaving the row for the next run.
	 *
	 * @param string $claim_token Ownership token.
	 * @return int Rows released.
	 */
	public function release_claims( string $claim_token ): int {
		return $this->update(
			array(
				'claim_token'   => null,
				'claimed_until' => null,
			),
			array( 'claim_token' => $claim_token )
		);
	}

	/**
	 * Write columns on a journey this run still holds the lease on.
	 *
	 * @param int                 $id          Journey id.
	 * @param string              $claim_token Ownership token.
	 * @param string              $status      Status the caller last saw.
	 * @param array<string,mixed> $patch       Columns to write.
	 * @return bool Whether this caller made the change.
	 */
	public function update_claimed( int $id, string $claim_token, string $status, array $patch ): bool {
		$patch['updated_at'] = $this->clock->now();

		return $this->compare_and_set(
			$id,
			$patch,
			array(
				'claim_token' => $claim_token,
				'status'      => $status,
			)
		);
	}

	/**
	 * Expire journeys that are past their useful life.
	 *
	 * @param int $limit Maximum rows per batch.
	 * @return int Rows expired.
	 */
	public function expire_due( int $limit = 500 ): int {
		$table    = $this->table();
		$now      = $this->clock->now();
		$statuses = Journey_State::active();

		$sql = "UPDATE `{$table}`
			SET status = %s, status_reason = %s, next_action_at = NULL, poll_at = NULL,
				claim_token = NULL, claimed_until = NULL, updated_at = %s
			WHERE status IN (" . $this->placeholders( $statuses ) . ')
				AND expires_at <= %s
			LIMIT %d';

		$args = array_merge(
			array( Journey_State::EXPIRED, 'max_age', $now ),
			$statuses,
			array( $now, max( 1, $limit ) )
		);

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; every value bound.
			$this->db()->prepare( $sql, $args )
		);
	}

	/**
	 * Journeys still being worked for a customer.
	 *
	 * @param int $customer_id Customer id.
	 * @param int $limit       Maximum rows.
	 * @return array<int,Recovery_Journey>
	 */
	public function active_for_customer( int $customer_id, int $limit = 50 ): array {
		$table    = $this->table();
		$statuses = Journey_State::active();

		$sql = "SELECT * FROM `{$table}`
			WHERE customer_id = %d AND status IN (" . $this->placeholders( $statuses ) . ')
			ORDER BY id DESC LIMIT %d';

		$args = array_merge( array( $customer_id ), $statuses, array( max( 1, $limit ) ) );

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; every value bound.
			$this->db()->prepare( $sql, $args )
		);

		return array_map( array( Recovery_Journey::class, 'from_row' ), $rows );
	}

	/**
	 * Every journey a customer has had, newest first.
	 *
	 * Used by the privacy tools, which have to speak for all of somebody's data
	 * rather than only the part still being worked. The limit is a safety rail
	 * rather than a page: a privacy export is a single request, and a customer
	 * with more journeys than this has a data problem the export should not
	 * make worse by trying to render all of it.
	 *
	 * @param int $customer_id Customer id.
	 * @param int $limit       Maximum rows.
	 * @return array<int,Recovery_Journey>
	 */
	public function all_for_customer( int $customer_id, int $limit = 200 ): array {
		$table = $this->table();

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; every value bound.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE customer_id = %d ORDER BY id DESC LIMIT %d",
				$customer_id,
				max( 1, $limit )
			)
		);

		return array_map( array( Recovery_Journey::class, 'from_row' ), $rows );
	}

	/**
	 * Whether a customer already has a journey in flight.
	 *
	 * @param int $customer_id Customer id.
	 * @return bool
	 */
	public function has_active( int $customer_id ): bool {
		return array() !== $this->active_for_customer( $customer_id, 1 );
	}

	/**
	 * How many journeys a customer has had since a given time.
	 *
	 * Backs the "no more than three approaches a month" limit, which is what
	 * stops a habitual browser being messaged about every basket they leave.
	 *
	 * @param int    $customer_id Customer id.
	 * @param string $since       UTC datetime.
	 * @return int
	 */
	public function count_since( int $customer_id, string $since ): int {
		$table = $this->table();

		return (int) $this->scalar(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE customer_id = %d AND created_at >= %s",
				$customer_id,
				$since
			)
		);
	}

	/**
	 * Record that a recovery link was followed.
	 *
	 * @param int $id Journey id.
	 * @return bool
	 */
	public function record_click( int $id ): bool {
		$table = $this->table();
		$now   = $this->clock->now();

		return 0 !== $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}` SET clicks_count = clicks_count + 1, last_click_at = %s, updated_at = %s WHERE id = %d",
				$now,
				$now,
				$id
			)
		);
	}

	/**
	 * A filtered, ordered page of journeys, with the total behind it.
	 *
	 * Backs both the admin list and the REST collection, which is deliberate:
	 * two queries answering the same question would eventually disagree about
	 * what "the third page of failed journeys" contains, and only one of them
	 * would be the one anybody had looked at.
	 *
	 * Every part of the ORDER BY and the WHERE is allow-listed rather than
	 * escaped. Escaping an identifier is not a thing SQL offers -- $wpdb->prepare
	 * binds values, not column names -- so a sort column that came from a query
	 * string could only ever be interpolated, and interpolating user input into
	 * SQL is the vulnerability itself rather than a risk of one. A name that is
	 * not on the list is replaced by the default; it is never quoted and hoped
	 * for.
	 *
	 * @param array<string,mixed> $args status, source, search, orderby, order, page, per_page.
	 * @return array{rows:array<int,Recovery_Journey>,total:int}
	 */
	public function query( array $args = array() ): array {
		$sortable = array(
			'created_at'     => 'j.created_at',
			'updated_at'     => 'j.updated_at',
			'next_action_at' => 'j.next_action_at',
			'status'         => 'j.status',
			'id'             => 'j.id',
		);

		$orderby  = $sortable[ (string) ( $args['orderby'] ?? '' ) ] ?? 'j.id';
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$per_page = min( 200, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$table  = $this->table();
		$where  = array( '1=1' );
		$values = array();

		$status = (string) ( $args['status'] ?? '' );

		if ( '' !== $status && in_array( $status, Journey_State::all(), true ) ) {
			$where[]  = 'j.status = %s';
			$values[] = $status;
		}

		$source = (string) ( $args['source'] ?? '' );

		if ( '' !== $source ) {
			$where[]  = 'j.source_id = %s';
			$values[] = $source;
		}

		/*
		 * Search matches the journey's own public reference, never a customer's
		 * details. Searching a phone number would mean either a scan of a
		 * plaintext column or hashing the search term -- and hashing it would
		 * turn the search box into an oracle that confirms whether a given
		 * number is a customer of this shop, to anyone who can reach the screen.
		 */
		$search = trim( (string) ( $args['search'] ?? '' ) );

		if ( '' !== $search ) {
			$where[]  = 'j.journey_uid LIKE %s';
			$values[] = '%' . $this->db()->esc_like( $search ) . '%';
		}

		$clause = implode( ' AND ', $where );

		$rows = $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from a class constant; the order and where fragments are allow-listed above; every value is bound.
			$this->db()->prepare(
				"SELECT j.* FROM `{$table}` AS j WHERE {$clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				array_merge( $values, array( $per_page, $offset ) )
			)
		);

		$total = $this->scalar(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- as above.
			array() === $values
				? "SELECT COUNT(*) FROM `{$table}` AS j WHERE {$clause}"
				: $this->db()->prepare( "SELECT COUNT(*) FROM `{$table}` AS j WHERE {$clause}", $values )
		);

		return array(
			'rows'  => array_map( array( Recovery_Journey::class, 'from_row' ), $rows ),
			'total' => (int) $total,
		);
	}

	/**
	 * Count journeys by state, for the overview.
	 *
	 * @return array<string,int> State name to count.
	 */
	public function counts_by_status(): array {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; no user input in this statement.
		$rows = $this->db()->get_results( "SELECT status, COUNT(*) AS total FROM `{$table}` GROUP BY status", ARRAY_A );

		$counts = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$counts[ (string) $row['status'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Detach a customer from their journeys during erasure.
	 *
	 * @param int $customer_id Customer id.
	 * @return int Rows changed.
	 */
	public function clear_recovery_details( int $customer_id ): int {
		return $this->update(
			array(
				'recovered_external_id' => null,
				'updated_at'            => $this->clock->now(),
			),
			array( 'customer_id' => $customer_id )
		);
	}
}
