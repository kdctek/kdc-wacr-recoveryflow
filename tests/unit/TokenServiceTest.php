<?php
/**
 * The one credential this plugin hands to a member of the public.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Tests\Unit;

use WAcr\RecoveryFlow\Security\Token_Service;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A recovery link is a bearer token in a URL, so its properties are the security.
 *
 * There is no session behind it and no password in front of it: whoever holds
 * the string can restore that basket. What keeps that acceptable is that the
 * string is unguessable, that only its hash is ever stored, and that the shape
 * is checked before anything reaches the database.
 */
final class TokenServiceTest extends TestCase {

	/**
	 * A minted token is the documented shape.
	 *
	 * @return void
	 */
	public function test_a_minted_token_has_the_documented_shape() {
		$token = Token_Service::mint();

		$this->assertSame( Token_Service::LENGTH, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $token, 'base64url only: the rewrite rule enforces this alphabet.' );
		$this->assertTrue( Token_Service::is_well_formed( $token ) );
	}

	/**
	 * Two tokens are never the same.
	 *
	 * A weak assertion on its own, and worth having anyway: it is what fails if
	 * the source of randomness is ever swapped for something seeded.
	 *
	 * @return void
	 */
	public function test_tokens_do_not_repeat() {
		$seen = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$seen[ Token_Service::mint() ] = true;
		}

		$this->assertCount( 200, $seen );
	}

	/**
	 * The shape check refuses everything that is not the shape.
	 *
	 * It runs before any query, so this is also what stops a crafted string
	 * reaching the lookup at all.
	 *
	 * @return void
	 */
	public function test_the_shape_check_refuses_anything_else() {
		$this->assertFalse( Token_Service::is_well_formed( '' ) );
		$this->assertFalse( Token_Service::is_well_formed( str_repeat( 'a', Token_Service::LENGTH - 1 ) ) );
		$this->assertFalse( Token_Service::is_well_formed( str_repeat( 'a', Token_Service::LENGTH + 1 ) ) );
		$this->assertFalse( Token_Service::is_well_formed( str_repeat( '/', Token_Service::LENGTH ) ) );
		$this->assertFalse( Token_Service::is_well_formed( str_repeat( '.', Token_Service::LENGTH ) ) );
	}

	/**
	 * Hashing is stable, and the hash is not the token.
	 *
	 * @return void
	 */
	public function test_hashing_is_stable_and_hides_the_token() {
		$token = Token_Service::mint();
		$hash  = Token_Service::hash( $token );

		$this->assertSame( $hash, Token_Service::hash( $token ) );
		$this->assertNotSame( $token, $hash );
		$this->assertStringNotContainsString( $token, $hash, 'A database dump must not yield working links.' );
	}

	/**
	 * A token matches its own hash and nothing else.
	 *
	 * @return void
	 */
	public function test_a_token_matches_only_its_own_hash() {
		$token = Token_Service::mint();
		$other = Token_Service::mint();

		$this->assertTrue( Token_Service::matches( $token, Token_Service::hash( $token ) ) );
		$this->assertFalse( Token_Service::matches( $other, Token_Service::hash( $token ) ) );
	}

	/**
	 * A prefix of a valid token does not match it.
	 *
	 * The comparison is over the hash rather than the token, so this holds by
	 * construction -- which is exactly why it is worth pinning: it is the
	 * property that would quietly disappear if the lookup were ever rewritten
	 * to compare strings.
	 *
	 * @return void
	 */
	public function test_a_prefix_does_not_match() {
		$token = Token_Service::mint();

		$this->assertFalse( Token_Service::matches( substr( $token, 0, 20 ), Token_Service::hash( $token ) ) );
	}

	/**
	 * An empty stored hash matches nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_stored_hash_matches_nothing() {
		$this->assertFalse( Token_Service::matches( Token_Service::mint(), '' ) );
	}
}
