<?php
/**
 * Which states a recovery may move between, and which are closed for good.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use WAcr\RecoveryFlow\Recovery\Journey_State;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The state machine, asserted as rules rather than as a copy of the table.
 *
 * A test that restated `transitions()` value for value would pass for any table
 * at all, including a wrong one -- it would only prove the array had not been
 * edited. What is asserted here is the handful of properties the table exists to
 * guarantee, each of which is a decision somebody made for a reason:
 *
 * - a terminal state that carries a decision ABOUT THE CUSTOMER stays closed;
 * - `FAILED` is the single exception, and it may only reopen into `SCHEDULED`;
 * - nothing may step into `MESSAGE_SENT` without passing the send gate.
 */
final class JourneyStateTest extends TestCase {

	/**
	 * The five terminal states that record a decision about a person.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function customer_decisions(): array {
		return array(
			'recovered' => array( Journey_State::RECOVERED ),
			'expired'   => array( Journey_State::EXPIRED ),
			'cancelled' => array( Journey_State::CANCELLED ),
			'opted out' => array( Journey_State::OPTED_OUT ),
			'invalid'   => array( Journey_State::INVALID ),
		);
	}

	/**
	 * None of them may lead anywhere at all.
	 *
	 * @dataProvider customer_decisions
	 * @param string $state The terminal state.
	 * @return void
	 */
	public function test_a_decision_about_the_customer_is_final( string $state ) {
		$this->assertTrue( Journey_State::is_terminal( $state ) );
		$this->assertSame(
			array(),
			Journey_State::transitions()[ $state ],
			"{$state} records something decided about a person and must never reopen."
		);
	}

	/**
	 * FAILED is terminal too, and is the one that may be retried.
	 *
	 * @return void
	 */
	public function test_failed_is_terminal_but_retryable() {
		$this->assertTrue( Journey_State::is_terminal( Journey_State::FAILED ) );
		$this->assertSame(
			array( Journey_State::SCHEDULED ),
			Journey_State::transitions()[ Journey_State::FAILED ],
			'FAILED means the machinery could not, so it reopens -- into the queue, and nowhere else.'
		);
	}

	/**
	 * Retry must never step over the send gate.
	 *
	 * @return void
	 */
	public function test_a_retry_cannot_step_straight_to_sent() {
		$this->assertNotContains(
			Journey_State::MESSAGE_SENT,
			Journey_State::transitions()[ Journey_State::FAILED ],
			'Going straight to MESSAGE_SENT would record a send that never passed consent, quiet hours or the frequency cap.'
		);
	}

	/**
	 * Every state in the table can be reached from the list of all states.
	 *
	 * A state with no row is a state a transition cannot be checked against,
	 * which fails open rather than closed.
	 *
	 * @return void
	 */
	public function test_every_state_has_a_row_in_the_table() {
		$transitions = Journey_State::transitions();

		foreach ( Journey_State::all() as $state ) {
			$this->assertArrayHasKey( $state, $transitions, "{$state} has no row, so nothing can validate a move out of it." );
		}
	}

	/**
	 * And no row names a state that does not exist.
	 *
	 * @return void
	 */
	public function test_no_transition_points_at_an_unknown_state() {
		$all = Journey_State::all();

		foreach ( Journey_State::transitions() as $from => $targets ) {
			foreach ( $targets as $to ) {
				$this->assertContains( $to, $all, "{$from} may move to {$to}, which is not a state." );
			}
		}
	}

	/**
	 * Terminal and active are opposites, with nothing in between.
	 *
	 * @return void
	 */
	public function test_terminal_and_active_partition_every_state() {
		foreach ( Journey_State::all() as $state ) {
			$this->assertNotSame(
				Journey_State::is_terminal( $state ),
				Journey_State::is_active( $state ),
				"{$state} is either finished or it is not; being both or neither leaves it out of every query."
			);
		}
	}
}
