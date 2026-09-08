<?php
/**
 * PSR-4 autoloader.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the plugin namespace onto the src/ tree.
 *
 * Composer is a development dependency only -- the shipped plugin must run with
 * no vendor directory, so the autoloader is hand-rolled. Class names follow the
 * WordPress underscore convention (Recovery_Journey) while directories follow
 * the namespace, so WAcr\RecoveryFlow\Recovery\Recovery_Journey resolves to
 * src/Recovery/Recovery_Journey.php.
 */
final class Autoloader {

	private const PREFIX = 'WAcr\\RecoveryFlow\\';

	/**
	 * Absolute path to the src/ directory, with a trailing slash.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Absolute path to src/, with a trailing slash.
	 */
	public function __construct( string $base_dir ) {
		$this->base_dir = $base_dir;
	}

	/**
	 * Register with SPL.
	 *
	 * @return void
	 */
	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	/**
	 * Load one class.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public function load( string $class_name ): void {
		if ( 0 !== strncmp( self::PREFIX, $class_name, strlen( self::PREFIX ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = $this->base_dir . str_replace( '\\', '/', $relative ) . '.php';

		// Guard against a namespace segment escaping the plugin directory.
		if ( false !== strpos( $relative, '.' ) ) {
			return;
		}

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
