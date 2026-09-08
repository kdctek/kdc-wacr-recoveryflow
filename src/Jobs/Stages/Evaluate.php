<?php
/**
 * Deciding which abandoned events become recovery journeys.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs\Stages;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Jobs\Scheduler_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Runner;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Jobs\Time_Budget;
use WAcr\RecoveryFlow\Recovery\Eligibility;
use WAcr\RecoveryFlow\Recovery\Eligibility_Evaluator;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Workflow\Workflow_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns quiet baskets into journeys, and says why when it does not.
 *
 * The stage begins by throwing away work rather than doing it. A busy store
 * produces far more baskets that will never be recovered than ones that will,
 * and closing those in one indexed statement is the difference between a stage
 * that keeps up and one that spends its whole budget loading rows it is about
 * to discard.
 *
 * A refusal is written onto the event, not into a journey. It is tempting to
 * record every "no" as a terminal journey so the merchant can see it, and it is
 * wrong: the monthly limit counts journeys, so three baskets left by somebody
 * who has not agreed to be messaged would silently block the first basket they
 * leave after they do agree. The event carries the reason instead.
 *
 * Losing the race to create a journey is a success. Two runs can both decide
 * the same basket deserves a message; the UNIQUE index on event_id lets exactly
 * one of them win, and the loser has nothing to fix.
 */
final class Evaluate implements Stage_Interface {

	/**
	 * How many stale events are closed per statement.
	 */
	private const EXPIRE_BATCH = 500;

	/**
	 * How many events are considered per query.
	 */
	private const CONSIDER_BATCH = 200;

	/**
	 * Event storage.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * The eligibility rules.
	 *
	 * @var Eligibility_Evaluator
	 */
	private Eligibility_Evaluator $evaluator;

	/**
	 * The registered sources.
	 *
	 * @var Source_Registry
	 */
	private Source_Registry $sources;

	/**
	 * Workflow storage, when the workflow layer is available.
	 *
	 * @var Workflow_Repository|null
	 */
	private ?Workflow_Repository $workflows;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository         $events    Event storage.
	 * @param Journey_Repository       $journeys  Journey storage.
	 * @param Customer_Repository      $customers Customer storage.
	 * @param Eligibility_Evaluator    $evaluator Eligibility rules.
	 * @param Source_Registry          $sources   Registered sources.
	 * @param Workflow_Repository|null $workflows Workflow storage.
	 * @param Clock                    $clock     Clock.
	 * @param Logger                   $logger    Logger.
	 */
	public function __construct(
		Event_Repository $events,
		Journey_Repository $journeys,
		Customer_Repository $customers,
		Eligibility_Evaluator $evaluator,
		Source_Registry $sources,
		?Workflow_Repository $workflows,
		Clock $clock,
		Logger $logger
	) {
		$this->events    = $events;
		$this->journeys  = $journeys;
		$this->customers = $customers;
		$this->evaluator = $evaluator;
		$this->sources   = $sources;
		$this->workflows = $workflows;
		$this->clock     = $clock;
		$this->logger    = $logger;
	}

	/**
	 * The stage's key.
	 *
	 * @return string
	 */
	public function key(): string {
		return Scheduler_Interface::EVALUATE;
	}

	/**
	 * The row in the locks table this stage runs under.
	 *
	 * @return string
	 */
	public function lock_key(): string {
		return Scheduler_Interface::EVALUATE;
	}

	/**
	 * Close what is past saving, then enrol what is not.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @return Stage_Stats
	 */
	public function run( Time_Budget $budget ): Stage_Stats {
		$stats = new Stage_Stats( $this->key() );

		// The site's own settings always outrank a source's defaults, so the
		// cut-offs that drive the two queries are the same for every source.
		$rules = Rule_Set::for_source();

		$this->close_stale( $budget, $stats, $rules );
		$this->enrol_due( $budget, $stats, $rules );

		return $stats;
	}

	/**
	 * Close open events that will never become journeys.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @param Rule_Set    $rules  The thresholds in force.
	 * @return void
	 */
	private function close_stale( Time_Budget $budget, Stage_Stats $stats, Rule_Set $rules ): void {
		$older_than = $this->clock->offset( -$rules->max_age_seconds() );
		$limit      = Stage_Runner::batch_size( $this->key(), self::EXPIRE_BATCH );

		while ( $budget->has_time( 2.0 ) ) {
			$closed = $this->events->expire_unidentified( $older_than, $limit );

			$stats->skipped += $closed;

			if ( $closed < $limit ) {
				return;
			}
		}

		// A full batch and no time left means there are more behind it.
		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * Consider every event that has gone quiet long enough.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @param Rule_Set    $rules  The thresholds in force.
	 * @return void
	 */
	private function enrol_due( Time_Budget $budget, Stage_Stats $stats, Rule_Set $rules ): void {
		$limit    = Stage_Runner::batch_size( $this->key(), self::CONSIDER_BATCH );
		$inactive = $this->clock->offset( -$rules->inactivity_seconds() );
		$seen     = array();
		$cache    = array();

		while ( $budget->has_time( 1.0 ) ) {
			$due = $this->events->due_for_evaluation( $inactive, $limit );

			if ( array() === $due ) {
				return;
			}

			$fresh = 0;

			foreach ( $due as $event ) {
				// An event that was refused for a reason that may change is
				// left open, so the same query keeps returning it. Without
				// this the stage would spend its whole budget re-reading the
				// rows it has already decided about.
				if ( isset( $seen[ $event->id ] ) ) {
					continue;
				}

				$seen[ $event->id ] = true;
				++$fresh;

				if ( ! $budget->has_time( 1.0 ) ) {
					$stats->backlog = max( $stats->backlog, 1 );

					return;
				}

				$this->consider( $event, $stats, $cache );
			}

			if ( 0 === $fresh ) {
				return;
			}
		}//end while

		$stats->backlog = max( $stats->backlog, 1 );
	}

	/**
	 * Decide what to do about one event.
	 *
	 * @param Recovery_Event         $event The abandoned thing.
	 * @param Stage_Stats            $stats Counters for this run.
	 * @param array<string,Rule_Set> $cache Rules already resolved this run, by source.
	 * @return void
	 */
	private function consider( Recovery_Event $event, Stage_Stats $stats, array &$cache ): void {
		$rules    = $this->rules_for( $event->source_id, $cache );
		$customer = null;

		if ( null !== $event->customer_id && $event->customer_id > 0 ) {
			$customer = $this->customers->find( $event->customer_id );
		}

		$decision = $this->evaluator->for_new_journey( $event, $customer, $rules );

		if ( ! $decision->allowed ) {
			$this->refuse( $event, $decision, $stats );

			return;
		}

		$workflow = $this->workflow_for( $event->source_id );

		if ( null === $workflow ) {
			// Nothing to enrol anybody into. The event is left open so that
			// publishing a workflow picks up the baskets left in the meantime.
			++$stats->skipped;

			if ( '' === $stats->last_error ) {
				$stats->last_error = 'no_workflow';
			}

			return;
		}

		$journey_id = $this->journeys->create(
			array(
				'event_id'         => $event->id,
				'customer_id'      => (int) $event->customer_id,
				'source_id'        => $event->source_id,
				'workflow_id'      => $workflow['id'],
				'workflow_version' => $workflow['version'],
				'status'           => Journey_State::SCHEDULED,
				'current_step'     => 0,
				'next_action_at'   => $this->clock->now(),
				// Measured from enrolment, not from when the basket was first
				// seen. A basket that is picked up and put down for a fortnight
				// would otherwise be enrolled and expired in the same minute,
				// and nobody would ever be messaged about it.
				'expires_at'       => $this->clock->offset( $rules->max_age_seconds() ),
			)
		);

		if ( 0 === $journey_id ) {
			++$stats->lost_race;

			return;
		}

		$this->events->attach_journey( $event->id, $journey_id );

		++$stats->processed;
	}

	/**
	 * Record why an event was left alone.
	 *
	 * @param Recovery_Event $event    The abandoned thing.
	 * @param Eligibility    $decision The refusal.
	 * @param Stage_Stats    $stats    Counters for this run.
	 * @return void
	 */
	private function refuse( Recovery_Event $event, Eligibility $decision, Stage_Stats $stats ): void {
		++$stats->skipped;

		// A refusal about this basket rather than this person can be different
		// tomorrow: a basket grows past the minimum, and the journey blocking
		// this one finishes. The event stays open and is asked again, and the
		// max-age sweep closes it if the answer never changes.
		if ( ! $decision->is_permanent() ) {
			return;
		}

		$this->events->close( $event->id, Recovery_Event::INVALID, $decision->reason );
	}

	/**
	 * The rules that apply to a source, resolved once per run.
	 *
	 * @param string                 $source_id Source id.
	 * @param array<string,Rule_Set> $cache     Rules already resolved this run.
	 * @return Rule_Set
	 */
	private function rules_for( string $source_id, array &$cache ): Rule_Set {
		if ( ! isset( $cache[ $source_id ] ) ) {
			$cache[ $source_id ] = Rule_Set::for_source( $this->sources->get( $source_id ) );
		}

		return $cache[ $source_id ];
	}

	/**
	 * The workflow a new journey for this source is pinned to.
	 *
	 * The version is pinned here, at enrolment, and never re-read: editing a
	 * workflow must not change what a running journey does next, or a customer
	 * receives step two of a sequence they were never enrolled in.
	 *
	 * @param string $source_id Source id.
	 * @return array{id:int,version:int}|null Null when this site has no default workflow.
	 */
	private function workflow_for( string $source_id ): ?array {
		if ( null === $this->workflows ) {
			return null;
		}

		$workflow = $this->workflows->default_for_source( $source_id );

		$id      = 0;
		$version = 1;

		// Read defensively. This stage is the only caller that has to survive a
		// site with no workflows at all, and enrolling a journey against
		// workflow 0 would create a row the engine can never run.
		if ( is_object( $workflow ) ) {
			$id      = isset( $workflow->id ) ? (int) $workflow->id : 0;
			$version = isset( $workflow->version ) ? (int) $workflow->version : 1;
		} elseif ( is_array( $workflow ) ) {
			$id      = isset( $workflow['id'] ) ? (int) $workflow['id'] : 0;
			$version = isset( $workflow['version'] ) ? (int) $workflow['version'] : 1;
		}

		if ( $id <= 0 ) {
			$this->logger->debug( 'jobs', 'No default workflow is published for this source.', array( 'source' => $source_id ) );

			return null;
		}

		return array(
			'id'      => $id,
			'version' => max( 1, $version ),
		);
	}
}
