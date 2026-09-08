<?php
/**
 * Stage locks.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Database;

use WAcr\RecoveryFlow\Support\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * A mutex that actually excludes.
 *
 * WordPress offers no lock a plugin can rely on. add_option() looks like one
 * and is not: core writes it with INSERT ... ON DUPLICATE KEY UPDATE, so a
 * second caller overwrites the first and is told it succeeded. Transients are
 * worse -- on a site with an object cache they may not be shared between the
 * PHP workers that are racing. WP-Cron's own guard expires after sixty seconds,
 * and a system cron hitting wp-cron.php directly takes no guard at all.
 *
 * So a lock here is a conditional UPDATE against a row that already exists. The
 * database decides the winner: exactly one caller can change a row from "free
 * or expired" to "owned by me", and it learns it won because the update
 * affected one row. Ownership is a random token rather than a flag, so a run
 * that overran its lease cannot release a lock that has since been taken by
 * somebody else.
 *
 * Rows are seeded at install. If one is missing -- a partial upgrade, a table
 * truncated by hand -- it is created on demand rather than failing the stage.
 */
final class Lock_Repository extends Repository {

	/**
	 * How long a lock is held before it is considered abandoned.
	 */
	public const DEFAULT_TTL = 90;

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::LOCKS;
	}

	/**
	 * Take a lock, if it is free or its holder has gone away.
	 *
	 * @param string $key Lock name, e.g. 'dispatch'.
	 * @param int    $ttl Seconds before the lock may be taken from us.
	 * @return string|null The ownership token, or null if somebody else holds it.
	 */
	public function acquire( string $key, int $ttl = self::DEFAULT_TTL ): ?string {
		$owner = Uuid::v4();
		$table = $this->table();
		$now   = $this->clock->now();

		$taken = $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}` SET owner = %s, expires_at = %s
				WHERE lock_key = %s AND (expires_at IS NULL OR expires_at < %s)",
				$owner,
				$this->clock->offset( $ttl ),
				$key,
				$now
			)
		);

		if ( 1 === $taken ) {
			return $owner;
		}

		// The row may simply not exist yet. Create it and try once more; if we
		// lose that race too, somebody else holds the lock and that is correct.
		if ( ! $this->exists( $key ) ) {
			$this->seed( $key );

			return $this->acquire( $key, $ttl );
		}

		return null;
	}

	/**
	 * Give a lock back.
	 *
	 * A run whose lease expired cannot release the lock somebody else has since
	 * taken, because the owner token will not match.
	 *
	 * @param string $key   Lock name.
	 * @param string $owner The token acquire() returned.
	 * @return bool Whether the lock was still ours.
	 */
	public function release( string $key, string $owner ): bool {
		$table = $this->table();

		return 1 === $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}` SET owner = NULL, expires_at = NULL WHERE lock_key = %s AND owner = %s",
				$key,
				$owner
			)
		);
	}

	/**
	 * Push a held lock's expiry back while work is still going on.
	 *
	 * @param string $key   Lock name.
	 * @param string $owner Ownership token.
	 * @param int    $ttl   Seconds from now.
	 * @return bool Whether the lock was still ours to extend.
	 */
	public function renew( string $key, string $owner, int $ttl = self::DEFAULT_TTL ): bool {
		$table = $this->table();

		return 1 === $this->execute(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"UPDATE `{$table}` SET expires_at = %s WHERE lock_key = %s AND owner = %s",
				$this->clock->offset( $ttl ),
				$key,
				$owner
			)
		);
	}

	/**
	 * Whether a lock is currently held by anybody.
	 *
	 * For diagnostics only: a caller must never branch on this instead of
	 * trying to acquire, because the answer can change in between.
	 *
	 * @param string $key Lock name.
	 * @return bool
	 */
	public function is_held( string $key ): bool {
		$table = $this->table();

		return null !== $this->scalar(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT owner FROM `{$table}` WHERE lock_key = %s AND expires_at IS NOT NULL AND expires_at >= %s",
				$key,
				$this->clock->now()
			)
		);
	}

	/**
	 * Whether the row backing a lock exists.
	 *
	 * @param string $key Lock name.
	 * @return bool
	 */
	private function exists( string $key ): bool {
		$table = $this->table();

		return null !== $this->scalar(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare( "SELECT lock_key FROM `{$table}` WHERE lock_key = %s", $key )
		);
	}

	/**
	 * Create a missing lock row.
	 *
	 * @param string $key Lock name.
	 * @return void
	 */
	private function seed( string $key ): void {
		$this->insert_ignore(
			array(
				'lock_key'   => $key,
				'owner'      => null,
				'expires_at' => null,
			)
		);
	}
}
