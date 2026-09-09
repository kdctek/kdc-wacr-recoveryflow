<?php
/**
 * Reading a telephone number out of a Gravity Forms entry.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use WAcr\RecoveryFlow\Integration\GravityForms\Field_Map;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * One field, two storage shapes, and a version boundary running through them.
 *
 * Up to Gravity Forms 2.9 a phone field held a plain string. Gravity Forms 3.0
 * added an international phone, and a field set to that format stores a JSON
 * document -- `country`, `national`, `formatted`, `e164` -- instead of a number.
 *
 * Both shapes are asserted together, because the interesting failure is not
 * that either one breaks. It is that reading the new shape with the old rule
 * fails SILENTLY: the phone normaliser is handed a JSON document, correctly
 * refuses to guess a number out of it, and the entry ends up looking exactly
 * like one from somebody who left the number blank. The form still counts as
 * messageable, since the field is there, so journeys are created for people no
 * message can reach.
 *
 * The shape is what is recognised, never the Gravity Forms version: the format
 * is chosen per field, a form can be edited to change it, and one site can hold
 * entries recorded under both.
 */
final class GravityFormsPhoneTest extends TestCase {

	/**
	 * A form with a single phone field on it, id 4.
	 *
	 * @return array<string,mixed>
	 */
	private function form(): array {
		return array(
			'id'     => 1,
			'fields' => array(
				(object) array(
					'id'   => 4,
					'type' => 'phone',
				),
			),
		);
	}

	/**
	 * That form's entry, carrying whatever the field stored.
	 *
	 * @param mixed $stored The stored value.
	 * @return array<string,mixed>
	 */
	private function entry( $stored ): array {
		return array(
			'id'      => 9,
			'form_id' => 1,
			'4'       => $stored,
		);
	}

	/**
	 * The shape every Gravity Forms up to 2.9 wrote, and 3.x still writes for a
	 * field left on the standard format.
	 *
	 * @return void
	 */
	public function test_a_plain_number_is_read_unchanged() {
		$hints = ( new Field_Map() )->hints( $this->form(), $this->entry( '07700 900123' ) );

		$this->assertSame( '07700 900123', $hints->phone_raw );
		$this->assertSame( '', $hints->country, 'A plain number names no country, and one must not be invented.' );
	}

	/**
	 * The shape Gravity Forms 3.0 writes for an international phone.
	 *
	 * @return void
	 */
	public function test_an_international_phone_yields_the_number_and_not_the_document() {
		$stored = '{"country":"gb","national":"07700 900123","formatted":"+44 7700 900123","e164":"+447700900123"}';

		$hints = ( new Field_Map() )->hints( $this->form(), $this->entry( $stored ) );

		$this->assertSame(
			'+447700900123',
			$hints->phone_raw,
			'Handing the whole document on makes every international entry look like a blank number.'
		);
		$this->assertSame( 'GB', $hints->country );
	}

	/**
	 * e164 is preferred, because Gravity Forms validated it on the way in.
	 *
	 * @return void
	 */
	public function test_the_validated_number_is_preferred_over_the_pretty_one() {
		$stored = '{"country":"gb","national":"07700 900123","formatted":"+44 7700 900123","e164":"+447700900123"}';

		$this->assertSame( '+447700900123', ( new Field_Map() )->hints( $this->form(), $this->entry( $stored ) )->phone_raw );
	}

	/**
	 * With no e164, the fallback runs in the order Gravity Forms uses itself.
	 *
	 * @return void
	 */
	public function test_it_falls_back_the_way_gravity_forms_does() {
		$formatted = '{"country":"gb","national":"07700 900123","formatted":"+44 7700 900123"}';
		$national  = '{"country":"gb","national":"07700 900123"}';

		$this->assertSame( '+44 7700 900123', ( new Field_Map() )->hints( $this->form(), $this->entry( $formatted ) )->phone_raw );
		$this->assertSame( '07700 900123', ( new Field_Map() )->hints( $this->form(), $this->entry( $national ) )->phone_raw );
	}

	/**
	 * An empty document is a blank number, not a brace.
	 *
	 * Passing the raw text on would put punctuation into the normaliser, which
	 * is how a number gets guessed out of something that is not one.
	 *
	 * @return void
	 */
	public function test_a_document_with_no_number_in_it_yields_nothing() {
		$hints = ( new Field_Map() )->hints( $this->form(), $this->entry( '{"country":"gb"}' ) );

		$this->assertSame( '', $hints->phone_raw );
		$this->assertSame( 'GB', $hints->country, 'The country is still known even when the number is not.' );
	}

	/**
	 * Text that merely begins with a brace is not silently discarded.
	 *
	 * @return void
	 */
	public function test_something_that_is_not_json_is_kept_rather_than_dropped() {
		$hints = ( new Field_Map() )->hints( $this->form(), $this->entry( '{not json at all' ) );

		$this->assertSame( '{not json at all', $hints->phone_raw );
	}

	/**
	 * The address field still wins, when the form has one.
	 *
	 * The phone's country is better evidence than the address -- it is the
	 * country of the number rather than of where the person lives -- but it is
	 * only consulted when the address did not answer, so a form that asks both
	 * behaves exactly as it did before this existed.
	 *
	 * @return void
	 */
	public function test_an_address_country_is_not_overridden_by_the_phone() {
		$form = array(
			'id'     => 1,
			'fields' => array(
				(object) array(
					'id'   => 4,
					'type' => 'phone',
				),
				(object) array(
					'id'   => 7,
					'type' => 'address',
				),
			),
		);

		$entry = array(
			'id'      => 9,
			'form_id' => 1,
			'4'       => '{"country":"gb","e164":"+447700900123"}',
			'7.6'     => 'IE',
		);

		$hints = ( new Field_Map() )->hints( $form, $entry );

		$this->assertSame( 'IE', $hints->country );
		$this->assertSame( '+447700900123', $hints->phone_raw );
	}

	/**
	 * A value Gravity Forms never writes, and the reader must not fall over on.
	 *
	 * @return void
	 */
	public function test_an_array_is_still_refused() {
		$hints = ( new Field_Map() )->hints( $this->form(), $this->entry( array( 'e164' => '+447700900123' ) ) );

		$this->assertSame( '', $hints->phone_raw );
	}
}
