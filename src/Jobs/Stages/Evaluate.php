<?php
/**
 * Deciding which abandoned events become recovery journeys.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs\Stages;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Integration\Pollable_Source_Interface;
use WAcr\RecoveryFlow\Integration\Source_Cursors;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Jobs\Scheduler_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Runner;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Jobs\Time_Budget;
use WAcr\RecoveryFlow\Recovery\Eligibility;
use WAcr\RecoveryFlow\Recovery\Eligibility_Evaluator;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Workflow\Workflow;
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
	 * How many drafts one pollable source is asked for at a time.
	 *
	 * Small, and deliberately smaller than the evaluation batch. A poll is a
	 * source going and looking -- possibly over the network, possibly across a
	 * table nobody has indexed for us -- and every draft it hands back costs an
	 * identity resolution and a write before this stage has evaluated anything
	 * at all.
	 */
	private const POLL_BATCH = 100;

	/**
	 * Seconds to reserve before asking one source for another page.
	 */
	private const POLL_COST = 3.0;

	/**
	 * Seconds to reserve before fetching another page of events.
	 */
	private const PAGE_COST = 1.0;

	/**
	 * Seconds to reserve before deciding about one more event.
	 *
	 * Deliberately smaller than the page cost. The outer loop asks whether there
	 * is room to go back to the database for another two hundred rows; the inner
	 * one asks whether there is room to finish the row in hand. Treating those as
	 * the same number means a batch either stops with most of a page unread or
	 * runs past its budget on the last one.
	 */
	private const EVENT_COST = 0.25;

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
	 * The one route a source has for reporting what it found.
	 *
	 * @var Event_Ingest
	 */
	private Event_Ingest $ingest;

	/**
	 * Where each pollable source stopped reading.
	 *
	 * @var Source_Cursors
	 */
	private Source_Cursors $cursors;

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
	 * @param Event_Ingest             $ingest    Event ingestion, for pollable sources.
	 * @param Source_Cursors           $cursors   Poll cursors.
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
		Event_Ingest $ingest,
		Source_Cursors $cursors,
		Clock $clock,
		Logger $logger
	) {
		$this->events    = $events;
		$this->journeys  = $journeys;
		$this->customers = $customers;
		$this->evaluator = $evaluator;
		$this->sources   = $sources;
		$this->workflows = $workflows;
		$this->ingest    = $ingest;
		$this->cursors   = $cursors;
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

		$this->collect_polled( $budget, $stats );
		$this->close_stale( $budget, $stats, $rules );
		$this->enrol_due( $budget, $stats, $rules );

		return $stats;
	}

	/**
	 * Ask every pollable source what has appeared since it last looked.
	 *
	 * Most sources push: they fire a hook when something happens and this stage
	 * never has to think about them. A source that implements
	 * Pollable_Source_Interface has nothing to push from, so it is asked -- and
	 * being asked is expensive in a way a hook never is, which is why this runs
	 * first and under the same budget as everything else. A poll that overruns
	 * would take its time out of the evaluation that turns those very drafts
	 * into journeys.
	 *
	 * The cursor is only advanced after the batch has been ingested. Storing it
	 * first would mean a fatal halfway through a page silently skipped every
	 * draft in it, and a source with no hooks has no second chance to report
	 * them: an unpolled row is simply never seen again.
	 *
	 * A source that throws is dropped for this run and its cursor left alone.
	 * One broken integration must not stop the four that work, and it must not
	 * lose its place either.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @return void
	 */
	private function collect_polled( Time_Budget $budget, Stage_Stats $stats ): void {
		$limit = Stage_Runner::batch_size( $this->key(), self::POLL_BATCH );

		foreach ( $this->sources->active() as $id => $source ) {
			if ( ! $source instanceof Pollable_Source_Interface ) {
				continue;
			}

			if ( ! $budget->has_time( self::POLL_COST ) ) {
				$stats->backlog = max( $stats->backlog, 1 );

				return;
			}

			$this->poll_one( $source, $id, $limit, $budget, $stats );
		}
	}

	/**
	 * Read one page from one pollable source.
	 *
	 * @param Pollable_Source_Interface $source The source.
	 * @param string                    $id     Its id.
	 * @param int                       $limit  Drafts to ask for.
	 * @param Time_Budget               $budget How long there is.
	 * @param Stage_Stats               $stats  Counters for this run.
	 * @return void
	 */
	private function poll_one( Pollable_Source_Interface $source, string $id, int $limit, Time_Budget $budget, Stage_Stats $stats ): void {
		try {
			$batch = $source->detect_recovery_events( $limit, $this->cursors->get( $id ) );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'evaluate',
				'A source could not be polled for new recovery events.',
				array( 'source' => $id )
			);

			return;
		}

		$found = 0;

		foreach ( $batch->drafts as $draft ) {
			if ( ! $draft instanceof Event_Draft || $draft->source_id !== $id ) {
				// A source may only report its own events: the dedupe key is
				// unique per (source, key), so a draft filed under somebody
				// else's id would collide with their rows.
				continue;
			}

			if ( 0 !== $this->ingest->ingest( $draft ) ) {
				++$found;
			}
		}

		$stats->processed += $found;

		$this->cursors->set( $id, $batch->cursor );

		if ( $batch->has_more ) {
			$stats->backlog = max( $stats->backlog, 1 );
		}
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

		while ( $budget->has_time( self::PAGE_COST ) ) {
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

				if ( ! $budget->has_time( self::EVENT_COST ) ) {
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
		$workflow = null === $this->workflows ? null : $this->workflows->default_for_source( $source_id );

		// A site with no published workflow is a normal state, not a fault:
		// enrolling a journey against workflow 0 would create rows the engine
		// could never run, so nothing is enrolled until there is one.
		if ( ! $workflow instanceof Workflow || $workflow->id <= 0 ) {
			$this->logger->debug( 'jobs', 'No default workflow is published for this source.', array( 'source' => $source_id ) );

			return null;
		}

		return array(
			'id'      => $workflow->id,
			'version' => max( 1, $workflow->version ),
		);
	}
}
