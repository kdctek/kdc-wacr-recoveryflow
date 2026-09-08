<?php
/**
 * Telling a machine that opened a link from a person who tapped one.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use WAcr\RecoveryFlow\Support\User_Agent;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The recognisable fetchers, and the honest limit of the list.
 *
 * WhatsApp fetches every URL in a message to build its preview card, and mail
 * providers do the same on delivery. Counting those as engagement would mark
 * every journey engaged the moment it was messaged.
 *
 * The last test here asserts the LIMIT rather than the feature, and it is the
 * important one: corporate link scanners open every URL behind an ordinary
 * browser's user agent and cannot be recognised at all. Guessing at their names
 * would look like coverage while catching nothing, so the absence is deliberate
 * and is written down as a test rather than as a comment somebody can delete.
 */
final class LinkPreviewTest extends TestCase {

	/**
	 * Fetchers that identify themselves.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function announced_fetchers(): array {
		return array(
			'WhatsApp'     => array( 'WhatsApp/2.23.20.0 A' ),
			'Facebook'     => array( 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' ),
			'Gmail proxy'  => array( 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)' ),
			'Yahoo proxy'  => array( 'YahooMailProxy; https://help.yahoo.com/kb/yahoo-mail-proxy-SLN28749.html' ),
			'Bing preview' => array( 'Mozilla/5.0 (compatible; BingPreview/1.0b)' ),
		);
	}

	/**
	 * Each is recognised.
	 *
	 * @dataProvider announced_fetchers
	 * @param string $ua The user agent.
	 * @return void
	 */
	public function test_an_announced_fetcher_is_not_a_customer( string $ua ) {
		$this->assertTrue( User_Agent::is_link_preview( $ua ) );
	}

	/**
	 * A real browser is.
	 *
	 * @return void
	 */
	public function test_a_person_in_a_browser_is_a_customer() {
		$this->assertFalse(
			User_Agent::is_link_preview(
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1'
			)
		);
	}

	/**
	 * No header at all is not a fetcher.
	 *
	 * Failing the other way would discard the clicks of every privacy tool that
	 * strips the header.
	 *
	 * @return void
	 */
	public function test_a_missing_user_agent_is_not_treated_as_a_fetcher() {
		$this->assertFalse( User_Agent::is_link_preview( '' ) );
	}

	/**
	 * Matching ignores case, because these strings are not a standard.
	 *
	 * @return void
	 */
	public function test_matching_ignores_case() {
		$this->assertTrue( User_Agent::is_link_preview( 'whatsapp/2.23.20.0 A' ) );
	}

	/**
	 * THE HONEST LIMIT. A corporate scanner looks exactly like a person.
	 *
	 * @return void
	 */
	public function test_a_corporate_scanner_cannot_be_recognised_and_that_is_known() {
		$this->assertFalse(
			User_Agent::is_link_preview(
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
			),
			'Safe Links and its equivalents send this exact string. What makes that survivable is not the list: a click sets no state, and the opt-out refuses a GET.'
		);
	}
}
