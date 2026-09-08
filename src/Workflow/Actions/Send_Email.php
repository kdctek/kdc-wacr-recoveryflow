<?php
/**
 * The action that sends a recovery email.
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
use WAcr\RecoveryFlow\Recovery\Attempt_Repository;
use WAcr\RecoveryFlow\Recovery\Channel;
use WAcr\RecoveryFlow\Recovery\Email_Sender;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Security\Token_Service;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Workflow\Email_Composer;
use WAcr\RecoveryFlow\Workflow\Send_Gate;
use WAcr\RecoveryFlow\Workflow\Step_Outcome;
use WAcr\RecoveryFlow\Workflow\Variable_Context;

defined( 'ABSPATH' ) || exit;

/**
 * One recovery email, sent at most once.
 *
 * The order is the same as the WhatsApp action's and for the same reason: the
 * gate first, so a message held back for quiet hours consumes neither an
 * attempt number nor a token; then the attempt row reserved under its unique
 * idempotency key BEFORE anything is handed to a mail transport, because that
 * row is the only thing standing between a retry and a customer receiving the
 * same reminder twice.
 *
 * Three things differ from the WhatsApp action, and each is a deliberate
 * simplification rather than an omission.
 *
 * There is no unknown outcome. An HTTP request to WA.cr can time out after the
 * body is written, genuinely leaving nobody able to say whether the message
 * went; wp_mail() either handed the message to a transport or it did not. So
 * there is no poll-back, no `needs_resolution` branch and no RECHECK -- and
 * inventing one would park recoveries waiting for a resolution that can never
 * arrive.
 *
 * There is no plan gate. Email costs the merchant nothing, goes through their
 * own mail configuration and never touches WA.cr's API, so it works on every
 * workspace including one with no API key at all. That is what stops the Lite
 * path being trialware: a shop that cannot send WhatsApp from WordPress can
 * still recover a basket.
 *
 * There is no rate budget. It is WA.cr's allowance and email does not spend it.
 */
final class Send_Email implements Action_Interface {

	/**
	 * The name a workflow refers to this by.
	 */
	public const ID = 'wacr.send_email';

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
	 * How long to wait when the site cannot send email at all.
	 */
	private const UNAVAILABLE_AFTER = 3600;

	/**
	 * Mail transport.
	 *
	 * @var Email_Sender
	 */
	private Email_Sender $mailer;

	/**
	 * Message builder.
	 *
	 * @var Email_Composer
	 */
	private Email_Composer $composer;

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
	 * The quiet-hours, touch-limit and opt-out gate.
	 *
	 * @var Send_Gate
	 */
	private Send_Gate $gate;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Log sink.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Email_Sender       $mailer   Mail transport.
	 * @param Email_Composer     $composer Message builder.
	 * @param Attempt_Repository $attempts Attempt ledger.
	 * @param Journey_Repository $journeys Journey storage.
	 * @param Send_Gate          $gate     The send gate.
	 * @param Clock              $clock    Clock.
	 * @param Logger             $logger   Log sink.
	 */
	public function __construct(
		Email_Sender $mailer,
		Email_Composer $composer,
		Attempt_Repository $attempts,
		Journey_Repository $journeys,
		Send_Gate $gate,
		Clock $clock,
		Logger $logger
	) {
		$this->mailer   = $mailer;
		$this->composer = $composer;
		$this->attempts = $attempts;
		$this->journeys = $journeys;
		$this->gate     = $gate;
		$this->clock    = $clock;
		$this->logger   = $logger;
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
		return __( 'Send an email from this site', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * The channel this action sends on.
	 *
	 * @return string
	 */
	public function get_channel(): string {
		return Channel::EMAIL;
	}

	/**
	 * Whether this site may send recovery email right now.
	 *
	 * Both gates, asked as one: the merchant's switch, and the compliance
	 * settings the law requires before commercial mail may go out at all.
	 * Rule_Set::channel_enabled() is the single home for that rule, so the
	 * editor, the status screen and this action cannot disagree about it.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return Rule_Set::for_source( null )->channel_enabled( Channel::EMAIL );
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

		/*
		 * Asked of the rules this run is working to, not of the stored settings.
		 * Rule_Set is a snapshot taken when the batch started, deliberately, so
		 * that a merchant saving a setting halfway through a pass cannot change
		 * the rules for half of it -- and is_available() reads the live ones.
		 */
		if ( ! $rules->channel_enabled( Channel::EMAIL ) ) {
			// Deferred rather than failed: settling the postal address later
			// should let the journeys that queued up in the meantime run, not
			// find them all dead.
			$this->logger->warning(
				'workflow',
				'Email reminders are not available on this site',
				array( 'blockers' => implode( ',', $rules->email_compliance_blockers() ) ),
				$journey->id
			);

			return $this->defer( $journey, $claim, $this->clock->offset( self::UNAVAILABLE_AFTER ), 'email_unavailable' );
		}

		$verdict = $this->gate->check( $journey, $customer instanceof Customer ? $customer : null, $rules, $this->get_channel() );

		if ( Send_Gate::DEFER === $verdict['decision'] ) {
			return $this->defer( $journey, $claim, $verdict['until'], $verdict['reason'] );
		}

		if ( Send_Gate::SKIP === $verdict['decision'] ) {
			return $this->halt( $journey, $claim, $verdict['reason'] );
		}

		if ( ! $customer instanceof Customer ) {
			return $this->halt( $journey, $claim, Send_Gate::REASON_RECIPIENT );
		}

		$history = $this->attempts->for_journey( $journey->id, 100 );

		foreach ( $this->for_step( $history, $step ) as $earlier ) {
			if ( $earlier->was_sent() ) {
				// The message went out; only the journey was not advanced,
				// which is what a run dying between the two looks like.
				return $this->record_sent( $journey, $context, $history, 'already_sent' );
			}
		}

		$attempt_no = count( $this->for_step( $history, $step ) ) + 1;

		if ( $attempt_no > self::MAX_ATTEMPTS ) {
			return $this->fail( $journey, 'attempts_exhausted' );
		}

		// Minted before the row is reserved so its hash goes in with the
		// reservation: the attempt row and the link in the message are one
		// thing, and a link that has reached a customer must keep working.
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
			// Somebody else holds this key. They are sending, or have sent.
			return Step_Outcome::of( Step_Outcome::SKIPPED, 'reserved_elsewhere' );
		}

		return $this->deliver( $journey, $parameters, $context, $attempt, $customer, $event, $token, $history, $rules );
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
	 * @param Rule_Set            $rules      The thresholds in force.
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
		array $history,
		Rule_Set $rules
	): Step_Outcome {
		$source = $context['source'] ?? null;

		$variables = Variable_Context::build(
			$event,
			$customer,
			$this->links( $journey, $source instanceof Recovery_Source_Interface ? $source : null, $token ),
			$source instanceof Recovery_Source_Interface ? $source->get_name() : '',
			$this->logger
		);

		$message = $this->composer->compose( $journey, $customer, $parameters, $variables, $rules->all() );

		if ( is_wp_error( $message ) ) {
			// Every refusal the composer can make -- no subject, no body, no
			// lawful footer, no address to write to -- is something only the
			// merchant can fix, so retrying would fail identically three times
			// and then give up anyway.
			$this->attempts->mark_failed( $attempt->id, $message->get_error_code(), $message->get_error_message() );

			return $this->fail( $journey, (string) $message->get_error_code() );
		}

		/**
		 * Fires immediately before the email is handed to WordPress, after the
		 * attempt row is reserved. Observation only: the message cannot be
		 * changed here.
		 *
		 * @param Recovery_Journey     $journey   The journey.
		 * @param Attempt              $attempt   The reserved attempt.
		 * @param array<string,string> $variables The rendered variable context.
		 */
		do_action( Hooks::BEFORE_MESSAGE, $journey, $attempt, $variables->all() );

		$sent = $this->mailer->send( $message, $journey->id );

		/**
		 * Fires immediately after the send, whatever the outcome.
		 *
		 * @param Recovery_Journey $journey The journey.
		 * @param Attempt          $attempt The attempt.
		 * @param true|\WP_Error   $sent    Whether WordPress accepted it.
		 */
		do_action( Hooks::AFTER_MESSAGE, $journey, $attempt, $sent );

		if ( true === $sent ) {
			$this->attempts->mark_sent( $attempt->id, array() );

			return $this->record_sent( $journey, $context, $history, 'sent' );
		}

		return $this->handle_failure( $journey, $context, $attempt, $sent );
	}

	/**
	 * Decide what a refused send means for the journey.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $context Run context.
	 * @param Attempt             $attempt The attempt that failed.
	 * @param \WP_Error           $error   Why it failed.
	 * @return Step_Outcome
	 */
	private function handle_failure( Recovery_Journey $journey, array $context, Attempt $attempt, \WP_Error $error ): Step_Outcome {
		$claim = (string) ( $context['claim_token'] ?? '' );
		$code  = substr( (string) $error->get_error_code(), 0, 32 );

		$this->attempts->mark_failed( $attempt->id, $code, $error->get_error_message() );

		$stamp = array(
			'last_error_code' => $code,
			'last_error_at'   => $this->clock->now(),
		);

		/*
		 * A refused send is retried, because the usual reason is the site's own
		 * mail transport being briefly unavailable -- expired SMTP credentials,
		 * a provider rejecting a burst -- and none of those are about this
		 * customer. What is NOT retried is a step that has used its attempts,
		 * which fails for good rather than being queued to fail again.
		 */
		if ( $attempt->attempt_no >= self::MAX_ATTEMPTS ) {
			$this->journeys->transition( $journey->id, $journey->status, Journey_State::FAILED, $stamp, $code );

			return Step_Outcome::of( Step_Outcome::FAILED, $code );
		}

		$stamp['next_action_at'] = $this->clock->offset( self::backoff( $attempt->attempt_no ) );

		return $this->touch( $journey, $claim, $stamp )
			? Step_Outcome::of( Step_Outcome::WAITING, 'retry_backoff' )
			: Step_Outcome::of( Step_Outcome::LOST_RACE );
	}

	/**
	 * Move the journey on after a message really went out.
	 *
	 * @param Recovery_Journey    $journey The journey.
	 * @param array<string,mixed> $context Run context.
	 * @param array<int,Attempt>  $history Every attempt on this journey.
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
