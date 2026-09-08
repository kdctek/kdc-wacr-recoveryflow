<?php
/**
 * Reading conversations back to learn what happened.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs\Stages;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Customer\Consent_Store;
use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Customer\Identity;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Jobs\Scheduler_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Runner;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Jobs\Time_Budget;
use WAcr\RecoveryFlow\Recovery\Attempt;
use WAcr\RecoveryFlow\Recovery\Attempt_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\Uuid;
use WAcr\RecoveryFlow\WAcr\Client;
use WAcr\RecoveryFlow\WAcr\Result;

defined( 'ABSPATH' ) || exit;

/**
 * The only way this plugin ever learns that somebody replied.
 *
 * WA.cr does not call us, so engagement, opt-outs and delivery status all come
 * from reading the conversation back. That makes this stage the plugin's ears,
 * and it makes two of its rules non-negotiable.
 *
 * A reply that is nothing but a stop word is an opt-out and is suppressed
 * immediately. The match is against the whole message, never a substring:
 * "please cancel my order" is a customer asking for help, and treating it as a
 * withdrawal of consent would silence the one channel they were using.
 *
 * An attempt whose outcome was never learned is settled by reading, never by
 * sending. A row stuck in 'sending' is not evidence that nothing went out; it
 * is evidence that a request was started and its answer was lost. Sending again
 * to find out would deliver the same reminder twice and bill the merchant
 * twice. So the conversation is read, and only a conversation that plainly does
 * not contain the message -- an hour after the fact -- is allowed to call it
 * failed.
 *
 * Polling stops seventy-two hours after the first message. Nobody replies to a
 * basket reminder three days later, and reading dead conversations for ever
 * would spend the merchant's request budget on nothing.
 */
final class Poll implements Stage_Interface {

	/**
	 * How many journeys one run leases.
	 */
	private const BATCH = 50;

	/**
	 * How many unresolved attempts one run settles.
	 */
	private const RESOLVE_BATCH = 25;

	/**
	 * Seconds set aside for a journey that makes an HTTP call.
	 */
	private const COST = 5.0;

	/**
	 * How far before a send the conversation is read from, to allow for clock drift.
	 */
	private const LOOKBACK = 60;

	/**
	 * How long after the first message polling continues.
	 */
	private const WINDOW = 72 * HOUR_IN_SECONDS;

	/**
	 * How close to a send an outbound message must be to be that send.
	 */
	private const MATCH_WINDOW = 180;

	/**
	 * How long an unsettled attempt waits before it is looked at again.
	 */
	private const RETRY_SECONDS = 600;

	/**
	 * How long an attempt missing from the conversation is given before it is called failed.
	 */
	private const GIVE_UP_AFTER = HOUR_IN_SECONDS;

	/**
	 * How long each poll waits before the next, by poll count.
	 */
	private const BACKOFF = array( 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS );

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * Attempt ledger.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * Consent.
	 *
	 * @var Consent_Store
	 */
	private Consent_Store $consent;

	/**
	 * The WA.cr client.
	 *
	 * @var Client
	 */
	private Client $client;

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
	 * @param Journey_Repository  $journeys  Journey storage.
	 * @param Attempt_Repository  $attempts  Attempt ledger.
	 * @param Customer_Repository $customers Customer storage.
	 * @param Consent_Store       $consent   Consent.
	 * @param Client              $client    WA.cr client.
	 * @param Clock               $clock     Clock.
	 * @param Logger              $logger    Logger.
	 */
	public function __construct(
		Journey_Repository $journeys,
		Attempt_Repository $attempts,
		Customer_Repository $customers,
		Consent_Store $consent,
		Client $client,
		Clock $clock,
		Logger $logger
	) {
		$this->journeys  = $journeys;
		$this->attempts  = $attempts;
		$this->customers = $customers;
		$this->consent   = $consent;
		$this->client    = $client;
		$this->clock     = $clock;
		$this->logger    = $logger;
	}

	/**
	 * The stage's key.
	 *
	 * @return string
	 */
	public function key(): string {
		return Scheduler_Interface::POLL;
	}

	/**
	 * The row in the locks table this stage runs under.
	 *
	 * @return string
	 */
	public function lock_key(): string {
		return Scheduler_Interface::POLL;
	}

	/**
	 * Read conversations for the journeys that are due, then settle stuck attempts.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @return Stage_Stats
	 */
	public function run( Time_Budget $budget ): Stage_Stats {
		$stats = new Stage_Stats( $this->key() );

		// Reading conversations needs the developer API. Without it the plugin
		// hands journeys to a WA.cr Auto Flow, which does its own listening.
		if ( ! Feature_Gate::is_enabled( Feature_Gate::ENGAGEMENT_POLL ) ) {
			return $stats;
		}

		$limit = Stage_Runner::batch_size( $this->key(), self::BATCH );
		$token = Uuid::v4();

		try {
			$claimed = $this->journeys->claim_pollable( $token, $limit );

			if ( $claimed > 0 ) {
				$batch   = $this->journeys->claimed( $token, $limit );
				$handled = 0;

				foreach ( $batch as $journey ) {
					if ( ! $budget->has_time( self::COST ) ) {
						break;
					}

					++$handled;

					$this->poll_journey( $journey, $token, $stats );
				}

				$left = max( 0, count( $batch ) - $handled );

				if ( 0 === $left && $claimed >= $limit ) {
					$left = 1;
				}

				$stats->backlog = $left;
			}//end if
		} finally {
			$this->journeys->release_claims( $token );
		}//end try

		$this->resolve_attempts( $budget, $stats );

		return $stats;
	}

	/**
	 * Read one journey's conversation and act on what it says.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $token   This run's lease.
	 * @param Stage_Stats      $stats   Counters for this run.
	 * @return void
	 */
	private function poll_journey( Recovery_Journey $journey, string $token, Stage_Stats $stats ): void {
		$customer = $this->customers->find( $journey->customer_id );

		if ( ! $customer instanceof Customer || '' === $customer->phone_e164 ) {
			// There is no conversation to read, and there never will be, so
			// this row stops asking rather than being read again every hour.
			$this->journeys->update_claimed( $journey->id, $token, $journey->status, array( 'poll_at' => null ) );

			++$stats->skipped;

			return;
		}

		$since = $this->sent_at( $journey );

		$result = $this->client->conversation( $customer->phone_e164, gmdate( 'c', max( 0, $since - self::LOOKBACK ) ) );

		if ( ! $result->ok ) {
			$stats->fail( $result->code() );

			// Still backed off, so a workspace that is refusing every read is
			// asked less and less rather than on every single run.
			$this->reschedule( $journey, $token );

			return;
		}

		$messages = $this->messages( $result );

		$this->reconcile_outbound( $journey, $messages );

		$replies = $this->inbound_after( $messages, $since );

		foreach ( $replies as $reply ) {
			if ( $this->is_opt_out( $reply ) ) {
				$this->opt_out( $journey, $customer, $stats );

				return;
			}
		}

		if ( array() !== $replies && ! $journey->is_engaged() ) {
			$this->engage( $journey, $stats );

			return;
		}

		$this->reschedule( $journey, $token );

		++$stats->processed;
	}

	/**
	 * Record that the customer replied, which stops the follow-ups.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param Stage_Stats      $stats   Counters for this run.
	 * @return void
	 */
	private function engage( Recovery_Journey $journey, Stage_Stats $stats ): void {
		$moved = $this->journeys->transition(
			$journey->id,
			$journey->status,
			Journey_State::ENGAGED,
			array(
				'engaged_at'  => $this->clock->now(),
				'engaged_via' => 'reply',
				'poll_at'     => $this->next_poll_at( $journey ),
				'poll_count'  => $journey->poll_count + 1,
			),
			'reply'
		);

		if ( $moved ) {
			++$stats->processed;

			return;
		}

		++$stats->lost_race;
	}

	/**
	 * Record that the customer asked to be left alone.
	 *
	 * @param Recovery_Journey $journey  The journey.
	 * @param Customer         $customer Who replied.
	 * @param Stage_Stats      $stats    Counters for this run.
	 * @return void
	 */
	private function opt_out( Recovery_Journey $journey, Customer $customer, Stage_Stats $stats ): void {
		// Suppression first, and unconditionally. It is keyed on the identity
		// hash rather than the journey, so it holds even if this journey has
		// already moved on underneath us -- losing a race must never lose an
		// opt-out. It covers every channel: somebody who replies STOP is
		// declining to be contacted, not switching to email.
		if ( '' !== $customer->phone_hash ) {
			$this->consent->suppress( Identity::E164, $customer->phone_hash, $customer->id, 'reply' );
		}

		$moved = $this->journeys->transition( $journey->id, $journey->status, Journey_State::OPTED_OUT, array(), 'reply_stop' );

		if ( $moved ) {
			++$stats->processed;

			return;
		}

		++$stats->lost_race;
	}

	/**
	 * Push the next read out, or stop reading altogether.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $token   This run's lease.
	 * @return void
	 */
	private function reschedule( Recovery_Journey $journey, string $token ): void {
		$this->journeys->update_claimed(
			$journey->id,
			$token,
			$journey->status,
			array(
				'poll_at'    => $this->next_poll_at( $journey ),
				'poll_count' => $journey->poll_count + 1,
			)
		);
	}

	/**
	 * When this journey should be read again.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @return string|null UTC datetime, or null to stop reading it.
	 */
	private function next_poll_at( Recovery_Journey $journey ): ?string {
		$first = null === $journey->first_sent_at ? 0 : $this->clock->parse( $journey->first_sent_at );

		if ( $first > 0 && $first + self::WINDOW < $this->clock->timestamp() ) {
			return null;
		}

		$step = min( max( 0, $journey->poll_count ), count( self::BACKOFF ) - 1 );

		return $this->clock->offset( self::BACKOFF[ $step ] );
	}

	/**
	 * When this journey's message went out, as a Unix timestamp.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @return int
	 */
	private function sent_at( Recovery_Journey $journey ): int {
		$stamp = null === $journey->first_sent_at || '' === $journey->first_sent_at
			? $journey->created_at
			: $journey->first_sent_at;

		return '' === $stamp ? 0 : $this->clock->parse( $stamp );
	}

	/**
	 * Update the attempts this conversation says something about.
	 *
	 * @param Recovery_Journey $journey  The journey.
	 * @param array<int,mixed> $messages The conversation.
	 * @return void
	 */
	private function reconcile_outbound( Recovery_Journey $journey, array $messages ): void {
		$by_id = array();

		foreach ( $this->attempts->for_journey( $journey->id, 20 ) as $attempt ) {
			if ( null !== $attempt->wacr_message_id && '' !== $attempt->wacr_message_id ) {
				$by_id[ $attempt->wacr_message_id ] = $attempt;
			}
		}

		if ( array() === $by_id ) {
			return;
		}

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || $this->is_inbound( $message ) ) {
				continue;
			}

			$id = $this->message_id( $message );

			if ( '' === $id || ! isset( $by_id[ $id ] ) ) {
				continue;
			}

			$attempt = $by_id[ $id ];
			$status  = $this->map_status( $this->message_status( $message ) );

			if ( '' === $status || $status === $attempt->status ) {
				continue;
			}

			$this->attempts->mark_status( $attempt->id, $status );
		}
	}

	/**
	 * Settle attempts whose outcome was never learned.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @param Stage_Stats $stats  Counters for this run.
	 * @return void
	 */
	private function resolve_attempts( Time_Budget $budget, Stage_Stats $stats ): void {
		$limit = Stage_Runner::batch_size( $this->key(), self::RESOLVE_BATCH );

		foreach ( $this->attempts->unresolved( $limit ) as $attempt ) {
			if ( ! $budget->has_time( self::COST ) ) {
				$stats->backlog = max( $stats->backlog, 1 );

				return;
			}

			$this->resolve( $attempt, $stats );
		}
	}

	/**
	 * Find out what became of one attempt, by reading rather than by sending.
	 *
	 * @param Attempt     $attempt The attempt.
	 * @param Stage_Stats $stats   Counters for this run.
	 * @return void
	 */
	private function resolve( Attempt $attempt, Stage_Stats $stats ): void {
		$journey  = $this->journeys->find( $attempt->journey_id );
		$customer = null === $journey ? null : $this->customers->find( $journey->customer_id );

		if ( ! $customer instanceof Customer || '' === $customer->phone_e164 ) {
			// Nothing can be read, so nothing is decided. The token is left
			// alive on purpose: if the message did reach somebody, withdrawing
			// their link would break the one thing that might still recover
			// the sale.
			$this->attempts->mark_unknown( $attempt->id, 'unreadable', self::RETRY_SECONDS );

			++$stats->skipped;

			return;
		}

		$started = $this->started_at( $attempt );
		$result  = $this->client->conversation( $customer->phone_e164, gmdate( 'c', max( 0, $started - self::LOOKBACK ) ) );

		if ( ! $result->ok ) {
			$stats->fail( $result->code() );
			$this->attempts->mark_unknown( $attempt->id, 'unread', self::RETRY_SECONDS );

			return;
		}

		$match = $this->match_outbound( $attempt, $this->messages( $result ), $started );

		if ( null !== $match ) {
			$patch      = array();
			$message_id = $this->message_id( $match );

			if ( '' !== $message_id && null === $attempt->wacr_message_id ) {
				$patch['wacr_message_id'] = $message_id;
			}

			$this->attempts->mark_sent( $attempt->id, $patch );

			$status = $this->map_status( $this->message_status( $match ) );

			if ( '' !== $status && Attempt::SENT !== $status ) {
				$this->attempts->mark_status( $attempt->id, $status );
			}

			++$stats->processed;

			return;
		}

		// A message that has been accepted but not yet written into the
		// conversation would look exactly like this, so it is given an hour
		// before it is called lost. Declaring failure early revokes a link that
		// may be on its way to somebody.
		if ( $this->clock->timestamp() - $started < self::GIVE_UP_AFTER ) {
			$this->attempts->mark_unknown( $attempt->id, 'not_found', self::RETRY_SECONDS );

			++$stats->skipped;

			return;
		}

		$this->attempts->mark_failed( $attempt->id, 'not_delivered' );

		$this->logger->warning( 'jobs', 'A send was never found in the conversation and has been recorded as failed.', array( 'attempt' => $attempt->id ), $attempt->journey_id );

		++$stats->processed;
	}

	/**
	 * When an attempt's request was started, as a Unix timestamp.
	 *
	 * @param Attempt $attempt The attempt.
	 * @return int
	 */
	private function started_at( Attempt $attempt ): int {
		$stamp = null === $attempt->sending_started_at || '' === $attempt->sending_started_at
			? $attempt->created_at
			: $attempt->sending_started_at;

		return '' === $stamp ? 0 : $this->clock->parse( $stamp );
	}

	/**
	 * The outbound message that is this attempt, if the conversation holds one.
	 *
	 * @param Attempt          $attempt  The attempt.
	 * @param array<int,mixed> $messages The conversation.
	 * @param int              $started  When the request was started.
	 * @return array<string,mixed>|null
	 */
	private function match_outbound( Attempt $attempt, array $messages, int $started ): ?array {
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || $this->is_inbound( $message ) ) {
				continue;
			}

			$id = $this->message_id( $message );

			if ( '' !== $id && null !== $attempt->wacr_message_id && $id === $attempt->wacr_message_id ) {
				return $message;
			}

			// With no id to match on, a tight window around the request is the
			// only evidence there is. It is deliberately narrow: a later
			// reminder to the same person uses the same template, and matching
			// the wrong one would mark an unsent message as sent.
			$at = $this->message_time( $message );

			if ( 0 === $at || $at < $started - self::LOOKBACK || $at > $started + self::MATCH_WINDOW ) {
				continue;
			}

			if ( null === $attempt->template_name || '' === $attempt->template_name ) {
				return $message;
			}

			if ( $this->mentions_template( $message, $attempt->template_name ) ) {
				return $message;
			}
		}//end foreach

		return null;
	}

	/**
	 * Whether an outbound message was sent from a given template.
	 *
	 * @param array<string,mixed> $message  One message.
	 * @param string              $template Template name.
	 * @return bool
	 */
	private function mentions_template( array $message, string $template ): bool {
		foreach ( array( 'template', 'templateName', 'template_name' ) as $key ) {
			if ( ! isset( $message[ $key ] ) ) {
				continue;
			}

			$value = $message[ $key ];

			if ( is_array( $value ) ) {
				$value = $value['name'] ?? '';
			}

			if ( is_string( $value ) && $value === $template ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The customer's replies that arrived after the message went out.
	 *
	 * @param array<int,mixed> $messages The conversation.
	 * @param int              $since    When the message went out.
	 * @return array<int,string> Message texts, oldest first.
	 */
	private function inbound_after( array $messages, int $since ): array {
		$replies = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! $this->is_inbound( $message ) ) {
				continue;
			}

			$at = $this->message_time( $message );

			// A message from before the send is part of an older conversation
			// and says nothing about this reminder.
			if ( $since > 0 && $at > 0 && $at < $since ) {
				continue;
			}

			$replies[] = $this->message_text( $message );
		}

		return $replies;
	}

	/**
	 * Whether a reply is a request to stop, and nothing else.
	 *
	 * @param string $text What the customer wrote.
	 * @return bool
	 */
	private function is_opt_out( string $text ): bool {
		$stripped = preg_replace( '/[^\p{L}\p{N}\s]+/u', '', $text );
		$squashed = preg_replace( '/\s+/', ' ', null === $stripped ? '' : $stripped );
		$word     = strtoupper( trim( null === $squashed ? '' : $squashed ) );

		if ( '' === $word ) {
			return false;
		}

		/**
		 * Filters the words that count as a request to stop being messaged.
		 *
		 * The whole message must be one of these. Matching a substring would
		 * read "please cancel my order" as a withdrawal of consent.
		 *
		 * @param string[] $keywords Stop words, uppercase.
		 */
		$keywords = apply_filters( Hooks::FILTER_OPTOUT_KEYWORDS, array( 'STOP', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' ) );

		foreach ( (array) $keywords as $keyword ) {
			if ( strtoupper( trim( (string) $keyword ) ) === $word ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The messages in a conversation response.
	 *
	 * @param Result $result What WA.cr answered.
	 * @return array<int,mixed>
	 */
	private function messages( Result $result ): array {
		foreach ( array( 'messages', 'data', 'items' ) as $key ) {
			$value = $result->get( $key, null );

			if ( is_array( $value ) ) {
				return $value;
			}
		}

		return array();
	}

	/**
	 * Whether a message came from the customer.
	 *
	 * An unrecognised shape is treated as outbound. Guessing "inbound" would
	 * mark somebody engaged, or opted out, on the strength of a field this
	 * plugin did not understand.
	 *
	 * @param array<string,mixed> $message One message.
	 * @return bool
	 */
	private function is_inbound( array $message ): bool {
		$direction = strtolower( $this->string_field( $message, array( 'direction', 'messageDirection', 'message_direction' ) ) );

		return in_array( $direction, array( 'inbound', 'in', 'incoming', 'received' ), true );
	}

	/**
	 * WA.cr's id for a message.
	 *
	 * @param array<string,mixed> $message One message.
	 * @return string
	 */
	private function message_id( array $message ): string {
		return $this->string_field( $message, array( 'id', 'messageId', 'message_id' ) );
	}

	/**
	 * A message's delivery status, as the API words it.
	 *
	 * @param array<string,mixed> $message One message.
	 * @return string
	 */
	private function message_status( array $message ): string {
		return $this->string_field( $message, array( 'status', 'deliveryStatus', 'delivery_status', 'state' ) );
	}

	/**
	 * A message's text.
	 *
	 * Read only to decide whether it says STOP, and never stored.
	 *
	 * @param array<string,mixed> $message One message.
	 * @return string
	 */
	private function message_text( array $message ): string {
		foreach ( array( 'text', 'body', 'message', 'content', 'caption' ) as $key ) {
			if ( ! isset( $message[ $key ] ) ) {
				continue;
			}

			$value = $message[ $key ];

			if ( is_string( $value ) ) {
				return $value;
			}

			if ( is_array( $value ) && isset( $value['body'] ) && is_string( $value['body'] ) ) {
				return $value['body'];
			}
		}

		return '';
	}

	/**
	 * When a message was sent, as a Unix timestamp.
	 *
	 * @param array<string,mixed> $message One message.
	 * @return int Zero when the message carries no readable time.
	 */
	private function message_time( array $message ): int {
		foreach ( array( 'createdAt', 'created_at', 'timestamp', 'sentAt', 'sent_at', 'time' ) as $key ) {
			if ( ! isset( $message[ $key ] ) ) {
				continue;
			}

			$value = $message[ $key ];

			if ( is_int( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
				$number = (int) $value;

				// Milliseconds are as common as seconds in message APIs, and a
				// millisecond value read as seconds lands in the year 5138.
				return $number > 100000000000 ? (int) round( $number / 1000 ) : $number;
			}

			if ( is_string( $value ) && '' !== $value ) {
				$parsed = strtotime( $value );

				if ( false !== $parsed ) {
					return $parsed;
				}
			}
		}//end foreach

		return 0;
	}

	/**
	 * The first of several possible keys that holds a string.
	 *
	 * The conversation format is read, not written, by this plugin, so the
	 * alternatives are accepted rather than assumed: a renamed field would
	 * otherwise mean "nobody ever replied", which is silent and wrong.
	 *
	 * @param array<string,mixed> $message One message.
	 * @param array<int,string>   $keys    Keys to try, in order.
	 * @return string
	 */
	private function string_field( array $message, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $message[ $key ] ) && is_string( $message[ $key ] ) ) {
				return $message[ $key ];
			}
		}

		return '';
	}

	/**
	 * Translate a delivery status into one of the attempt states.
	 *
	 * @param string $status What the API said.
	 * @return string An Attempt constant, or empty when it says nothing useful.
	 */
	private function map_status( string $status ): string {
		switch ( strtolower( $status ) ) {
			case 'delivered':
				return Attempt::DELIVERED;

			case 'read':
			case 'seen':
				return Attempt::READ;

			case 'failed':
			case 'undelivered':
			case 'error':
				return Attempt::FAILED;

			case 'sent':
			case 'accepted':
				return Attempt::SENT;

			default:
				return '';
		}
	}
}
