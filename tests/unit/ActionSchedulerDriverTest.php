<?php
/**
 * One recurring chain per stage, however many requests raced to start one.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WAcr\RecoveryFlow\Jobs\Action_Scheduler_Driver;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

require_once dirname( __DIR__ ) . '/fixtures/action-scheduler.php';

/**
 * The second chain is the one that starts in the gap Action Scheduler
 * leaves between completing a recurring action and storing its successor.
 * It cannot be prevented from this side, so these tests pin the healing:
 * the oldest pending row survives, every other one is cancelled, and a
 * stage that is merely mid-run is not mistaken for an unscheduled one.
 */
final class ActionSchedulerDriverTest extends TestCase {

	/**
	 * Pending action ids per hook, as the fake store holds them.
	 *
	 * @var array<string,array<int,int>>
	 */
	private array $pending = array();

	/**
	 * Hooks with a run in progress.
	 *
	 * @var array<int,string>
	 */
	private array $running = array();

	/**
	 * Hooks a new recurring action was scheduled on.
	 *
	 * @var array<int,string>
	 */
	private array $scheduled = array();

	/**
	 * Wire Action Scheduler's functions to the fake store.
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();
		Monkey\setUp();

		\ActionScheduler::$cancelled = array();

		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( $timestamp, $interval, $hook ) {
				$this->scheduled[] = $hook;
				return 1;
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ) {
				return array_values( array_diff( $this->pending[ $args['hook'] ] ?? array(), \ActionScheduler::$cancelled ) );
			}
		);
		Functions\when( 'as_has_scheduled_action' )->alias(
			function ( $hook ) {
				return in_array( $hook, $this->running, true )
					|| array() !== array_diff( $this->pending[ $hook ] ?? array(), \ActionScheduler::$cancelled );
			}
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
	 * Give every stage exactly one pending action.
	 *
	 * @return void
	 */
	private function one_chain_each(): void {
		$id = 100;
		foreach ( Action_Scheduler_Driver::STAGES as $stage ) {
			$this->pending[ Action_Scheduler_Driver::hook( $stage ) ] = array( ++$id );
		}
	}

	/**
	 * A site where every stage is already scheduled is left alone.
	 *
	 * @return void
	 */
	public function test_one_chain_per_stage_is_left_alone() {
		$this->one_chain_each();

		( new Action_Scheduler_Driver() )->schedule_all();

		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( array(), \ActionScheduler::$cancelled );
	}

	/**
	 * A fresh site gets one chain per stage.
	 *
	 * @return void
	 */
	public function test_a_fresh_site_gets_one_chain_per_stage() {
		( new Action_Scheduler_Driver() )->schedule_all();

		$this->assertCount( count( Action_Scheduler_Driver::STAGES ), $this->scheduled );
		$this->assertSame( count( Action_Scheduler_Driver::STAGES ), count( array_unique( $this->scheduled ) ) );
	}

	/**
	 * A second chain is cancelled, the oldest row is kept, and nothing new
	 * is scheduled in its place.
	 *
	 * The ids arrive out of order on purpose: the store is asked for no
	 * ordering, so "oldest" has to be worked out here.
	 *
	 * @return void
	 */
	public function test_a_second_chain_is_cancelled_and_the_oldest_kept() {
		$this->one_chain_each();
		$dispatch                   = Action_Scheduler_Driver::hook( Action_Scheduler_Driver::DISPATCH );
		$this->pending[ $dispatch ] = array( 3894685, 3894580, 3894700 );

		( new Action_Scheduler_Driver() )->schedule_all();

		$this->assertSame( array( 3894685, 3894700 ), \ActionScheduler::$cancelled );
		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * A stage between runs -- its action running, its successor not yet
	 * stored -- is not given a second chain.
	 *
	 * @return void
	 */
	public function test_a_stage_mid_run_is_not_rescheduled() {
		$this->one_chain_each();
		$evaluate = Action_Scheduler_Driver::hook( Action_Scheduler_Driver::EVALUATE );
		unset( $this->pending[ $evaluate ] );
		$this->running[] = $evaluate;

		( new Action_Scheduler_Driver() )->schedule_all();

		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * Two requests healing the same duplicate keep the same row. The
	 * second finds the extra already cancelled and the store's refusal to
	 * cancel it twice is not an error.
	 *
	 * @return void
	 */
	public function test_two_requests_healing_at_once_keep_the_same_row() {
		$this->one_chain_each();
		$poll                   = Action_Scheduler_Driver::hook( Action_Scheduler_Driver::POLL );
		$this->pending[ $poll ] = array( 3894627, 3894626 );

		// Both requests read the store before either cancels.
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ) {
				return $this->pending[ $args['hook'] ] ?? array();
			}
		);

		( new Action_Scheduler_Driver() )->schedule_all();
		( new Action_Scheduler_Driver() )->schedule_all();

		$this->assertSame( array( 3894627 ), array_values( array_unique( \ActionScheduler::$cancelled ) ) );
		$this->assertSame( array(), $this->scheduled );
	}
}
