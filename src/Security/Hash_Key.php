<?php
/**
 * Keyed hashing of identifiers.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a phone number or email address into a lookup key.
 *
 * A bare SHA-256 of a phone number is not anonymous: the whole national number
 * space can be enumerated in seconds, so a leaked hash is a leaked number.
 * Hashing under a per-site random key makes the digest useless off-site, while
 * staying deterministic for lookups and for the suppression list that has to
 * outlive an erasure request.
 */
final class Hash_Key {

	public const OPTION = 'recoveryflow_hash_key';

	/**
	 * Create the site's key if it does not exist yet.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( '' === (string) get_option( self::OPTION, '' ) ) {
			add_option( self::OPTION, bin2hex( random_bytes( 32 ) ), '', false );
		}
	}

	/**
	 * Remove the key. Only from uninstall -- deleting it orphans every hash.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The raw key material.
	 *
	 * @return string
	 */
	private static function key(): string {
		$key = (string) get_option( self::OPTION, '' );

		if ( '' === $key ) {
			self::install();
			$key = (string) get_option( self::OPTION, '' );
		}

		return $key;
	}

	/**
	 * Hash one identifier.
	 *
	 * @param string $value Normalised identifier: E.164 phone, or lower-cased email.
	 * @return string 64 hex characters, or '' for an empty value.
	 */
	public static function hash( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		return hash_hmac( 'sha256', $value, self::key() );
	}

	/**
	 * Hash an email address, normalising case first.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	public static function email( string $email ): string {
		return self::hash( strtolower( trim( $email ) ) );
	}
}
