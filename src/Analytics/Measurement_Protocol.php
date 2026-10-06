<?php
/**
 * Sends events to a GA4 property over the Measurement Protocol.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Analytics;

use WAcr\RecoveryFlow\WAcr\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a request to Google is built and sent.
 *
 * What Google documents, and what this class does about it (developer docs for
 * the GA4 Measurement Protocol, read 2026-10-06):
 *
 * - **The live endpoint answers 2xx to anything**, including a malformed
 *   payload. A 2xx therefore means "it arrived", never "it was valid". The
 *   payload is built here from fixed shapes so that it is valid by
 *   construction, and the unit tests hold the shapes.
 * - **The debug endpoint validates the payload but not the credentials** --
 *   it "does not validate the api_secret or measurement_id". validate() is
 *   honest about that in what it returns.
 * - **Up to 25 events per request**, all for one client id.
 * - **Backdating is limited to 72 hours**; older events are silently
 *   re-stamped to "72 hours ago". The stage refuses to send them instead.
 * - **The `consent` object, when omitted**, makes GA4 use whatever the site's
 *   own tag recorded for that client. It is therefore sent only to say DENIED,
 *   when the site's consent tool refused marketing; we never claim a GRANTED
 *   we did not see.
 * - **No personal data** may be sent outside the hashed user_data block, which
 *   this plugin does not use. Event params are a journey reference, a workflow,
 *   a source and money.
 *
 * The API secret travels in the query string because that is where Google
 * takes it. The URL is therefore never logged, and the redactor masks an
 * `api_secret=` in any string that reaches a log anyway.
 */
final class Measurement_Protocol {

	/**
	 * Google's global collection host.
	 */
	public const HOST_GLOBAL = 'https://www.google-analytics.com';

	/**
	 * Google's EU collection host.
	 */
	public const HOST_EU = 'https://region1.google-analytics.com';

	/**
	 * Events Google accepts in one request.
	 */
	public const MAX_EVENTS = 25;

	/**
	 * Parameters Google accepts on one event.
	 */
	public const MAX_PARAMS = 25;

	/**
	 * Longest parameter value Google keeps on a standard property.
	 */
	public const MAX_VALUE_LENGTH = 100;

	/**
	 * Google has it.
	 */
	public const OUTCOME_SENT = 'sent';

	/**
	 * Certainly did not arrive; safe to try again.
	 */
	public const OUTCOME_RETRY = 'retry';

	/**
	 * May have arrived. Never sent again, because GA4 would count it twice.
	 */
	public const OUTCOME_UNKNOWN = 'unknown';

	/**
	 * Google refused it, and the same request will be refused again.
	 */
	public const OUTCOME_REJECTED = 'rejected';

	/**
	 * Seconds to wait for Google. Short: this runs inside a time-budgeted stage.
	 */
	private const TIMEOUT = 5;

	/**
	 * The collection URL.
	 *
	 * @param string $region         Ga4_Settings::REGION_* constant.
	 * @param string $measurement_id Measurement ID.
	 * @param string $api_secret     API secret.
	 * @param bool   $debug          Whether to use the validation endpoint.
	 * @return string
	 */
	public static function url( string $region, string $measurement_id, string $api_secret, bool $debug = false ): string {
		$host = Ga4_Settings::REGION_EU === $region ? self::HOST_EU : self::HOST_GLOBAL;
		$path = $debug ? '/debug/mp/collect' : '/mp/collect';

		return $host . $path . '?' . http_build_query(
			array(
				'measurement_id' => $measurement_id,
				'api_secret'     => $api_secret,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * One event, cleaned to what Google accepts.
	 *
	 * Parameter names that are not lower-case words are dropped rather than
	 * repaired; values are cut to the length Google keeps; numbers stay
	 * numbers, because `value` must be one for GA4 to sum it.
	 *
	 * @param string              $name             Event name.
	 * @param array<string,mixed> $params           Parameters.
	 * @param int                 $timestamp_micros When it happened, in microseconds since the epoch; 0 for now.
	 * @return array<string,mixed>
	 */
	public static function event( string $name, array $params, int $timestamp_micros = 0 ): array {
		$clean = array();

		foreach ( $params as $key => $value ) {
			$key = (string) $key;

			// Lower-case words of up to 40 characters, and none of the
			// prefixes Google reserves for itself.
			if ( count( $clean ) >= self::MAX_PARAMS
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key )
				|| 1 === preg_match( '/^(?:ga_|google_|firebase_)/', $key ) ) {
				continue;
			}

			if ( is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) && '' !== $value ) {
				$clean[ $key ] = substr( $value, 0, self::MAX_VALUE_LENGTH );
			}
		}

		$event = array(
			'name'   => $name,
			'params' => (object) $clean,
		);

		if ( $timestamp_micros > 0 ) {
			$event['timestamp_micros'] = $timestamp_micros;
		}

		return $event;
	}

	/**
	 * A request body for one client.
	 *
	 * @param string                         $client_id  The full `_ga` cookie value.
	 * @param array<int,array<string,mixed>> $events     Events from event(), at most MAX_EVENTS.
	 * @param bool                           $ads_denied Whether the site's consent tool refused marketing.
	 * @return array<string,mixed>
	 */
	public static function payload( string $client_id, array $events, bool $ads_denied ): array {
		$payload = array(
			'client_id' => $client_id,
			'events'    => array_values( array_slice( $events, 0, self::MAX_EVENTS ) ),
		);

		if ( $ads_denied ) {
			$payload['consent'] = array(
				'ad_user_data'       => 'DENIED',
				'ad_personalization' => 'DENIED',
			);
		}

		return $payload;
	}

	/**
	 * Send a body to the live endpoint and classify what happened.
	 *
	 * @param string              $url     From url().
	 * @param array<string,mixed> $payload From payload().
	 * @return string One of the OUTCOME_* constants.
	 */
	public function send( string $url, array $payload ): string {
		$response = $this->post( $url, $payload );

		if ( is_wp_error( $response ) ) {
			return Transport::failure_may_have_been_sent( (string) $response->get_error_code(), (string) $response->get_error_message() )
				? self::OUTCOME_UNKNOWN
				: self::OUTCOME_RETRY;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return self::OUTCOME_SENT;
		}

		// A 5xx or a 429 is Google saying "not now"; anything else in the 4xx
		// range is a request it will refuse every time.
		if ( 429 === $status || $status >= 500 ) {
			return self::OUTCOME_RETRY;
		}

		return self::OUTCOME_REJECTED;
	}

	/**
	 * Ask the validation endpoint what it thinks of a body.
	 *
	 * @param string              $url     From url() with $debug true.
	 * @param array<string,mixed> $payload From payload().
	 * @return array<int,string>|\WP_Error Google's validation messages, empty when it found nothing; or why it could not be asked.
	 */
	public function validate( string $url, array $payload ) {
		$response = $this->post( $url, $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error( 'recoveryflow_ga4_http', '', array( 'status' => $status ) );
		}

		$decoded  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$messages = array();

		if ( is_array( $decoded ) && isset( $decoded['validationMessages'] ) && is_array( $decoded['validationMessages'] ) ) {
			foreach ( $decoded['validationMessages'] as $message ) {
				if ( is_array( $message ) && isset( $message['description'] ) ) {
					$messages[] = sanitize_text_field( (string) $message['description'] );
				}
			}
		}

		return $messages;
	}

	/**
	 * POST a JSON body.
	 *
	 * The safe variant refuses private and loopback addresses, and the host
	 * is one of two constants, so nothing a merchant types can point this at
	 * anything but Google.
	 *
	 * @param string              $url     Endpoint.
	 * @param array<string,mixed> $payload Body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function post( string $url, array $payload ) {
		return wp_safe_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => (string) wp_json_encode( $payload ),
				'user-agent'  => sprintf( 'RecoveryFlow/%s (WordPress)', defined( 'KDC_WACR_RECOVERYFLOW_VERSION' ) ? KDC_WACR_RECOVERYFLOW_VERSION : '0' ),
			)
		);
	}
}
