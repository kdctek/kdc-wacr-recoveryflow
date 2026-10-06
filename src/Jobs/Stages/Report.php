<?php
/**
 * The report stage: sends queued journey milestones to GA4.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs\Stages;

use WAcr\RecoveryFlow\Analytics\Ga4_Settings;
use WAcr\RecoveryFlow\Analytics\Journey_Reporter;
use WAcr\RecoveryFlow\Analytics\Measurement_Protocol;
use WAcr\RecoveryFlow\Analytics\Queue_Repository;
use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Jobs\Scheduler_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Runner;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Jobs\Time_Budget;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * Drains the analytics queue into the merchant's GA4 property.
 *
 * Runs after Expire, so a journey that expired in this tick is reported in it.
 * Does nothing at all -- not a query -- unless reporting is switched on and
 * configured; the rows a merchant queued before switching it off simply age
 * into `stale` and are pruned.
 *
 * Rows are grouped by browser, because Google takes up to 25 events per
 * request but only for one client id. A row whose event no longer has a client
 * id -- the shopper was erased, or the basket was anonymised -- is dropped
 * unsent.
 */
final class Report implements Stage_Interface {

	/**
	 * Rows per batch.
	 */
	private const BATCH = 50;

	/**
	 * Seconds a lease on queue rows lasts.
	 */
	private const CLAIM_TTL = 120;

	/**
	 * The oldest milestone still sent, in hours. Google backdates up to 72
	 * hours and silently re-stamps anything older to "72 hours ago", which
	 * would put an expiry on the wrong day, so the cut-off leaves an hour of
	 * margin for the queue itself.
	 */
	public const MAX_AGE_HOURS = 71;

	/**
	 * The queue.
	 *
	 * @var Queue_Repository
	 */
	private Queue_Repository $queue;

	/**
	 * Decides what each milestone says.
	 *
	 * @var Journey_Reporter
	 */
	private Journey_Reporter $reporter;

	/**
	 * The Measurement Protocol client.
	 *
	 * @var Measurement_Protocol
	 */
	private Measurement_Protocol $client;

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
	 * @param Queue_Repository     $queue    The queue.
	 * @param Journey_Reporter     $reporter Decides what each milestone says.
	 * @param Measurement_Protocol $client   The Measurement Protocol client.
	 * @param Journey_Repository   $journeys Journey storage.
	 * @param Event_Repository     $events   Event storage.
	 * @param Clock                $clock    Clock.
	 * @param Logger               $logger   Logger.
	 */
	public function __construct(
		Queue_Repository $queue,
		Journey_Reporter $reporter,
		Measurement_Protocol $client,
		Journey_Repository $journeys,
		Event_Repository $events,
		Clock $clock,
		Logger $logger
	) {
		$this->queue    = $queue;
		$this->reporter = $reporter;
		$this->client   = $client;
		$this->journeys = $journeys;
		$this->events   = $events;
		$this->clock    = $clock;
		$this->logger   = $logger;
	}

	/**
	 * The stage's key.
	 *
	 * @return string
	 */
	public function key(): string {
		return Scheduler_Interface::REPORT;
	}

	/**
	 * The row in the locks table this stage runs under.
	 *
	 * @return string
	 */
	public function lock_key(): string {
		return Scheduler_Interface::REPORT;
	}

	/**
	 * Send what is queued, until the queue or the time runs out.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @return Stage_Stats
	 */
	public function run( Time_Budget $budget ): Stage_Stats {
		$stats = new Stage_Stats( $this->key() );

		if ( ! Ga4_Settings::is_reporting() ) {
			return $stats;
		}

		$stats->skipped += $this->queue->mark_stale( $this->clock->offset( -self::MAX_AGE_HOURS * HOUR_IN_SECONDS ) );

		$url   = Measurement_Protocol::url( Ga4_Settings::region(), Ga4_Settings::measurement_id(), Ga4_Settings::api_secret() );
		$limit = Stage_Runner::batch_size( $this->key(), self::BATCH );

		while ( $budget->has_time( 8.0 ) ) {
			$token = Uuid::v4();

			if ( 0 === $this->queue->claim( $token, $limit, self::CLAIM_TTL ) ) {
				return $stats;
			}

			$rows = $this->queue->claimed( $token, $limit );

			$this->send_batch( $rows, $token, $url, $budget, $stats );
			$this->queue->release( $token );

			if ( count( $rows ) < $limit ) {
				return $stats;
			}
		}

		$stats->backlog = max( $stats->backlog, 1 );

		return $stats;
	}

	/**
	 * Group one claimed batch by browser and send each group.
	 *
	 * @param array<int,array{id:int,journey_id:int,event_name:string,occurred_at:string,attempts:int}> $rows   Claimed rows.
	 * @param string                                                                                    $token  This run's claim token.
	 * @param string                                                                                    $url    Collection URL.
	 * @param Time_Budget                                                                               $budget How long there is.
	 * @param Stage_Stats                                                                               $stats  Counters for this run.
	 * @return void
	 */
	private function send_batch( array $rows, string $token, string $url, Time_Budget $budget, Stage_Stats $stats ): void {
		$groups  = array();
		$dropped = array();

		foreach ( $rows as $row ) {
			$journey = $this->journeys->find( $row['journey_id'] );
			$event   = null === $journey ? null : $this->events->find( $journey->event_id );

			if ( null === $journey || null === $event || null === $event->ga_client_id ) {
				$dropped[] = $row['id'];
				continue;
			}

			$ga_event = $this->reporter->event_for( $row['event_name'], $row['occurred_at'], $journey, $event );

			if ( null === $ga_event ) {
				$dropped[] = $row['id'];
				continue;
			}

			$group = $event->ga_client_id . '|' . ( $event->ga_ads_denied ? '1' : '0' );

			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = array(
					'client_id'  => $event->ga_client_id,
					'ads_denied' => $event->ga_ads_denied,
					'ids'        => array(),
					'events'     => array(),
				);
			}

			$groups[ $group ]['ids'][]    = $row['id'];
			$groups[ $group ]['events'][] = $ga_event;
		}//end foreach

		$stats->skipped += $this->queue->finish( $dropped, Queue_Repository::DROPPED );

		foreach ( $groups as $group ) {
			foreach ( array_chunk( $group['ids'], Measurement_Protocol::MAX_EVENTS ) as $index => $ids ) {
				// Each request may take its full timeout; stop while there is
				// still time to record the answer to the last one.
				if ( ! $budget->has_time( 8.0 ) ) {
					$stats->backlog = max( $stats->backlog, 1 );

					return;
				}

				$events = array_slice( $group['events'], $index * Measurement_Protocol::MAX_EVENTS, Measurement_Protocol::MAX_EVENTS );

				$this->send_group( $ids, $events, $group['client_id'], $group['ads_denied'], $token, $url, $stats );
			}
		}
	}

	/**
	 * Send one request's worth of events for one browser.
	 *
	 * @param int[]                          $ids        Queue row ids, in the same order as the events.
	 * @param array<int,array<string,mixed>> $events     Events.
	 * @param string                         $client_id  The browser.
	 * @param bool                           $ads_denied Whether marketing use was refused.
	 * @param string                         $token      This run's claim token.
	 * @param string                         $url        Collection URL.
	 * @param Stage_Stats                    $stats      Counters for this run.
	 * @return void
	 */
	private function send_group( array $ids, array $events, string $client_id, bool $ads_denied, string $token, string $url, Stage_Stats $stats ): void {
		// Marked before the request goes out, so a run that dies mid-request
		// leaves these where no later run will send them a second time.
		if ( $this->queue->begin_sending( $ids, $token ) !== count( $ids ) ) {
			++$stats->lost_race;

			return;
		}

		$outcome = $this->client->send( $url, Measurement_Protocol::payload( $client_id, $events, $ads_denied ) );

		switch ( $outcome ) {
			case Measurement_Protocol::OUTCOME_SENT:
				$stats->processed += $this->queue->finish( $ids, Queue_Repository::SENT );
				break;

			case Measurement_Protocol::OUTCOME_RETRY:
				$this->queue->retry( $ids );
				++$stats->failed;
				break;

			case Measurement_Protocol::OUTCOME_UNKNOWN:
				$this->queue->finish( $ids, Queue_Repository::UNKNOWN );
				++$stats->failed;
				break;

			default:
				$this->queue->finish( $ids, Queue_Repository::DROPPED );
				++$stats->failed;
				$stats->last_error = 'ga4_rejected';

				$this->logger->warning( 'analytics', 'Google Analytics refused a report. Check the Measurement ID and API secret in Settings › Analytics.' );
				break;
		}//end switch
	}
}
