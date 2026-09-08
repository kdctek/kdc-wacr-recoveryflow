<?php
/**
 * Recovery link tokens.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Mints and checks the tokens in a recovery link.
 *
 * A recovery link is handed to somebody over WhatsApp and has to work when they
 * tap it, possibly on a different device, without asking them to log in. It is
 * therefore a bearer credential, and it is treated as one:
 *
 * - 32 bytes from the CSPRNG, which is far past guessing. The rate limits that
 *   sit in front of it are there for abuse and load, not for entropy.
 * - Only the SHA-256 of the token is stored. A database dump yields nothing
 *   that can be replayed, which matters because these links live in message
 *   histories for months.
 * - One token per message sent, never rotated on retry. Rotating would kill
 *   the link in a message the customer has already received; minting a new one
 *   for a new message is free.
 * - Nothing about the customer is in the URL. The token identifies the journey,
 *   and the journey knows the customer.
 *
 * Timing: the lookup is by hash, so the only thing an attacker could time is a
 * comparison of digests of their own input. There is no oracle there, but the
 * fetched row is still compared with hash_equals because it costs nothing.
 */
final class Token_Service {

	public const BYTES  = 32;
	public const LENGTH = 43;

	/**
	 * Mint a token.
	 *
	 * The plaintext exists only in the return value: it is put into one message
	 * and then forgotten. Nothing logs it, and nothing can recover it.
	 *
	 * @return string 43 URL-safe characters.
	 */
	public static function mint(): string {
		return rtrim( strtr( base64_encode( random_bytes( self::BYTES ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * The stored form of a token.
	 *
	 * @param string $token Plaintext token.
	 * @return string 64 hex characters.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Whether a string could be one of our tokens.
	 *
	 * Checked before any database work, so a scanner throwing rubbish at the
	 * endpoint costs a regular expression rather than a query.
	 *
	 * @param string $token Candidate.
	 * @return bool
	 */
	public static function is_well_formed( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{' . self::LENGTH . '}$/', $token );
	}

	/**
	 * Confirm a fetched row really matches the presented token.
	 *
	 * @param string $token       Plaintext token from the request.
	 * @param string $stored_hash Hash from the database row.
	 * @return bool
	 */
	public static function matches( string $token, string $stored_hash ): bool {
		return hash_equals( $stored_hash, self::hash( $token ) );
	}
}
