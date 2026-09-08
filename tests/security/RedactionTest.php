<?php
/**
 * What must never reach a log, a diagnostic report or a support thread.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Security;

use WAcr\RecoveryFlow\Privacy\Redactor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The last line before text leaves the site.
 *
 * The diagnostic report is built from an allow-list rather than by dumping and
 * redacting, which is the real defence. This runs over the finished text anyway,
 * as a second guarantee, and the reason it is worth a suite of its own is that
 * everything it catches is something a person pastes into a public forum.
 *
 * Both directions matter. A redactor that removed everything would be perfectly
 * safe and useless, so each case here asserts that the surrounding text survives
 * as well as that the secret does not.
 */
final class RedactionTest extends TestCase {

	/**
	 * Things that must not survive, and a fragment that must.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function secrets(): array {
		return array(
			'a phone number in E.164' => array( '+447700900123', 'Sending to' ),
			'an email address'        => array( 'ada@example.test', 'Sending to' ),
			'a live API key'          => array( 'wacr_live_abcdef0123456789', 'Sending to' ),
			'a test API key'          => array( 'waht_test_abcdef0123456789', 'Sending to' ),
			'a bearer header'         => array( 'Bearer abcdef0123456789abcdef', 'Sending to' ),
		);
	}

	/**
	 * Each is removed, and the sentence around it is not.
	 *
	 * @dataProvider secrets
	 * @param string $secret    What must not survive.
	 * @param string $surviving A fragment that must.
	 * @return void
	 */
	public function test_a_secret_does_not_survive_scrubbing( string $secret, string $surviving ) {
		$scrubbed = Redactor::scrub_string( "Sending to {$secret} now." );

		$this->assertStringNotContainsString( $secret, $scrubbed );
		$this->assertStringContainsString( $surviving, $scrubbed, 'A redactor that erased the message would be safe and useless.' );
	}

	/**
	 * A recovery token is a working credential, so it goes too.
	 *
	 * @return void
	 */
	public function test_a_recovery_token_is_removed() {
		$token    = str_repeat( 'a', 43 );
		$scrubbed = Redactor::scrub_string( "Visit https://shop.example/recovery/{$token} to restore." );

		$this->assertStringNotContainsString( $token, $scrubbed );
	}

	/**
	 * Ordinary text is left alone entirely.
	 *
	 * @return void
	 */
	public function test_ordinary_text_passes_through() {
		$message = 'The dispatch pass ran and found nothing due.';

		$this->assertSame( $message, Redactor::scrub_string( $message ) );
	}

	/**
	 * Values are removed by key as well as by pattern.
	 *
	 * A number nobody managed to write in E.164 is still a number.
	 *
	 * @return void
	 */
	public function test_a_value_is_removed_by_its_key() {
		$scrubbed = Redactor::scrub(
			array(
				'phone' => '07700 900123',
				'stage' => 'dispatch',
			)
		);

		$this->assertIsArray( $scrubbed );
		$this->assertNotSame( '07700 900123', $scrubbed['phone'] ?? null );
		$this->assertSame( 'dispatch', $scrubbed['stage'] ?? null, 'The keys that describe what happened are the point of a log.' );
	}

	/**
	 * Nesting does not smuggle anything out.
	 *
	 * @return void
	 */
	public function test_nesting_does_not_smuggle_a_secret_out() {
		$scrubbed = Redactor::scrub(
			array(
				'request' => array(
					'body' => array(
						'to' => '+447700900123',
					),
				),
			)
		);

		$this->assertStringNotContainsString( '+447700900123', print_r( $scrubbed, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- flattening a nested array so one assertion can look through all of it.
	}
}
