<?php
/**
 * The campaign tags on a recovery link's destination.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use WAcr\RecoveryFlow\Analytics\Utm_Tagger;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Adding utm_* tags to an address without breaking it or overruling the shop.
 *
 * The address being tagged is the shop's own checkout or form, so it may
 * already carry a query string, a fragment, or the shop's own campaign tags.
 * Each rule below is one of those ways a careless append would break a
 * restore or misattribute a sale.
 */
final class RestoreUtmTest extends TestCase {

	/**
	 * The tags a WhatsApp message's link carries.
	 *
	 * @return array<string,string>
	 */
	private function tags(): array {
		return array(
			'utm_source'   => 'recoveryflow',
			'utm_medium'   => 'whatsapp',
			'utm_campaign' => 'default-cart',
			'utm_content'  => 'step-2',
		);
	}

	/**
	 * A bare address gains a query string.
	 *
	 * @return void
	 */
	public function test_a_bare_address_gains_a_query_string(): void {
		$this->assertSame(
			'https://shop.example/checkout/?utm_source=recoveryflow&utm_medium=whatsapp&utm_campaign=default-cart&utm_content=step-2',
			Utm_Tagger::merge( 'https://shop.example/checkout/', $this->tags() )
		);
	}

	/**
	 * An existing query string is extended, not replaced.
	 *
	 * @return void
	 */
	public function test_an_existing_query_is_extended(): void {
		$tagged = Utm_Tagger::merge( 'https://shop.example/?gf_token=abc', $this->tags() );

		$this->assertStringStartsWith( 'https://shop.example/?gf_token=abc&utm_source=recoveryflow&', $tagged );
	}

	/**
	 * The fragment stays last, where a browser reads it as a fragment.
	 *
	 * @return void
	 */
	public function test_the_fragment_stays_at_the_end(): void {
		$tagged = Utm_Tagger::merge( 'https://shop.example/form/#gf_7', array( 'utm_source' => 'recoveryflow' ) );

		$this->assertSame( 'https://shop.example/form/?utm_source=recoveryflow#gf_7', $tagged );
	}

	/**
	 * A tag the shop already put on its own address wins.
	 *
	 * @return void
	 */
	public function test_the_shops_own_tag_is_never_overwritten(): void {
		$tagged = Utm_Tagger::merge( 'https://shop.example/checkout/?utm_source=newsletter', $this->tags() );

		$this->assertStringContainsString( 'utm_source=newsletter', $tagged );
		$this->assertStringNotContainsString( 'utm_source=recoveryflow', $tagged );
		$this->assertStringContainsString( 'utm_medium=whatsapp', $tagged );
	}

	/**
	 * A filter cannot use this to put anything other than utm_* on the URL.
	 *
	 * @return void
	 */
	public function test_only_utm_keys_are_added(): void {
		$tagged = Utm_Tagger::merge(
			'https://shop.example/',
			array(
				'utm_source'  => 'recoveryflow',
				'token'       => 'secret',
				'add-to-cart' => '12',
			)
		);

		$this->assertSame( 'https://shop.example/?utm_source=recoveryflow', $tagged );
	}

	/**
	 * Empty values are dropped and long ones cut, so a filter cannot bloat the URL.
	 *
	 * @return void
	 */
	public function test_empty_values_are_dropped_and_long_ones_cut(): void {
		$tagged = Utm_Tagger::merge(
			'https://shop.example/',
			array(
				'utm_source'   => '',
				'utm_campaign' => str_repeat( 'x', 300 ),
			)
		);

		$this->assertSame( 'https://shop.example/?utm_campaign=' . str_repeat( 'x', 100 ), $tagged );
	}

	/**
	 * Values are encoded, so a slug with a space or an ampersand cannot split the query.
	 *
	 * @return void
	 */
	public function test_values_are_encoded(): void {
		$this->assertSame(
			'https://shop.example/?utm_campaign=a%20b%26c',
			Utm_Tagger::merge( 'https://shop.example/', array( 'utm_campaign' => 'a b&c' ) )
		);
	}

	/**
	 * Nothing to add leaves the address exactly as it was.
	 *
	 * @return void
	 */
	public function test_nothing_to_add_changes_nothing(): void {
		$this->assertSame( 'https://shop.example/a?b=1#c', Utm_Tagger::merge( 'https://shop.example/a?b=1#c', array() ) );
	}
}
