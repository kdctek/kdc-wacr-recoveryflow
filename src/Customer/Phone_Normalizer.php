<?php
/**
 * Phone number normalisation.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

use WAcr\RecoveryFlow\Core\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Turns whatever a customer typed into E.164, or says it cannot.
 *
 * Checkout fields do not validate phone numbers, so a number arrives in
 * whatever national form the customer is used to: "9876543210",
 * "022 4897 1532", "(555) 010-9999", "07700 900123". A naive digit strip turns
 * the first of those into "+9876543210", which is a real number in Yemen. That
 * is the specific failure this class exists to prevent -- a recovery message
 * delivered to a stranger is worse than one not delivered at all.
 *
 * The billing country is the hint that makes it work. Without one, only a
 * number that already carries an international prefix is accepted.
 *
 * This is deliberately not a full libphonenumber: bundling one would be a
 * megabyte of dependency for a plugin whose messaging platform validates the
 * number again anyway. It handles the international forms and the trunk-prefix
 * rules for the countries that actually appear at checkout, and it refuses
 * rather than guesses when it is unsure.
 */
final class Phone_Normalizer {

	public const VALID   = 'valid';
	public const INVALID = 'invalid';

	/**
	 * ISO 3166-1 alpha-2 to calling code.
	 *
	 * @return array<string,string>
	 */
	public static function calling_codes(): array {
		$codes = array(
			'AE' => '971',
			'AR' => '54',
			'AT' => '43',
			'AU' => '61',
			'BD' => '880',
			'BE' => '32',
			'BG' => '359',
			'BH' => '973',
			'BR' => '55',
			'CA' => '1',
			'CH' => '41',
			'CL' => '56',
			'CN' => '86',
			'CO' => '57',
			'CZ' => '420',
			'DE' => '49',
			'DK' => '45',
			'EG' => '20',
			'ES' => '34',
			'FI' => '358',
			'FR' => '33',
			'GB' => '44',
			'GH' => '233',
			'GR' => '30',
			'HK' => '852',
			'HR' => '385',
			'HU' => '36',
			'ID' => '62',
			'IE' => '353',
			'IL' => '972',
			'IN' => '91',
			'IT' => '39',
			'JP' => '81',
			'KE' => '254',
			'KR' => '82',
			'KW' => '965',
			'LK' => '94',
			'MA' => '212',
			'MX' => '52',
			'MY' => '60',
			'NG' => '234',
			'NL' => '31',
			'NO' => '47',
			'NP' => '977',
			'NZ' => '64',
			'OM' => '968',
			'PE' => '51',
			'PH' => '63',
			'PK' => '92',
			'PL' => '48',
			'PT' => '351',
			'QA' => '974',
			'RO' => '40',
			'RS' => '381',
			'RU' => '7',
			'SA' => '966',
			'SE' => '46',
			'SG' => '65',
			'SK' => '421',
			'TH' => '66',
			'TR' => '90',
			'TW' => '886',
			'TZ' => '255',
			'UA' => '380',
			'UG' => '256',
			'US' => '1',
			'VN' => '84',
			'ZA' => '27',
		);

		/**
		 * Filter the ISO country code to calling code map.
		 *
		 * A store selling into a country not listed here can add it, or replace
		 * the map wholesale with one from another source.
		 *
		 * @param array<string,string> $codes Country code => calling code.
		 */
		return (array) apply_filters( Hooks::FILTER_CALLING_CODES, $codes );
	}

	/**
	 * Countries where a leading zero is a trunk prefix to be dropped.
	 *
	 * Italy is the notable exception: the leading zero on an Italian landline
	 * is part of the number and dropping it produces an unreachable one. The
	 * North American plan has no trunk prefix at all, so a leading 1 there is
	 * the country code rather than a prefix to strip.
	 *
	 * @return string[]
	 */
	private static function keeps_leading_zero(): array {
		return array( 'IT' );
	}

	/**
	 * Expected national number lengths, where they are reliable.
	 *
	 * Only countries with a single fixed mobile length are listed: a length
	 * check is only worth having when it cannot produce a false rejection.
	 *
	 * @return array<string,int[]>
	 */
	private static function national_lengths(): array {
		return array(
			'IN' => array( 10 ),
			'US' => array( 10 ),
			'CA' => array( 10 ),
			'AE' => array( 9 ),
			'SA' => array( 9 ),
			'AU' => array( 9 ),
			'SG' => array( 8 ),
			'HK' => array( 8 ),
			'GB' => array( 10 ),
			'ZA' => array( 9 ),
			'NZ' => array( 8, 9 ),
			'MY' => array( 9, 10 ),
			'LK' => array( 9 ),
			'NP' => array( 10 ),
			'BD' => array( 10 ),
			'PK' => array( 10 ),
		);
	}

	/**
	 * Normalise one number.
	 *
	 * @param string $raw     What the customer typed.
	 * @param string $country ISO 3166-1 alpha-2 billing country, or ''.
	 * @return array{status:string,e164:string,reason:string}
	 */
	public static function normalize( string $raw, string $country = '' ): array {
		$country = strtoupper( substr( trim( $country ), 0, 2 ) );

		$result = self::parse( $raw, $country );

		/**
		 * Filter the outcome of phone normalisation.
		 *
		 * @param array  $result  status, e164 and reason.
		 * @param string $raw     The original input.
		 * @param string $country ISO country hint.
		 */
		return (array) apply_filters( Hooks::FILTER_NORMALIZE_PHONE, $result, $raw, $country );
	}

	/**
	 * The normalisation itself.
	 *
	 * @param string $raw     What the customer typed.
	 * @param string $country ISO country code, upper case, or ''.
	 * @return array{status:string,e164:string,reason:string}
	 */
	private static function parse( string $raw, string $country ): array {
		$input = trim( $raw );

		if ( '' === $input ) {
			return self::invalid( 'empty' );
		}

		// A number with letters in it is a note, not a number.
		if ( 1 === preg_match( '/[a-z]/i', $input ) ) {
			return self::invalid( 'not_a_number' );
		}

		if ( strlen( $input ) > 25 ) {
			return self::invalid( 'too_long' );
		}

		// "+44 (0) 7700 900123" -- the bracketed trunk prefix is not dialled.
		$input = preg_replace( '/\((\s*0\s*)\)/', '', $input );

		$international = 0 === strpos( $input, '+' ) || 0 === strpos( ltrim( $input, ' ' ), '00' );
		$digits        = preg_replace( '/\D+/', '', (string) $input );

		if ( '' === $digits ) {
			return self::invalid( 'no_digits' );
		}

		// 00 is the international access prefix in most of the world.
		if ( ! str_starts_with( $input, '+' ) && str_starts_with( $digits, '00' ) ) {
			$digits        = substr( $digits, 2 );
			$international = true;
		}

		if ( $international ) {
			return self::from_international( $digits );
		}

		if ( '' === $country ) {
			// Without a country we can only accept something that already looks
			// international. Guessing here is how a national number becomes a
			// real number somewhere else entirely.
			return self::from_bare_digits( $digits );
		}

		return self::from_national( $digits, $country );
	}

	/**
	 * Handle a number the customer wrote in international form.
	 *
	 * @param string $digits Digits only, country code first.
	 * @return array{status:string,e164:string,reason:string}
	 */
	private static function from_international( string $digits ): array {
		if ( strlen( $digits ) < 8 || strlen( $digits ) > 15 ) {
			return self::invalid( 'bad_length' );
		}

		if ( '0' === $digits[0] ) {
			return self::invalid( 'bad_country_code' );
		}

		return self::valid( $digits );
	}

	/**
	 * Handle digits with no country hint and no international marker.
	 *
	 * @param string $digits Digits only.
	 * @return array{status:string,e164:string,reason:string}
	 */
	private static function from_bare_digits( string $digits ): array {
		$length = strlen( $digits );

		if ( $length < 11 || $length > 15 || '0' === $digits[0] ) {
			return self::invalid( 'no_country' );
		}

		// Only accept it if it opens with a calling code we recognise, so a
		// ten-digit national number can never be read as an international one.
		foreach ( self::calling_codes() as $code ) {
			if ( str_starts_with( $digits, $code ) ) {
				return self::valid( $digits );
			}
		}

		return self::invalid( 'no_country' );
	}

	/**
	 * Handle a national number, using the billing country.
	 *
	 * @param string $digits  Digits only.
	 * @param string $country ISO country code.
	 * @return array{status:string,e164:string,reason:string}
	 */
	private static function from_national( string $digits, string $country ): array {
		$codes = self::calling_codes();

		if ( ! isset( $codes[ $country ] ) ) {
			return self::from_bare_digits( $digits );
		}

		$code    = $codes[ $country ];
		$lengths = self::national_lengths()[ $country ] ?? array();

		// Drop the trunk prefix, where the country has one.
		if ( str_starts_with( $digits, '0' ) && ! in_array( $country, self::keeps_leading_zero(), true ) ) {
			$digits = ltrim( $digits, '0' );
		}

		// A customer who typed their own country code without a plus.
		if ( str_starts_with( $digits, $code ) && strlen( $digits ) > strlen( $code ) ) {
			$remainder = substr( $digits, strlen( $code ) );

			if ( array() === $lengths || in_array( strlen( $remainder ), $lengths, true ) ) {
				return self::valid( $digits );
			}
		}

		if ( array() !== $lengths && ! in_array( strlen( $digits ), $lengths, true ) ) {
			return self::invalid( 'wrong_length' );
		}

		$candidate = $code . $digits;

		if ( strlen( $candidate ) < 8 || strlen( $candidate ) > 15 ) {
			return self::invalid( 'bad_length' );
		}

		return self::valid( $candidate );
	}

	/**
	 * A successful result.
	 *
	 * @param string $digits Digits, country code first.
	 * @return array{status:string,e164:string,reason:string}
	 */
	private static function valid( string $digits ): array {
		return array(
			'status' => self::VALID,
			'e164'   => '+' . $digits,
			'reason' => '',
		);
	}

	/**
	 * A failed result.
	 *
	 * @param string $reason Why.
	 * @return array{status:string,e164:string,reason:string}
	 */
	private static function invalid( string $reason ): array {
		return array(
			'status' => self::INVALID,
			'e164'   => '',
			'reason' => $reason,
		);
	}

	/**
	 * Convenience: the E.164 number, or '' when it could not be parsed.
	 *
	 * @param string $raw     What the customer typed.
	 * @param string $country ISO country hint.
	 * @return string
	 */
	public static function to_e164( string $raw, string $country = '' ): string {
		$result = self::normalize( $raw, $country );

		return self::VALID === $result['status'] ? $result['e164'] : '';
	}

	/**
	 * The bare digits form, which conversation lookups address people by.
	 *
	 * @param string $e164 A number in E.164.
	 * @return string
	 */
	public static function digits( string $e164 ): string {
		return ltrim( $e164, '+' );
	}
}
