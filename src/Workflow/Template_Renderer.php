<?php
/**
 * Placeholder substitution for message templates.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

defined( 'ABSPATH' ) || exit;

/**
 * Swaps {{ name }} for an allow-listed value, and does nothing else.
 *
 * There is no expression language here and there will not be one. No eval, no
 * create_function, no call_user_func on anything a definition contains, no
 * variable variables, no callable strings: a placeholder is looked up in a flat
 * map and replaced with a string. Anything a template names that is not on the
 * allow-list is removed and logged, so a stray "{{ foo }}" is never delivered
 * to a customer and never becomes a way to probe what else is in scope.
 *
 * The three modes exist because the same value is unsafe in different ways
 * depending on where it lands.
 *
 * whatsapp_param is the strict one. Meta refuses a template parameter that
 * contains a newline, a tab, or four or more consecutive spaces, and the whole
 * send fails with a validation error that tells the merchant nothing. A product
 * name pasted from a spreadsheet contains all three, so the value is cleaned
 * rather than the send being lost.
 *
 * url percent-encodes a value destined for a URL button, so a token or an item
 * name cannot break out of the path it was appended to.
 *
 * text is for anything read by a person in wp-admin, where escaping happens at
 * the point of output instead.
 */
final class Template_Renderer {

	public const MODE_TEXT  = 'text';
	public const MODE_PARAM = 'whatsapp_param';
	public const MODE_URL   = 'url';

	/**
	 * Meta's limits on template parameter values.
	 */
	public const MAX_PARAM_LENGTH  = 1024;
	public const MAX_HEADER_LENGTH = 60;

	/**
	 * Anything that looks like a placeholder, so that a malformed one is
	 * removed rather than delivered.
	 */
	private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_.\-]{1,64})\s*\}\}/';

	/**
	 * Fill in a template.
	 *
	 * @param string           $template   The string an administrator typed.
	 * @param Variable_Context $context    The allow-listed values.
	 * @param string           $mode       One of the MODE_ constants.
	 * @param int              $max_length Length cap for whatsapp_param mode.
	 * @return string
	 */
	public static function render( string $template, Variable_Context $context, string $mode = self::MODE_TEXT, int $max_length = self::MAX_PARAM_LENGTH ): string {
		if ( '' === $template ) {
			return '';
		}

		$rendered = preg_replace_callback(
			self::PLACEHOLDER,
			static function ( array $matches ) use ( $context, $mode ): string {
				$value = $context->get( (string) $matches[1] );

				if ( self::MODE_URL === $mode ) {
					return self::encode_for_url( $value );
				}

				if ( self::MODE_PARAM === $mode ) {
					return self::flatten( $value );
				}

				return $value;
			},
			$template
		);

		if ( ! is_string( $rendered ) ) {
			return '';
		}

		if ( self::MODE_PARAM === $mode ) {
			// Run over the whole string as well: the literal text between
			// placeholders came out of the same JSON document and can carry the
			// same newlines that Meta refuses.
			return self::sanitize_param( $rendered, $max_length );
		}

		return $rendered;
	}

	/**
	 * Make a value safe to send as a WhatsApp template parameter.
	 *
	 * @param string $value      The value.
	 * @param int    $max_length Cap, in characters.
	 * @return string
	 */
	public static function sanitize_param( string $value, int $max_length = self::MAX_PARAM_LENGTH ): string {
		$flat = trim( self::flatten( $value ) );
		$cap  = max( 1, $max_length );

		if ( mb_strlen( $flat ) <= $cap ) {
			return $flat;
		}

		// An ellipsis rather than a hard cut: a truncated item list should read
		// as truncated, not as a name that happens to stop mid-word.
		return rtrim( mb_substr( $flat, 0, $cap - 1 ) ) . '…';
	}

	/**
	 * Whether a string contains anything this class would substitute.
	 *
	 * @param string $template The string.
	 * @return bool
	 */
	public static function has_placeholder( string $template ): bool {
		return 1 === preg_match( self::PLACEHOLDER, $template );
	}

	/**
	 * Remove the whitespace Meta refuses.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private static function flatten( string $value ): string {
		$flat = str_replace( array( "\r\n", "\r", "\n", "\t", "\v", "\f" ), ' ', $value );
		$flat = preg_replace( '/ {4,}/', ' ', $flat );

		return is_string( $flat ) ? $flat : '';
	}

	/**
	 * Percent-encode a value bound for a URL button.
	 *
	 * A value that is already an absolute link is left alone. Encoding it would
	 * turn the recovery link into "https%3A%2F%2F..." in the customer's message,
	 * which is a dead link and the single most expensive thing this class could
	 * get wrong.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private static function encode_for_url( string $value ): string {
		if ( 1 === preg_match( '#^https?://#i', $value ) ) {
			return $value;
		}

		return rawurlencode( $value );
	}
}
