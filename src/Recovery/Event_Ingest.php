<?php
/**
 * The one way an adapter reports a lost conversion.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Customer\Consent_Store;
use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Customer\Identity_Resolver;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The front door for adapters, and the boundary they must not reach past.
 *
 * Everything here runs inside a shopper's own page request, so it does the
 * least work that is correct: resolve who they are if the contact details have
 * changed, write one row, and stop. Deciding whether the basket is worth
 * chasing, creating a journey and composing a message all happen later on the
 * background tick, where taking a second longer costs nobody a slow page.
 *
 * Nothing in here is allowed to throw. A recovery plugin that breaks a checkout
 * because its own table is missing has done far more damage than the sale it
 * was trying to save, so failures are logged and swallowed.
 */
final class Event_Ingest {

	/**
	 * Event storage.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Identity resolution.
	 *
	 * @var Identity_Resolver
	 */
	private Identity_Resolver $identity;

	/**
	 * Consent.
	 *
	 * @var Consent_Store
	 */
	private Consent_Store $consent;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository  $events   Event storage.
	 * @param Identity_Resolver $identity Identity resolution.
	 * @param Consent_Store     $consent  Consent.
	 * @param Logger            $logger   Logger.
	 */
	public function __construct( Event_Repository $events, Identity_Resolver $identity, Consent_Store $consent, Logger $logger ) {
		$this->events   = $events;
		$this->identity = $identity;
		$this->consent  = $consent;
		$this->logger   = $logger;
	}

	/**
	 * Record what an adapter saw.
	 *
	 * @param Event_Draft $draft What the adapter reported.
	 * @return int The event row id, or 0 if nothing was written.
	 */
	public function ingest( Event_Draft $draft ): int {
		try {
			return $this->write( $draft );
		} catch ( \Throwable $e ) {
			// A tracking failure must never surface in a shopper's request.
			$this->logger->error(
				'ingest',
				'Could not record a recovery event.',
				array( 'source' => $draft->source_id )
			);

			return 0;
		}
	}

	/**
	 * Do the work ingest() guards.
	 *
	 * @param Event_Draft $draft What the adapter reported.
	 * @return int Event row id.
	 */
	private function write( Event_Draft $draft ): int {
		/**
		 * Filters a recovery event before it is recorded.
		 *
		 * Return null to drop it. The draft carries no personal data except in
		 * its identity hints.
		 *
		 * @param Event_Draft|null $draft The draft.
		 */
		$draft = apply_filters( 'recoveryflow_pre_ingest_event', $draft );

		if ( ! $draft instanceof Event_Draft ) {
			return 0;
		}

		$event_id = $this->events->upsert( $draft );

		if ( 0 === $event_id ) {
			$this->logger->warning( 'ingest', 'A recovery event could not be written.', array( 'source' => $draft->source_id ) );

			return 0;
		}

		$customer = $this->attach_identity( $event_id, $draft );

		if ( null !== $customer && null !== $draft->identity->consent ) {
			$this->consent->record(
				$customer,
				Channel::WHATSAPP,
				$draft->identity->consent,
				'' === $draft->identity->consent_source ? $draft->source_id : $draft->identity->consent_source,
				$draft->identity->consent_text_version,
				$this->client_ip()
			);
		}

		/**
		 * Fires when a recovery event has been recorded or updated.
		 *
		 * @param int         $event_id The event row id.
		 * @param Event_Draft $draft    What the adapter reported.
		 */
		do_action( Hooks::EVENT_CREATED, $event_id, $draft );

		return $event_id;
	}

	/**
	 * Resolve who this is and attach them to the event.
	 *
	 * @param int         $event_id Event row id.
	 * @param Event_Draft $draft    The draft.
	 * @return Customer|null
	 */
	private function attach_identity( int $event_id, Event_Draft $draft ): ?Customer {
		if ( ! $draft->identity->has_identity() ) {
			return null;
		}

		$customer = $this->identity->resolve( $draft->identity );

		if ( null === $customer ) {
			return null;
		}

		$this->events->attach_customer( $event_id, $customer->id );

		return $customer;
	}

	/**
	 * Close the open event behind an adapter key.
	 *
	 * @param string $source_id  Adapter id.
	 * @param string $dedupe_key Adapter key.
	 * @param string $status     One of the Recovery_Event constants.
	 * @param string $reason     Short machine-readable reason.
	 * @return bool
	 */
	public function close( string $source_id, string $dedupe_key, string $status, string $reason = '' ): bool {
		$event = $this->events->find_open( $source_id, $dedupe_key );

		if ( null === $event || ! $event->is_open() ) {
			return false;
		}

		$closed = $this->events->close( $event->id, $status, $reason );

		if ( $closed && Recovery_Event::COMPLETED === $status ) {
			/**
			 * Fires when the thing an event described was completed.
			 *
			 * @param int $event_id Event row id.
			 */
			do_action( Hooks::EVENT_COMPLETED, $event->id );
		}

		return $closed;
	}

	/**
	 * Move an open event onto a new adapter key.
	 *
	 * @param string $source_id   Adapter id.
	 * @param string $old_key     Current key.
	 * @param string $new_key     New key.
	 * @param string $new_session Session identifier, or empty to leave it.
	 * @return bool
	 */
	public function rekey( string $source_id, string $old_key, string $new_key, string $new_session = '' ): bool {
		try {
			return $this->events->rekey( $source_id, $old_key, $new_key, $new_session );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'ingest', 'Could not move a recovery event to its new key.', array( 'source' => $source_id ) );

			return false;
		}
	}

	/**
	 * Event storage, for adapters that need to read what they wrote.
	 *
	 * @return Event_Repository
	 */
	public function events(): Event_Repository {
		return $this->events;
	}

	/**
	 * The caller's IP address, for the consent record.
	 *
	 * Never stored raw: the consent ledger keeps a keyed hash, which is enough
	 * to corroborate a record and useless for tracking anybody.
	 *
	 * @return string
	 */
	private function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return false === filter_var( $remote, FILTER_VALIDATE_IP ) ? '' : $remote;
	}
}
