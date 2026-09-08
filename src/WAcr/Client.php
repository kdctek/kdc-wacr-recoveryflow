<?php
/**
 * The WA.cr API client.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Customer\Phone_Normalizer;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Every call RecoveryFlow makes to WA.cr, in one place.
 *
 * Nothing outside this class knows a URL, a header or a payload shape, so the
 * day the API gains a field there is exactly one file to change, and an audit
 * of "what does this plugin send, and when" is a read of one class.
 *
 * Two rules are load-bearing.
 *
 * The workspace is never a parameter. WA.cr resolves it from the credential, so
 * one saved key addresses exactly one workspace and a site cannot be persuaded
 * to write into somebody else's.
 *
 * send_template() never retries by itself. The API has no idempotency key, so a
 * retry it decided to make on its own could deliver a second WhatsApp message
 * and bill the merchant twice. Deciding to retry needs the attempt ledger,
 * which lives a layer up; this class only reports what happened accurately
 * enough for that decision to be made.
 */
class Client {

	private const CACHE_CHANNELS  = 'recoveryflow_wacr_channels';
	private const CACHE_TEMPLATES = 'recoveryflow_wacr_templates';
	private const CACHE_TTL       = 900;

	/**
	 * Connection settings.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * HTTP transport.
	 *
	 * @var Transport
	 */
	private Transport $transport;

	/**
	 * Request budget.
	 *
	 * @var Rate_Budget
	 */
	private Rate_Budget $budget;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Connection settings.
	 * @param Transport   $transport   HTTP transport.
	 * @param Rate_Budget $budget      Request budget.
	 * @param Logger      $logger      Logger.
	 * @param Clock       $clock       Clock.
	 */
	public function __construct(
		Credentials $credentials,
		Transport $transport,
		Rate_Budget $budget,
		Logger $logger,
		Clock $clock
	) {
		$this->credentials = $credentials;
		$this->transport   = $transport;
		$this->budget      = $budget;
		$this->logger      = $logger;
		$this->clock       = $clock;
	}

	/**
	 * Describe the credential and the workspace it belongs to.
	 *
	 * This is the call to test a connection with: it needs no scope, so it
	 * reports a perfectly good key as good even when that key cannot do
	 * anything else yet. Probing with a real endpoint instead would report a
	 * missing permission as a broken key.
	 *
	 * @return Result
	 */
	public function me(): Result {
		return $this->request( 'GET', '/v1/me' );
	}

	/**
	 * Check the connection and remember what it found.
	 *
	 * @return Result
	 */
	public function verify(): Result {
		$result = $this->me();

		if ( $result->ok ) {
			$tenant     = (array) $result->get( 'tenant', array() );
			$credential = (array) $result->get( 'credential', array() );

			$this->credentials->remember_snapshot(
				array(
					'ok'            => true,
					'tenant_name'   => isset( $tenant['name'] ) ? (string) $tenant['name'] : '',
					'tenant_status' => isset( $tenant['status'] ) ? (string) $tenant['status'] : '',
					'mode'          => isset( $credential['mode'] ) ? (string) $credential['mode'] : '',
					'scopes'        => isset( $credential['scopes'] ) ? array_map( 'strval', (array) $credential['scopes'] ) : array(),
					'reason'        => '',
					'checked_at'    => $this->clock->now(),
				)
			);

			$this->budget->resume();

			return $result;
		}

		$this->credentials->remember_snapshot(
			array(
				'ok'         => false,
				'scopes'     => array(),
				'reason'     => $this->credentials->is_key_unreadable() ? 'undecryptable' : $result->code(),
				'checked_at' => $this->clock->now(),
			)
		);

		return $result;
	}

	/**
	 * The workspace's connected WhatsApp senders.
	 *
	 * Only connected ones are asked for. WA.cr derives that status from the
	 * same conditions its own send path applies, so "connected" means sendable
	 * and nothing else; offering a pending number in a picker would guarantee a
	 * refused send later.
	 *
	 * @param bool $force Bypass the cache.
	 * @return Result
	 */
	public function channels( bool $force = false ): Result {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_CHANNELS );

			if ( is_array( $cached ) ) {
				return Result::success( $cached );
			}
		}

		$result = $this->request(
			'GET',
			'/v1/channels',
			array(
				'status' => 'connected',
				'limit'  => 200,
			)
		);

		if ( $result->ok ) {
			set_transient( self::CACHE_CHANNELS, $result->value, self::CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Approved templates, optionally narrowed to one WhatsApp Business Account.
	 *
	 * Only APPROVED templates are asked for: nothing else can be sent, and a
	 * picker must offer only valid choices.
	 *
	 * @param string $waba_id Business account to scope to, or ''.
	 * @param bool   $force   Bypass the cache.
	 * @return Result
	 */
	public function templates( string $waba_id = '', bool $force = false ): Result {
		$cache_key = self::CACHE_TEMPLATES;

		if ( ! $force ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) && isset( $cached['waba'], $cached['value'] ) && $cached['waba'] === $waba_id ) {
				return Result::success( $cached['value'] );
			}
		}

		$query = array( 'status' => 'APPROVED' );

		if ( '' !== $waba_id ) {
			$query['wabaId'] = $waba_id;
		}

		$result = $this->request( 'GET', '/v1/templates', $query );

		if ( $result->ok ) {
			set_transient(
				$cache_key,
				array(
					'waba'  => $waba_id,
					'value' => $result->value,
				),
				self::CACHE_TTL
			);
		}

		return $result;
	}

	/**
	 * Mark a contact as opted out in the merchant's WA.cr workspace.
	 *
	 * What this DOES do: WA.cr expands every segment with a global
	 * `opted_out = false` filter, so a contact marked here stops being pulled
	 * into broadcasts and segment-based campaigns.
	 *
	 * What it does NOT do: a direct `/v1/messages` send does not consult the
	 * flag at all. Anything the merchant sends by API, or a flow sends by name,
	 * still reaches this person. That distinction is the whole reason the
	 * setting that calls this has to describe it precisely -- a merchant who
	 * believes they have a global stop switch, and has not, will find out from
	 * a customer.
	 *
	 * @param string $contact_id The WA.cr contact id.
	 * @return Result
	 */
	public function opt_out_contact( string $contact_id ): Result {
		$contact_id = trim( $contact_id );

		if ( '' === $contact_id ) {
			return Result::failure(
				Error::configuration( 'no_contact', __( 'No WA.cr contact to mark as opted out.', 'kdc-wacr-recoveryflow' ) )
			);
		}

		return $this->request( 'PATCH', '/v1/contacts/' . rawurlencode( $contact_id ), array(), array( 'optedOut' => true ) );
	}

	/**
	 * Send an approved template message.
	 *
	 * Never called without an attempt row already reserved, and never retried
	 * from inside this method. See the class docblock.
	 *
	 * @param Send_Request $request What to send.
	 * @return Result
	 */
	public function send_template( Send_Request $request ): Result {
		if ( ! $this->credentials->is_configured() ) {
			return Result::failure(
				Error::configuration(
					'not_configured',
					__( 'No WA.cr API key is saved, so RecoveryFlow cannot send. Add one on the settings screen.', 'kdc-wacr-recoveryflow' )
				)
			);
		}

		if ( ! $this->budget->take() ) {
			return Result::failure(
				new Error(
					Error::RATE_LIMIT,
					'local_budget',
					__( 'RecoveryFlow has used its share of this minute\'s API requests and will continue shortly.', 'kdc-wacr-recoveryflow' ),
					0,
					true,
					Error::NOT_SENT,
					60
				)
			);
		}

		$result = $this->request( 'POST', '/v1/messages', array(), $request->to_payload( $this->credentials->sender() ) );

		if ( ! $result->ok && $result->error instanceof Error && Error::RATE_LIMIT === $result->error->category ) {
			$this->budget->pause( $result->error->retry_after ?? 60 );
		}

		return $result;
	}

	/**
	 * Read a conversation back.
	 *
	 * The only way to learn that a customer replied, or what became of a
	 * message: WA.cr does not call us. Nothing here is stored -- the reply's
	 * text is read to decide whether it says STOP and then discarded.
	 *
	 * @param string      $e164  Customer number in E.164.
	 * @param string|null $after ISO-8601 instant to read forward from.
	 * @param int         $limit Maximum messages.
	 * @return Result
	 */
	public function conversation( string $e164, ?string $after = null, int $limit = 200 ): Result {
		$digits = Phone_Normalizer::digits( $e164 );

		if ( '' === $digits ) {
			return Result::failure( Error::configuration( 'no_number', __( 'No phone number to read a conversation for.', 'kdc-wacr-recoveryflow' ) ) );
		}

		$query = array( 'limit' => max( 1, min( 200, $limit ) ) );

		if ( null !== $after && '' !== $after ) {
			$query['after'] = $after;
		}

		return $this->request( 'GET', '/v1/conversations/' . rawurlencode( $digits ) . '/messages', $query );
	}

	/**
	 * Look a contact up by number.
	 *
	 * Used to read the workspace-level opt-out flag before sending, because
	 * WA.cr does not apply it to direct sends. Somebody who told the business
	 * to stop messaging them must not receive a cart reminder because the
	 * refusal was recorded in a different system.
	 *
	 * @param string $e164 Number in E.164.
	 * @return Result
	 */
	public function find_contact( string $e164 ): Result {
		$digits = Phone_Normalizer::digits( $e164 );

		if ( '' === $digits ) {
			return Result::failure( Error::configuration( 'no_number', __( 'No phone number to look up.', 'kdc-wacr-recoveryflow' ) ) );
		}

		$result = $this->request(
			'GET',
			'/v1/contacts',
			array(
				'q'     => $digits,
				'limit' => 100,
			)
		);

		if ( ! $result->ok ) {
			return $result;
		}

		$contacts = (array) $result->get( 'contacts', array() );

		foreach ( $contacts as $contact ) {
			if ( is_array( $contact ) && isset( $contact['phoneE164'] ) && $e164 === (string) $contact['phoneE164'] ) {
				return Result::success( array( 'contact' => $contact ) );
			}
		}

		// A search that matched nobody is a successful answer, not a failure.
		return Result::success( array( 'contact' => null ) );
	}

	/**
	 * Hand a recovery journey to a WA.cr Auto Flow.
	 *
	 * This is the path that needs no developer API access: the flow owns the
	 * waiting, the follow-ups and the stop-on-reply, and this plugin owns
	 * detecting that there is something worth recovering.
	 *
	 * The body is signed when a secret is configured, so the flow can refuse
	 * anything that did not come from this site. The URL alone is a credential
	 * otherwise -- fine for a first setup, worth hardening once it matters.
	 *
	 * @param array $payload Flat scalars the flow reads as variables.
	 * @return Result
	 */
	public function start_flow( array $payload ): Result {
		if ( ! $this->credentials->has_hook() ) {
			return Result::failure(
				Error::configuration(
					'no_hook',
					__( 'No WA.cr Auto Flow webhook URL is saved, so RecoveryFlow has nowhere to hand this journey. Add one on the settings screen.', 'kdc-wacr-recoveryflow' )
				)
			);
		}

		if ( ! $this->budget->take() ) {
			return Result::failure(
				new Error( Error::RATE_LIMIT, 'local_budget', __( 'RecoveryFlow has used its share of this minute\'s API requests and will continue shortly.', 'kdc-wacr-recoveryflow' ), 0, true, Error::NOT_SENT, 60 )
			);
		}

		$body    = wp_json_encode( $payload );
		$headers = array();
		$secret  = $this->credentials->hook_secret();

		if ( '' !== $secret && is_string( $body ) ) {
			$headers['x-wacr-signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$result = $this->transport->request( 'POST', $this->credentials->hook_url(), $headers, $payload );

		$this->log_result( 'start_flow', $result );

		return $result;
	}

	/**
	 * Make an authenticated API call.
	 *
	 * @param string     $method HTTP verb.
	 * @param string     $path   Path beginning with a slash.
	 * @param array      $query  Query arguments.
	 * @param array|null $body   JSON body.
	 * @return Result
	 */
	protected function request( string $method, string $path, array $query = array(), ?array $body = null ): Result {
		$key = $this->credentials->api_key();

		if ( '' === $key ) {
			$message = $this->credentials->is_key_unreadable()
				? __( 'The saved WA.cr API key can no longer be read, which happens when a site\'s security keys are rotated. Enter it again on the settings screen.', 'kdc-wacr-recoveryflow' )
				: __( 'No WA.cr API key is saved. Add one on the settings screen.', 'kdc-wacr-recoveryflow' );

			return Result::failure( Error::configuration( 'not_configured', $message ) );
		}

		$url = $this->credentials->base_url() . $path;

		if ( array() !== $query ) {
			$url = add_query_arg( $query, $url );
		}

		$result = $this->transport->request(
			$method,
			$url,
			array( 'Authorization' => 'Bearer ' . $key ),
			$body
		);

		$this->log_result( $method . ' ' . $path, $result );

		return $result;
	}

	/**
	 * Record a failure, without recording what was in it.
	 *
	 * Only the endpoint, the category and the code: never the payload, never
	 * the recipient, never the message.
	 *
	 * @param string $what   Which call.
	 * @param Result $result Outcome.
	 * @return void
	 */
	private function log_result( string $what, Result $result ): void {
		if ( $result->ok || ! $result->error instanceof Error ) {
			return;
		}

		$this->logger->warning(
			'wacr',
			sprintf( '%s failed: %s', $what, $result->error->category ),
			array(
				'code'       => $result->error->code,
				'status'     => $result->error->status,
				'send_state' => $result->error->send_state,
			)
		);
	}

	/**
	 * Drop the cached senders and templates.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		delete_transient( self::CACHE_CHANNELS );
		delete_transient( self::CACHE_TEMPLATES );
	}
}
