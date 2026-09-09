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
 *
 * So is an ENTRY that answered neither, which is a different question and the
 * one that catches more people: Gravity Forms discards a phone value whose
 * E.164 form it cannot validate, so a form asking for a number routinely
 * yields entries with none. See is_messageable() and has_contact().
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
	 * Gravity Forms' own type for a consent checkbox.
	 */
	public const TYPE_CONSENT = 'consent';

	/**
	 * What is written into the consent ledger's source column, so an audit can
	 * tell a form's own consent question from a checkout's.
	 */
	public const CONSENT_SOURCE = 'gravityforms_field';

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
	 * The sub-field of a consent field holding the ticked box itself.
	 */
	private const CONSENT_TICKED = '.1';

	/**
	 * The sub-field of a consent field holding the form revision its wording
	 * came from, which is what makes an old consent auditable after the
	 * merchant edits the question.
	 */
	private const CONSENT_REVISION = '.3';

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

		$telephone = '' === $phone ? array( '', '' ) : self::telephone( $this->value( $entry, $phone ) );

		$hints->email      = '' === $email ? '' : $this->value( $entry, $email );
		$hints->phone_raw  = $telephone[0];
		$hints->first_name = '' === $name ? '' : $this->value( $entry, $name . self::NAME_FIRST );
		$hints->last_name  = '' === $name ? '' : $this->value( $entry, $name . self::NAME_LAST );
		$hints->country    = '' === $where ? '' : $this->country( $this->value( $entry, $where . self::ADDRESS_COUNTRY ) );

		/*
		 * An international phone carries its own country, and it is better
		 * evidence than the address field: it is the country of the number
		 * itself rather than of where the person happens to live. Only used
		 * when the address did not answer, so a form asking both keeps the
		 * behaviour it had.
		 */
		if ( '' === $hints->country && '' !== $telephone[1] ) {
			$hints->country = $telephone[1];
		}

		$this->consent( $hints, $form, $entry, $override );

		// A logged-in submitter is the strongest identity there is, and it is
		// on the entry whether or not the form asked for anything.
		$user = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;

		if ( $user > 0 ) {
			$hints->wp_user_id = $user;
		}

		return $hints;
	}

	/**
	 * Read the merchant's own consent question, if the form carries one.
	 *
	 * Gravity Forms has no checkout and RecoveryFlow adds no field of its own
	 * to a form, so the merchant's own consent question is the only place a
	 * yes can come from. Gravity Forms has had a consent field type since 2.4
	 * and it stores exactly what a consent record needs: whether the box was
	 * ticked, and the form revision its wording came from.
	 *
	 * A form with no consent field leaves this null rather than false, and the
	 * difference is the whole point: null is "was never asked", false is "was
	 * asked and said no". Recording the second when the first is true would
	 * write down a refusal that nobody ever made.
	 *
	 * @param Identity_Hints      $hints    The hints being built.
	 * @param array<string,mixed> $form     The form object.
	 * @param array<string,mixed> $entry    The entry, complete or partial.
	 * @param array<string,mixed> $override Pinned field ids, by type.
	 * @return void
	 */
	private function consent( Identity_Hints $hints, array $form, array $entry, array $override ): void {
		$field = $this->field_id( $form, self::TYPE_CONSENT, $override );

		if ( '' === $field ) {
			return;
		}

		$hints->consent              = '1' === $this->value( $entry, $field . self::CONSENT_TICKED );
		$hints->consent_source       = self::CONSENT_SOURCE;
		$hints->consent_text_version = $this->value( $entry, $field . self::CONSENT_REVISION );
	}

	/**
	 * Whether this ENTRY yielded somebody who could actually be messaged.
	 *
	 * Asking is_messageable() is asking about the FORM -- whether it carries a
	 * phone or an email field at all -- and that is a different question with a
	 * different answer. Gravity Forms drops a phone value whose E.164 form it cannot
	 * validate, which is what happens every time somebody types a national
	 * number into an International (formatted) field: the field is on the form,
	 * the person filled it in, and the entry comes back with nothing in it.
	 *
	 * Asking only the form is how a customer gets written down with no way to
	 * reach them, and a journey gets created that no message can ever complete.
	 * A refusal that is correct is still a silent defect when the caller cannot
	 * tell "refused" from "absent", so the entry is asked too.
	 *
	 * @param Identity_Hints $hints What was read off the entry.
	 * @return bool
	 */
	public function has_contact( Identity_Hints $hints ): bool {
		return '' !== trim( $hints->email ) || '' !== trim( $hints->phone_raw );
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
	 * The number out of a phone field, whichever shape this Gravity Forms uses.
	 *
	 * Up to Gravity Forms 2.9 a phone field held a plain string and there was
	 * nothing to decide. Gravity Forms 3.0 added an international phone, and a
	 * field set to that format does not store a number at all: it stores a JSON
	 * document with `country`, `national`, `formatted` and `e164` in it.
	 *
	 * Handed that document, the phone normaliser did the right thing and
	 * refused it -- so nobody was ever sent a stranger's reminder, which is the
	 * failure worth having. What happened instead was quieter and still wrong:
	 * every entry from an international phone field looked like an entry from
	 * somebody who had left the number blank. The form is still messageable,
	 * because the field exists, so journeys were created for people no message
	 * could ever reach.
	 *
	 * The shape is what is recognised, not the version. Gravity Forms decides
	 * per field which format to use, a form can be edited to change it, and a
	 * site can hold entries recorded under both -- so a version test would be
	 * wrong for the entries either side of the change.
	 *
	 * `e164` is preferred because Gravity Forms has already validated it
	 * against E.164 on the way in. The other keys are tried in the order
	 * Gravity Forms itself falls back through, so a document written by an
	 * older 3.x, or repaired by hand, still yields the best number present.
	 *
	 * @param string $raw The stored field value.
	 * @return array{0:string,1:string} The number, and its country if it named one.
	 */
	private static function telephone( string $raw ): array {
		if ( '' === $raw || '{' !== substr( $raw, 0, 1 ) ) {
			return array( $raw, '' );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array( $raw, '' );
		}

		$country = isset( $decoded['country'] ) && is_string( $decoded['country'] )
			? strtoupper( substr( trim( $decoded['country'] ), 0, 2 ) )
			: '';

		foreach ( array( 'e164', 'formatted', 'national' ) as $key ) {
			if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) && '' !== trim( $decoded[ $key ] ) ) {
				return array( trim( $decoded[ $key ] ), $country );
			}
		}

		/*
		 * A JSON document with none of the four keys in it is not a phone
		 * number, and handing the raw text on would put a brace and a quote
		 * into the normaliser. Nothing is a better answer than that.
		 */
		return array( '', $country );
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
