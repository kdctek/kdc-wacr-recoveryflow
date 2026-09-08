<?php
/**
 * HTTP transport.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

defined( 'ABSPATH' ) || exit;

/**
 * The only place in the plugin that makes an HTTP request.
 *
 * Keeping it in one class means the timeout, the certificate check, the user
 * agent and -- most importantly -- the interpretation of a failure are decided
 * once. Everything else asks for a Result and never sees a response array.
 *
 * The interpretation is the interesting part. WordPress reports a DNS failure
 * and a read timeout as the same kind of WP_Error, but they mean opposite
 * things to a caller about to retry: one request never left, the other may have
 * been fully processed. Getting that wrong sends a customer two identical
 * WhatsApp messages and bills the merchant twice, so the two are separated
 * here and the ambiguous case is reported as ambiguous rather than guessed.
 */
class Transport {

	public const TIMEOUT = 15;

	/**
	 * Perform a request.
	 *
	 * @param string               $method  HTTP verb.
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Request headers.
	 * @param array|null           $body    Body to JSON-encode, or null.
	 * @return Result
	 */
	public function request( string $method, string $url, array $headers = array(), ?array $body = null ): Result {
		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => $this->user_agent(),
				),
				$headers
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return Result::failure(
				Error::from_transport(
					$response->get_error_code(),
					$response->get_error_message(),
					$this->may_have_been_sent( (string) $response->get_error_code(), (string) $response->get_error_message() )
				)
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $status >= 400 ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

			return Result::failure( Error::from_response( $status, $decoded, $retry_after ) );
		}

		return Result::success( $decoded, $status );
	}

	/**
	 * Whether a transport failure might still have been processed remotely.
	 *
	 * A connection that was never established cannot have sent anything. A
	 * timeout, on the other hand, happens after the request has been written,
	 * so the far end may well have acted on it.
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message.
	 * @return bool
	 */
	protected function may_have_been_sent( string $code, string $message ): bool {
		$definitely_not = array(
			'could not resolve host',
			'couldn\'t resolve host',
			'connection refused',
			'failed to connect',
			'ssl certificate problem',
			'certificate verify failed',
			'name or service not known',
			'no route to host',
			'network is unreachable',
		);

		$haystack = strtolower( $code . ' ' . $message );

		foreach ( $definitely_not as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return false;
			}
		}

		// Anything else -- a timeout, a reset mid-response, an unreadable reply
		// -- is genuinely unknown, and must be treated as possibly delivered.
		return true;
	}

	/**
	 * Identify the plugin to the API.
	 *
	 * @return string
	 */
	protected function user_agent(): string {
		return sprintf(
			'RecoveryFlow/%s (WordPress/%s; %s)',
			KDC_WACR_RECOVERYFLOW_VERSION,
			get_bloginfo( 'version' ),
			home_url( '/' )
		);
	}
}
