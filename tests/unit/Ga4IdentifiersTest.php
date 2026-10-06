<?php
/**
 * The two identifiers GA4 reporting depends on: the visitor's and the merchant's.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use WAcr\RecoveryFlow\Analytics\Client_Id;
use WAcr\RecoveryFlow\Analytics\Ga4_Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What counts as a GA client id cookie, and what counts as a Measurement ID.
 *
 * A malformed client id is not merely useless. Google's live endpoint answers
 * 2xx to anything, so a wrong value is never reported back: it is counted as a
 * brand-new user with one event and no history, and the audience it was meant
 * to join never hears of it. The only place to refuse it is here.
 */
final class Ga4IdentifiersTest extends TestCase {

	/**
	 * Cookie values Google's tag writes.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function real_cookies(): array {
		return array(
			'top-level domain'   => array( 'GA1.1.1234567890.1700000000' ),
			'sub-domain depth 2' => array( 'GA1.2.987654321.1699999999' ),
			'short random part'  => array( 'GA1.1.42.1700000000' ),
		);
	}

	/**
	 * The whole value is kept, because that is what Google documents accepting.
	 *
	 * @dataProvider real_cookies
	 * @param string $cookie A cookie value.
	 * @return void
	 */
	public function test_a_real_cookie_is_kept_whole( string $cookie ): void {
		$this->assertSame( $cookie, Client_Id::normalize( $cookie ) );
	}

	/**
	 * Surrounding whitespace is not part of the id.
	 *
	 * @return void
	 */
	public function test_whitespace_is_trimmed(): void {
		$this->assertSame( 'GA1.1.1234567890.1700000000', Client_Id::normalize( "  GA1.1.1234567890.1700000000\n" ) );
	}

	/**
	 * Values that are not a GA client id cookie.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function not_cookies(): array {
		return array(
			'empty'                      => array( '' ),
			'two numbers only'           => array( '1234567890.1700000000' ),
			'session cookie'             => array( 'GS1.1.1700000000.1.1.1700000100.0.0.0' ),
			'unknown version'            => array( 'GA2.1.1234567890.1700000000' ),
			'depth zero'                 => array( 'GA1.0.1234567890.1700000000' ),
			'letters in the random part' => array( 'GA1.1.12345abc90.1700000000' ),
			'trailing data'              => array( 'GA1.1.1234567890.1700000000.5' ),
			'an injected email'          => array( 'GA1.1.someone@example.com.1' ),
			'longer than the column'     => array( 'GA1.1.' . str_repeat( '9', 40 ) . '.' . str_repeat( '9', 20 ) ),
		);
	}

	/**
	 * Anything else is no cookie at all.
	 *
	 * @dataProvider not_cookies
	 * @param string $value A value that must be refused.
	 * @return void
	 */
	public function test_anything_else_is_refused( string $value ): void {
		$this->assertSame( '', Client_Id::normalize( $value ) );
	}

	/**
	 * Measurement IDs are accepted as typed, however they were cased.
	 *
	 * @return void
	 */
	public function test_a_measurement_id_is_normalised_and_accepted(): void {
		$id = Ga4_Settings::normalize_measurement_id( ' g-abc123XYZ ' );

		$this->assertSame( 'G-ABC123XYZ', $id );
		$this->assertTrue( Ga4_Settings::is_valid_measurement_id( $id ) );
	}

	/**
	 * Things merchants paste into the Measurement ID box that are not one.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function not_measurement_ids(): array {
		return array(
			'universal analytics' => array( 'UA-12345678-1' ),
			'google tag id'       => array( 'GT-ABCDEFG' ),
			'ads conversion id'   => array( 'AW-123456789' ),
			'stream id'           => array( '1234567890' ),
			'bare prefix'         => array( 'G-' ),
			'with a space inside' => array( 'G-ABC 123' ),
		);
	}

	/**
	 * Only a GA4 web stream's id is accepted.
	 *
	 * @dataProvider not_measurement_ids
	 * @param string $value A value that must be refused.
	 * @return void
	 */
	public function test_other_google_ids_are_refused( string $value ): void {
		$this->assertFalse( Ga4_Settings::is_valid_measurement_id( Ga4_Settings::normalize_measurement_id( $value ) ) );
	}
}
