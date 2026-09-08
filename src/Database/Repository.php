<?php
/**
 * Shared database access.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Database;

use WAcr\RecoveryFlow\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The small set of database operations every repository needs.
 *
 * Two of these are load-bearing for correctness rather than convenience.
 *
 * compare_and_set() is how the plugin avoids a mutex. Background stages can run
 * twice at once -- WP-Cron's own lock expires after sixty seconds, and a system
 * cron hitting wp-cron.php takes no lock at all -- so every state change is
 * written as an UPDATE whose WHERE clause names the state it expects to find.
 * The row itself decides who won, and the loser is told it lost instead of
 * quietly overwriting a conversion that landed a millisecond earlier.
 *
 * insert_ignore() turns a UNIQUE index into an idempotency guarantee: a
 * duplicate is a no-op that returns zero rather than an error, so two runs
 * racing to create the same journey produce one journey.
 *
 * Table names are interpolated because they come from Table_Names constants and
 * can never originate in a request; every other value is prepared.
 */
abstract class Repository {

	/**
	 * Time source.
	 *
	 * @var Clock
	 */
	protected Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Time source.
	 */
	public function __construct( Clock $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Which table this repository owns.
	 *
	 * @return string One of the Table_Names constants.
	 */
	abstract protected function table_key(): string;

	/**
	 * The prefixed table name.
	 *
	 * @return string
	 */
	protected function table(): string {
		return Table_Names::get( $this->table_key() );
	}

	/**
	 * The WordPress database handle.
	 *
	 * @return \wpdb
	 */
	protected function db(): \wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * The time source, for callers that need to stamp a row.
	 *
	 * @return Clock
	 */
	public function clock(): Clock {
		return $this->clock;
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string,mixed>      $data    Column values.
	 * @param array<int|string,string> $formats Column formats, in the same order.
	 * @return int The new row id, or 0 if the insert failed.
	 */
	protected function insert( array $data, array $formats ): int {
		$ok = $this->db()->insert( $this->table(), $data, $formats );

		return false === $ok ? 0 : (int) $this->db()->insert_id;
	}

	/**
	 * Insert a row, treating a duplicate key as success-by-somebody-else.
	 *
	 * Used wherever a UNIQUE index is the idempotency mechanism: two stage runs
	 * racing to create the same journey or reserve the same send must produce
	 * one of each, and the loser must be able to tell that it lost.
	 *
	 * @param array<string,mixed> $data Column values, already sanitised.
	 * @return int The new row id, or 0 when the row already existed.
	 */
	protected function insert_ignore( array $data ): int {
		$columns      = array_keys( $data );
		$placeholders = array();

		foreach ( $data as $value ) {
			if ( null === $value ) {
				$placeholders[] = 'NULL';
			} elseif ( is_int( $value ) ) {
				$placeholders[] = '%d';
			} elseif ( is_float( $value ) ) {
				$placeholders[] = '%f';
			} else {
				$placeholders[] = '%s';
			}
		}

		$values = array_values( array_filter( $data, static fn ( $value ): bool => null !== $value ) );
		$table  = $this->table();
		$fields = '`' . implode( '`, `', $columns ) . '`';
		$sql    = "INSERT IGNORE INTO `{$table}` ({$fields}) VALUES (" . implode( ', ', $placeholders ) . ')';

		$prepared = array() === $values
			? $sql
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above from column types, values bound here.
			: $this->db()->prepare( $sql, $values );

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above.
		$rows = $this->db()->query( $prepared );

		return 1 === $rows ? (int) $this->db()->insert_id : 0;
	}

	/**
	 * Update rows matching a set of exact column values.
	 *
	 * @param array<string,mixed> $data  Column values to write.
	 * @param array<string,mixed> $where Columns that must match.
	 * @return int Rows changed.
	 */
	protected function update( array $data, array $where ): int {
		$rows = $this->db()->update( $this->table(), $data, $where );

		return false === $rows ? 0 : (int) $rows;
	}

	/**
	 * Write a row only if it is still in the state the caller last saw.
	 *
	 * The return value is the whole point: 0 means somebody else changed the
	 * row first and the caller must not act as though its write happened.
	 *
	 * @param int                 $id       Row id.
	 * @param array<string,mixed> $data     Column values to write.
	 * @param array<string,mixed> $expected Columns that must still hold these values.
	 * @return bool Whether this caller made the change.
	 */
	protected function compare_and_set( int $id, array $data, array $expected ): bool {
		$where       = $expected;
		$where['id'] = $id;

		return 1 === $this->update( $data, $where );
	}

	/**
	 * Fetch one row by primary key.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	protected function find_by_id( int $id ): ?array {
		$table = $this->table();

		return $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id )
		);
	}

	/**
	 * Fetch one row by an exact column match.
	 *
	 * @param string $column Column name, from a class constant or literal.
	 * @param mixed  $value  Value to match.
	 * @return array<string,mixed>|null
	 */
	protected function find_one_by( string $column, $value ): ?array {
		$table  = $this->table();
		$format = is_int( $value ) ? '%d' : '%s';

		return $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column are code-supplied identifiers.
			$this->db()->prepare( "SELECT * FROM `{$table}` WHERE `{$column}` = {$format} LIMIT 1", $value )
		);
	}

	/**
	 * Run a prepared statement that returns at most one row.
	 *
	 * @param string|null $prepared Prepared SQL.
	 * @return array<string,mixed>|null
	 */
	protected function one( ?string $prepared ): ?array {
		if ( null === $prepared ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		$row = $this->db()->get_row( $prepared, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Run a prepared statement that returns many rows.
	 *
	 * @param string|null $prepared Prepared SQL.
	 * @return array<int,array<string,mixed>>
	 */
	protected function many( ?string $prepared ): array {
		if ( null === $prepared ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		$rows = $this->db()->get_results( $prepared, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a prepared statement that returns a single value.
	 *
	 * @param string|null $prepared Prepared SQL.
	 * @return string|null
	 */
	protected function scalar( ?string $prepared ): ?string {
		if ( null === $prepared ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		$value = $this->db()->get_var( $prepared );

		return null === $value ? null : (string) $value;
	}

	/**
	 * The ids a prepared SELECT returned.
	 *
	 * Exists because several statements here are far cheaper written as a
	 * SELECT that reads exactly the index built for it, followed by an UPDATE
	 * addressed by primary key, than as one UPDATE ... ORDER BY ... LIMIT --
	 * which MySQL is free to satisfy with a different index and a sort, and at
	 * a hundred thousand rows does.
	 *
	 * @param string|null $prepared A prepared statement selecting one id column.
	 * @return int[]
	 */
	protected function ids( ?string $prepared ): array {
		if ( null === $prepared ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		$ids = $this->db()->get_col( $prepared );

		// Cast rather than checked: these are interpolated into the IN () of the
		// statement that follows, so "they came back as strings" is not a
		// stylistic point.
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Run a prepared statement for its row count.
	 *
	 * @param string|null $prepared Prepared SQL.
	 * @return int Rows affected.
	 */
	protected function execute( ?string $prepared ): int {
		if ( null === $prepared ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
		$rows = $this->db()->query( $prepared );

		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Delete rows older than a cut-off, in one bounded batch.
	 *
	 * @param string $column Datetime column to compare.
	 * @param string $before UTC datetime; rows strictly older are removed.
	 * @param int    $limit  Maximum rows to remove.
	 * @return int Rows removed.
	 */
	protected function delete_older_than( string $column, string $before, int $limit ): int {
		$table = $this->table();

		return $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column are code-supplied identifiers.
			$this->db()->prepare( "DELETE FROM `{$table}` WHERE `{$column}` < %s LIMIT %d", $before, $limit )
		);
	}

	/**
	 * Count rows matching an exact column value.
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Value to match.
	 * @return int
	 */
	protected function count_by( string $column, $value ): int {
		$table  = $this->table();
		$format = is_int( $value ) ? '%d' : '%s';

		return (int) $this->scalar(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column are code-supplied identifiers.
			$this->db()->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = {$format}", $value )
		);
	}

	/**
	 * Turn a list of values into a prepared IN() fragment.
	 *
	 * The alternative -- interpolating a joined string -- is how status filters
	 * become injection points, so the placeholders are generated from the count
	 * and the values are always bound.
	 *
	 * @param array<int,string> $values Values.
	 * @return string Placeholder list, e.g. "%s, %s, %s"; empty when there are none.
	 */
	protected function placeholders( array $values ): string {
		return implode( ', ', array_fill( 0, count( $values ), '%s' ) );
	}
}
