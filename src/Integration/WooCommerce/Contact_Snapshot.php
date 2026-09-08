<?php
/**
 * The one place a contact detail is written into the shopper's session.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Merging what we have just learned into what we already knew.
 *
 * Extracted when a second capture point arrived. Until then the merge lived
 * inside the checkout's own capture, which was correct while the checkout was
 * the only place a detail could be typed -- and would have become the fourth
 * duplicated rule in this plugin the moment the basket page grew a field.
 *
 * The rules it owns are small and easy to get subtly wrong twice:
 *
 * - **A blank never overwrites something known.** A shopper who typed a number
 *   on the product page and then left the basket field empty has not withdrawn
 *   it. This is the whole reason the merge is not `array_merge()`.
 * - **An unchanged value is not a write.** The session is persisted per
 *   request, so rewriting an identical snapshot costs a query on every page a
 *   shopper looks at and changes nothing.
 * - **Everything is sanitised and capped here**, once, rather than at each of
 *   the places that call it. A capture point that forgot would not fail; it
 *   would quietly store whatever it was handed.
 */
final class Contact_Snapshot {

	/**
	 * How much of any one field is kept.
	 *
	 * Long enough for a real name, an address and an international number, and
	 * short enough that a field somebody pasted a document into cannot bloat
	 * every session row on the site.
	 */
	public const MAX_LENGTH = 100;

	/**
	 * The fields a snapshot may hold.
	 *
	 * An allow-list rather than "whatever was posted", because a capture point
	 * is reading a form somebody else's plugin may also have written to.
	 */
	public const FIELDS = array( 'phone', 'email', 'first_name', 'last_name', 'country', 'shipping_country' );

	/**
	 * The shopper's session.
	 *
	 * @var Session
	 */
	private Session $session;

	/**
	 * Constructor.
	 *
	 * @param Session $session The session adapter.
	 */
	public function __construct( Session $session ) {
		$this->session = $session;
	}

	/**
	 * Merge fields into the stored snapshot.
	 *
	 * @param array<string,string> $fields Field name to value. Unknown names are ignored.
	 * @return bool Whether anything was actually written.
	 */
	public function remember( array $fields ): bool {
		$stored = $this->session->get( Session::KEY_CONTACT, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$changed = false;

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( (string) $key, self::FIELDS, true ) ) {
				continue;
			}

			$value = 'country' === $key || 'shipping_country' === $key
				? self::country( (string) $value )
				: self::clean( (string) $value );

			if ( '' === $value || ( isset( $stored[ $key ] ) && (string) $stored[ $key ] === $value ) ) {
				continue;
			}

			$stored[ $key ] = $value;
			$changed        = true;
		}

		if ( ! $changed ) {
			return false;
		}

		$this->session->set( Session::KEY_CONTACT, $stored );

		return true;
	}

	/**
	 * Sanitise and cap one value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean( string $value ): string {
		return substr( sanitize_text_field( $value ), 0, self::MAX_LENGTH );
	}

	/**
	 * Normalise a country code, or discard it.
	 *
	 * Two letters or nothing. A country is used to decide how to read a phone
	 * number into E.164, so a half-recognised one is worse than none: it turns
	 * a good number into a wrong number rather than into a refusal.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function country( string $value ): string {
		$value = strtoupper( substr( sanitize_text_field( $value ), 0, 2 ) );

		return 1 === preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
	}
}
