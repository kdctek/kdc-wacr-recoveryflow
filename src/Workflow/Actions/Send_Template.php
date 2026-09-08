<?php
/**
 * The action that sends a WhatsApp recovery message.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Actions;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Feature_Gate;
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
use WAcr\RecoveryFlow\WAcr\Error;
use WAcr\RecoveryFlow\WAcr\Rate_Budget;
use WAcr\RecoveryFlow\WAcr\Result;
use WAcr\RecoveryFlow\Workflow\Message_Composer;
use WAcr\RecoveryFlow\Workflow\Send_Gate;
use WAcr\RecoveryFlow\Workflow\Step_Outcome;
use WAcr\RecoveryFlow\Workflow\Variable_Context;

defined( 'ABSPATH' ) || exit;

/**
 * One message, sent at most once, whatever else goes wrong.
 *
 * The order of the first four things this class does is the whole design, and
 * it is not negotiable.
 *
 * The gate is asked first, because a message held back for quiet hours or a
 * paused rate budget should not consume an attempt number or a token.
 *
 * Then the attempt row is reserved, under a UNIQUE idempotency key, BEFORE the
 * HTTP call. The WA.cr messaging API has no idempotency key of its own, so this
 * row is the only thing standing between a retry and a customer receiving the
 * same reminder twice with the merchant billed twice for it. A null return from
 * reserve() means another run already holds that key, and the only safe reading
 * of that is "a send is already in flight": this run returns SKIPPED and does
 * not send, however certain it is that nothing has gone out.
 *
 * Existing attempts for the same step are inspected before a new one is
 * reserved. One that already sent means the message went out and the journey
 * simply was not advanced; one still in 'sending' or 'unknown' means a request
 * was started and its outcome was never learned, which is resolved by reading
 * the conversation back, never by sending again.
 *
 * Failures are then sorted by what they say about delivery rather than by HTTP
 * status. A request that failed before it left is retried with backoff. A
 * request that timed out after the body was written is marked unknown and left
 * for the Poll stage, because a blind retry there is exactly the double send
 * this class exists to prevent.
 */
final class Send_Template implements Action_Interface {

	/**
	 * The name a workflow refers to this by.
	 */
	public const ID = 'wacr.send_template';

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
	 * When to first read the conversation back after a send.
	 */
	private const POLL_AFTER = 300;

	/**
	 * When an unknown outcome should be looked into, and when the workflow may
	 * safely look at this step again once it has been.
	 */
	private const RESOLVE_AFTER = 120;
	private const RECHECK_AFTER = 1200;

	/**
	 * How long to wait when the site cannot send directly at all.
	 */
	private const UNAVAILABLE_AFTER = 3600;

	/**
	 * WA.cr client.
	 *
	 * @var Client
	 */
	private Client $client;

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
	 * Builds the request from the step's variable mapping.
	 *
	 * @var Message_Composer
	 */
	private Message_Composer $composer;

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
	 * @param Client             $client   WA.cr client.
	 * @param Attempt_Repository $attempts Attempt ledger.
	 * @param Journey_Repository $journeys Journey storage.
	 * @param Send_Gate          $gate     The send gate.
	 * @param Message_Composer   $composer Message builder.
	 * @param Rate_Budget        $budget   Request budget.
	 * @param Logger             $logger   Logger.
	 * @param Clock              $clock    Clock.
	 */
	public function __construct(
		Client $client,
		Attempt_Repository $attempts,
		Journey_Repository $journeys,
		Send_Gate $gate,
		Message_Composer $composer,
		Rate_Budget $budget,
		Logger $logger,
		Clock $clock
	) {
		$this->client   = $client;
		$this->attempts = $attempts;
		$this->journeys = $journeys;
		$this->gate     = $gate;
		$this->composer = $composer;
		$this->budget   = $budget;
		$this->logger   = $logger;
		$this->clock    = $clock;
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
		return __( 'Send an approved WhatsApp template from WordPress', 'kdc-wacr-recoveryflow' );
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
	 * Whether this site can send directly.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return Feature_Gate::is_enabled( Feature_Gate::DIRECT_SEND );
	}

	/**
	 * Send the message.
	 *
	 * @param Recovery_Journey    $journey    The journey being messaged.
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
			// Deferred rather than failed: connecting a key later should let
			// the journeys that queued up in the meantime run, not find them
			// all dead.
			$this->logger->warning( 'workflow', 'Direct sending is not available on this workspace', array(), $journey->id );

			return $this->defer( $journey, $claim, $this->clock->offset( self::UNAVAILABLE_AFTER ), 'direct_send_unavailable' );
		}

		$verdict = $this->gate->check( $journey, $customer instanceof Customer ? $customer : null, $rules );

		if ( Send_Gate::DEFER === $verdict['decision'] ) {
			return $this->defer( $journey, $claim, $verdict['until'], $verdict['reason'] );
		}

		if ( Send_Gate::SKIP === $verdict['decision'] ) {
			return $this->halt( $journey, $claim, $verdict['reason'] );
		}

		if ( ! $customer instanceof Customer ) {
			return $this->halt( $journey, $claim, Send_Gate::REASON_RECIPIENT );
		}

		$history  = $this->attempts->for_journey( $journey->id, 100 );
		$resolved = $this->resume_from_history( $journey, $context, $history, $step );

		if ( $resolved instanceof Step_Outcome ) {
			return $resolved;
		}

		$attempt_no = count( $this->for_step( $history, $step ) ) + 1;

		if ( $attempt_no > self::MAX_ATTEMPTS ) {
			return $this->fail( $journey, 'attempts_exhausted' );
		}

		// The token is minted before the row is reserved so its hash goes in
		// with the reservation: the attempt row and the link in the message are
		// one thing, and a link that has reached a customer must keep working.
		$token = Token_Service::mint();

		$attempt = $this->attempts->reserve(
			array(
				'journey_id'       => $journey->id,
				'step_index'       => $step,
				'attempt_no'       => $attempt_no,
				'idempotency_key'  => Attempt::key( $journey->journey_uid, $step, $attempt_no ),
				'action_type'      => self::ID,
				'channel'          => $this->get_channel(),
				'template_name'    => substr( trim( (string) ( $parameters['template'] ?? '' ) ), 0, 191 ),
				'language_code'    => substr( trim( (string) ( $parameters['language'] ?? 'en' ) ), 0, 12 ),
				'token_hash'       => Token_Service::hash( $token ),
				'token_expires_at' => $this->clock->offset( $rules->link_ttl_seconds() ),
				'status'           => Attempt::SENDING,
			)
		);

		if ( null === $attempt ) {
			// Somebody else holds this key. They are sending, or have sent.
			return Step_Outcome::of( Step_Outcome::SKIPPED, 'reserved_elsewhere' );
		}

		return $this->deliver( $journey, $parameters, $context, $attempt, $customer, $event, $token, $history );
	}

	/**
	 * Compose and send, then act on what came back.
	 *
	 * @param Recovery_Journey    $journey    The journey.
	 * @param array<string,mixed> $parameters The step's "with" block.
	 * @param array<string,mixed> $context    Run context.
	 * @param Attempt             $attempt    The reserved attempt.
	 * @param Customer            $customer   The recipient.
	 * @param Recovery_Event      $event      What was abandoned.
	 * @param string              $token      The plaintext recovery token.
	 * @param array<int,Attempt>  $history    Every earlier attempt on this journey.
	 * @return Step_Outcome
	 */
	private function deliver(
		Recovery_Journey $journey,
		array $parameters,
		array $context,
		Attempt $attempt,
		Customer $customer,
		Recovery_Event $event,
		string $token,
		array $history
	): Step_Outcome {
		$source = $context['source'] ?? null;

		$variables = Variable_Context::build(
			$event,
			$customer,
			$this->links( $journey, $source instanceof Recovery_Source_Interface ? $source : null, $token ),
			$source instanceof Recovery_Source_Interface ? $source->get_name() : '',
			$this->logger
		);

		$request = $this->composer->compose( $journey, $customer, $parameters, $variables );

		if ( is_wp_error( $request ) ) {
			$this->attempts->mark_failed( $attempt->id, 'invalid_template', $request->get_error_message() );

			return $this->fail( $journey, 'invalid_template' );
		}

		/**
		 * Fires immediately before the request to WA.cr, after the attempt row
		 * is reserved. Observation only: the message cannot be changed here.
		 *
		 * @param Recovery_Journey     $journey   The journey.
		 * @param Attempt              $attempt   The reserved attempt.
		 * @param array<string,string> $variables The rendered variable context.
		 */
		do_action( Hooks::BEFORE_MESSAGE, $journey, $attempt, $variables->all() );

		$result = $this->client->send_template( $request );

		/**
		 * Fires immediately after the request to WA.cr, whatever the outcome.
		 *
		 * @param Recovery_Journey $journey The journey.
		 * @param Attempt          $attempt The attempt.
		 * @param Result           $result  What WA.cr answered.
		 */
		do_action( Hooks::AFTER_MESSAGE, $journey, $attempt, $result );

		if ( $result->ok ) {
			$this->attempts->mark_sent(
				$attempt->id,
				array(
					'wacr_message_id'     => self::first_string( $result, array( 'messageId', 'id' ) ),
					'provider_message_id' => self::first_string( $result, array( 'providerMessageId', 'waMessageId', 'externalId' ) ),
				)
			);

			return $this->record_sent( $journey, $context, $history, 'sent' );
		}

		return $this->handle_failure( $journey, $context, $attempt, $result->error );
	}

	/**
	 * Decide what an earlier attempt on this step means for this run.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $context Run context.
	 * @param array<int,Attempt>  $history Every attempt on this journey.
	 * @param int                 $step    The step index.
	 * @return Step_Outcome|null Null when a fresh attempt may be reserved.
	 */
	private function resume_from_history( Recovery_Journey $journey, array $context, array $history, int $step ): ?Step_Outcome {
		$claim = (string) ( $context['claim_token'] ?? '' );

		foreach ( $this->for_step( $history, $step ) as $earlier ) {
			if ( $earlier->was_sent() ) {
				// The message went out; only the journey was not advanced,
				// which is what a run dying between the two looks like.
				return $this->record_sent( $journey, $context, $history, 'already_sent' );
			}

			if ( $earlier->needs_resolution() ) {
				// A request was started and its outcome never learned. The Poll
				// stage settles that by reading the conversation. Sending again
				// here is precisely the double send this class prevents.
				return $this->defer( $journey, $claim, $this->clock->offset( self::RECHECK_AFTER ), 'awaiting_resolution' );
			}
		}

		return null;
	}

	/**
	 * Move the journey on after a message reached WA.cr.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $context Run context.
	 * @param array<int,Attempt>  $history Attempts as they were before this send.
	 * @param string              $reason  Machine-readable reason.
	 * @return Step_Outcome
	 */
	private function record_sent( Recovery_Journey $journey, array $context, array $history, string $reason ): Step_Outcome {
		$step = (int) ( $context['step_index'] ?? 0 );
		$now  = $this->clock->now();

		// Recounted rather than incremented, so a crash between the send and
		// this write cannot make the touch counter drift in either direction.
		$sent = 'already_sent' === $reason ? 0 : 1;

		foreach ( $history as $attempt ) {
			if ( $attempt->was_sent() ) {
				++$sent;
			}
		}

		$patch = array(
			'current_step'   => $step + 1,
			'next_action_at' => $context['next_action_at'] ?? null,
			'poll_at'        => $this->clock->offset( self::POLL_AFTER ),
			'attempts_count' => $sent,
		);

		if ( null === $journey->first_sent_at ) {
			$patch['first_sent_at'] = $now;
		}

		if ( ! $this->journeys->transition( $journey->id, $journey->status, Journey_State::MESSAGE_SENT, $patch, $reason ) ) {
			return Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		$journey->status       = Journey_State::MESSAGE_SENT;
		$journey->current_step = $step + 1;

		return Step_Outcome::of( Step_Outcome::SENT, $reason );
	}

	/**
	 * Sort a failure by what it says about delivery.
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

			// The batch ends either way: every remaining journey would meet the
			// same limit, and finding that out one 429 at a time helps nobody.
			return $this->touch( $journey, $claim, $stamp )
				? Step_Outcome::of( Step_Outcome::SKIPPED, 'rate_limited' )->and_stop_batch()
				: Step_Outcome::of( Step_Outcome::LOST_RACE )->and_stop_batch();
		}

		if ( $error->is_fatal_for_sending() ) {
			$this->attempts->mark_failed( $attempt->id, $error->code, $error->message );
			$this->journeys->transition( $journey->id, $journey->status, Journey_State::FAILED, $stamp, $error->code );

			// A revoked key or a plan change is about the connection, not this
			// journey; continuing would turn every scheduled journey into the
			// same failure.
			return Step_Outcome::of( Step_Outcome::FAILED, $error->code )->and_stop_batch();
		}

		if ( Error::UNKNOWN === $error->send_state ) {
			$this->attempts->mark_unknown( $attempt->id, $error->code, self::RESOLVE_AFTER );

			$stamp['next_action_at'] = $this->clock->offset( self::RECHECK_AFTER );
			$stamp['poll_at']        = $this->clock->offset( self::RESOLVE_AFTER );

			return $this->touch( $journey, $claim, $stamp )
				? Step_Outcome::of( Step_Outcome::WAITING, 'send_unknown' )
				: Step_Outcome::of( Step_Outcome::LOST_RACE );
		}

		// Everything below here is a request that definitely did not send, so
		// the attempt is closed and its unused link withdrawn.
		$this->attempts->mark_failed( $attempt->id, $error->code, $error->message );

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
	 * The attempts belonging to one step.
	 *
	 * @param array<int,Attempt> $history Every attempt on the journey.
	 * @param int                $step    The step index.
	 * @return array<int,Attempt>
	 */
	private function for_step( array $history, int $step ): array {
		$matching = array();

		foreach ( $history as $attempt ) {
			if ( $attempt->step_index === $step ) {
				$matching[] = $attempt;
			}
		}

		return $matching;
	}

	/**
	 * The links that go in the message.
	 *
	 * @param Recovery_Journey               $journey The journey.
	 * @param Recovery_Source_Interface|null $source  The source, when it is loaded.
	 * @param string                         $token   The plaintext token.
	 * @return array<string,string>
	 */
	private function links( Recovery_Journey $journey, ?Recovery_Source_Interface $source, string $token ): array {
		$recovery = Rewrites::url( $token, 'restore' );

		if ( null !== $source ) {
			try {
				$built = $source->build_recovery_url( $journey, $token );

				if ( is_string( $built ) && '' !== $built ) {
					$recovery = $built;
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

		return array(
			'recovery_url' => $recovery,
			'opt_out_url'  => Rewrites::url( $token, 'opt-out' ),
			'token'        => $token,
		);
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
	 * Used when no further message may ever be sent -- the touch limit, or a
	 * recipient who can no longer be messaged -- but the journey itself is not a
	 * failure. It keeps its state and its live recovery link, and the expiry
	 * stage closes it in its own time.
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

	/**
	 * The first of several possible fields that holds a non-empty string.
	 *
	 * WA.cr has spelled the message identifier more than one way across its own
	 * surfaces, and an attempt row with no identifier cannot be reconciled.
	 *
	 * @param Result   $result The response.
	 * @param string[] $fields Field names to try, in order.
	 * @return string|null
	 */
	private static function first_string( Result $result, array $fields ): ?string {
		foreach ( $fields as $field ) {
			$value = $result->get( $field );

			if ( is_string( $value ) && '' !== $value ) {
				return substr( $value, 0, 128 );
			}
		}

		$message = $result->get( 'message' );

		if ( is_array( $message ) && isset( $message['id'] ) && is_string( $message['id'] ) && '' !== $message['id'] ) {
			return substr( $message['id'], 0, 128 );
		}

		return null;
	}
}
