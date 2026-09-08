<?php
/**
 * The two rules that decide whether a link still works.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use WAcr\RecoveryFlow\Recovery\Attempt;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * One token, two questions, and they must not collapse into one.
 *
 * `link_is_usable()` decides whether a basket may be rebuilt.
 * `opt_out_is_usable()` decides whether somebody may unsubscribe. They differ in
 * exactly one clause, which is why they are tested against the same fixtures
 * rather than separately: a test that only asked the question that passes would
 * go on passing with the clause put back.
 *
 * The clause matters because tokens are revoked when the customer converts. Ask
 * both questions the same way and the customer most likely to want out of the
 * reminders becomes the only one who cannot get out of them -- while
 * Email_Compliance goes on refusing to send at all without a thirty-day
 * unsubscribe it can no longer honour.
 *
 * These are pure: no WordPress, no database, no clock beyond the string handed
 * in. That is the whole reason they belong in this suite rather than in the
 * smoke run.
 */
final class AttemptLinkRulesTest extends TestCase {

	/**
	 * The moment every case is judged against.
	 */
	private const NOW = '2026-01-15 12:00:00';

	/**
	 * Build an attempt with a live token, overriding what a case cares about.
	 *
	 * @param array<string,mixed> $overrides Row values to change.
	 * @return Attempt
	 */
	private function attempt( array $overrides = array() ): Attempt {
		return Attempt::from_row(
			array_merge(
				array(
					'id'               => 1,
					'journey_id'       => 1,
					'token_hash'       => str_repeat( 'a', 64 ),
					'token_expires_at' => '2026-02-15 12:00:00',
				),
				$overrides
			)
		);
	}

	/**
	 * A live link does both jobs.
	 *
	 * @return void
	 */
	public function test_a_live_link_restores_and_unsubscribes() {
		$attempt = $this->attempt();

		$this->assertTrue( $attempt->link_is_usable( self::NOW ) );
		$this->assertTrue( $attempt->opt_out_is_usable( self::NOW ) );
	}

	/**
	 * Revocation closes the basket link. This is the rule working correctly.
	 *
	 * @return void
	 */
	public function test_a_revoked_link_no_longer_restores_a_basket() {
		$attempt = $this->attempt( array( 'token_revoked_at' => '2026-01-10 09:00:00' ) );

		$this->assertFalse(
			$attempt->link_is_usable( self::NOW ),
			'A basket that has already been bought must not be rebuilt.'
		);
	}

	/**
	 * The same revocation must NOT close the unsubscribe. This is the defect.
	 *
	 * @return void
	 */
	public function test_the_same_revoked_link_still_unsubscribes() {
		$attempt = $this->attempt( array( 'token_revoked_at' => '2026-01-10 09:00:00' ) );

		$this->assertTrue(
			$attempt->opt_out_is_usable( self::NOW ),
			'A customer who bought must still be able to unsubscribe from the mail that brought them.'
		);
	}

	/**
	 * Expiry closes both, which is what keeps the thirty days measurable.
	 *
	 * @return void
	 */
	public function test_expiry_closes_both() {
		$attempt = $this->attempt( array( 'token_expires_at' => '2026-01-01 09:00:00' ) );

		$this->assertFalse( $attempt->link_is_usable( self::NOW ) );
		$this->assertFalse(
			$attempt->opt_out_is_usable( self::NOW ),
			'Without this the unsubscribe window would be unbounded, which is a different promise from the one the footer makes.'
		);
	}

	/**
	 * An attempt that never carried a token opens nothing.
	 *
	 * @return void
	 */
	public function test_an_attempt_with_no_token_opens_nothing() {
		$attempt = Attempt::from_row( array( 'id' => 1 ) );

		$this->assertFalse( $attempt->link_is_usable( self::NOW ) );
		$this->assertFalse( $attempt->opt_out_is_usable( self::NOW ) );
	}

	/**
	 * A token with no expiry set is open-ended, and both agree about that.
	 *
	 * @return void
	 */
	public function test_a_token_with_no_expiry_is_open_ended() {
		$attempt = Attempt::from_row(
			array(
				'id'         => 1,
				'journey_id' => 1,
				'token_hash' => str_repeat( 'a', 64 ),
			)
		);

		$this->assertTrue( $attempt->link_is_usable( self::NOW ) );
		$this->assertTrue( $attempt->opt_out_is_usable( self::NOW ) );
	}
}
