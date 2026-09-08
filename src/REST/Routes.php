<?php
/**
 * Where the plugin's REST routes live.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\REST;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the REST namespace is spelled out.
 *
 * KDC plugins share a single REST namespace and separate themselves by path,
 * so a site running several of them presents one coherent API surface instead
 * of one namespace per plugin. RecoveryFlow's routes therefore sit at
 * kdc/v1 under wacr/recoveryflow rather than in a namespace of their own.
 *
 * A namespace is a public contract: changing it breaks every caller. Keeping it
 * in constants means a route is registered and linked to from one definition,
 * and the smoke tests can assert the full path a client will actually call.
 */
final class Routes {

	/**
	 * The shared KDC namespace.
	 */
	public const REST_NAMESPACE = 'kdc/v1';

	/**
	 * This plugin's path prefix within that namespace, without slashes.
	 */
	public const PREFIX = 'wacr/recoveryflow';

	/**
	 * A route path, as register_rest_route() wants it.
	 *
	 * @param string $route Route below the plugin prefix, e.g. 'journeys' or 'journeys/(?P<uid>[a-z0-9-]+)'.
	 * @return string Leading-slashed path, e.g. '/wacr/recoveryflow/journeys'.
	 */
	public static function path( string $route = '' ): string {
		$route = trim( $route, '/' );

		return '' === $route ? '/' . self::PREFIX : '/' . self::PREFIX . '/' . $route;
	}

	/**
	 * The full URL a client would call.
	 *
	 * @param string $route Route below the plugin prefix.
	 * @return string
	 */
	public static function url( string $route = '' ): string {
		return rest_url( self::REST_NAMESPACE . self::path( $route ) );
	}
}
