<?php
/**
 * One way of reaching a person.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

defined( 'ABSPATH' ) || exit;

/**
 * A single identifier belonging to a customer.
 *
 * Identity is a set, not a pair of columns. A person may be reachable on a
 * phone number, an email address, both, or on an id belonging to whichever
 * system introduced them, and which of those we hold changes over the life of
 * the relationship.
 *
 * The distinction that matters is between strong and weak kinds. A phone number
 * identifies exactly one person, so two records carrying the same one are the
 * same person and may be merged on sight. An email address does not: households
 * share addresses, and so do the role addresses that small businesses order
 * from. Treating a weak identifier as a merge key silently joins two strangers'
 * carts, which is worse than failing to recognise a returning customer.
 */
final class Identity {

	/**
	 * A phone number in E.164, the identity a WhatsApp message is addressed to.
	 */
	public const E164 = 'e164';

	/**
	 * An email address, lowercased. Weak: never a merge key.
	 */
	public const EMAIL = 'email';

	/**
	 * An id belonging to the system that introduced this person.
	 */
	public const EXTERNAL_ID = 'external_id';

	/**
	 * A WordPress user id. Strong, and authoritative when present.
	 */
	public const WP_USER = 'wp_user';

	public const STATUS_UNKNOWN = 'unknown';
	public const STATUS_VALID   = 'valid';
	public const STATUS_INVALID = 'invalid';

	/**
	 * Kinds that identify exactly one person.
	 *
	 * These carry the UNIQUE index, so the database refuses a second row and a
	 * race between two requests enrolling the same value resolves to one
	 * customer without the calling code arbitrating.
	 *
	 * @return string[]
	 */
	public static function strong_kinds(): array {
		return array( self::E164, self::EXTERNAL_ID, self::WP_USER );
	}

	/**
	 * Every kind.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		return array( self::E164, self::EMAIL, self::EXTERNAL_ID, self::WP_USER );
	}

	/**
	 * Whether this kind may be used to decide two records are the same person.
	 *
	 * @param string $kind Identity kind.
	 * @return bool
	 */
	public static function is_strong( string $kind ): bool {
		return in_array( $kind, self::strong_kinds(), true );
	}

	/**
	 * Whether this is a kind we recognise.
	 *
	 * @param string $kind Identity kind.
	 * @return bool
	 */
	public static function is_kind( string $kind ): bool {
		return in_array( $kind, self::kinds(), true );
	}

	/**
	 * Normalise a raw value for its kind, before hashing.
	 *
	 * Only case and surrounding whitespace are touched. Email addresses are
	 * lowercased because the platform stores and matches them that way, and a
	 * hash of "Asha@Example.com" would otherwise never match a hash of the same
	 * address typed in lower case. Nothing else is canonicalised: stripping dots
	 * or +tags from a Gmail address would merge addresses their owner may be
	 * using deliberately to keep things apart.
	 *
	 * @param string $kind  Identity kind.
	 * @param string $value Raw value.
	 * @return string Normalised value, or '' when it cannot be used.
	 */
	public static function normalize_value( string $kind, string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		return self::EMAIL === $kind ? strtolower( $value ) : $value;
	}

	/**
	 * A human label for a kind.
	 *
	 * The kind itself is the stored machine value and must stay untranslated:
	 * it is written to the database, the log and the REST response, and a
	 * translated one would make those rows unreadable the moment a site changed
	 * language, and unqueryable for good.
	 *
	 * @param string $kind Identity kind.
	 * @return string
	 */
	public static function label( string $kind ): string {
		$labels = array(
			self::E164        => _x( 'Phone number', 'a way of reaching a customer', 'kdc-wacr-recoveryflow' ),
			self::EMAIL       => _x( 'Email address', 'a way of reaching a customer', 'kdc-wacr-recoveryflow' ),
			self::EXTERNAL_ID => _x( 'External ID', 'a way of reaching a customer', 'kdc-wacr-recoveryflow' ),
			self::WP_USER     => _x( 'WordPress account', 'a way of reaching a customer', 'kdc-wacr-recoveryflow' ),
		);

		return $labels[ $kind ] ?? $kind;
	}
}
