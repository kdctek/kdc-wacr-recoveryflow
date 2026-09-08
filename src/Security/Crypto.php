<?php
/**
 * At-rest encryption for credentials.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts the API credential with a key derived from the site's salts.
 *
 * This protects the credential against casual database exposure -- a leaked
 * dump, a plugin that lists options, a support export. It is not protection
 * against an attacker who already has wp-config.php, and nothing stored on the
 * same server could be.
 *
 * AES-256-GCM is authenticated: a tampered ciphertext fails to decrypt rather
 * than yielding plausible rubbish that would be sent as a bearer token.
 * Payloads are tagged 'rfenc1:' so the format can change later without
 * guessing at what an untagged string is.
 */
final class Crypto {

	private const PREFIX     = 'rfenc1:';
	private const CIPHER     = 'aes-256-gcm';
	private const IV_LENGTH  = 12;
	private const TAG_LENGTH = 16;

	/**
	 * Whether encryption is available on this host.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::CIPHER, (array) openssl_get_cipher_methods(), true );
	}

	/**
	 * Derive the key.
	 *
	 * Rotating the site's salts therefore invalidates the stored credential.
	 * That is the correct trade: the alternative is a key stored beside the
	 * ciphertext. A failed decryption is surfaced on the status screen as
	 * "re-enter your key" rather than as a silent inability to send.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key(): string {
		if ( defined( 'KDC_WACR_RECOVERYFLOW_ENCRYPTION_KEY' ) && '' !== (string) constant( 'KDC_WACR_RECOVERYFLOW_ENCRYPTION_KEY' ) ) {
			return hash( 'sha256', (string) constant( 'KDC_WACR_RECOVERYFLOW_ENCRYPTION_KEY' ), true );
		}

		$material = '';

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $constant ) {
			if ( defined( $constant ) ) {
				$material .= (string) constant( $constant );
			}
		}

		if ( '' === $material ) {
			$material = (string) get_option( 'siteurl' ) . ABSPATH;
		}

		return hash( 'sha256', 'recoveryflow|' . $material, true );
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string Tagged payload, or the plain value when OpenSSL is unavailable.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! self::available() ) {
			return $plaintext;
		}

		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';

		$cipher = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH );

		if ( false === $cipher ) {
			return $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a payload produced by encrypt().
	 *
	 * @param string $payload Stored value.
	 * @return string The secret, or '' when it cannot be decrypted.
	 */
	public static function decrypt( string $payload ): string {
		if ( 0 !== strpos( $payload, self::PREFIX ) ) {
			// Never encrypted (no OpenSSL when it was saved), so pass it through.
			return $payload;
		}

		if ( ! self::available() ) {
			return '';
		}

		$raw = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) <= self::IV_LENGTH + self::TAG_LENGTH ) {
			return '';
		}

		$plain = openssl_decrypt(
			substr( $raw, self::IV_LENGTH + self::TAG_LENGTH ),
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, self::IV_LENGTH ),
			substr( $raw, self::IV_LENGTH, self::TAG_LENGTH )
		);

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a stored payload is encrypted but unreadable.
	 *
	 * Distinguishes "the salts changed" from "no credential saved", which the
	 * status screen needs in order to give an actionable message.
	 *
	 * @param string $payload Stored value.
	 * @return bool
	 */
	public static function is_undecryptable( string $payload ): bool {
		return 0 === strpos( $payload, self::PREFIX ) && '' === self::decrypt( $payload );
	}
}
