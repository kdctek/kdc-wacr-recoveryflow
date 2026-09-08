<?php
/**
 * Display masking for personal data.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

defined( 'ABSPATH' ) || exit;

/**
 * Shows enough of a contact detail to recognise it, and no more.
 *
 * The journeys list is a screen a shop worker leaves open all day, often on a
 * shared counter. Masking by default means a passer-by cannot read a column of
 * customer phone numbers, while the person working the queue can still tell one
 * row from another. The full value is one click away for anyone holding the
 * reveal capability, and that click is recorded.
 */
final class Mask {

	/**
	 * Mask a phone number, keeping the first two and last four digits.
	 *
	 * Two digits at the front rather than a parsed country code: working out
	 * how long a calling code is needs a full number library, and being wrong
	 * about it would reveal a digit it claimed to hide.
	 *
	 * @param string $e164 Number in E.164.
	 * @return string
	 */
	public static function phone( string $e164 ): string {
		$digits = preg_replace( '/\D+/', '', $e164 );

		if ( ! is_string( $digits ) || strlen( $digits ) < 6 ) {
			return '';
		}

		$head = substr( $digits, 0, 2 );
		$tail = substr( $digits, -4 );

		return '+' . $head . str_repeat( '•', max( 2, strlen( $digits ) - 6 ) ) . $tail;
	}

	/**
	 * Mask an email address.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function email( string $email ): string {
		$at = strpos( $email, '@' );

		if ( false === $at || $at < 1 ) {
			return '';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$dot    = strrpos( $domain, '.' );
		$tld    = false === $dot ? '' : substr( $domain, $dot );

		return $local[0] . '•••@' . $domain[0] . '•••' . $tld;
	}

	/**
	 * Shorten a name to a first name and a last initial.
	 *
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return string
	 */
	public static function name( string $first, string $last ): string {
		$first = trim( $first );
		$last  = trim( $last );

		if ( '' === $first && '' === $last ) {
			return __( 'Unnamed customer', 'kdc-wacr-recoveryflow' );
		}

		if ( '' === $last ) {
			return $first;
		}

		if ( '' === $first ) {
			return $last;
		}

		return $first . ' ' . mb_substr( $last, 0, 1 ) . '.';
	}
}
