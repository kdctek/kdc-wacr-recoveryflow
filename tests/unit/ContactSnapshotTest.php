<?php
/**
 * Cleaning and capping what a shopper typed.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WAcr\RecoveryFlow\Integration\WooCommerce\Contact_Snapshot;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The two decisions that are pure, tested where they are cheap to test.
 *
 * The merge itself needs a session and is exercised against one in the smoke
 * run. These two are not: they decide what a value BECOMES before anything sees
 * it, and getting either wrong is quiet. A country that is half-recognised turns
 * a good phone number into a wrong one rather than into a refusal, and an
 * uncapped field lets one pasted document sit in every session row on the site.
 */
final class ContactSnapshotTest extends TestCase {

	/**
	 * Stand in for the one WordPress function these two call.
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();

		Monkey\setUp();

		// Close enough for what is being tested here: the real one strips tags
		// and control characters, and what these cases turn on is the trim and
		// the cap rather than the stripping.
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( string $value ): string => trim( (string) preg_replace( '/<[^>]*>/', '', $value ) )
		);
	}

	/**
	 * Put Brain Monkey away.
	 *
	 * @return void
	 */
	protected function tear_down() {
		Monkey\tearDown();

		parent::tear_down();
	}

	/**
	 * Anything longer than the cap is trimmed to it.
	 *
	 * @return void
	 */
	public function test_a_long_value_is_capped() {
		$this->assertSame(
			Contact_Snapshot::MAX_LENGTH,
			strlen( Contact_Snapshot::clean( str_repeat( 'a', 5000 ) ) )
		);
	}

	/**
	 * An ordinary value survives untouched.
	 *
	 * @return void
	 */
	public function test_an_ordinary_value_is_left_alone() {
		$this->assertSame( '07700 900123', Contact_Snapshot::clean( '  07700 900123  ' ) );
	}

	/**
	 * A two-letter code is kept, in upper case.
	 *
	 * @return void
	 */
	public function test_a_country_code_is_normalised() {
		$this->assertSame( 'GB', Contact_Snapshot::country( 'gb' ) );
		$this->assertSame( 'GB', Contact_Snapshot::country( 'GB' ) );
	}

	/**
	 * Anything that is not two letters is discarded rather than guessed at.
	 *
	 * @return void
	 */
	public function test_anything_that_is_not_two_letters_is_discarded() {
		$this->assertSame( '', Contact_Snapshot::country( 'U' ) );
		$this->assertSame( '', Contact_Snapshot::country( '44' ) );
		$this->assertSame( '', Contact_Snapshot::country( '' ) );
	}

	/**
	 * The allow-list is the full set the snapshot stores, and no more.
	 *
	 * Asserted because it is what stops a capture point storing whatever a form
	 * it does not own happened to post.
	 *
	 * @return void
	 */
	public function test_the_allow_list_is_closed() {
		$this->assertSame(
			array( 'phone', 'email', 'first_name', 'last_name', 'country', 'shipping_country' ),
			Contact_Snapshot::FIELDS
		);
	}
}
