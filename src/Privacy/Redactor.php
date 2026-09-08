<?php
/**
 * Log redaction.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Strips personal data out of anything on its way to a log.
 *
 * Diagnostic logs get pasted into support tickets, forum posts and screenshots.
 * A recovery plugin's logs are exactly the wrong thing to paste, because every
 * interesting line is about a named person's phone number and shopping basket.
 * So nothing reaches the log table without passing through here first, and the
 * message content itself is never logged at all.
 *
 * Two passes, because either alone leaks. Known keys are dropped wholesale, and
 * the remaining values are pattern-matched, which catches a phone number that
 * arrived inside an error string from somebody else's API.
 */
final class Redactor {

	public const MASK = '[redacted]';

	/**
	 * Keys whose values never appear in a log.
	 *
	 * @return string[]
	 */
	private static function denied_keys(): array {
		return array(
			'phone',
			'phone_e164',
			'phone_raw',
			'to',
			'email',
			'billing_email',
			'billing_phone',
			'first_name',
			'last_name',
			'name',
			'display_name',
			'text',
			'body',
			'message',
			'components',
			'token',
			'recovery_url',
			'secret',
			'api_key',
			'apikey',
			'authorization',
			'password',
			'address',
			'address_1',
			'address_2',
			'items',
		);
	}

	/**
	 * Redact a value of any shape.
	 *
	 * @param mixed $value Value to clean.
	 * @param int   $depth Recursion guard.
	 * @return mixed
	 */
	public static function scrub( $value, int $depth = 0 ) {
		if ( $depth > 6 ) {
			return self::MASK;
		}

		if ( is_array( $value ) ) {
			$clean = array();

			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && self::is_denied( $key ) ) {
					$clean[ $key ] = self::MASK;
					continue;
				}

				$clean[ $key ] = self::scrub( $item, $depth + 1 );
			}

			return $clean;
		}

		if ( is_object( $value ) ) {
			return self::scrub( get_object_vars( $value ), $depth + 1 );
		}

		if ( is_string( $value ) ) {
			return self::scrub_string( $value );
		}

		return $value;
	}

	/**
	 * Whether a key's value must never be logged.
	 *
	 * Matched on a normalised key so that billingPhone, billing_phone and
	 * BILLING-PHONE are all caught.
	 *
	 * @param string $key Array key.
	 * @return bool
	 */
	private static function is_denied( string $key ): bool {
		// billingPhone, billing_phone and BILLING-PHONE must all be caught, so
		// the camel-case boundary becomes a separator before folding case.
		$normal = (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
		$normal = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '_', $normal ) );
		$normal = trim( $normal, '_' );

		foreach ( self::denied_keys() as $denied ) {
			if ( $normal === $denied || str_ends_with( $normal, '_' . $denied ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Mask personal data inside a free-text string.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function scrub_string( string $value ): string {
		// Bearer credentials, ours and anybody else's.
		$value = (string) preg_replace( '/\b(?:wacr|waht)_(?:live|test)_[A-Za-z0-9_-]+/', '[api-key]', $value );
		$value = (string) preg_replace( '/\bBearer\s+\S+/i', 'Bearer [redacted]', $value );

		// Recovery links and bare tokens.
		$value = (string) preg_replace( '#/recovery/[A-Za-z0-9_-]{43}#', '/recovery/[token]', $value );
		$value = (string) preg_replace( '/\b[A-Za-z0-9_-]{43}\b/', '[token]', $value );

		// Email addresses.
		$value = (string) preg_replace_callback(
			'/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9-])[A-Za-z0-9.-]*\.[A-Za-z]{2,}/',
			static fn ( array $m ): string => $m[1] . '***@' . $m[2] . '***',
			$value
		);

		// Phone numbers, keeping the last two digits so a human can still tell
		// two log lines apart without the number being readable.
		$value = (string) preg_replace_callback(
			'/(?<![A-Za-z0-9])\+?[1-9]\d{7,14}(?![A-Za-z0-9])/',
			static fn ( array $m ): string => '[phone ***' . substr( $m[0], -2 ) . ']',
			$value
		);

		return $value;
	}
}
