<?php
/**
 * The journeys REST collection.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\REST;

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

defined( 'ABSPATH' ) || exit;

/**
 * Reading and working the recovery queue over REST.
 *
 * Rows are shaped here rather than handed back as they come out of the
 * database. A repository row carries columns that exist for the engine's
 * benefit -- claim tokens, token hashes, internal ids -- and an endpoint that
 * returned the row would publish all of them and then be unable to change any
 * of them without breaking a caller. What goes out is a deliberate shape.
 *
 * Contact details are masked unless the caller both holds the reveal capability
 * and asks; see Abstract_Controller for why both halves are required.
 */
final class Journeys_Controller extends Abstract_Controller {

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
	 * Constructor.
	 *
	 * @param Journey_Repository  $journeys  Journey storage.
	 * @param Event_Repository    $events    Event storage.
	 * @param Customer_Repository $customers Customer storage.
	 * @param Attempt_Repository  $attempts  Attempt ledger.
	 * @param Receipt_Repository  $receipts  Receipt ledger, for reveal auditing.
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
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Routes::REST_NAMESPACE,
			Routes::path( 'journeys' ),
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->require_cap( Capabilities::VIEW_JOURNEYS ),
					'args'                => array_merge(
						$this->collection_args(),
						array(
							'status'         => array(
								'type'              => 'string',
								'default'           => '',
								'enum'              => array_merge( array( '' ), Journey_State::all() ),
								'sanitize_callback' => 'sanitize_key',
							),
							'source'         => array(
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_key',
							),
							'orderby'        => array(
								'type'              => 'string',
								'default'           => 'id',
								'enum'              => array( 'id', 'created_at', 'updated_at', 'next_action_at', 'status' ),
								'sanitize_callback' => 'sanitize_key',
							),
							'order'          => array(
								'type'              => 'string',
								'default'           => 'desc',
								'enum'              => array( 'asc', 'desc' ),
								'sanitize_callback' => 'sanitize_key',
							),
							self::REVEAL_ARG => array(
								'type'    => 'boolean',
								'default' => false,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			Routes::REST_NAMESPACE,
			Routes::path( 'journeys/(?P<uid>[A-Za-z0-9\-]{6,64})' ),
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $this->require_cap( Capabilities::VIEW_JOURNEYS ),
					'args'                => array(
						'uid'            => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						self::REVEAL_ARG => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'cancel' ),
					'permission_callback' => $this->require_cap( Capabilities::MANAGE_JOURNEYS ),
					'args'                => array(
						'uid'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'action' => array(
							'type'              => 'string',
							'required'          => true,
							'enum'              => array( 'cancel' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * A page of journeys.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$reveal   = $this->may_reveal( $request, 'journeys' );

		$result = $this->journeys->query(
			array(
				'status'   => (string) $request->get_param( 'status' ),
				'source'   => (string) $request->get_param( 'source' ),
				'search'   => (string) $request->get_param( 'search' ),
				'orderby'  => (string) $request->get_param( 'orderby' ),
				'order'    => (string) $request->get_param( 'order' ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => $per_page,
			)
		);

		$items = array();

		foreach ( $result['rows'] as $journey ) {
			$items[] = $this->summary( $journey, $reveal );
		}

		return $this->paged( $items, $result['total'], $per_page );
	}

	/**
	 * One journey in full.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$journey = $this->journeys->find_by_uid( (string) $request->get_param( 'uid' ) );

		if ( null === $journey ) {
			return $this->not_found();
		}

		$reveal = $this->may_reveal( $request, 'journey:' . $journey->journey_uid );
		$data   = $this->summary( $journey, $reveal );

		$data['attempts'] = array();

		foreach ( $this->attempts->for_journey( $journey->id ) as $attempt ) {
			$data['attempts'][] = array(
				'step'       => $attempt->step_index,
				'attempt_no' => $attempt->attempt_no,
				'channel'    => $attempt->channel,
				'action'     => $attempt->action_type,
				'status'     => $attempt->status,
				'error_code' => $attempt->error_code,
				'created_at' => $attempt->created_at,
			);
		}

		return new \WP_REST_Response( $data );
	}

	/**
	 * Stop working a journey.
	 *
	 * Cancelling is the one write this controller offers, and it only ever
	 * stops work. There is no "send now" here on purpose: a message costs money
	 * and reaches a real person, and an endpoint that could fire one is a much
	 * larger thing to get right than one that can only stop.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( \WP_REST_Request $request ) {
		$journey = $this->journeys->find_by_uid( (string) $request->get_param( 'uid' ) );

		if ( null === $journey ) {
			return $this->not_found();
		}

		if ( $journey->is_terminal() ) {
			return new \WP_Error(
				'recoveryflow_already_finished',
				__( 'This recovery has already finished, so there is nothing to cancel.', 'kdc-wacr-recoveryflow' ),
				array( 'status' => 409 )
			);
		}

		$done = $this->journeys->transition(
			$journey->id,
			$journey->status,
			Journey_State::CANCELLED,
			array(),
			'admin'
		);

		if ( ! $done ) {
			// The state it was in when it was read is not the state it is in
			// now: a background run moved it between the read and the write.
			// Reporting that is more useful than retrying into whatever it has
			// since become.
			return new \WP_Error(
				'recoveryflow_moved',
				__( 'This recovery changed while you were looking at it. Reload and try again.', 'kdc-wacr-recoveryflow' ),
				array( 'status' => 409 )
			);
		}

		$this->attempts->revoke_tokens( $journey->id );

		return new \WP_REST_Response(
			array(
				'uid'    => $journey->journey_uid,
				'status' => Journey_State::CANCELLED,
			)
		);
	}

	/**
	 * One journey, shaped for a caller.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param bool             $reveal  Whether contact details may be unmasked.
	 * @return array<string,mixed>
	 */
	private function summary( Recovery_Journey $journey, bool $reveal ): array {
		$event    = $journey->event_id > 0 ? $this->events->find( $journey->event_id ) : null;
		$customer = $journey->customer_id > 0 ? $this->customers->find( $journey->customer_id ) : null;

		return array(
			'uid'            => $journey->journey_uid,
			'status'         => $journey->status,
			'status_label'   => Journey_State::label( $journey->status ),
			'status_reason'  => $journey->status_reason,
			'source'         => $journey->source_id,
			'step'           => $journey->current_step,
			'attempts'       => $journey->attempts_count,
			'clicks'         => $journey->clicks_count,
			'created_at'     => $journey->created_at,
			'next_action_at' => $journey->next_action_at,
			'expires_at'     => $journey->expires_at,
			'recovered_at'   => $journey->recovered_at,
			'amount'         => null === $event ? null : $event->amount,
			'currency'       => null === $event ? '' : $event->currency,
			'item_count'     => null === $event ? 0 : $event->item_count,
			'customer'       => $this->customer_summary( $customer, $reveal ),
		);
	}

	/**
	 * The customer behind a journey, masked unless revealing was earned.
	 *
	 * @param Customer|null $customer The customer.
	 * @param bool          $reveal   Whether contact details may be unmasked.
	 * @return array<string,mixed>|null
	 */
	private function customer_summary( ?Customer $customer, bool $reveal ): ?array {
		if ( null === $customer ) {
			return null;
		}

		if ( $customer->is_anonymized() ) {
			// Nothing left to mask or reveal. Saying so is better than an empty
			// row that reads as missing data.
			return array(
				'name'       => __( 'Removed at the customer\'s request', 'kdc-wacr-recoveryflow' ),
				'phone'      => '',
				'email'      => '',
				'anonymized' => true,
			);
		}

		return array(
			'name'       => $reveal
				? trim( $customer->first_name . ' ' . $customer->last_name )
				: Mask::name( $customer->first_name, $customer->last_name ),
			'phone'      => $reveal ? $customer->phone_e164 : Mask::phone( $customer->phone_e164 ),
			'email'      => $reveal ? $customer->email : Mask::email( $customer->email ),
			'anonymized' => false,
		);
	}

	/**
	 * The one shape a missing journey takes.
	 *
	 * Deliberately identical whether the uid never existed or has been erased:
	 * a different answer for the two would let anybody with the view capability
	 * confirm that a particular reference used to be real.
	 *
	 * @return \WP_Error
	 */
	private function not_found(): \WP_Error {
		return new \WP_Error(
			'recoveryflow_not_found',
			__( 'No recovery was found with that reference.', 'kdc-wacr-recoveryflow' ),
			array( 'status' => 404 )
		);
	}
}
