<?php
/**
 * Public URL routing.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The /recovery/{token} endpoint.
 *
 * The token is 32 random bytes in base64url, which is always exactly 43
 * characters, so the rewrite itself rejects anything of the wrong shape before
 * a database lookup happens. That keeps a scanner hammering the endpoint from
 * turning into a query per request.
 *
 * A ?rf= query fallback exists for sites without pretty permalinks; both forms
 * reach the same controller.
 */
final class Rewrites {

	public const QUERY_TOKEN  = 'recoveryflow_token';
	public const QUERY_ACTION = 'recoveryflow_action';
	public const TOKEN_REGEX  = '[A-Za-z0-9_-]{43}';
	public const BASE         = 'recovery';

	/**
	 * Attach to WordPress.
	 *
	 * Rewrite rules cannot be added at plugins_loaded: WordPress has not built
	 * $wp_rewrite yet, and add_rewrite_rule() fatals on it. They go on init,
	 * which is also when a rule needs to exist for the request being served.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );

		if ( did_action( 'init' ) ) {
			self::register();

			return;
		}

		add_action( 'init', array( self::class, 'register' ), 5 );
	}

	/**
	 * Add the rewrite rules.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_rewrite_rule(
			'^' . self::BASE . '/(' . self::TOKEN_REGEX . ')/opt-out/?$',
			'index.php?' . self::QUERY_TOKEN . '=$matches[1]&' . self::QUERY_ACTION . '=opt-out',
			'top'
		);

		add_rewrite_rule(
			'^' . self::BASE . '/(' . self::TOKEN_REGEX . ')/?$',
			'index.php?' . self::QUERY_TOKEN . '=$matches[1]&' . self::QUERY_ACTION . '=restore',
			'top'
		);
	}

	/**
	 * Register the query vars WordPress will pass through.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_TOKEN;
		$vars[] = self::QUERY_ACTION;

		return $vars;
	}

	/**
	 * Build the public URL for a token.
	 *
	 * @param string $token  The plaintext token.
	 * @param string $action 'restore' or 'opt-out'.
	 * @return string
	 */
	public static function url( string $token, string $action = 'restore' ): string {
		$permalinks = (string) get_option( 'permalink_structure', '' );

		if ( '' !== $permalinks ) {
			$path = self::BASE . '/' . $token . ( 'opt-out' === $action ? '/opt-out' : '' );

			return home_url( '/' . $path );
		}

		return add_query_arg(
			array(
				'rf'        => $token,
				'rf_action' => $action,
			),
			home_url( '/' )
		);
	}

	/**
	 * Read the requested token and action from the current request.
	 *
	 * Both the pretty and the query-string form are validated against the same
	 * pattern, so an unparseable token never reaches the database.
	 *
	 * @return array{token:string,action:string}|null
	 */
	public static function current_request(): ?array {
		$token  = (string) get_query_var( self::QUERY_TOKEN, '' );
		$action = (string) get_query_var( self::QUERY_ACTION, '' );

		if ( '' === $token ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link, no state change on read.
			$token = isset( $_GET['rf'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['rf'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link, no state change on read.
			$action = isset( $_GET['rf_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['rf_action'] ) ) : 'restore';
		}

		if ( '' === $token || 1 !== preg_match( '/^' . self::TOKEN_REGEX . '$/', $token ) ) {
			return null;
		}

		return array(
			'token'  => $token,
			'action' => 'opt-out' === $action ? 'opt-out' : 'restore',
		);
	}
}
