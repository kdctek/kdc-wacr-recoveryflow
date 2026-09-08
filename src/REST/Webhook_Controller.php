<?php
/**
 * The receiver an Auto Flow can call back into.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\REST;

use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Customer\Identity;
use WAcr\RecoveryFlow\Customer\Identity_Repository;
use WAcr\RecoveryFlow\Customer\Identity_Resolver;
use WAcr\RecoveryFlow\Database\Receipt_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Suppressor;
use WAcr\RecoveryFlow\Security\Webhook_Secret;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Letting a WA.cr Auto Flow tell this site something.
 *
 * **What this is NOT, and why.** The plan described a `/webhooks/wacr` receiver
 * for delivery statuses and inbound replies, verifying an `x-wacr-signature`
 * header. Read against the platform, neither half of that is available:
 *
 * - **WA.cr has no outbound webhook subscription.** There is no way to ask it
 *   to notify a URL when a message is delivered, read or replied to; that
 *   machinery has been deferred upstream. It is why this plugin reconciles
 *   through the poll pass instead, asking
 *   `GET /v1/conversations/{e164}/messages?after=`, and the poll pass remains
 *   the only route by which a delivery status or a reply reaches this site.
 * - **The one thing that can call a URL does not sign the body.** An Auto Flow
 *   webhook node sends `content-type: application/json` plus whatever headers
 *   the merchant typed into the flow. There is no HMAC in that direction. (The
 *   signature the plan is remembering runs the other way: RecoveryFlow signs
 *   what it pushes INTO a flow.)
 *
 * So a receiver verifying a signature would verify a header nothing sends and
 * refuse every real request, while looking rigorous -- a dead contract of
 * exactly the kind this plugin has already closed three times.
 *
 * **What this IS.** A flow author can add a webhook node to their flow, point
 * it here, and give it the secret from Settings > WA.cr. That is a real path
 * that works today, and it carries the one thing polling handles badly: a
 * customer who replies STOP inside a flow should be suppressed HERE, now,
 * rather than up to a poll interval later, because every minute of that delay
 * is a minute in which another reminder can go out to somebody who has asked
 * to be left alone.
 *
 * The refusals are deliberately distinct, because they need different fixes and
 * an operator reading their flow's node log has nothing else to go on:
 *
 * - **415** the body was not JSON -- the flow is posting a form.
 * - **413** the body was too large; the cap is small on purpose, since nothing
 *   this accepts needs more than a few hundred bytes and an unbounded reader on
 *   an unauthenticated path is a way to spend a site's memory.
 * - **401** the secret was wrong, absent, or none has been generated. All three
 *   are one answer on purpose: distinguishing "no secret configured" from
 *   "wrong secret" tells an attacker which sites are worth returning to.
 * - **400** the event is not one this accepts. Named rather than ignored,
 *   because a flow author who typed the event name wrongly would otherwise see
 *   a 200 and conclude it worked.
 *
 * **A customer this site does not know is a 200, not a 404.** The flow did
 * nothing wrong, there is simply nobody here by that number; answering 404
 * would also turn the endpoint into a way to ask which phone numbers belong to
 * this shop's customers. The response says `matched: 0` instead.
 *
 * **Every accepted event is deduplicated through the receipt ledger.** The
 * webhook node retries nothing and is at-most-once, but a merchant can build a
 * flow that reaches this twice, and a shared secret is replayable by anyone who
 * has seen it. A repeat does what the first one did, which is nothing.
 */
final class Webhook_Controller extends Abstract_Controller {

	/**
	 * The most body this will read, in bytes.
	 */
	public const MAX_BODY = 16384;

	/**
	 * Events a flow may send.
	 *
	 * Both are things a flow KNOWS and this site cannot find out any other way
	 * without waiting for a poll. Nothing here reports a delivery status: WA.cr
	 * does not push those, and inventing an event for them would document a
	 * capability that does not exist.
	 */
	public const EVENTS = array( 'recovery.opt_out', 'recovery.replied' );

	/**
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * The one implementation of "stop messaging me".
	 *
	 * @var Suppressor
	 */
	private Suppressor $suppressor;

	/**
	 * The dedupe ledger.
	 *
	 * @var Receipt_Repository
	 */
	private Receipt_Repository $ledger;

	/**
	 * The log.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Customer_Repository $customers  Customer storage.
	 * @param Journey_Repository  $journeys   Journey storage.
	 * @param Suppressor          $suppressor The one implementation of "stop messaging me".
	 * @param Receipt_Repository  $ledger     The dedupe ledger.
	 * @param Logger              $logger     The log.
	 */
	public function __construct(
		Customer_Repository $customers,
		Journey_Repository $journeys,
		Suppressor $suppressor,
		Receipt_Repository $ledger,
		Logger $logger
	) {
		$this->customers  = $customers;
		$this->journeys   = $journeys;
		$this->suppressor = $suppressor;
		$this->ledger     = $ledger;
		$this->logger     = $logger;
	}

	/**
	 * Register the route.
	 *
	 * POST only. A GET is not merely useless here, it is dangerous: WhatsApp's
	 * link-preview fetcher and every crawler in existence will GET any URL they
	 * find, and this one suppresses customers.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Routes::REST_NAMESPACE,
			Routes::path( 'webhooks/wacr' ),
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => array( $this, 'authorised' ),
				),
			)
		);
	}

	/**
	 * Whether this request carries the right secret.
	 *
	 * A permission callback rather than a check inside the handler, so the
	 * route declares who may call it in the same place every other route in
	 * this plugin does, and so a body is never parsed for an unauthorised
	 * caller.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function authorised( \WP_REST_Request $request ) {
		if ( Webhook_Secret::matches( (string) $request->get_header( Webhook_Secret::HEADER ) ) ) {
			return true;
		}

		// One answer for a wrong secret, a missing secret and a site that has
		// never generated one. Telling them apart tells somebody probing which
		// sites are worth coming back to.
		return new \WP_Error(
			'recoveryflow_webhook_unauthorised',
			__( 'This request did not carry the right secret.', 'kdc-wacr-recoveryflow' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Take one event from a flow.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function receive( \WP_REST_Request $request ) {
		$type = strtolower( (string) $request->get_header( 'content-type' ) );

		if ( false === strpos( $type, 'application/json' ) ) {
			return new \WP_Error(
				'recoveryflow_webhook_unsupported_media',
				__( 'This endpoint reads JSON. Set the webhook node to send application/json.', 'kdc-wacr-recoveryflow' ),
				array( 'status' => 415 )
			);
		}

		$body = (string) $request->get_body();

		if ( strlen( $body ) > self::MAX_BODY ) {
			return new \WP_Error(
				'recoveryflow_webhook_too_large',
				sprintf(
					/* translators: %d: the largest number of bytes this endpoint will read. */
					__( 'That request body is larger than the %d bytes this endpoint reads. Nothing it accepts needs to be that big.', 'kdc-wacr-recoveryflow' ),
					self::MAX_BODY
				),
				array( 'status' => 413 )
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'recoveryflow_webhook_unreadable',
				__( 'That request body is not readable JSON.', 'kdc-wacr-recoveryflow' ),
				array( 'status' => 400 )
			);
		}

		$event = isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '';

		if ( ! in_array( $event, self::EVENTS, true ) ) {
			// Named rather than ignored: a flow author who mistyped the event
			// would otherwise get a 200 and believe it was working.
			return new \WP_Error(
				'recoveryflow_webhook_unknown_event',
				sprintf(
					/* translators: %s: a comma-separated list of the events this endpoint accepts. */
					__( 'That is not an event this site accepts. It accepts: %s.', 'kdc-wacr-recoveryflow' ),
					implode( ', ', self::EVENTS )
				),
				array( 'status' => 400 )
			);
		}

		$id = isset( $payload['id'] ) ? sanitize_text_field( (string) $payload['id'] ) : '';

		if ( '' !== $id && ! $this->ledger->claim( 'webhook:' . $event . ':' . $id, Receipt_Repository::KIND_WEBHOOK ) ) {
			// Already handled. A repeat is answered exactly as the first one
			// was, so a flow retrying by hand cannot tell it did nothing --
			// which is the point of it doing nothing.
			return new \WP_REST_Response(
				array(
					'ok'      => true,
					'event'   => $event,
					'matched' => 0,
					'repeat'  => true,
				)
			);
		}

		return $this->handle( $event, $payload );
	}

	/**
	 * Act on one accepted event.
	 *
	 * @param string              $event   The event name.
	 * @param array<string,mixed> $payload The decoded body.
	 * @return \WP_REST_Response
	 */
	private function handle( string $event, array $payload ): \WP_REST_Response {
		$customer = $this->customer_from( $payload );

		if ( null === $customer ) {
			// Nobody here by that number. The flow did nothing wrong, and a 404
			// would let this endpoint be asked which numbers belong to this
			// shop's customers.
			return new \WP_REST_Response(
				array(
					'ok'      => true,
					'event'   => $event,
					'matched' => 0,
				)
			);
		}

		if ( 'recovery.opt_out' === $event ) {
			$result = $this->suppressor->suppress( $customer, Suppressor::SOURCE_FLOW );

			return new \WP_REST_Response(
				array(
					'ok'       => true,
					'event'    => $event,
					'matched'  => 1,
					'stopped'  => $result['journeys'],
					'silenced' => $result['identities'],
				)
			);
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'event'   => $event,
				'matched' => 1,
				'engaged' => $this->mark_engaged( $customer ),
			)
		);
	}

	/**
	 * Move this customer's open recoveries to engaged.
	 *
	 * A reply is engagement and nothing more. It deliberately does not stop the
	 * recovery: somebody answering "how much is postage?" has not finished
	 * their order, and treating a reply as a conversion would close a recovery
	 * that was working.
	 *
	 * @param int $customer_id The customer.
	 * @return int How many recoveries moved.
	 */
	private function mark_engaged( int $customer_id ): int {
		$moved = 0;

		foreach ( $this->journeys->active_for_customer( $customer_id, 100 ) as $journey ) {
			if ( $this->journeys->transition( $journey->id, $journey->status, Journey_State::ENGAGED, array(), 'flow' ) ) {
				++$moved;
			}
		}

		return $moved;
	}

	/**
	 * Which customer a payload is about.
	 *
	 * The number is normalised through the same resolver the checkout stored it
	 * with, because a flow reports whatever WhatsApp gave it and this site
	 * stored a hash of the E.164 form.
	 *
	 * @param array<string,mixed> $payload The decoded body.
	 * @return int|null Customer id, or null when nobody here matches.
	 */
	private function customer_from( array $payload ): ?int {
		$raw = isset( $payload['phone'] ) ? sanitize_text_field( (string) $payload['phone'] ) : '';

		if ( '' === $raw ) {
			return null;
		}

		$e164 = Identity_Resolver::to_e164( $raw, Identity_Resolver::site_country() );

		if ( '' === $e164 ) {
			$this->logger->warning( 'webhook', 'A flow reported an event for a number that could not be read.' );

			return null;
		}

		$customer = $this->customers->find_by_phone_hash(
			Identity_Repository::hash_for( Identity::E164, $e164 )
		);

		return null === $customer ? null : $customer->id;
	}
}
