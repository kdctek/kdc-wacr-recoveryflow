<?php
/**
 * The recovery queue, as a WordPress list table.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Customer\Mask;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The journeys list.
 *
 * Extends core's WP_List_Table rather than drawing a table, so the screen gets
 * WordPress's own sorting, paging, screen options, keyboard behaviour and
 * responsive collapse -- and keeps them when WordPress improves them. The class
 * is not formally part of WordPress's public API, which is a real caveat; it is
 * also what every shipped WordPress admin list uses, and reimplementing it
 * would mean reimplementing its accessibility too.
 *
 * Contact details are masked. The full value is on the journey's own screen for
 * somebody holding the reveal capability, which keeps a screen that sits open
 * on a counter from being a list of customer phone numbers.
 */
final class Journeys_Table extends \WP_List_Table {

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
	 * Constructor.
	 *
	 * @param Journey_Repository  $journeys  Journey storage.
	 * @param Event_Repository    $events    Event storage.
	 * @param Customer_Repository $customers Customer storage.
	 */
	public function __construct( Journey_Repository $journeys, Event_Repository $events, Customer_Repository $customers ) {
		$this->journeys  = $journeys;
		$this->events    = $events;
		$this->customers = $customers;

		parent::__construct(
			array(
				'singular' => 'recovery',
				'plural'   => 'recoveries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The columns, in order.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'reference'  => __( 'Reference', 'kdc-wacr-recoveryflow' ),
			'customer'   => __( 'Customer', 'kdc-wacr-recoveryflow' ),
			'status'     => __( 'Status', 'kdc-wacr-recoveryflow' ),
			'amount'     => __( 'Basket', 'kdc-wacr-recoveryflow' ),
			'reminders'  => __( 'Reminders', 'kdc-wacr-recoveryflow' ),
			'next'       => __( 'Next step', 'kdc-wacr-recoveryflow' ),
			'created_at' => __( 'Started', 'kdc-wacr-recoveryflow' ),
		);
	}

	/**
	 * Which columns can be sorted, and by what.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function get_sortable_columns(): array {
		return array(
			'status'     => array( 'status', false ),
			'next'       => array( 'next_action_at', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Fetch the page being viewed.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'recoveryflow_journeys_per_page', 20 );

		// Nonce-free on purpose: these are the list's own view controls, which
		// change nothing. Requiring a nonce to sort a table would break every
		// bookmark and every link in a support reply.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result = $this->journeys->query(
			array(
				'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->items = $result['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / max( 1, $per_page ) ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'reference' );
	}

	/**
	 * Filter by status, above the table.
	 *
	 * Rendered as links rather than a dropdown, so each filtered view has an
	 * address that can be bookmarked and quoted -- the same reason the settings
	 * tabs are links.
	 *
	 * @return array<string,string>
	 */
	protected function get_views(): array {
		$counts  = $this->journeys->counts_by_status();
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a view filter changes nothing.
		$views   = array();

		$views['all'] = $this->view_link( '', __( 'All', 'kdc-wacr-recoveryflow' ), array_sum( $counts ), '' === $current );

		foreach ( Journey_State::all() as $state ) {
			if ( empty( $counts[ $state ] ) ) {
				continue;
			}

			$views[ $state ] = $this->view_link( $state, Journey_State::label( $state ), $counts[ $state ], $state === $current );
		}

		return $views;
	}

	/**
	 * One filter link.
	 *
	 * @param string $status  Status to filter to, or '' for all.
	 * @param string $label   Link text.
	 * @param int    $count   How many.
	 * @param bool   $current Whether this is the view being shown.
	 * @return string
	 */
	private function view_link( string $status, string $label, int $count, bool $current ): string {
		$url = Screen::url( Screen::JOURNEYS, '' === $status ? array() : array( 'status' => $status ) );

		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * What to show when there is nothing.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No recoveries yet. They appear here once a shopper leaves something behind.', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * Fallback renderer for a column with no method of its own.
	 *
	 * @param Recovery_Journey $item        The journey.
	 * @param string           $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'status':
				return $this->status_cell( $item );

			case 'amount':
				return $this->amount_cell( $item );

			case 'customer':
				return $this->customer_cell( $item );

			case 'reminders':
				return esc_html( number_format_i18n( $item->attempts_count ) );

			case 'next':
				return null === $item->next_action_at
					? '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Nothing scheduled', 'kdc-wacr-recoveryflow' ) . '</span>'
					: esc_html( $this->local_time( $item->next_action_at ) );

			case 'created_at':
				return esc_html( $this->local_time( $item->created_at ) );

			default:
				return '';
		}//end switch
	}

	/**
	 * The reference column, which carries the row's actions.
	 *
	 * @param Recovery_Journey $item The journey.
	 * @return string
	 */
	protected function column_reference( Recovery_Journey $item ): string {
		$url = Screen::journey_url( $item->journey_uid );

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>',
			esc_url( $url ),
			esc_html( $item->journey_uid )
		);
	}

	/**
	 * Status, said in words and not only shown in a colour.
	 *
	 * @param Recovery_Journey $item The journey.
	 * @return string
	 */
	private function status_cell( Recovery_Journey $item ): string {
		$label = Journey_State::label( $item->status );

		if ( null === $item->status_reason || '' === $item->status_reason ) {
			return esc_html( $label );
		}

		return sprintf(
			'%1$s<br /><span class="description">%2$s</span>',
			esc_html( $label ),
			esc_html( $item->status_reason )
		);
	}

	/**
	 * Basket value.
	 *
	 * @param Recovery_Journey $item The journey.
	 * @return string
	 */
	private function amount_cell( Recovery_Journey $item ): string {
		$event = $item->event_id > 0 ? $this->events->find( $item->event_id ) : null;

		if ( null === $event ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not known', 'kdc-wacr-recoveryflow' ) . '</span>';
		}

		return esc_html( $event->amount . ' ' . $event->currency );
	}

	/**
	 * The customer, masked.
	 *
	 * @param Recovery_Journey $item The journey.
	 * @return string
	 */
	private function customer_cell( Recovery_Journey $item ): string {
		$customer = $item->customer_id > 0 ? $this->customers->find( $item->customer_id ) : null;

		if ( null === $customer ) {
			return esc_html__( 'Not identified', 'kdc-wacr-recoveryflow' );
		}

		if ( $customer->is_anonymized() ) {
			return esc_html__( 'Removed at the customer\'s request', 'kdc-wacr-recoveryflow' );
		}

		$reach = '' !== $customer->phone_e164 ? Mask::phone( $customer->phone_e164 ) : Mask::email( $customer->email );

		return sprintf(
			'%1$s<br /><span class="description">%2$s</span>',
			esc_html( Mask::name( $customer->first_name, $customer->last_name ) ),
			esc_html( $reach )
		);
	}

	/**
	 * A stored UTC time, shown in the site's own timezone.
	 *
	 * Everything is stored in UTC deliberately, and showing UTC to a shopkeeper
	 * asking "when did this go out?" is a way of being accurate and useless at
	 * the same time.
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

	/**
	 * Whether this viewer may work the queue rather than only read it.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE_JOURNEYS );
	}
}
