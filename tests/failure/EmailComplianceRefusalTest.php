<?php
/**
 * Why a recovery email refuses to send, and what it says about it.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Failure;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WAcr\RecoveryFlow\Recovery\Email_Compliance;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The refusals, tested as refusals rather than as a happy path with a negation.
 *
 * A recovery email is commercial mail: it must carry the sender's postal address
 * and an unsubscribe that keeps working for thirty days. This gate decides
 * whether the site may send at all, and the interesting cases are all the ones
 * where it says no -- each of which a merchant has to be able to clear, which is
 * why the reasons are codes with screens behind them rather than one boolean.
 *
 * Each blocker is asserted ALONE, on settings that are otherwise complete.
 * Testing them together would pass just as well against a gate that returned the
 * same single reason for everything, and a merchant clearing that gate would be
 * sent round in a circle.
 */
final class EmailComplianceRefusalTest extends TestCase {

	/**
	 * Settings with nothing wrong with them.
	 *
	 * @param array<string,mixed> $overrides What this case breaks.
	 * @return array<string,mixed>
	 */
	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				Email_Compliance::SETTING_ADDRESS => "Northbound Supply\n1 Example Way\nLondon N1 1AA",
				Email_Compliance::SETTING_COUNTRY => 'GB',
				'recovery_link_ttl_days'          => 30,
			),
			$overrides
		);
	}

	/**
	 * Stand in for the sanitisers the address reader calls.
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();

		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->alias( static fn ( string $v ): string => trim( $v ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn ( string $v ): string => trim( $v ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $v ): string => $v );
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
	 * Complete settings send.
	 *
	 * @return void
	 */
	public function test_complete_settings_are_satisfied() {
		$this->assertSame( array(), Email_Compliance::blockers( $this->settings() ) );
		$this->assertTrue( Email_Compliance::is_satisfied( $this->settings() ) );
	}

	/**
	 * No postal address, and that reason alone.
	 *
	 * @return void
	 */
	public function test_a_missing_postal_address_refuses_on_its_own() {
		$blockers = Email_Compliance::blockers( $this->settings( array( Email_Compliance::SETTING_ADDRESS => '' ) ) );

		$this->assertSame( array( Email_Compliance::NO_POSTAL_ADDRESS ), $blockers );
	}

	/**
	 * No country, and that reason alone.
	 *
	 * The country is a separate setting because an address is laid out
	 * according to its own country and nothing in the text says which.
	 *
	 * @return void
	 */
	public function test_a_missing_country_refuses_on_its_own() {
		$blockers = Email_Compliance::blockers( $this->settings( array( Email_Compliance::SETTING_COUNTRY => '' ) ) );

		$this->assertSame( array( Email_Compliance::NO_POSTAL_COUNTRY ), $blockers );
	}

	/**
	 * A link lifetime shorter than the unsubscribe has to last.
	 *
	 * The unsubscribe link in an email IS the recovery link, so a seven-day
	 * link is an unsubscribe that dies on day eight, three weeks inside what
	 * the law asks.
	 *
	 * @return void
	 */
	public function test_a_short_link_lifetime_refuses_on_its_own() {
		$blockers = Email_Compliance::blockers( $this->settings( array( 'recovery_link_ttl_days' => 7 ) ) );

		$this->assertSame( array( Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT ), $blockers );
	}

	/**
	 * Exactly the minimum is enough, and one day under is not.
	 *
	 * The boundary rather than a comfortable number, because an off-by-one here
	 * is a refusal a merchant cannot explain or a promise the site cannot keep.
	 *
	 * @return void
	 */
	public function test_the_thirty_day_boundary_is_exact() {
		$this->assertTrue(
			Email_Compliance::is_satisfied( $this->settings( array( 'recovery_link_ttl_days' => Email_Compliance::MIN_UNSUBSCRIBE_DAYS ) ) )
		);
		$this->assertFalse(
			Email_Compliance::is_satisfied( $this->settings( array( 'recovery_link_ttl_days' => Email_Compliance::MIN_UNSUBSCRIBE_DAYS - 1 ) ) )
		);
	}

	/**
	 * Several faults are all reported, not just the first.
	 *
	 * A gate that stopped at the first would send a merchant round the loop
	 * once per fault, which is how a clearable gate starts feeling broken.
	 *
	 * @return void
	 */
	public function test_every_fault_is_reported_at_once() {
		$blockers = Email_Compliance::blockers(
			array(
				Email_Compliance::SETTING_ADDRESS => '',
				Email_Compliance::SETTING_COUNTRY => '',
				'recovery_link_ttl_days'          => 7,
			)
		);

		$this->assertCount( 3, $blockers );
	}

	/**
	 * Whitespace is not a postal address.
	 *
	 * @return void
	 */
	public function test_whitespace_is_not_an_address() {
		$this->assertContains(
			Email_Compliance::NO_POSTAL_ADDRESS,
			Email_Compliance::blockers( $this->settings( array( Email_Compliance::SETTING_ADDRESS => "  \n \t " ) ) )
		);
	}
}
