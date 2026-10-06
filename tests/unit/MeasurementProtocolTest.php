<?php
/**
 * The shape of what is sent to Google.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use WAcr\RecoveryFlow\Analytics\Ga4_Settings;
use WAcr\RecoveryFlow\Analytics\Measurement_Protocol;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Valid by construction, because Google will not say when it is not.
 *
 * The live endpoint answers 2xx to a malformed payload and drops it, so a
 * mistake in these shapes would never be reported by anything: the reports
 * would simply never appear in GA4. These tests are the only place the shape
 * is checked.
 */
final class MeasurementProtocolTest extends TestCase {

	/**
	 * The global and EU hosts, and the debug path.
	 *
	 * @return void
	 */
	public function test_the_url_carries_the_id_and_secret_to_the_right_host(): void {
		$this->assertSame(
			'https://www.google-analytics.com/mp/collect?measurement_id=G-ABC123&api_secret=s3cr%2Bt',
			Measurement_Protocol::url( Ga4_Settings::REGION_GLOBAL, 'G-ABC123', 's3cr+t' )
		);
		$this->assertStringStartsWith(
			'https://region1.google-analytics.com/debug/mp/collect?',
			Measurement_Protocol::url( Ga4_Settings::REGION_EU, 'G-ABC123', 'x', true )
		);
	}

	/**
	 * Parameters Google would refuse are dropped, numbers stay numbers.
	 *
	 * @return void
	 */
	public function test_an_event_keeps_only_what_google_accepts(): void {
		$event = Measurement_Protocol::event(
			'recoveryflow_recovered',
			array(
				'journey_ref' => 'abc',
				'value'       => 49.5,
				'Bad-Key'     => 'dropped',
				'_reserved'   => 'dropped',
				'ga_session'  => 'dropped',
				'google_x'    => 'dropped',
				'empty'       => '',
				'long'        => str_repeat( 'y', 300 ),
				'nested'      => array( 'dropped' ),
			),
			1757000000000000
		);

		$params = (array) $event['params'];

		$this->assertSame( 'recoveryflow_recovered', $event['name'] );
		$this->assertSame( 1757000000000000, $event['timestamp_micros'] );
		$this->assertSame( array( 'journey_ref', 'value', 'long' ), array_keys( $params ) );
		$this->assertSame( 49.5, $params['value'] );
		$this->assertSame( 100, strlen( $params['long'] ) );
	}

	/**
	 * At most 25 parameters per event.
	 *
	 * @return void
	 */
	public function test_an_event_is_capped_at_twenty_five_params(): void {
		$params = array();

		for ( $i = 0; $i < 40; $i++ ) {
			$params[ 'p' . $i ] = 'v';
		}

		$this->assertCount( Measurement_Protocol::MAX_PARAMS, (array) Measurement_Protocol::event( 'recoveryflow_messaged', $params )['params'] );
	}

	/**
	 * An event with no params still encodes params as an object, as Google expects.
	 *
	 * @return void
	 */
	public function test_empty_params_encode_as_an_object(): void {
		$json = (string) json_encode( Measurement_Protocol::event( 'recoveryflow_opted_out', array() ) );

		$this->assertStringContainsString( '"params":{}', $json );
	}

	/**
	 * Consent is only ever stated as a refusal.
	 *
	 * @return void
	 */
	public function test_consent_is_sent_only_to_refuse(): void {
		$silent  = Measurement_Protocol::payload( 'GA1.1.1.1', array(), false );
		$refused = Measurement_Protocol::payload( 'GA1.1.1.1', array(), true );

		$this->assertArrayNotHasKey( 'consent', $silent, 'Omitting it lets GA4 use what the site\'s own tag recorded; sending GRANTED would claim a consent we never saw.' );
		$this->assertSame(
			array(
				'ad_user_data'       => 'DENIED',
				'ad_personalization' => 'DENIED',
			),
			$refused['consent']
		);
	}

	/**
	 * One request never carries more than 25 events.
	 *
	 * @return void
	 */
	public function test_a_payload_is_capped_at_twenty_five_events(): void {
		$events = array_fill( 0, 30, Measurement_Protocol::event( 'recoveryflow_messaged', array() ) );

		$this->assertCount( Measurement_Protocol::MAX_EVENTS, Measurement_Protocol::payload( 'GA1.1.1.1', $events, false )['events'] );
	}
}
