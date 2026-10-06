<?php
/**
 * Decides which journey milestones are reported to GA4, and what each one says.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Analytics;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Workflow\Workflow_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Four milestones, and only for journeys that actually sent a message.
 *
 * | Event                    | When                                   | What a merchant does with it      |
 * | ------------------------ | -------------------------------------- | --------------------------------- |
 * | recoveryflow_messaged    | the first message of a journey is sent | pause ads while WhatsApp works    |
 * | recoveryflow_recovered   | the order that recovered it is paid    | exclude: WhatsApp already won     |
 * | recoveryflow_expired     | it ran out of time unrecovered         | the retargeting audience          |
 * | recoveryflow_opted_out   | the shopper said stop                  | keep them out of ads too          |
 *
 * **Only messaged journeys.** A basket nobody was ever messaged about says
 * nothing GA4's own begin_checkout has not already said, and in the default
 * explicit-consent mode "messaged" also means the shopper ticked the recovery
 * box, so every report is about somebody already in a consented relationship.
 *
 * **Queued here, sent by the report stage.** Two of these moments happen in a
 * shopper's own request -- the paid order and the unsubscribe -- and a call to
 * Google there would make them wait on it. Queuing is one INSERT IGNORE.
 *
 * **Expired has no hook.** Expiry is one bulk UPDATE in Journey_Repository, so
 * it is reported from the Expire stage's tidy pass, which visits every expired
 * journey one at a time by its present state.
 */
final class Journey_Reporter {

	public const MESSAGED  = 'recoveryflow_messaged';
	public const RECOVERED = 'recoveryflow_recovered';
	public const EXPIRED   = 'recoveryflow_expired';
	public const OPTED_OUT = 'recoveryflow_opted_out';

	/**
	 * The queue.
	 *
	 * @var Queue_Repository
	 */
	private Queue_Repository $queue;

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
	 * Workflow storage, for the workflow's slug.
	 *
	 * @var Workflow_Repository
	 */
	private Workflow_Repository $workflows;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Workflow slugs already looked up, by id.
	 *
	 * @var array<int,string>
	 */
	private array $slugs = array();

	/**
	 * Constructor.
	 *
	 * @param Queue_Repository    $queue     The queue.
	 * @param Journey_Repository  $journeys  Journey storage.
	 * @param Event_Repository    $events    Event storage.
	 * @param Workflow_Repository $workflows Workflow storage.
	 * @param Clock               $clock     Clock.
	 */
	public function __construct( Queue_Repository $queue, Journey_Repository $journeys, Event_Repository $events, Workflow_Repository $workflows, Clock $clock ) {
		$this->queue     = $queue;
		$this->journeys  = $journeys;
		$this->events    = $events;
		$this->workflows = $workflows;
		$this->clock     = $clock;
	}

	/**
	 * Attach to the journey hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( Hooks::MESSAGE_SENT, array( $this, 'on_message_sent' ), 20, 1 );
		add_action( Hooks::JOURNEY_RECOVERED, array( $this, 'on_recovered' ), 20, 1 );
		add_action( Hooks::JOURNEY_OPTED_OUT, array( $this, 'on_opted_out' ), 20, 1 );
	}

	/**
	 * A message went out. Only the first one is reported; the queue's unique
	 * key turns the rest away, because the hook fires on every step's send.
	 *
	 * @param mixed $journey_id Journey id.
	 * @return void
	 */
	public function on_message_sent( $journey_id ): void {
		$this->queue_for( (int) $journey_id, self::MESSAGED );
	}

	/**
	 * The journey's order was paid.
	 *
	 * @param mixed $journey_id Journey id.
	 * @return void
	 */
	public function on_recovered( $journey_id ): void {
		$this->queue_for( (int) $journey_id, self::RECOVERED );
	}

	/**
	 * The shopper stopped the reminders.
	 *
	 * @param mixed $journey_id Journey id.
	 * @return void
	 */
	public function on_opted_out( $journey_id ): void {
		$this->queue_for( (int) $journey_id, self::OPTED_OUT );
	}

	/**
	 * A journey ran out of time. Called by the Expire stage's tidy pass.
	 *
	 * A journey that expired without ever sending a message is never reported,
	 * so its client id has no further use and is forgotten here.
	 *
	 * @param int $journey_id Journey id.
	 * @param int $event_id   Its event id.
	 * @return void
	 */
	public function on_expired( int $journey_id, int $event_id ): void {
		$journey = $this->journeys->find( $journey_id );

		if ( null !== $journey && null === $journey->first_sent_at ) {
			$this->events->forget_ga_client_id( $event_id );

			return;
		}

		$this->queue_for( $journey_id, self::EXPIRED, $journey );
	}

	/**
	 * Queue a milestone, if this journey is one that is reported.
	 *
	 * Never throws: a reporting fault must not interrupt the transition that
	 * triggered it, which may be a shopper's order.
	 *
	 * @param int                   $journey_id Journey id.
	 * @param string                $name       Event name.
	 * @param Recovery_Journey|null $journey    The journey, when the caller already has it.
	 * @return bool Whether it was queued by this call.
	 */
	public function queue_for( int $journey_id, string $name, ?Recovery_Journey $journey = null ): bool {
		if ( $journey_id < 1 || ! Ga4_Settings::is_reporting() ) {
			return false;
		}

		try {
			$journey = $journey ?? $this->journeys->find( $journey_id );

			if ( null === $journey || null === $journey->first_sent_at ) {
				return false;
			}

			$event = $this->events->find( $journey->event_id );

			if ( null === $event || null === $event->ga_client_id ) {
				return false;
			}

			return $this->queue->enqueue( $journey_id, $name, $this->occurred_at( $journey, $name ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * When a milestone happened, from the journey's own columns.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $name    Event name.
	 * @return string UTC datetime.
	 */
	private function occurred_at( Recovery_Journey $journey, string $name ): string {
		switch ( $name ) {
			case self::MESSAGED:
				return (string) ( $journey->first_sent_at ?? $this->clock->now() );

			case self::RECOVERED:
				return (string) ( $journey->recovered_at ?? $this->clock->now() );

			case self::EXPIRED:
				return '' !== $journey->updated_at ? $journey->updated_at : $this->clock->now();

			default:
				return $this->clock->now();
		}
	}

	/**
	 * The event to send for one queued milestone, or null when it must not be sent.
	 *
	 * @param string           $name        Event name.
	 * @param string           $occurred_at When it happened, UTC.
	 * @param Recovery_Journey $journey     The journey.
	 * @param Recovery_Event   $event       Its event.
	 * @return array<string,mixed>|null
	 */
	public function event_for( string $name, string $occurred_at, Recovery_Journey $journey, Recovery_Event $event ): ?array {
		$params = array(
			'journey_ref' => $journey->journey_uid,
			'workflow'    => $this->workflow_slug( $journey->workflow_id ),
			'source'      => $journey->source_id,
		);

		/*
		 * Only a recovery carries GA4's own `value`: GA4 sums `value` as money
		 * when an event is marked as a key event, and an expired basket is
		 * money that did not arrive. Its worth goes in `basket_value`, which an
		 * audience condition can still read ("expired baskets over 100").
		 */
		if ( self::RECOVERED === $name && null !== $journey->recovered_amount && '' !== $event->currency ) {
			$params['value']    = round( (float) $journey->recovered_amount, 2 );
			$params['currency'] = $event->currency;
		} elseif ( self::EXPIRED === $name && '' !== $event->currency ) {
			$params['basket_value'] = round( (float) $event->amount, 2 );
			$params['currency']     = $event->currency;
		}

		/**
		 * Filters one GA4 event before it is sent, or stops it being sent.
		 *
		 * The params never contain personal data, and nothing a filter adds
		 * should either: Google's terms forbid it outside hashed user_data.
		 *
		 * @param array<string,mixed>|null $params  journey_ref, workflow, source; value + currency on a recovery; basket_value + currency on an expiry. Return null to send nothing.
		 * @param string                   $name    recoveryflow_messaged, _recovered, _expired or _opted_out.
		 * @param Recovery_Journey         $journey The journey.
		 */
		$params = apply_filters( Hooks::FILTER_GA4_EVENT, $params, $name, $journey );

		if ( ! is_array( $params ) ) {
			return null;
		}

		$micros = $this->clock->parse( $occurred_at );

		return Measurement_Protocol::event( $name, $params, $micros > 0 ? $micros * 1000000 : 0 );
	}

	/**
	 * A workflow's slug, looked up once per run.
	 *
	 * @param int $workflow_id Workflow id.
	 * @return string
	 */
	private function workflow_slug( int $workflow_id ): string {
		if ( ! isset( $this->slugs[ $workflow_id ] ) ) {
			$workflow = $this->workflows->find( $workflow_id );

			$this->slugs[ $workflow_id ] = null !== $workflow && '' !== $workflow->slug ? $workflow->slug : 'workflow-' . $workflow_id;
		}

		return $this->slugs[ $workflow_id ];
	}
}
