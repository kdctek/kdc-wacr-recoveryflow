<?php
/**
 * Running a journey through its workflow.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Integration\Recovery_Source_Interface;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Recovery\Eligibility;
use WAcr\RecoveryFlow\Recovery\Eligibility_Evaluator;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Executes steps from where a journey left off until it must stop or wait.
 *
 * Three rules shape everything here.
 *
 * The workflow it runs is the frozen snapshot the journey pinned when it
 * started, never the live row. A merchant editing a sequence must not change
 * what happens next to the customers already inside it.
 *
 * Every write is a compare-and-set that names the state and the lease the run
 * last saw, and a false return is treated as final. A journey can change
 * underneath a batch -- the customer completes the order while the run is
 * deciding to message them -- and the correct response is to abandon the work
 * silently. Retrying, or writing anyway, would overwrite a conversion and
 * message somebody who had already bought.
 *
 * Nothing in a definition is called. A step names a condition or an action and
 * the registry is asked for the object registered under that name; a name
 * nobody registered stops the journey rather than reaching any dynamic call.
 *
 * The run always ends: a wait, a stop, the end of the workflow, or a hard cap
 * on how many steps one pass may execute. A definition that somehow contained a
 * cycle would burn one batch, not a server.
 */
final class Engine {

	/**
	 * How many steps one pass may execute before giving up.
	 *
	 * A pass advances by exactly one step per iteration and stops at the first
	 * wait or action, so a well-formed workflow can never reach this. It is a
	 * safety net against a definition that somehow contained a cycle, set one
	 * above the largest workflow the validator will accept so that it can never
	 * become a functional limit.
	 */
	private const MAX_STEPS_PER_RUN = Workflow_Definition::MAX_STEPS + 1;

	/**
	 * How long to wait when the customer is temporarily ineligible.
	 */
	private const RETRY_INELIGIBLE = 3600;

	/**
	 * How long to wait when the source could not say whether the sale happened.
	 */
	private const RETRY_UNVERIFIED = 900;

	/**
	 * Workflow storage.
	 *
	 * @var Workflow_Repository
	 */
	private Workflow_Repository $workflows;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * Event storage.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * The registered sources.
	 *
	 * @var Source_Registry
	 */
	private Source_Registry $sources;

	/**
	 * The registered conditions and actions.
	 *
	 * @var Step_Registry
	 */
	private Step_Registry $steps;

	/**
	 * The eligibility rules.
	 *
	 * @var Eligibility_Evaluator
	 */
	private Eligibility_Evaluator $eligibility;

	/**
	 * The send gate, used here for its quiet-hours arithmetic.
	 *
	 * @var Send_Gate
	 */
	private Send_Gate $gate;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Repository   $workflows   Workflow storage.
	 * @param Journey_Repository    $journeys    Journey storage.
	 * @param Event_Repository      $events      Event storage.
	 * @param Customer_Repository   $customers   Customer storage.
	 * @param Source_Registry       $sources     The registered sources.
	 * @param Step_Registry         $steps       The registered conditions and actions.
	 * @param Eligibility_Evaluator $eligibility The eligibility rules.
	 * @param Send_Gate             $gate        The send gate.
	 * @param Logger                $logger      Logger.
	 * @param Clock                 $clock       Clock.
	 */
	public function __construct(
		Workflow_Repository $workflows,
		Journey_Repository $journeys,
		Event_Repository $events,
		Customer_Repository $customers,
		Source_Registry $sources,
		Step_Registry $steps,
		Eligibility_Evaluator $eligibility,
		Send_Gate $gate,
		Logger $logger,
		Clock $clock
	) {
		$this->workflows   = $workflows;
		$this->journeys    = $journeys;
		$this->events      = $events;
		$this->customers   = $customers;
		$this->sources     = $sources;
		$this->steps       = $steps;
		$this->eligibility = $eligibility;
		$this->gate        = $gate;
		$this->logger      = $logger;
		$this->clock       = $clock;
	}

	/**
	 * Run one journey as far as it will go this pass.
	 *
	 * @param Recovery_Journey $journey     The claimed journey.
	 * @param string           $claim_token The lease this run holds on it.
	 * @return Step_Outcome
	 */
	public function run( Recovery_Journey $journey, string $claim_token ): Step_Outcome {
		try {
			return $this->execute( $journey, $claim_token );
		} catch ( \Throwable $error ) {
			// Only the class of the failure is recorded. An exception message
			// can carry a phone number or an address -- a database error quotes
			// the statement -- and this line lands in an exportable table.
			$this->logger->error(
				'workflow',
				'Workflow run failed: ' . get_class( $error ),
				array( 'step' => $journey->current_step ),
				$journey->id
			);

			return Step_Outcome::of( Step_Outcome::FAILED, 'exception' );
		}
	}

	/**
	 * The run itself.
	 *
	 * @param Recovery_Journey $journey     The claimed journey.
	 * @param string           $claim_token The lease this run holds on it.
	 * @return Step_Outcome
	 */
	private function execute( Recovery_Journey $journey, string $claim_token ): Step_Outcome {
		if ( ! $journey->is_active() ) {
			return Step_Outcome::of( Step_Outcome::SKIPPED, 'not_active' );
		}

		if ( '' === $claim_token ) {
			// Every write below is a compare-and-set that includes the lease,
			// so without one nothing could be written and the run would report
			// a lost race it never entered. Caught here, where it is legible.
			$this->logger->error( 'workflow', 'Workflow run started without a claim token', array(), $journey->id );

			return Step_Outcome::of( Step_Outcome::FAILED, 'no_claim' );
		}

		$workflow = $this->workflows->version( $journey->workflow_id, $journey->workflow_version );

		if ( null === $workflow ) {
			// Running the live definition instead would be worse than failing:
			// the customer would receive a step from a sequence they were never
			// enrolled in.
			$this->logger->error(
				'workflow',
				'The workflow version this journey was started on is missing',
				array(
					'workflow' => $journey->workflow_id,
					'version'  => $journey->workflow_version,
				),
				$journey->id
			);

			return $this->fail( $journey, 'no_workflow' );
		}

		$event = $this->events->find( $journey->event_id );

		if ( ! $event instanceof Recovery_Event ) {
			return $this->fail( $journey, 'no_event' );
		}

		$steps = $workflow->steps();

		if ( array() === $steps ) {
			return $this->fail( $journey, 'empty_workflow' );
		}

		$source = $this->sources->get( $journey->source_id );

		$context = array(
			'event'          => $event,
			'customer'       => $this->customers->find( $journey->customer_id ),
			'rules'          => Rule_Set::for_source( $source ),
			'source'         => $source,
			'workflow'       => $workflow,
			'claim_token'    => $claim_token,
			'step_index'     => $journey->current_step,
			'next_action_at' => null,
		);

		$total = count( $steps );

		for ( $pass = 0; $pass < self::MAX_STEPS_PER_RUN; $pass++ ) {
			$index = $journey->current_step;

			if ( $index < 0 || $index >= $total ) {
				return $this->finish( $journey, $claim_token );
			}

			$step                  = $steps[ $index ];
			$type                  = isset( $step['type'] ) ? (string) $step['type'] : '';
			$context['step_index'] = $index;

			if ( Workflow_Definition::TYPE_WAIT === $type ) {
				return $this->run_wait( $journey, $step, $context );
			}

			if ( Workflow_Definition::TYPE_ACTION === $type ) {
				return $this->run_action( $journey, $step, $context, $total );
			}

			if ( Workflow_Definition::TYPE_CONDITION !== $type ) {
				$this->logger->error(
					'workflow',
					'Workflow step type is not one this version can run',
					array( 'step' => $index ),
					$journey->id
				);

				return $this->fail( $journey, 'unknown_step' );
			}

			$outcome = $this->run_condition( $journey, $step, $context );

			if ( $outcome instanceof Step_Outcome ) {
				return $outcome;
			}
		}//end for

		$this->logger->error(
			'workflow',
			'Workflow did not settle within the step limit for one pass',
			array( 'step' => $journey->current_step ),
			$journey->id
		);

		return $this->fail( $journey, 'step_limit' );
	}

	/**
	 * Evaluate a condition step.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $step    The step.
	 * @param array<string,mixed> $context Run context.
	 * @return Step_Outcome|null Null to carry on to the next step.
	 */
	private function run_condition( Recovery_Journey $journey, array $step, array $context ): ?Step_Outcome {
		$expression = isset( $step['if'] ) ? (string) $step['if'] : '';
		$name       = Workflow_Definition::condition_name( $expression );
		$condition  = $this->steps->condition( $name );
		$claim      = (string) $context['claim_token'];

		if ( null === $condition ) {
			$this->logger->error(
				'workflow',
				'Workflow names a condition nothing has registered',
				array(
					'condition' => substr( $name, 0, 32 ),
					'step'      => $journey->current_step,
				),
				$journey->id
			);

			return $this->fail( $journey, 'unknown_condition' );
		}

		$passed = $condition->evaluate( $journey, $context, Workflow_Definition::condition_argument( $expression ) );

		if ( $passed ) {
			return $this->advance( $journey, $claim ) ? null : Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		$branch = isset( $step['else'] ) ? (string) $step['else'] : '';
		$state  = Workflow_Definition::stop_state( $branch );

		if ( '' === $state ) {
			// A condition with no else branch is a note, not a gate: the
			// workflow carries on either way. That is what omitting else means
			// in the definition format.
			return $this->advance( $journey, $claim ) ? null : Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		if ( ! $this->stop( $journey, $state, $name ) ) {
			return Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		return Step_Outcome::of( Step_Outcome::STOPPED, $name );
	}

	/**
	 * Park the journey until a wait has elapsed.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $step    The step.
	 * @param array<string,mixed> $context Run context.
	 * @return Step_Outcome
	 */
	private function run_wait( Recovery_Journey $journey, array $step, array $context ): Step_Outcome {
		$seconds = Workflow_Definition::duration_to_seconds( isset( $step['for'] ) ? (string) $step['for'] : '' );

		if ( $seconds < 1 ) {
			return $this->fail( $journey, 'bad_duration' );
		}

		$rules = $context['rules'];
		$claim = (string) $context['claim_token'];

		// Shifted here as well as at send time so that a reminder due at three
		// in the morning is stored as due at nine, rather than being claimed,
		// refused and re-queued once a minute all night.
		$due = $this->gate->quiet_shift( $this->clock->timestamp() + $seconds, $rules instanceof Rule_Set ? $rules : Rule_Set::for_source( null ) );

		$patch = array(
			'current_step'   => $journey->current_step + 1,
			'next_action_at' => $this->clock->at( $due ),
		);

		$written = Journey_State::SCHEDULED === $journey->status
			? $this->journeys->update_claimed( $journey->id, $claim, $journey->status, $patch )
			: $this->journeys->transition( $journey->id, $journey->status, Journey_State::SCHEDULED, $patch, 'wait' );

		if ( ! $written ) {
			return Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		$journey->current_step   = $patch['current_step'];
		$journey->next_action_at = $patch['next_action_at'];
		$journey->status         = Journey_State::SCHEDULED;

		return Step_Outcome::of( Step_Outcome::WAITING, 'wait' );
	}

	/**
	 * Run an action step, after re-asking the two questions that matter.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $step    The step.
	 * @param array<string,mixed> $context Run context.
	 * @param int                 $total   How many steps the workflow has.
	 * @return Step_Outcome
	 */
	private function run_action( Recovery_Journey $journey, array $step, array $context, int $total ): Step_Outcome {
		$name    = isset( $step['do'] ) ? (string) $step['do'] : '';
		$handler = $this->steps->action( $name );

		if ( null === $handler ) {
			$this->logger->error(
				'workflow',
				'Workflow names an action nothing has registered',
				array(
					'action' => substr( $name, 0, 32 ),
					'step'   => $journey->current_step,
				),
				$journey->id
			);

			return $this->fail( $journey, 'unknown_action' );
		}

		$outcome = $this->guard_conversion( $journey, $context );

		if ( $outcome instanceof Step_Outcome ) {
			return $outcome;
		}

		$outcome = $this->guard_eligibility( $journey, $context );

		if ( $outcome instanceof Step_Outcome ) {
			return $outcome;
		}

		// An action always leaves the journey in SCHEDULED or beyond, and the
		// state machine has no move from ELIGIBLE straight to MESSAGE_SENT.
		// Without this the post-send transition would be refused and read as a
		// lost race, and the journey would retry the same send every tick.
		if ( Journey_State::ELIGIBLE === $journey->status ) {
			if ( ! $this->journeys->transition( $journey->id, $journey->status, Journey_State::SCHEDULED, array(), 'dispatching' ) ) {
				return Step_Outcome::of( Step_Outcome::LOST_RACE );
			}

			$journey->status = Journey_State::SCHEDULED;
		}

		// What to set next_action_at to if the action succeeds. Due now rather
		// than at some computed future moment: the next tick reads the steps
		// that follow and works out the real time, which keeps that arithmetic
		// in one place.
		$context['next_action_at'] = ( $journey->current_step + 1 ) < $total ? $this->clock->now() : null;

		$parameters = isset( $step['with'] ) && is_array( $step['with'] ) ? $step['with'] : array();

		return $handler->run( $journey, $parameters, $context );
	}

	/**
	 * Refuse to act if the thing has already been bought.
	 *
	 * Asked of the source, not of the stored event: between scheduling a
	 * reminder and sending it the customer may have checked out somewhere else,
	 * and asking somebody to finish an order they have paid for is the most
	 * expensive mistake this plugin can make.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $context Run context.
	 * @return Step_Outcome|null Null when the sale is still outstanding.
	 */
	private function guard_conversion( Recovery_Journey $journey, array $context ): ?Step_Outcome {
		$event  = $context['event'];
		$source = $context['source'];
		$claim  = (string) $context['claim_token'];

		if ( ! $event instanceof Recovery_Event ) {
			return $this->fail( $journey, 'no_event' );
		}

		if ( Recovery_Event::COMPLETED === $event->status ) {
			return $this->stop( $journey, Journey_State::RECOVERED, 'completed' )
				? Step_Outcome::of( Step_Outcome::STOPPED, 'completed' )
				: Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		if ( ! $event->is_open() ) {
			return $this->stop( $journey, Journey_State::CANCELLED, (string) $event->status_reason )
				? Step_Outcome::of( Step_Outcome::STOPPED, 'event_closed' )
				: Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		if ( ! $source instanceof Recovery_Source_Interface ) {
			// The integration that detected this is not answering -- switched
			// off, or not loaded in this request. Nothing can confirm the sale
			// did not happen, so nothing is sent.
			$this->logger->warning(
				'workflow',
				'Send held back: the source that detected this is not available',
				array( 'source' => substr( $journey->source_id, 0, 32 ) ),
				$journey->id
			);

			return $this->defer( $journey, $claim, self::RETRY_UNVERIFIED, 'source_unavailable' );
		}

		try {
			$complete = $source->is_conversion_complete( $event );
		} catch ( \Throwable $error ) {
			$this->logger->error(
				'workflow',
				'Source could not confirm whether the order completed: ' . get_class( $error ),
				array( 'source' => substr( $journey->source_id, 0, 32 ) ),
				$journey->id
			);

			return $this->defer( $journey, $claim, self::RETRY_UNVERIFIED, 'unverified' );
		}

		if ( ! $complete ) {
			return null;
		}

		return $this->stop( $journey, Journey_State::RECOVERED, 'completed' )
			? Step_Outcome::of( Step_Outcome::STOPPED, 'completed' )
			: Step_Outcome::of( Step_Outcome::LOST_RACE );
	}

	/**
	 * Refuse to act if this person may no longer be messaged.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $context Run context.
	 * @return Step_Outcome|null Null when the send may go ahead.
	 */
	private function guard_eligibility( Recovery_Journey $journey, array $context ): ?Step_Outcome {
		$rules = $context['rules'];
		$claim = (string) $context['claim_token'];

		if ( ! $rules instanceof Rule_Set ) {
			return $this->fail( $journey, 'no_rules' );
		}

		$verdict = $this->eligibility->for_send( $context['customer'], $rules );

		if ( $verdict->allowed ) {
			return null;
		}

		if ( ! $verdict->is_permanent() ) {
			// Temporary: the frequency cap will lapse, and the site can be
			// switched back on. Coming back in an hour costs nothing.
			return $this->defer( $journey, $claim, self::RETRY_INELIGIBLE, $verdict->reason );
		}

		$opted_out = in_array( $verdict->reason, array( Eligibility::SUPPRESSED, Eligibility::WACR_OPTED_OUT ), true );
		$state     = $opted_out ? Journey_State::OPTED_OUT : Journey_State::CANCELLED;

		return $this->stop( $journey, $state, $verdict->reason )
			? Step_Outcome::of( Step_Outcome::STOPPED, $verdict->reason )
			: Step_Outcome::of( Step_Outcome::LOST_RACE );
	}

	/**
	 * The workflow has no more steps.
	 *
	 * The journey keeps whatever state its last send left it in and expires on
	 * its own schedule; clearing next_action_at is what takes it out of the due
	 * queue without pretending the recovery failed.
	 *
	 * @param Recovery_Journey $journey     The journey.
	 * @param string           $claim_token The lease this run holds.
	 * @return Step_Outcome
	 */
	private function finish( Recovery_Journey $journey, string $claim_token ): Step_Outcome {
		if ( null === $journey->next_action_at ) {
			return Step_Outcome::of( Step_Outcome::STOPPED, 'workflow_complete' );
		}

		if ( ! $this->journeys->update_claimed( $journey->id, $claim_token, $journey->status, array( 'next_action_at' => null ) ) ) {
			return Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		$journey->next_action_at = null;

		return Step_Outcome::of( Step_Outcome::STOPPED, 'workflow_complete' );
	}

	/**
	 * Move the cursor on by one step.
	 *
	 * @param Recovery_Journey $journey     The journey.
	 * @param string           $claim_token The lease this run holds.
	 * @return bool False when somebody else changed the row first.
	 */
	private function advance( Recovery_Journey $journey, string $claim_token ): bool {
		$next = $journey->current_step + 1;

		if ( ! $this->journeys->update_claimed( $journey->id, $claim_token, $journey->status, array( 'current_step' => $next ) ) ) {
			return false;
		}

		$journey->current_step = $next;

		return true;
	}

	/**
	 * Come back to this journey later.
	 *
	 * @param Recovery_Journey $journey     The journey.
	 * @param string           $claim_token The lease this run holds.
	 * @param int              $seconds     How long to wait.
	 * @param string           $reason      Machine-readable reason.
	 * @return Step_Outcome
	 */
	private function defer( Recovery_Journey $journey, string $claim_token, int $seconds, string $reason ): Step_Outcome {
		$due = $this->clock->offset( $seconds );

		if ( ! $this->journeys->update_claimed( $journey->id, $claim_token, $journey->status, array( 'next_action_at' => $due ) ) ) {
			return Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		$journey->next_action_at = $due;

		return Step_Outcome::of( Step_Outcome::WAITING, $reason );
	}

	/**
	 * End the journey in a terminal state.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $state   A terminal Journey_State.
	 * @param string           $reason  Machine-readable reason.
	 * @return bool False when somebody else changed the row first.
	 */
	private function stop( Recovery_Journey $journey, string $state, string $reason ): bool {
		if ( ! $this->journeys->transition( $journey->id, $journey->status, $state, array(), $reason ) ) {
			return false;
		}

		$journey->status = $state;

		return true;
	}

	/**
	 * End the journey as failed, recording why for the admin screens.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $reason  Machine-readable reason.
	 * @return Step_Outcome
	 */
	private function fail( Recovery_Journey $journey, string $reason ): Step_Outcome {
		$patch = array(
			'last_error_code' => substr( $reason, 0, 32 ),
			'last_error_at'   => $this->clock->now(),
		);

		if ( ! $this->journeys->transition( $journey->id, $journey->status, Journey_State::FAILED, $patch, $reason ) ) {
			return Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		$journey->status = Journey_State::FAILED;

		return Step_Outcome::of( Step_Outcome::FAILED, $reason );
	}
}
