<?php
/**
 * Identifier generation.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Version 4 UUIDs for events, journeys and lease tokens.
 *
 * Public identifiers are random rather than sequential so that one journey's
 * id never tells anybody how many customers a store has, or lets them guess the
 * next one.
 */
final class Uuid {

	/**
	 * A random UUID.
	 *
	 * @return string 36 characters.
	 */
	public static function v4(): string {
		$bytes = random_bytes( 16 );

		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Whether a string looks like a v4 UUID.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
	}
}
