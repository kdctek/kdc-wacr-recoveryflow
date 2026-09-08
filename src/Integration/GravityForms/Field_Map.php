<?php
/**
 * Finding the person inside a Gravity Forms entry.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\GravityForms;

use WAcr\RecoveryFlow\Customer\Identity_Hints;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a form and a half-filled entry into contact details, or into nothing.
 *
 * WooCommerce has one billing phone field with one name, everywhere, for ever.
 * Gravity Forms has whatever the merchant dragged onto the canvas: a form may
 * have three email fields, or a phone field the merchant labelled "Mobile",
 * or none at all. There is no fixed key to read, so the form's own definition
 * is what is read -- the field TYPES, which Gravity Forms owns and the merchant
 * cannot rename.
 *
 * The first field of each type wins, and the merchant can override that per
 * form on the Integrations tab. First rather than last is a deliberate,
 * arbitrary rule that has to be written down somewhere, because a form asking
 * for a work number and then a mobile would otherwise be read differently
 * depending on the order somebody happened to drag the fields in.
 *
 * A form with neither a phone nor an email field is not an integration
 * failure. It is a form nobody could ever be messaged about, and reporting one
 * would create a row that the eligibility rules would spend the rest of its
 * life refusing. Those are dropped here, before anything is written.
 */
final class Field_Map {

	/**
	 * Gravity Forms' own type for an email address field.
	 */
	public const TYPE_EMAIL = 'email';

	/**
	 * Gravity Forms' own type for a telephone field.
	 */
	public const TYPE_PHONE = 'phone';

	/**
	 * Gravity Forms' own type for a name field.
	 */
	public const TYPE_NAME = 'name';

	/**
	 * Gravity Forms' own type for an address field, which carries the country.
	 */
	public const TYPE_ADDRESS = 'address';

	/**
	 * The sub-field of a name field holding the first name.
	 */
	private const NAME_FIRST = '.3';

	/**
	 * The sub-field of a name field holding the last name.
	 */
	private const NAME_LAST = '.6';

	/**
	 * The sub-field of an address field holding the country.
	 */
	private const ADDRESS_COUNTRY = '.6';

	/**
	 * Read whatever contact details a form's own fields carry.
	 *
	 * @param array<string,mixed> $form     The Gravity Forms form object.
	 * @param array<string,mixed> $entry    The entry, complete or partial.
	 * @param array<string,mixed> $override Field ids the merchant pinned, by kind.
	 * @return Identity_Hints
	 */
	public function hints( array $form, array $entry, array $override = array() ): Identity_Hints {
		$hints = new Identity_Hints();

		$email = $this->field_id( $form, self::TYPE_EMAIL, $override );
		$phone = $this->field_id( $form, self::TYPE_PHONE, $override );
		$name  = $this->field_id( $form, self::TYPE_NAME, $override );
		$where = $this->field_id( $form, self::TYPE_ADDRESS, $override );

		$hints->email      = '' === $email ? '' : $this->value( $entry, $email );
		$hints->phone_raw  = '' === $phone ? '' : $this->value( $entry, $phone );
		$hints->first_name = '' === $name ? '' : $this->value( $entry, $name . self::NAME_FIRST );
		$hints->last_name  = '' === $name ? '' : $this->value( $entry, $name . self::NAME_LAST );
		$hints->country    = '' === $where ? '' : $this->country( $this->value( $entry, $where . self::ADDRESS_COUNTRY ) );

		// A logged-in submitter is the strongest identity there is, and it is
		// on the entry whether or not the form asked for anything.
		$user = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;

		if ( $user > 0 ) {
			$hints->wp_user_id = $user;
		}

		return $hints;
	}

	/**
	 * Whether this form could ever produce somebody to message.
	 *
	 * @param array<string,mixed> $form     The form object.
	 * @param array<string,mixed> $override Pinned field ids.
	 * @return bool
	 */
	public function is_messageable( array $form, array $override = array() ): bool {
		return '' !== $this->field_id( $form, self::TYPE_EMAIL, $override )
			|| '' !== $this->field_id( $form, self::TYPE_PHONE, $override );
	}

	/**
	 * The id of the first field of a type, or the one the merchant pinned.
	 *
	 * @param array<string,mixed> $form     The form object.
	 * @param string              $type     A Gravity Forms field type.
	 * @param array<string,mixed> $override Pinned field ids, by type.
	 * @return string Empty when the form has no such field.
	 */
	public function field_id( array $form, string $type, array $override = array() ): string {
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
		$pinned = isset( $override[ $type ] ) ? (string) $override[ $type ] : '';

		foreach ( $fields as $field ) {
			$id = (string) $this->attribute( $field, 'id' );

			if ( '' === $id ) {
				continue;
			}

			// A pinned field is honoured only if it is still on the form. A
			// merchant who deletes the field they pinned should fall back to
			// the form as it is now, not to nothing at all.
			if ( '' !== $pinned && $pinned === $id ) {
				return $id;
			}

			if ( '' === $pinned && $type === (string) $this->attribute( $field, 'type' ) ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * One value out of an entry.
	 *
	 * Gravity Forms keys entry values by field id as a string, and sub-fields
	 * with a decimal suffix. A partial entry simply has fewer of them.
	 *
	 * @param array<string,mixed> $entry The entry.
	 * @param string              $key   Field id, possibly with a sub-field.
	 * @return string
	 */
	private function value( array $entry, string $key ): string {
		$raw = $entry[ $key ] ?? '';

		if ( is_array( $raw ) || is_object( $raw ) ) {
			return '';
		}

		return trim( (string) $raw );
	}

	/**
	 * A country name from an address field, as a two-letter code.
	 *
	 * Gravity Forms stores the country as its English name, not its code, and
	 * the phone normaliser needs a code. An unrecognised name is dropped rather
	 * than guessed: a wrong country turns a perfectly good local number into a
	 * number in somebody else's country, and the message goes to a stranger.
	 *
	 * @param string $name Country name as Gravity Forms stores it.
	 * @return string Two-letter code, or empty.
	 */
	private function country( string $name ): string {
		if ( '' === $name ) {
			return '';
		}

		if ( 2 === strlen( $name ) && ctype_alpha( $name ) ) {
			return strtoupper( $name );
		}

		// Both spellings are tried because Gravity Forms has moved this helper
		// between classes across major versions, and neither is documented as
		// the public one. An unrecognised name simply yields nothing, which the
		// normaliser already knows how to cope with.
		foreach ( array( array( '\GF_Field_Address', 'get_country_code' ), array( '\GFCommon', 'get_country_code' ) ) as $callable ) {
			if ( ! is_callable( $callable ) ) {
				continue;
			}

			$code = call_user_func( $callable, $name );

			if ( is_string( $code ) && 2 === strlen( $code ) ) {
				return strtoupper( $code );
			}
		}

		return '';
	}

	/**
	 * One property off a field, which may be an object or an array.
	 *
	 * Gravity Forms hands fields over as GF_Field objects in most contexts and
	 * as plain arrays in a few -- a form read straight out of the database, a
	 * form passed through an import. Reading them one way works until the day
	 * it does not.
	 *
	 * @param mixed  $field The field.
	 * @param string $key   Property name.
	 * @return mixed
	 */
	private function attribute( $field, string $key ) {
		if ( is_array( $field ) ) {
			return $field[ $key ] ?? '';
		}

		if ( is_object( $field ) ) {
			return isset( $field->{$key} ) ? $field->{$key} : '';
		}

		return '';
	}
}
