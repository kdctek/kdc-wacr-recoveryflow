<?php
/**
 * Answering "what do you hold about me?".
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Privacy;

use WAcr\RecoveryFlow\Customer\Consent_Repository;
use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Customer\Identity;
use WAcr\RecoveryFlow\Customer\Identity_Repository;
use WAcr\RecoveryFlow\Recovery\Attempt_Repository;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds RecoveryFlow's records into WordPress's personal data export.
 *
 * The request arrives as an email address, because that is the only handle
 * WordPress's privacy tools have. RecoveryFlow does not store people by email
 * -- it stores identities as rows, and an address is deliberately a weak key
 * that two people can share -- so the export starts by finding every customer
 * reachable from that address and then speaks for each of them in turn.
 *
 * Two consequences of that are worth stating plainly, because they are the kind
 * of thing a privacy officer will ask about:
 *
 * - **A phone number is exported when it is attached to a customer the address
 *   found.** Somebody asking what a shop holds about them is entitled to the
 *   number as well as the address, and hiding it because they asked by email
 *   would answer a narrower question than the one they asked.
 * - **Values are exported in full, not masked.** Masking exists to stop a
 *   passer-by reading a shop screen. It has no place in an export that is going
 *   to the person the data is about; a redacted copy of your own data is not a
 *   copy of your own data.
 *
 * Exported one customer per page. A shared address really can find several
 * people, and a single request that tried to render all of them is the request
 * that times out on the one site where it matters.
 */
final class Exporter {

	/**
	 * The group WordPress files this data under.
	 */
	public const GROUP = 'recoveryflow';

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * Identity storage.
	 *
	 * @var Identity_Repository
	 */
	private Identity_Repository $identities;

	/**
	 * Consent ledger.
	 *
	 * @var Consent_Repository
	 */
	private Consent_Repository $consents;

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
	 * Attempt ledger.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

	/**
	 * Constructor.
	 *
	 * @param Customer_Repository $customers  Customer storage.
	 * @param Identity_Repository $identities Identity storage.
	 * @param Consent_Repository  $consents   Consent ledger.
	 * @param Journey_Repository  $journeys   Journey storage.
	 * @param Event_Repository    $events     Event storage.
	 * @param Attempt_Repository  $attempts   Attempt ledger.
	 */
	public function __construct(
		Customer_Repository $customers,
		Identity_Repository $identities,
		Consent_Repository $consents,
		Journey_Repository $journeys,
		Event_Repository $events,
		Attempt_Repository $attempts
	) {
		$this->customers  = $customers;
		$this->identities = $identities;
		$this->consents   = $consents;
		$this->journeys   = $journeys;
		$this->events     = $events;
		$this->attempts   = $attempts;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register' ) );
	}

	/**
	 * Register this exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Cart and checkout recovery', 'kdc-wacr-recoveryflow' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Export one page of RecoveryFlow's records for an address.
	 *
	 * @param string $email_address The address being asked about.
	 * @param int    $page          One-based page number.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$found = $this->customers->find_all_by_email_hash(
			Identity_Repository::hash_for( Identity::EMAIL, $email_address )
		);

		$page  = max( 1, $page );
		$index = $page - 1;

		if ( ! isset( $found[ $index ] ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$customer = $found[ $index ];

		return array(
			'data' => array_merge(
				array( $this->customer_item( $customer ) ),
				$this->identity_items( $customer ),
				$this->consent_items( $customer ),
				$this->journey_items( $customer )
			),
			'done' => ! isset( $found[ $index + 1 ] ),
		);
	}

	/**
	 * The person, as this plugin knows them.
	 *
	 * @param Customer $customer The customer.
	 * @return array<string,mixed>
	 */
	private function customer_item( Customer $customer ): array {
		$data = array(
			$this->pair( __( 'First name', 'kdc-wacr-recoveryflow' ), $customer->first_name ),
			$this->pair( __( 'Last name', 'kdc-wacr-recoveryflow' ), $customer->last_name ),
			$this->pair( __( 'Country', 'kdc-wacr-recoveryflow' ), $customer->country_iso2 ),
			$this->pair( __( 'First seen', 'kdc-wacr-recoveryflow' ), $customer->created_at ),
		);

		if ( null !== $customer->anonymized_at ) {
			$data[] = $this->pair( __( 'Anonymised on', 'kdc-wacr-recoveryflow' ), (string) $customer->anonymized_at );
		}

		return $this->item(
			'customer-' . $customer->id,
			__( 'Recovery record', 'kdc-wacr-recoveryflow' ),
			__( 'What this shop recorded in order to remind you about an unfinished order.', 'kdc-wacr-recoveryflow' ),
			$data
		);
	}

	/**
	 * Every way this shop could reach them.
	 *
	 * @param Customer $customer The customer.
	 * @return array<int,array<string,mixed>>
	 */
	private function identity_items( Customer $customer ): array {
		$items = array();

		foreach ( $this->identities->for_customer( $customer->id ) as $row ) {
			$kind  = (string) ( $row['kind'] ?? '' );
			$value = (string) ( $row['value_raw'] ?? '' );

			if ( '' === $value ) {
				// Blanked by an erasure or by retention. Saying so is more
				// useful than omitting the row, which would read as this shop
				// never having held it.
				$value = __( 'Removed', 'kdc-wacr-recoveryflow' );
			}

			$items[] = $this->item(
				'identity-' . (int) ( $row['id'] ?? 0 ),
				__( 'Contact detail', 'kdc-wacr-recoveryflow' ),
				__( 'A way this shop was able to reach you about an unfinished order.', 'kdc-wacr-recoveryflow' ),
				array(
					$this->pair( __( 'Type', 'kdc-wacr-recoveryflow' ), Identity::label( $kind ) ),
					$this->pair( __( 'Value', 'kdc-wacr-recoveryflow' ), $value ),
					$this->pair( __( 'Recorded', 'kdc-wacr-recoveryflow' ), (string) ( $row['created_at'] ?? '' ) ),
				)
			);
		}//end foreach

		return $items;
	}

	/**
	 * What they agreed to, and when they changed their mind.
	 *
	 * The consent ledger is the part of this export that answers the question
	 * behind the request: not only what the shop knows, but on what basis it
	 * ever messaged them.
	 *
	 * @param Customer $customer The customer.
	 * @return array<int,array<string,mixed>>
	 */
	private function consent_items( Customer $customer ): array {
		$items = array();

		foreach ( $this->identities->for_customer( $customer->id ) as $row ) {
			$kind = (string) ( $row['kind'] ?? '' );
			$hash = (string) ( $row['value_hash'] ?? '' );

			foreach ( $this->consents->history( $kind, $hash ) as $consent ) {
				$items[] = $this->item(
					'consent-' . (int) ( $consent['id'] ?? 0 ),
					__( 'Messaging permission', 'kdc-wacr-recoveryflow' ),
					__( 'A record of whether you agreed to be reminded about unfinished orders, and on which channel.', 'kdc-wacr-recoveryflow' ),
					array(
						$this->pair( __( 'Channel', 'kdc-wacr-recoveryflow' ), (string) ( $consent['channel'] ?? '' ) ),
						$this->pair( __( 'Decision', 'kdc-wacr-recoveryflow' ), $this->consent_label( (string) ( $consent['status'] ?? '' ) ) ),
						$this->pair( __( 'Recorded at', 'kdc-wacr-recoveryflow' ), (string) ( $consent['created_at'] ?? '' ) ),
						$this->pair( __( 'Recorded from', 'kdc-wacr-recoveryflow' ), (string) ( $consent['source'] ?? '' ) ),
						$this->pair( __( 'Wording agreed to', 'kdc-wacr-recoveryflow' ), (string) ( $consent['text_version'] ?? '' ) ),
					)
				);
			}
		}

		return $items;
	}

	/**
	 * Each unfinished order this shop chased, and what it did about it.
	 *
	 * @param Customer $customer The customer.
	 * @return array<int,array<string,mixed>>
	 */
	private function journey_items( Customer $customer ): array {
		$items = array();

		foreach ( $this->journeys->all_for_customer( $customer->id ) as $journey ) {
			$event = $journey->event_id > 0 ? $this->events->find( $journey->event_id ) : null;

			$data = array(
				$this->pair( __( 'Started', 'kdc-wacr-recoveryflow' ), $journey->created_at ),
				$this->pair( __( 'Outcome', 'kdc-wacr-recoveryflow' ), Journey_State::label( $journey->status ) ),
				$this->pair( __( 'Reminders sent', 'kdc-wacr-recoveryflow' ), (string) $journey->attempts_count ),
			);

			if ( null !== $event ) {
				$data[] = $this->pair( __( 'Basket value', 'kdc-wacr-recoveryflow' ), $event->amount . ' ' . $event->currency );
				$data[] = $this->pair( __( 'Items', 'kdc-wacr-recoveryflow' ), $event->items_summary( 20 ) );
			}

			foreach ( $this->attempts->for_journey( $journey->id ) as $attempt ) {
				$data[] = $this->pair(
					__( 'Reminder', 'kdc-wacr-recoveryflow' ),
					sprintf(
						/* translators: 1: the channel a reminder was sent on. 2: what happened to it. 3: when it was sent. */
						__( '%1$s, %2$s, %3$s', 'kdc-wacr-recoveryflow' ),
						$attempt->channel,
						$attempt->status,
						(string) $attempt->created_at
					)
				);
			}

			$items[] = $this->item(
				'journey-' . $journey->id,
				__( 'Unfinished order', 'kdc-wacr-recoveryflow' ),
				__( 'An order you started and did not complete, and the reminders this shop sent about it.', 'kdc-wacr-recoveryflow' ),
				$data
			);
		}//end foreach

		return $items;
	}

	/**
	 * What a stored consent status means, in words.
	 *
	 * The stored value is a machine code and stays one. This is the label, made
	 * at the moment of rendering, which is the only place a code should ever
	 * become a sentence.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	private function consent_label( string $status ): string {
		switch ( $status ) {
			case Consent_Repository::GRANTED:
				return __( 'You agreed to be reminded', 'kdc-wacr-recoveryflow' );

			case Consent_Repository::DENIED:
				return __( 'You were asked and did not agree', 'kdc-wacr-recoveryflow' );

			case Consent_Repository::WITHDRAWN:
				return __( 'You withdrew your agreement', 'kdc-wacr-recoveryflow' );

			case Consent_Repository::SUPPRESSED:
				return __( 'You asked to stop receiving reminders', 'kdc-wacr-recoveryflow' );

			default:
				return __( 'No decision recorded', 'kdc-wacr-recoveryflow' );
		}
	}

	/**
	 * One export item in the shape WordPress wants.
	 *
	 * @param string                         $id          Item id, unique within the group.
	 * @param string                         $label       Group label.
	 * @param string                         $description Group description.
	 * @param array<int,array<string,mixed>> $data    Name/value pairs.
	 * @return array<string,mixed>
	 */
	private function item( string $id, string $label, string $description, array $data ): array {
		return array(
			'group_id'          => self::GROUP . '-' . sanitize_key( $label ),
			'group_label'       => $label,
			'group_description' => $description,
			'item_id'           => $id,
			'data'              => array_values( array_filter( $data ) ),
		);
	}

	/**
	 * One name/value pair, dropped when there is nothing to say.
	 *
	 * @param string $name  Field name.
	 * @param string $value Field value.
	 * @return array<string,string>|null
	 */
	private function pair( string $name, string $value ): ?array {
		if ( '' === trim( $value ) ) {
			return null;
		}

		return array(
			'name'  => $name,
			'value' => $value,
		);
	}
}
