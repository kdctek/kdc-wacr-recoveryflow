<?php
/**
 * Normalised API errors.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

use WAcr\RecoveryFlow\Core\Feature_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * One shape for everything that can go wrong talking to WA.cr.
 *
 * Callers need three decisions from a failure and should not have to read HTTP
 * status codes to make them: may I retry, is this the merchant's problem or
 * ours, and what do I tell them. The category answers the first two; the
 * message answers the third and is written for a shop owner, not a developer.
 *
 * The subtle one is `send_state`. A request that failed before it left the
 * building can be retried safely. A request that timed out after the body was
 * written may or may not have sent a WhatsApp message, and retrying it would
 * message the customer twice and bill the merchant twice. Those two are not the
 * same failure and must never be treated as one.
 */
final class Error {

	// Categories.
	public const CONFIGURATION      = 'CONFIGURATION_ERROR';
	public const AUTHENTICATION     = 'AUTHENTICATION_ERROR';
	public const AUTHORIZATION      = 'AUTHORIZATION_ERROR';
	public const VALIDATION         = 'VALIDATION_ERROR';
	public const RATE_LIMIT         = 'RATE_LIMIT_ERROR';
	public const API                = 'API_ERROR';
	public const INTEGRATION        = 'INTEGRATION_ERROR';
	public const RECOVERY_EXPIRED   = 'RECOVERY_EXPIRED';
	public const RECOVERY_COMPLETED = 'RECOVERY_ALREADY_COMPLETED';

	// What we know about whether the request reached the other end.
	public const NOT_SENT = 'not_sent';
	public const UNKNOWN  = 'unknown';
	public const SENT     = 'sent';

	/**
	 * Category constant.
	 *
	 * @var string
	 */
	public string $category;

	/**
	 * Machine-readable code, from WA.cr where it gave one.
	 *
	 * @var string
	 */
	public string $code;

	/**
	 * Message for an administrator. Never contains customer data.
	 *
	 * @var string
	 */
	public string $message;

	/**
	 * HTTP status, or 0 when the request never completed.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Seconds to wait, when the API said so.
	 *
	 * @var int|null
	 */
	public ?int $retry_after;

	/**
	 * Whether the same request may be repeated.
	 *
	 * @var bool
	 */
	public bool $retryable;

	/**
	 * What is known about delivery: not_sent, unknown or sent.
	 *
	 * @var string
	 */
	public string $send_state;

	/**
	 * Constructor.
	 *
	 * @param string   $category    Category constant.
	 * @param string   $code        Machine-readable code.
	 * @param string   $message     Administrator-facing message.
	 * @param int      $status      HTTP status, 0 if none.
	 * @param bool     $retryable   Whether a retry is safe and useful.
	 * @param string   $send_state  not_sent, unknown or sent.
	 * @param int|null $retry_after Seconds to wait.
	 */
	public function __construct(
		string $category,
		string $code,
		string $message,
		int $status = 0,
		bool $retryable = false,
		string $send_state = self::NOT_SENT,
		?int $retry_after = null
	) {
		$this->category    = $category;
		$this->code        = $code;
		$this->message     = $message;
		$this->status      = $status;
		$this->retryable   = $retryable;
		$this->send_state  = $send_state;
		$this->retry_after = $retry_after;
	}

	/**
	 * Build an error from a WA.cr error envelope.
	 *
	 * @param int   $status      HTTP status.
	 * @param array $body        Decoded response body.
	 * @param int   $retry_after Retry-After header value, 0 when absent.
	 * @return Error
	 */
	public static function from_response( int $status, array $body, int $retry_after = 0 ): self {
		$code   = '';
		$detail = '';

		if ( isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$code   = isset( $body['error']['code'] ) ? (string) $body['error']['code'] : '';
			$detail = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
		}

		// A 4xx is a decision the other end has already made: the same request
		// will be refused again. Only 429 and 5xx are worth repeating.
		$retryable = 429 === $status || $status >= 500;

		// The request completed, so the other end decided. Nothing was sent
		// unless it told us it was.
		$send_state = self::NOT_SENT;

		return new self(
			self::categorise( $status, $code ),
			'' !== $code ? $code : 'http_' . $status,
			self::explain( $status, $code, $detail ),
			$status,
			$retryable,
			$send_state,
			$retry_after > 0 ? $retry_after : null
		);
	}

	/**
	 * Build an error for a request that never completed.
	 *
	 * @param string $code       Transport code.
	 * @param string $detail     Transport message.
	 * @param bool   $may_have_sent Whether the request may have reached WA.cr.
	 * @return Error
	 */
	public static function from_transport( string $code, string $detail, bool $may_have_sent ): self {
		if ( $may_have_sent ) {
			return new self(
				self::API,
				'timeout',
				__( 'WA.cr did not answer in time. RecoveryFlow does not know whether the message was sent, so it will check before trying again rather than risk sending twice.', 'kdc-wacr-recoveryflow' ),
				0,
				false,
				self::UNKNOWN
			);
		}

		return new self(
			self::API,
			'' !== $code ? $code : 'network_error',
			__( 'RecoveryFlow could not reach WA.cr. This is usually a temporary network problem and it will try again.', 'kdc-wacr-recoveryflow' ),
			0,
			true,
			self::NOT_SENT
		);
	}

	/**
	 * A configuration problem, which no retry will fix.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return Error
	 */
	public static function configuration( string $code, string $message ): self {
		return new self( self::CONFIGURATION, $code, $message, 0, false, self::NOT_SENT );
	}

	/**
	 * Which category a response belongs to.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   WA.cr error code.
	 * @return string
	 */
	private static function categorise( int $status, string $code ): string {
		if ( 'plan_upgrade_required' === $code || 'insufficient_scope' === $code ) {
			return self::AUTHORIZATION;
		}

		if ( 401 === $status ) {
			return self::AUTHENTICATION;
		}

		if ( 429 === $status ) {
			return self::RATE_LIMIT;
		}

		if ( 422 === $status || 400 === $status ) {
			return self::VALIDATION;
		}

		if ( 403 === $status ) {
			return self::AUTHORIZATION;
		}

		return self::API;
	}

	/**
	 * Say what happened in words a shop owner can act on.
	 *
	 * WA.cr's own message is included only where it names something the
	 * merchant controls; otherwise it is replaced, because "invalid_body:
	 * Required" tells nobody anything.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Error code.
	 * @param string $detail Message from WA.cr.
	 * @return string
	 */
	private static function explain( int $status, string $code, string $detail ): string {
		switch ( $code ) {
			case 'missing_token':
			case 'invalid_key':
				return __( 'WA.cr did not accept the API key. Check it on the RecoveryFlow settings screen, or create a new one in the WA.cr console.', 'kdc-wacr-recoveryflow' );

			case 'key_revoked':
				return __( 'This API key has been revoked in the WA.cr console. Create a new one and save it on the RecoveryFlow settings screen.', 'kdc-wacr-recoveryflow' );

			case 'insufficient_scope':
				return __( 'This API key is missing a permission RecoveryFlow needs. Edit the key in the WA.cr console and tick the scopes listed on the RecoveryFlow settings screen.', 'kdc-wacr-recoveryflow' );

			case 'plan_upgrade_required':
				return __( 'Sending directly from WordPress needs the WA.cr developer API, which is included from the Scale plan upwards. RecoveryFlow can hand journeys to a WA.cr Auto Flow instead.', 'kdc-wacr-recoveryflow' ) . ' ' . Feature_Gate::auto_flow_requirement();

			case 'rate_limited':
				return __( 'WA.cr is rate limiting this workspace. RecoveryFlow has paused sending and will resume shortly.', 'kdc-wacr-recoveryflow' );

			case 'unknown_sender':
			case 'unknown_channel':
				return __( 'The WhatsApp number RecoveryFlow is set to send from is not available. Choose a connected sender on the settings screen.', 'kdc-wacr-recoveryflow' );

			case 'send_failed':
				return __( 'WA.cr accepted the request but WhatsApp refused the message. Check that the template is approved and that its variables match.', 'kdc-wacr-recoveryflow' );

			case 'invalid_body':
				return __( 'WA.cr rejected the message. This usually means the template variables do not match what the approved template expects.', 'kdc-wacr-recoveryflow' );
		}//end switch

		if ( $status >= 500 ) {
			return __( 'WA.cr reported a problem on its side. RecoveryFlow will try again.', 'kdc-wacr-recoveryflow' );
		}

		if ( '' !== $detail ) {
			return $detail;
		}

		return __( 'WA.cr refused the request.', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * Whether this failure should stop the plugin sending at all.
	 *
	 * A revoked key or a plan change is not a per-journey problem: continuing
	 * to try would burn through every scheduled journey turning each one into
	 * the same failure.
	 *
	 * @return bool
	 */
	public function is_fatal_for_sending(): bool {
		return in_array( $this->category, array( self::AUTHENTICATION, self::AUTHORIZATION, self::CONFIGURATION ), true );
	}
}
