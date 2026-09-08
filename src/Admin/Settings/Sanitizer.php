<?php
/**
 * Cleaning settings on their way into storage.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Settings;

use WAcr\RecoveryFlow\Recovery\Email_Compliance;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a posted settings form into values worth storing.
 *
 * All of RecoveryFlow's settings live in one option, and the screen splits them
 * across six tabs. That combination has a trap in it that is easy to walk into
 * and hard to notice afterwards: WordPress hands the sanitiser only the fields
 * the posted form contained, so a sanitiser that returns what it was given
 * replaces the whole option with one tab's worth of settings and silently
 * deletes the other five. The site keeps working, on defaults, and the merchant
 * discovers it weeks later when quiet hours stop being observed.
 *
 * So sanitising starts from the stored settings, and writes back only the keys
 * the posted tab is allowed to speak for. Which tab that is comes from a hidden
 * field in the form, checked against the schema; an unrecognised tab writes
 * nothing rather than guessing.
 *
 * The second trap is the unchecked checkbox. A browser posts nothing at all for
 * one, so "absent" has to mean false -- but only for a checkbox on the tab that
 * was actually submitted, or every save would switch off every checkbox on
 * every other tab.
 */
final class Sanitizer {

	/**
	 * The hidden form field naming the tab being saved.
	 */
	public const TAB_FIELD = 'recoveryflow_tab';

	/**
	 * Clean a posted settings form.
	 *
	 * @param mixed  $posted What the form sent.
	 * @param string $tab    Tab id the form belongs to.
	 * @return array<string,mixed> The complete settings option, ready to store.
	 */
	public static function sanitize( $posted, string $tab ): array {
		$settings = Options::all();

		if ( ! is_array( $posted ) || ! Schema::has_tab( $tab ) ) {
			return $settings;
		}

		$fields = Schema::fields();

		foreach ( Schema::keys_for_tab( $tab ) as $key ) {
			$spec = $fields[ $key ];
			$type = (string) ( $spec['type'] ?? 'text' );

			if ( 'checkbox' === $type ) {
				// Absent means unticked, but only because this tab was the one
				// posted -- see the class docblock.
				$settings[ $key ] = ! empty( $posted[ $key ] );
				continue;
			}

			if ( ! array_key_exists( $key, $posted ) ) {
				continue;
			}

			$settings[ $key ] = self::value( $posted[ $key ], $spec, $settings[ $key ] ?? null );
		}

		return $settings;
	}

	/**
	 * Clean one value according to its field spec.
	 *
	 * A value that cannot be made sense of falls back to what was already
	 * stored rather than to the field's default. Silently resetting a setting
	 * somebody had deliberately changed is worse than ignoring a bad edit.
	 *
	 * @param mixed               $raw      The posted value.
	 * @param array<string,mixed> $spec     Field spec from the schema.
	 * @param mixed               $current  What is stored now.
	 * @return mixed
	 */
	private static function value( $raw, array $spec, $current ) {
		$type = (string) ( $spec['type'] ?? 'text' );

		switch ( $type ) {
			case 'number':
				return self::number( $raw, $spec, $current );

			case 'select':
			case 'radio':
				$options = is_array( $spec['options'] ?? null ) ? $spec['options'] : array();

				return array_key_exists( (string) $raw, $options ) ? (string) $raw : $current;

			case 'time':
				return self::time( $raw, $current );

			case 'url':
				return self::url( $raw );

			case 'address':
				return Email_Compliance::sanitize_address( (string) $raw );

			case 'country':
				return Email_Compliance::sanitize_country( (string) $raw );

			case 'textarea':
				return sanitize_textarea_field( (string) $raw );

			default:
				$value = sanitize_text_field( (string) $raw );
				$limit = isset( $spec['maxlength'] ) ? (int) $spec['maxlength'] : 0;

				return $limit > 0 ? mb_substr( $value, 0, $limit ) : $value;
		}//end switch
	}

	/**
	 * Clean a number, held inside the range the field declares.
	 *
	 * Clamping rather than rejecting: a merchant who typed 500 into a field
	 * that stops at 30 meant "as long as possible", and refusing the whole save
	 * to teach them the maximum helps nobody. The clamped value is what the box
	 * shows afterwards, so the correction is visible rather than silent.
	 *
	 * @param mixed               $raw     Posted value.
	 * @param array<string,mixed> $spec    Field spec.
	 * @param mixed               $current Stored value.
	 * @return int|float
	 */
	private static function number( $raw, array $spec, $current ) {
		$raw = is_string( $raw ) ? trim( $raw ) : $raw;

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return is_numeric( $current ) ? $current + 0 : 0;
		}

		$decimal = isset( $spec['step'] ) && '1' !== (string) $spec['step'];
		$value   = $decimal ? (float) $raw : (int) $raw;

		if ( isset( $spec['min'] ) ) {
			$value = max( $spec['min'] + 0, $value );
		}

		if ( isset( $spec['max'] ) ) {
			$value = min( $spec['max'] + 0, $value );
		}

		return $value;
	}

	/**
	 * Clean a time of day.
	 *
	 * @param mixed $raw     Posted value.
	 * @param mixed $current Stored value.
	 * @return string HH:MM.
	 */
	private static function time( $raw, $current ): string {
		$value = trim( (string) $raw );

		if ( 1 !== preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $value ) ) {
			return is_string( $current ) ? $current : '';
		}

		return $value;
	}

	/**
	 * Clean a URL, keeping only ones a server could actually call.
	 *
	 * @param mixed $raw Posted value.
	 * @return string
	 */
	private static function url( $raw ): string {
		return esc_url_raw( trim( (string) $raw ), array( 'https', 'http' ) );
	}
}
