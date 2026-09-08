<?php
/**
 * Putting a basket value into words a shopkeeper reads.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one place an amount becomes something to read.
 *
 * Amounts are stored as `decimal(13,4)`, because four places is what it takes to
 * hold a unit price that divides oddly without losing money on the way. MySQL
 * hands that back as a string with all four places on it, so a basket worth
 * £18.99 arrives as `18.9900` -- and both screens that showed a basket value
 * printed it exactly like that, next to the currency code, on the queue a shop
 * worker has open all day.
 *
 * Nothing was wrong with the number. It simply had not been read by anybody
 * before it shipped.
 *
 * **It does not use `wc_price()`.** RecoveryFlow works with WooCommerce absent
 * -- other integrations record amounts too, and the plugin is deliberately
 * usable with no shop at all -- so reaching for a WooCommerce function here
 * would fatal on exactly the installs the integration framework exists to
 * support. The
 * currency is shown as its ISO code rather than a symbol for the same reason:
 * mapping codes to symbols correctly is a large job that WooCommerce has
 * already done, and half-doing it here would put the wrong symbol in front of
 * somebody's money.
 */
final class Money {

	/**
	 * An amount and its currency, as a person would read them.
	 *
	 * @param string $amount   The stored decimal, e.g. "18.9900".
	 * @param string $currency ISO 4217 code, e.g. "GBP".
	 * @return string
	 */
	public static function format( string $amount, string $currency = '' ): string {
		$amount = trim( $amount );

		if ( '' === $amount || ! is_numeric( $amount ) ) {
			return '';
		}

		// Two places, localised, so a German shop reads 1.234,56 rather than
		// the 1234.5600 the column holds.
		$formatted = number_format_i18n( (float) $amount, 2 );

		$currency = strtoupper( trim( $currency ) );

		if ( '' === $currency ) {
			return $formatted;
		}

		return sprintf(
			/* translators: 1: an amount of money, already formatted. 2: an ISO currency code, e.g. GBP. */
			_x( '%1$s %2$s', 'a basket value and its currency', 'kdc-wacr-recoveryflow' ),
			$formatted,
			$currency
		);
	}
}
