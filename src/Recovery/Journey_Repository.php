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

		$sql = "UPDATE `{$table}`
			SET claim_token = %s, claimed_until = %s
			WHERE status IN (" . $this->placeholders( $statuses ) . ')
				AND next_action_at IS NOT NULL
				AND next_action_at <= %s
				AND (claimed_until IS NULL OR claimed_until < %s)
			ORDER BY next_action_at ASC
			LIMIT %d';

		$args = array_merge(
			array( $claim_token, $this->clock->offset( $ttl ) ),
			$statuses,
			array( $now, $now, max( 1, $limit ) )
		);

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; every value bound.
			$this->db()->prepare( $sql, $args )
		);
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

		$sql = "UPDATE `{$table}`
			SET claim_token = %s, claimed_until = %s
			WHERE status IN (" . $this->placeholders( $statuses ) . ')
				AND poll_at IS NOT NULL
				AND poll_at <= %s
				AND (claimed_until IS NULL OR claimed_until < %s)
			ORDER BY poll_at ASC
			LIMIT %d';

		$args = array_merge(
			array( $claim_token, $this->clock->offset( $ttl ) ),
			$statuses,
			array( $now, $now, max( 1, $limit ) )
		);

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant; every value bound.
			$this->db()->prepare( $sql, $args )
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
