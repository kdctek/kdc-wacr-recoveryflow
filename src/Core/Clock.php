<?php
/**
 * Time source.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of "now", so tests can move time without sleeping.
 *
 * Every stored timestamp is UTC and formatted here. Nothing in the plugin calls
 * SQL NOW(): a host may hand the connection a non-UTC session timezone, which
 * would silently shift every due-time comparison.
 */
class Clock {

	public const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Fixed timestamp for tests, or null to use the real clock.
	 *
	 * @var int|null
	 */
	private ?int $frozen_at = null;

	/**
	 * Current Unix timestamp.
	 *
	 * @return int
	 */
	public function timestamp(): int {
		return $this->frozen_at ?? time();
	}

	/**
	 * Current time as a UTC datetime string.
	 *
	 * @return string
	 */
	public function now(): string {
		return gmdate( self::FORMAT, $this->timestamp() );
	}

	/**
	 * A UTC datetime string offset from now.
	 *
	 * @param int $seconds Seconds to add; negative to subtract.
	 * @return string
	 */
	public function offset( int $seconds ): string {
		return gmdate( self::FORMAT, $this->timestamp() + $seconds );
	}

	/**
	 * Format an arbitrary timestamp the way the database stores it.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public function at( int $timestamp ): string {
		return gmdate( self::FORMAT, $timestamp );
	}

	/**
	 * Read a stored datetime back as a Unix timestamp.
	 *
	 * @param string $datetime UTC datetime string.
	 * @return int
	 */
	public function parse( string $datetime ): int {
		$parsed = strtotime( $datetime . ' UTC' );

		return false === $parsed ? 0 : $parsed;
	}

	/**
	 * Freeze time. Test helper.
	 *
	 * @param int|null $timestamp Timestamp to freeze at, or null to unfreeze.
	 * @return void
	 */
	public function freeze( ?int $timestamp ): void {
		$this->frozen_at = $timestamp;
	}
}
