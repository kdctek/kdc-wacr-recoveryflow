<?php
/**
 * The system status REST endpoint.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\REST;

use WAcr\RecoveryFlow\Admin\Settings\Schema as Settings_Schema;
use WAcr\RecoveryFlow\Core\Health;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\WAcr\Client;
use WAcr\RecoveryFlow\WAcr\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "is this working, and if not, what do I do about it?".
 *
 * Every check reports the same four things: what was checked, whether it
 * passed, a sentence a shopkeeper can act on, and -- where there is one -- a
 * deeplink to the exact setting that fixes it. That last field is the reason
 * this is worth building rather than printing a list of ticks: "missing postal
 * address" is a diagnosis, and a link to the box you type it into is a fix.
 *
 * Severity is `ok`, `warning` or `error` and is always accompanied by the
 * sentence, never conveyed by a colour on its own.
 */
final class Status_Controller extends Abstract_Controller {

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * WA.cr client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * The health checks, shared with the status screen.
	 *
	 * @var Health
	 */
	private Health $health;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Credentials.
	 * @param Client      $client      WA.cr client.
	 * @param Health      $health      The health checks.
	 */
	public function __construct( Credentials $credentials, Client $client, Health $health ) {
		$this->credentials = $credentials;
		$this->client      = $client;
		$this->health      = $health;
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Routes::REST_NAMESPACE,
			Routes::path( 'status' ),
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->require_cap( Capabilities::VIEW_STATUS ),
				),
			)
		);

		register_rest_route(
			Routes::REST_NAMESPACE,
			Routes::path( 'connection/test' ),
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'test_connection' ),
					'permission_callback' => $this->require_cap( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);
	}

	/**
	 * Every check.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'checks' => $this->health->checks(),
				'stages' => $this->health->stage_report(),
			)
		);
	}

	/**
	 * Ask WA.cr whether the saved credential works.
	 *
	 * A live call, made only when somebody presses the button. Checking a
	 * credential on every page load of a status screen would turn an
	 * intermittent network into an intermittent status screen, and would make
	 * the shop's own admin depend on somebody else's uptime.
	 *
	 * @return \WP_REST_Response
	 */
	public function test_connection(): \WP_REST_Response {
		if ( ! $this->credentials->has_api_key() ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'There is no API key saved to test.', 'kdc-wacr-recoveryflow' ),
					'link'    => Settings_Schema::deeplink( Settings_Schema::FIELD_API_KEY ),
				)
			);
		}

		$result = $this->client->verify();

		if ( ! $result->ok ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => null === $result->error ? __( 'WA.cr could not be reached.', 'kdc-wacr-recoveryflow' ) : $result->error->message,
					'link'    => Settings_Schema::deeplink( Settings_Schema::FIELD_API_KEY ),
				)
			);
		}

		$tenant = (string) $result->get( 'tenant_name', '' );

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => '' === $tenant
					? __( 'Connected to WA.cr.', 'kdc-wacr-recoveryflow' )
					: sprintf(
						/* translators: %s: the name of the connected WA.cr workspace. */
						__( 'Connected to %s.', 'kdc-wacr-recoveryflow' ),
						$tenant
					),
				'link'    => '',
			)
		);
	}
}
