<?php
/**
 * The "Send a test event" button on the Analytics settings tab.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Analytics\Client_Id;
use WAcr\RecoveryFlow\Analytics\Ga4_Settings;
use WAcr\RecoveryFlow\Analytics\Measurement_Protocol;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one test event, and says honestly what that proves.
 *
 * Google gives a plugin no way to check a Measurement ID and API secret. The
 * live endpoint answers 2xx to anything, and the validation endpoint "does not
 * validate the api_secret or measurement_id". So this does the two things
 * that can be done and claims nothing more:
 *
 * 1. asks the validation endpoint whether the event is well formed, and shows
 *    any complaint it has;
 * 2. sends one `recoveryflow_test` event to the live endpoint with debug_mode
 *    on, and tells the merchant where to look for it: GA4's DebugView. If it
 *    appears there, the pair works. If it does not, the ID or the secret is
 *    wrong, and only the merchant can see which.
 *
 * The test is keyed on the merchant's own GA cookie when this browser has one,
 * so DebugView shows it against their own device; otherwise on a throwaway id.
 */
final class Ga4_Test {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'recoveryflow_ga4_test';

	/**
	 * The id of the deferred form the button submits.
	 */
	public const FORM_ID = 'recoveryflow-ga4-test-form';

	/**
	 * The event name the test sends.
	 */
	public const EVENT = 'recoveryflow_test';

	/**
	 * Seconds the result waits to be shown.
	 */
	private const RESULT_TTL = 60;

	/**
	 * The Measurement Protocol client.
	 *
	 * @var Measurement_Protocol
	 */
	private Measurement_Protocol $client;

	/**
	 * Constructor.
	 *
	 * @param Measurement_Protocol $client The Measurement Protocol client.
	 */
	public function __construct( Measurement_Protocol $client ) {
		$this->client = $client;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Run the test and go back to the settings tab.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to test Google Analytics reporting on this site.', 'kdc-wacr-recoveryflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		set_transient( self::transient_key(), $this->check(), self::RESULT_TTL );

		wp_safe_redirect( Screen::settings_url( 'analytics', 'ga4', 'ga4_events_enabled' ) );
		exit;
	}

	/**
	 * Validate, then send.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function check(): array {
		$id     = Ga4_Settings::measurement_id();
		$secret = Ga4_Settings::api_secret();

		if ( '' === $id || '' === $secret ) {
			return array(
				'ok'      => false,
				'message' => __( 'A Measurement ID and an API secret are both needed. Enter them and save, then test.', 'kdc-wacr-recoveryflow' ),
			);
		}

		$payload = Measurement_Protocol::payload( self::client_id(), array( self::event() ), false );
		$region  = Ga4_Settings::region();

		$messages = $this->client->validate( Measurement_Protocol::url( $region, $id, $secret, true ), $payload );

		if ( is_wp_error( $messages ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Google could not be reached. Check that this site can make outbound requests, then test again.', 'kdc-wacr-recoveryflow' ),
			);
		}

		if ( array() !== $messages ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: the validation messages Google returned, in Google's own words. */
					__( 'Google refused the test event: %s', 'kdc-wacr-recoveryflow' ),
					implode( ' ', $messages )
				),
			);
		}

		$outcome = $this->client->send( Measurement_Protocol::url( $region, $id, $secret ), $payload );

		if ( Measurement_Protocol::OUTCOME_SENT !== $outcome ) {
			return array(
				'ok'      => false,
				'message' => __( 'The test event was well formed, but sending it to Google failed. Try again in a minute.', 'kdc-wacr-recoveryflow' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: the event name, recoveryflow_test. */
				__( 'Google accepted a test event called %s. Google does not tell a plugin whether the Measurement ID and API secret match, so open DebugView in GA4 (Admin, DebugView): if the event appears within a minute, reporting will work.', 'kdc-wacr-recoveryflow' ),
				self::EVENT
			),
		);
	}

	/**
	 * The test event.
	 *
	 * Built by hand rather than through Measurement_Protocol::event(), which
	 * drops booleans: debug_mode has to be true for DebugView to show it.
	 *
	 * @return array<string,mixed>
	 */
	private static function event(): array {
		return array(
			'name'   => self::EVENT,
			'params' => array(
				'debug_mode'           => true,
				'engagement_time_msec' => 1,
			),
		);
	}

	/**
	 * This browser's GA client id, or a throwaway one.
	 *
	 * @return string
	 */
	private static function client_id(): string {
		$own = isset( $_COOKIE[ Client_Id::COOKIE ] ) && is_string( $_COOKIE[ Client_Id::COOKIE ] )
			? Client_Id::normalize( sanitize_text_field( wp_unslash( $_COOKIE[ Client_Id::COOKIE ] ) ) )
			: '';

		return '' !== $own ? $own : sprintf( 'GA1.1.%d.%d', wp_rand( 100000000, 999999999 ), time() );
	}

	/**
	 * The button, beside the reporting fields.
	 *
	 * @return void
	 */
	public static function button(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		printf(
			'<p class="recoveryflow-connection-test">
				<button type="submit" form="%1$s" class="button">%2$s</button>
				<span class="description">%3$s</span>
			</p>',
			esc_attr( Deferred_Form::need( self::FORM_ID, self::ACTION ) ),
			esc_html__( 'Send a test event', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'Checks the event with Google, then sends one recoveryflow_test event to your property. Save first.', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * Show the result of the last test, once.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$result = get_transient( self::transient_key() );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( self::transient_key() );

		$ok = ! empty( $result['ok'] );

		printf(
			'<div class="notice %1$s" role="status"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_attr( $ok ? 'notice-success' : 'notice-error' ),
			esc_html(
				$ok
					? __( 'Test event sent.', 'kdc-wacr-recoveryflow' )
					: __( 'Test event not sent.', 'kdc-wacr-recoveryflow' )
			),
			esc_html( isset( $result['message'] ) ? (string) $result['message'] : '' )
		);
	}

	/**
	 * Where this user's result waits.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return 'recoveryflow_ga4_test_' . get_current_user_id();
	}
}
