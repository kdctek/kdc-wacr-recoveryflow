<?php
/**
 * One recovery, in full.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Admin\Journey_Actions;
use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Customer\Mask;
use WAcr\RecoveryFlow\Database\Receipt_Repository;
use WAcr\RecoveryFlow\Recovery\Attempt_Repository;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The screen somebody opens when a customer rings up about a message.
 *
 * That is the use case it is built for, and it decides the shape: what was in
 * the basket, who it was sent to, what was actually sent and when, and on what
 * basis this shop believed it was allowed to send it. A support call that
 * cannot answer the last of those is a support call that becomes a complaint.
 *
 * Contact details are shortened until somebody with the reveal capability asks
 * for them, and asking is recorded. The reveal is a link rather than a script,
 * so it works with JavaScript off and leaves an ordinary page load in the
 * server log.
 */
final class Journey_Detail {

	/**
	 * The query argument that asks to see contact details in full.
	 */
	public const REVEAL_ARG = 'reveal';

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
	 * Attempt ledger.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

	/**
	 * Receipt ledger, for recording a reveal.
	 *
	 * @var Receipt_Repository
	 */
	private Receipt_Repository $receipts;

	/**
	 * Constructor.
	 *
	 * @param Journey_Repository  $journeys  Journey storage.
	 * @param Event_Repository    $events    Event storage.
	 * @param Customer_Repository $customers Customer storage.
	 * @param Attempt_Repository  $attempts  Attempt ledger.
	 * @param Receipt_Repository  $receipts  Receipt ledger.
	 */
	public function __construct(
		Journey_Repository $journeys,
		Event_Repository $events,
		Customer_Repository $customers,
		Attempt_Repository $attempts,
		Receipt_Repository $receipts
	) {
		$this->journeys  = $journeys;
		$this->events    = $events;
		$this->customers = $customers;
		$this->attempts  = $attempts;
		$this->receipts  = $receipts;
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_JOURNEYS ) ) {
			wp_die( esc_html__( 'You do not have permission to view recoveries.', 'kdc-wacr-recoveryflow' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which recovery to display changes nothing.
		$uid     = isset( $_GET['journey'] ) ? sanitize_text_field( wp_unslash( $_GET['journey'] ) ) : '';
		$journey = '' === $uid ? null : $this->journeys->find_by_uid( $uid );

		echo '<div class="wrap recoveryflow-journey">';

		if ( null === $journey ) {
			printf( '<h1>%s</h1>', esc_html__( 'Recovery not found', 'kdc-wacr-recoveryflow' ) );
			printf(
				'<p>%s</p><p><a href="%s">%s</a></p>',
				esc_html__( 'No recovery was found with that reference. It may have been removed by the retention clear-out.', 'kdc-wacr-recoveryflow' ),
				esc_url( Screen::url( Screen::JOURNEYS ) ),
				esc_html__( 'Back to all recoveries', 'kdc-wacr-recoveryflow' )
			);
			echo '</div>';

			return;
		}

		printf(
			'<h1>%s</h1><p><a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %s: the public reference of a recovery. */
					__( 'Recovery %s', 'kdc-wacr-recoveryflow' ),
					$journey->journey_uid
				)
			),
			esc_url( Screen::url( Screen::JOURNEYS ) ),
			esc_html__( 'Back to all recoveries', 'kdc-wacr-recoveryflow' )
		);

		Journey_Actions::notice();

		$this->summary_card( $journey );
		$this->customer_card( $journey );
		$this->messages_card( $journey );
		Journey_Actions::buttons( $journey );

		echo '</div>';
	}

	/**
	 * Where this recovery has got to.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @return void
	 */
	private function summary_card( Recovery_Journey $journey ): void {
		$event = $journey->event_id > 0 ? $this->events->find( $journey->event_id ) : null;

		$rows = array(
			array( __( 'Status', 'kdc-wacr-recoveryflow' ), Journey_State::label( $journey->status ) ),
			array( __( 'Started', 'kdc-wacr-recoveryflow' ), $this->local_time( $journey->created_at ) ),
			array( __( 'Next step due', 'kdc-wacr-recoveryflow' ), null === $journey->next_action_at ? __( 'Nothing scheduled', 'kdc-wacr-recoveryflow' ) : $this->local_time( $journey->next_action_at ) ),
			array( __( 'Expires', 'kdc-wacr-recoveryflow' ), $this->local_time( $journey->expires_at ) ),
			array( __( 'Reminders sent', 'kdc-wacr-recoveryflow' ), number_format_i18n( $journey->attempts_count ) ),
			array( __( 'Link opened', 'kdc-wacr-recoveryflow' ), number_format_i18n( $journey->clicks_count ) ),
			array( __( 'Where it came from', 'kdc-wacr-recoveryflow' ), $journey->source_id ),
		);

		if ( null !== $journey->status_reason && '' !== $journey->status_reason ) {
			$rows[] = array( __( 'Reason', 'kdc-wacr-recoveryflow' ), $journey->status_reason );
		}

		if ( null !== $event ) {
			$rows[] = array( __( 'Basket value', 'kdc-wacr-recoveryflow' ), Money::format( (string) $event->amount, (string) $event->currency ) );
			$rows[] = array( __( 'What was in it', 'kdc-wacr-recoveryflow' ), '' === $event->items_summary( 20 ) ? __( 'No longer held', 'kdc-wacr-recoveryflow' ) : $event->items_summary( 20 ) );
		}

		$this->card( __( 'This recovery', 'kdc-wacr-recoveryflow' ), $rows );
	}

	/**
	 * Who it is about.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @return void
	 */
	private function customer_card( Recovery_Journey $journey ): void {
		$customer = $journey->customer_id > 0 ? $this->customers->find( $journey->customer_id ) : null;

		if ( null === $customer ) {
			$this->card(
				__( 'Customer', 'kdc-wacr-recoveryflow' ),
				array( array( __( 'Identified', 'kdc-wacr-recoveryflow' ), __( 'Nobody was identified, so nothing was ever sent.', 'kdc-wacr-recoveryflow' ) ) )
			);

			return;
		}

		if ( $customer->is_anonymized() ) {
			$this->card(
				__( 'Customer', 'kdc-wacr-recoveryflow' ),
				array(
					array( __( 'Details', 'kdc-wacr-recoveryflow' ), __( 'Removed at the customer\'s request, or by the retention period expiring.', 'kdc-wacr-recoveryflow' ) ),
					array( __( 'Removed on', 'kdc-wacr-recoveryflow' ), $this->local_time( (string) $customer->anonymized_at ) ),
				)
			);

			return;
		}

		$reveal = $this->may_reveal( $journey );

		$rows = array(
			array( __( 'Name', 'kdc-wacr-recoveryflow' ), $reveal ? trim( $customer->first_name . ' ' . $customer->last_name ) : Mask::name( $customer->first_name, $customer->last_name ) ),
			array( __( 'Phone', 'kdc-wacr-recoveryflow' ), $this->contact( $customer->phone_e164, $reveal, 'phone' ) ),
			array( __( 'Email', 'kdc-wacr-recoveryflow' ), $this->contact( $customer->email, $reveal, 'email' ) ),
		);

		$this->card( __( 'Customer', 'kdc-wacr-recoveryflow' ), $rows, $this->reveal_link( $journey, $reveal ) );
	}

	/**
	 * One contact detail, masked or not.
	 *
	 * @param string $value The value.
	 * @param bool   $reveal Whether it may be shown in full.
	 * @param string $kind   phone or email.
	 * @return string
	 */
	private function contact( string $value, bool $reveal, string $kind ): string {
		if ( '' === $value ) {
			return __( 'Not given', 'kdc-wacr-recoveryflow' );
		}

		if ( $reveal ) {
			return $value;
		}

		return 'phone' === $kind ? Mask::phone( $value ) : Mask::email( $value );
	}

	/**
	 * Whether this request may see contact details in full.
	 *
	 * Both halves are needed: the capability, and having asked. Somebody who
	 * holds the capability and merely opened the screen sees the shortened
	 * version, which is what keeps the screen safe to leave open.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @return bool
	 */
	private function may_reveal( Recovery_Journey $journey ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading whether to unmask; the capability below is the control, and the reveal is recorded either way.
		$asked = isset( $_GET[ self::REVEAL_ARG ] ) && '1' === $_GET[ self::REVEAL_ARG ];

		if ( ! $asked || ! current_user_can( Capabilities::REVEAL_PII ) ) {
			return false;
		}

		$this->receipts->claim(
			'reveal:' . get_current_user_id() . ':' . $journey->journey_uid . ':' . gmdate( 'YmdHi' ),
			Receipt_Repository::KIND_REVEAL
		);

		return true;
	}

	/**
	 * The link that asks to see contact details in full.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param bool             $reveal  Whether they are already shown.
	 * @return string
	 */
	private function reveal_link( Recovery_Journey $journey, bool $reveal ): string {
		if ( ! current_user_can( Capabilities::REVEAL_PII ) ) {
			return '';
		}

		if ( $reveal ) {
			return sprintf(
				'<p><a href="%1$s" class="button">%2$s</a> <span class="description">%3$s</span></p>',
				esc_url( Screen::journey_url( $journey->journey_uid ) ),
				esc_html__( 'Hide contact details', 'kdc-wacr-recoveryflow' ),
				esc_html__( 'This viewing has been recorded.', 'kdc-wacr-recoveryflow' )
			);
		}

		return sprintf(
			'<p><a href="%1$s" class="button">%2$s</a> <span class="description">%3$s</span></p>',
			esc_url( add_query_arg( self::REVEAL_ARG, '1', Screen::journey_url( $journey->journey_uid ) ) ),
			esc_html__( 'Show contact details', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'Who looked and when is recorded.', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * What was actually sent.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @return void
	 */
	private function messages_card( Recovery_Journey $journey ): void {
		$attempts = $this->attempts->for_journey( $journey->id );

		printf( '<div class="card recoveryflow-card"><h2>%s</h2>', esc_html__( 'Messages', 'kdc-wacr-recoveryflow' ) );

		if ( array() === $attempts ) {
			printf( '<p>%s</p></div>', esc_html__( 'Nothing has been sent for this recovery.', 'kdc-wacr-recoveryflow' ) );

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'When', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Channel', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Step', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Result', 'kdc-wacr-recoveryflow' ) );
		echo '</tr></thead><tbody>';

		foreach ( $attempts as $attempt ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( $this->local_time( $attempt->created_at ) ),
				esc_html( $attempt->channel ),
				esc_html( number_format_i18n( $attempt->step_index + 1 ) ),
				esc_html(
					null === $attempt->error_code || '' === $attempt->error_code
						? $attempt->status
						: sprintf(
							/* translators: 1: what happened to a message. 2: the error code the messaging service gave. */
							__( '%1$s (%2$s)', 'kdc-wacr-recoveryflow' ),
							$attempt->status,
							$attempt->error_code
						)
				)
			);
		}

		echo '</tbody></table>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Message contents are not stored. What was sent is decided by the workflow and the template at the time it went out.', 'kdc-wacr-recoveryflow' )
		);
		echo '</div>';
	}

	/**
	 * A card of label/value rows.
	 *
	 * @param string                       $title  Card heading.
	 * @param array<int,array<int,string>> $rows Label/value pairs.
	 * @param string                       $footer Optional pre-escaped markup below the table.
	 * @return void
	 */
	private function card( string $title, array $rows, string $footer = '' ): void {
		printf( '<div class="card recoveryflow-card"><h2>%s</h2><table class="widefat striped"><tbody>', esc_html( $title ) );

		foreach ( $rows as $row ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $row[0] ), esc_html( $row[1] ) );
		}

		echo '</tbody></table>';
		echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by reveal_link().
		echo '</div>';
	}

	/**
	 * A stored UTC time, shown in the site's own timezone.
	 *
	 * @param string $utc UTC datetime.
	 * @return string
	 */
	private function local_time( string $utc ): string {
		if ( '' === $utc ) {
			return '';
		}

		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return $utc;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
