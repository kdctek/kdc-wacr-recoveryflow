<?php
/**
 * The action that hands a journey to a WA.cr Auto Flow.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Actions;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Core\Rewrites;
use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Integration\Recovery_Source_Interface;
use WAcr\RecoveryFlow\Recovery\Attempt;
use WAcr\RecoveryFlow\Recovery\Channel;
use WAcr\RecoveryFlow\Recovery\Attempt_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Security\Token_Service;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\WAcr\Client;
use WAcr\RecoveryFlow\WAcr\Credentials;
use WAcr\RecoveryFlow\WAcr\Error;
use WAcr\RecoveryFlow\WAcr\Rate_Budget;
use WAcr\RecoveryFlow\Workflow\Send_Gate;
use WAcr\RecoveryFlow\Workflow\Step_Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * The path that works without a developer API key.
 *
 * One signed push tells a WA.cr Auto Flow that this person has something worth
 * recovering, and the flow owns everything after that: the delay, the template,
 * the follow-ups, stopping on a reply. WordPress keeps only what it is best
 * placed to know -- that a conversion was abandoned, who abandoned it, and
 * whether they have since bought.
 *
 * It still reserves an attempt row before the push, for the same reason a
 * direct send does: the hook has no idempotency key, so pushing twice starts
 * two flows and the customer gets two conversations about one basket.
 *
 * The unknown outcome is handled differently from a direct send, and
 * deliberately. A direct send that timed out can be resolved by reading the
 * conversation back; a hook push cannot be resolved at all. So an unknown push
 * is never repeated: the journey stops being worked and expires on its own,
 * which risks one lost recovery rather than one duplicated conversation.
 */
final class Start_Flow implements Action_Interface {

	/**
	 * The name a workflow refers to this by.
	 */
	public const ID = 'wacr.start_flow';

	/**
	 * The event name the Auto Flow trigger matches on.
	 */
	public const EVENT = 'recovery.journey_eligible';

	/**
	 * How many times one step may try before the journey is given up on.
	 */
	private const MAX_ATTEMPTS = Attempt::MAX_PER_STEP;

	/**
	 * Backoff between tries: five minutes, doubling, capped at six hours.
	 */
	private const BACKOFF_BASE = 300;
	private const BACKOFF_MAX  = 21600;

	/**
	 * When to first look for a reply after the hand-off.
	 */
	private const POLL_AFTER = 300;

	/**
	 * How long to wait when there is no hook URL saved yet.
	 */
	private const UNAVAILABLE_AFTER = 3600;

	/**
	 * How long the items summary may be, so the push stays well inside WA.cr's
	 * 64 KB hook limit whatever is in the basket.
	 */
	private const MAX_SUMMARY_LENGTH = 400;

	/**
	 * WA.cr client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Connection settings.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Attempt ledger.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * The send gate.
	 *
	 * @var Send_Gate
	 */
	private Send_Gate $gate;

	/**
	 * Request budget.
	 *
	 * @var Rate_Budget
	 */
	private Rate_Budget $budget;

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
	 * @param Client             $client      WA.cr client.
	 * @param Credentials        $credentials Connection settings.
	 * @param Attempt_Repository $attempts    Attempt ledger.
	 * @param Journey_Repository $journeys    Journey storage.
	 * @param Send_Gate          $gate        The send gate.
	 * @param Rate_Budget        $budget      Request budget.
	 * @param Logger             $logger      Logger.
	 * @param Clock              $clock       Clock.
	 */
	public function __construct(
		Client $client,
		Credentials $credentials,
		Attempt_Repository $attempts,
		Journey_Repository $journeys,
		Send_Gate $gate,
		Rate_Budget $budget,
		Logger $logger,
		Clock $clock
	) {
		$this->client      = $client;
		$this->credentials = $credentials;
		$this->attempts    = $attempts;
		$this->journeys    = $journeys;
		$this->gate        = $gate;
		$this->budget      = $budget;
		$this->logger      = $logger;
		$this->clock       = $clock;
	}

	/**
	 * The name a workflow refers to this by.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * One sentence for the workflow editor.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Hand the journey to a WA.cr Auto Flow', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * The channel this action sends on.
	 *
	 * @return string
	 */
	public function get_channel(): string {
		return Channel::WHATSAPP;
	}

	/**
	 * Whether a hook URL has been saved.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->credentials->has_hook();
	}

	/**
	 * Push the journey to the flow.
	 *
	 * @param Recovery_Journey    $journey    The journey being handed off.
	 * @param array<string,mixed> $parameters The step's "with" block.
	 * @param array<string,mixed> $context    Run context.
	 * @return Step_Outcome
	 */
	public function run( Recovery_Journey $journey, array $parameters, array $context ): Step_Outcome {
		$rules    = $context['rules'] ?? null;
		$event    = $context['event'] ?? null;
		$customer = $context['customer'] ?? null;
		$claim    = (string) ( $context['claim_token'] ?? '' );
		$step     = (int) ( $context['step_index'] ?? 0 );

		if ( ! $rules instanceof Rule_Set || ! $event instanceof Recovery_Event ) {
			return Step_Outcome::of( Step_Outcome::FAILED, 'bad_context' );
		}

		if ( ! $this->is_available() ) {
			// Deferred rather than failed: pasting the hook URL later should
			// let the journeys that queued up in the meantime run.
			$this->logger->warning( 'workflow', 'No WA.cr Auto Flow hook URL is saved', array(), $journey->id );

			return $this->defer( $journey, $claim, $this->clock->offset( self::UNAVAILABLE_AFTER ), 'no_hook' );
		}

		$verdict = $this->gate->check( $journey, $customer instanceof Customer ? $customer : null, $rules );

		if ( Send_Gate::DEFER === $verdict['decision'] ) {
			return $this->defer( $journey, $claim, $verdict['until'], $verdict['reason'] );
		}

		if ( Send_Gate::SKIP === $verdict['decision'] ) {
			return $this->halt( $journey, $claim, $verdict['reason'] );
		}

		if ( ! $customer instanceof Customer || '' === $customer->phone_e164 ) {
			return $this->halt( $journey, $claim, Send_Gate::REASON_RECIPIENT );
		}

		$history = $this->attempts->for_journey( $journey->id, 100 );

		foreach ( $history as $earlier ) {
			if ( $earlier->step_index !== $step ) {
				continue;
			}

			if ( $earlier->was_sent() ) {
				return $this->record_sent( $journey, $context, 'already_handed_off' );
			}

			if ( $earlier->needs_resolution() ) {
				// Nothing can tell us whether the first push landed, and a
				// second would start a second conversation. Stop here.
				return $this->halt( $journey, $claim, 'handoff_unresolved' );
			}
		}

		$attempt_no = $this->count_for_step( $history, $step ) + 1;

		if ( $attempt_no > self::MAX_ATTEMPTS ) {
			return $this->fail( $journey, 'attempts_exhausted' );
		}

		$token = Token_Service::mint();

		$attempt = $this->attempts->reserve(
			array(
				'journey_id'       => $journey->id,
				'step_index'       => $step,
				'attempt_no'       => $attempt_no,
				'idempotency_key'  => Attempt::key( $journey->journey_uid, $step, $attempt_no ),
				'action_type'      => self::ID,
				'channel'          => $this->get_channel(),
				'token_hash'       => Token_Service::hash( $token ),
				'token_expires_at' => $this->clock->offset( $rules->link_ttl_seconds() ),
				'status'           => Attempt::SENDING,
			)
		);

		if ( null === $attempt ) {
			// Somebody else holds this key. They are pushing, or have pushed.
			return Step_Outcome::of( Step_Outcome::SKIPPED, 'reserved_elsewhere' );
		}

		return $this->push( $journey, $context, $attempt, $customer, $event, $token );
	}

	/**
	 * Send the payload and act on what came back.
	 *
	 * @param Recovery_Journey    $journey  The journey.
	 * @param array<string,mixed> $context  Run context.
	 * @param Attempt             $attempt  The reserved attempt.
	 * @param Customer            $customer The recipient.
	 * @param Recovery_Event      $event    What was abandoned.
	 * @param string              $token    The plaintext recovery token.
	 * @return Step_Outcome
	 */
	private function push( Recovery_Journey $journey, array $context, Attempt $attempt, Customer $customer, Recovery_Event $event, string $token ): Step_Outcome {
		$source  = $context['source'] ?? null;
		$payload = $this->payload( $journey, $customer, $event, $source instanceof Recovery_Source_Interface ? $source : null, $token );

		/**
		 * Fires immediately before the hand-off to WA.cr, after the attempt row
		 * is reserved. Observation only.
		 *
		 * @param Recovery_Journey    $journey The journey.
		 * @param Attempt             $attempt The reserved attempt.
		 * @param array<string,mixed> $payload What is about to be pushed.
		 */
		do_action( Hooks::BEFORE_MESSAGE, $journey, $attempt, $payload );

		$result = $this->client->start_flow( $payload );

		/**
		 * Fires immediately after the hand-off, whatever the outcome.
		 *
		 * @param Recovery_Journey $journey The journey.
		 * @param Attempt          $attempt The attempt.
		 * @param \WAcr\RecoveryFlow\WAcr\Result $result What WA.cr answered.
		 */
		do_action( Hooks::AFTER_MESSAGE, $journey, $attempt, $result );

		if ( $result->ok ) {
			$this->attempts->mark_sent( $attempt->id );

			return $this->record_sent( $journey, $context, 'handed_off' );
		}

		return $this->handle_failure( $journey, $context, $attempt, $result->error );
	}

	/**
	 * The flat payload the Auto Flow reads its variables from.
	 *
	 * Flat scalars only: WA.cr exposes each top-level key to the flow, and a
	 * nested structure would arrive as nothing usable.
	 *
	 * Every key is always present, even when empty. A flow that references a
	 * variable the payload omitted renders the placeholder literally, and
	 * "{{var.hook_first_name}}" reaching a customer is worse than a greeting
	 * with no name in it.
	 *
	 * @param Recovery_Journey               $journey  The journey.
	 * @param Customer                       $customer The recipient.
	 * @param Recovery_Event                 $event    What was abandoned.
	 * @param Recovery_Source_Interface|null $source   The source, when it is loaded.
	 * @param string                         $token    The plaintext recovery token.
	 * @return array<string,mixed>
	 */
	private function payload( Recovery_Journey $journey, Customer $customer, Recovery_Event $event, ?Recovery_Source_Interface $source, string $token ): array {
		$summary = $event->items_summary();

		if ( mb_strlen( $summary ) > self::MAX_SUMMARY_LENGTH ) {
			$summary = rtrim( mb_substr( $summary, 0, self::MAX_SUMMARY_LENGTH - 1 ) ) . '…';
		}

		return array(
			'event'         => self::EVENT,
			'journey_uid'   => $journey->journey_uid,
			'source'        => $journey->source_id,
			'source_type'   => $event->source_type,
			'phone'         => $customer->phone_e164,
			'first_name'    => $customer->first_name,
			'currency'      => strtoupper( trim( $event->currency ) ),
			'total'         => number_format( $event->amount_value(), 2, '.', '' ),
			'item_count'    => $event->item_count,
			'items_summary' => $summary,
			'recovery_url'  => $this->recovery_url( $journey, $source, $token ),
			'opt_out_url'   => Rewrites::url( $token, 'opt-out' ),
			'site_name'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'      => home_url( '/' ),
			'abandoned_at'  => gmdate( 'c', $this->clock->parse( $event->last_activity_at ) ),
		);
	}

	/**
	 * Where the flow should send the customer.
	 *
	 * @param Recovery_Journey               $journey The journey.
	 * @param Recovery_Source_Interface|null $source  The source, when it is loaded.
	 * @param string                         $token   The plaintext token.
	 * @return string
	 */
	private function recovery_url( Recovery_Journey $journey, ?Recovery_Source_Interface $source, string $token ): string {
		if ( null !== $source ) {
			try {
				$built = $source->build_recovery_url( $journey, $token );

				if ( is_string( $built ) && '' !== $built ) {
					return $built;
				}
			} catch ( \Throwable $error ) {
				// The plugin's own endpoint always works; a source that throws
				// while building a URL must not cost the customer their link.
				$this->logger->warning(
					'workflow',
					'Source could not build a recovery URL: ' . get_class( $error ),
					array( 'source' => substr( $journey->source_id, 0, 32 ) ),
					$journey->id
				);
			}
		}

		return Rewrites::url( $token, 'restore' );
	}

	/**
	 * Move the journey on after the flow was started.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $context Run context.
	 * @param string              $reason  Machine-readable reason.
	 * @return Step_Outcome
	 */
	private function record_sent( Recovery_Journey $journey, array $context, string $reason ): Step_Outcome {
		$step = (int) ( $context['step_index'] ?? 0 );

		$patch = array(
			'current_step'   => $step + 1,
			'next_action_at' => $context['next_action_at'] ?? null,
			'poll_at'        => $this->clock->offset( self::POLL_AFTER ),
			'attempts_count' => max( 1, $journey->attempts_count ),
		);

		if ( null === $journey->first_sent_at ) {
			$patch['first_sent_at'] = $this->clock->now();
		}

		if ( ! $this->journeys->transition( $journey->id, $journey->status, Journey_State::MESSAGE_SENT, $patch, $reason ) ) {
			return Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		$journey->status       = Journey_State::MESSAGE_SENT;
		$journey->current_step = $step + 1;

		return Step_Outcome::of( Step_Outcome::SENT, $reason );
	}

	/**
	 * Sort a failed hand-off by what it says about the push.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $context Run context.
	 * @param Attempt             $attempt The attempt that failed.
	 * @param Error|null          $error   What went wrong.
	 * @return Step_Outcome
	 */
	private function handle_failure( Recovery_Journey $journey, array $context, Attempt $attempt, ?Error $error ): Step_Outcome {
		$claim = (string) ( $context['claim_token'] ?? '' );

		if ( ! $error instanceof Error ) {
			$this->attempts->mark_failed( $attempt->id, 'unknown_error' );

			return $this->fail( $journey, 'unknown_error' );
		}

		$stamp = array(
			'last_error_code' => substr( $error->code, 0, 32 ),
			'last_error_at'   => $this->clock->now(),
		);

		if ( Error::RATE_LIMIT === $error->category ) {
			$wait = max( 60, (int) ( $error->retry_after ?? 60 ) );

			$this->attempts->mark_failed( $attempt->id, $error->code, $error->message );
			$this->budget->pause( $wait );

			$stamp['next_action_at'] = $this->clock->offset( $wait );

			return $this->touch( $journey, $claim, $stamp )
				? Step_Outcome::of( Step_Outcome::SKIPPED, 'rate_limited' )->and_stop_batch()
				: Step_Outcome::of( Step_Outcome::LOST_RACE )->and_stop_batch();
		}

		if ( Error::UNKNOWN === $error->send_state ) {
			// There is no conversation to read back and no way to ask the flow
			// whether it started. Recorded, and never repeated.
			$this->attempts->mark_unknown( $attempt->id, $error->code );

			$this->logger->error(
				'workflow',
				'Auto Flow hand-off did not complete and cannot be confirmed',
				array( 'code' => substr( $error->code, 0, 32 ) ),
				$journey->id
			);

			return $this->halt( $journey, $claim, 'handoff_unknown' );
		}

		$this->attempts->mark_failed( $attempt->id, $error->code, $error->message );

		if ( Error::CONFIGURATION === $error->category ) {
			// The hook URL is wrong or missing. Fixing it on the settings
			// screen should let this journey run, so it is not given up on.
			$stamp['next_action_at'] = $this->clock->offset( self::UNAVAILABLE_AFTER );

			return $this->touch( $journey, $claim, $stamp )
				? Step_Outcome::of( Step_Outcome::WAITING, $error->code )
				: Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		if ( $error->is_fatal_for_sending() ) {
			$this->journeys->transition( $journey->id, $journey->status, Journey_State::FAILED, $stamp, $error->code );

			return Step_Outcome::of( Step_Outcome::FAILED, $error->code )->and_stop_batch();
		}

		if ( ! $error->retryable || $attempt->attempt_no >= self::MAX_ATTEMPTS ) {
			$this->journeys->transition( $journey->id, $journey->status, Journey_State::FAILED, $stamp, $error->code );

			return Step_Outcome::of( Step_Outcome::FAILED, $error->code );
		}

		$stamp['next_action_at'] = $this->clock->offset( self::backoff( $attempt->attempt_no ) );

		return $this->touch( $journey, $claim, $stamp )
			? Step_Outcome::of( Step_Outcome::WAITING, 'retry_backoff' )
			: Step_Outcome::of( Step_Outcome::LOST_RACE );
	}

	/**
	 * How many attempts a step has already had.
	 *
	 * @param array<int,Attempt> $history Every attempt on the journey.
	 * @param int                $step    The step index.
	 * @return int
	 */
	private function count_for_step( array $history, int $step ): int {
		$total = 0;

		foreach ( $history as $attempt ) {
			if ( $attempt->step_index === $step ) {
				++$total;
			}
		}

		return $total;
	}

	/**
	 * Write columns on a journey this run still holds.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param string              $claim   The lease this run holds.
	 * @param array<string,mixed> $patch   Columns to write.
	 * @return bool False when somebody else changed the row first.
	 */
	private function touch( Recovery_Journey $journey, string $claim, array $patch ): bool {
		return $this->journeys->update_claimed( $journey->id, $claim, $journey->status, $patch );
	}

	/**
	 * Come back to this journey at a given moment.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $claim   The lease this run holds.
	 * @param string           $until   UTC datetime to try again at.
	 * @param string           $reason  Machine-readable reason.
	 * @return Step_Outcome
	 */
	private function defer( Recovery_Journey $journey, string $claim, string $until, string $reason ): Step_Outcome {
		if ( '' === $until ) {
			$until = $this->clock->offset( self::BACKOFF_BASE );
		}

		if ( ! $this->touch( $journey, $claim, array( 'next_action_at' => $until ) ) ) {
			return Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		$journey->next_action_at = $until;

		return Step_Outcome::of( Step_Outcome::WAITING, $reason );
	}

	/**
	 * Stop working this journey without ending it.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $claim   The lease this run holds.
	 * @param string           $reason  Machine-readable reason.
	 * @return Step_Outcome
	 */
	private function halt( Recovery_Journey $journey, string $claim, string $reason ): Step_Outcome {
		if ( ! $this->touch( $journey, $claim, array( 'next_action_at' => null ) ) ) {
			return Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		$journey->next_action_at = null;

		return Step_Outcome::of( Step_Outcome::STOPPED, $reason );
	}

	/**
	 * End the journey as failed.
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

	/**
	 * How long to wait before the next try.
	 *
	 * @param int $attempt_no Which try just failed.
	 * @return int Seconds.
	 */
	private static function backoff( int $attempt_no ): int {
		return (int) min( self::BACKOFF_MAX, self::BACKOFF_BASE * ( 2 ** max( 0, $attempt_no - 1 ) ) );
	}
}
