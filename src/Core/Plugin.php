<?php
/**
 * The plugin container.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together and hands services to whoever needs them.
 *
 * Services are created lazily and stored by id. Anything with a hooks() method
 * is asked to attach its own hooks at boot, which keeps the wiring in the class
 * that owns the behaviour rather than in one long list here.
 */
final class Plugin {

	/**
	 * The one instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Resolved services, by id.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * Factories, by id.
	 *
	 * @var array<string,callable>
	 */
	private array $factories = array();

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private: use instance().
	 */
	private function __construct() {
		$this->register_factories();
	}

	/**
	 * The container.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Declare how each service is built.
	 *
	 * @return void
	 */
	private function register_factories(): void {
		$this->factories = array(
			'clock' => static fn (): Clock => new Clock(),
		);
	}

	/**
	 * Fetch a service.
	 *
	 * @param string $id Service id.
	 * @return object|null
	 */
	public function get( string $id ): ?object {
		if ( isset( $this->services[ $id ] ) ) {
			return $this->services[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			return null;
		}

		$this->services[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->services[ $id ];
	}

	/**
	 * Register a service or replace one. Test seam.
	 *
	 * @param string $id      Service id.
	 * @param object $service The service.
	 * @return void
	 */
	public function set( string $id, object $service ): void {
		$this->services[ $id ] = $service;
	}

	/**
	 * The clock, which everything that stores a time uses.
	 *
	 * @return Clock
	 */
	public function clock(): Clock {
		$clock = $this->get( 'clock' );

		return $clock instanceof Clock ? $clock : new Clock();
	}

	/**
	 * Attach the plugin to WordPress.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_filter( 'map_meta_cap', array( Capabilities::class, 'map_meta_cap' ), 10, 4 );

		// A role can appear after RecoveryFlow is installed -- WooCommerce
		// creates shop_manager when it activates -- so the grant is re-run at
		// the one moment the role set can change.
		add_action( 'activated_plugin', array( Capabilities::class, 'on_plugin_activated' ) );

		Rewrites::hooks();

		// Migrations run late on init so that anything they touch is registered.
		add_action( 'init', array( Upgrader::class, 'maybe_upgrade' ), 99 );

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'hooks' ) ) {
				$service->hooks();
			}
		}

		/**
		 * Fires once RecoveryFlow has attached itself to WordPress.
		 *
		 * The registries are not populated yet at this point; use
		 * recoveryflow_register_sources to add an integration.
		 *
		 * @param Plugin $plugin The container.
		 */
		do_action( Hooks::BOOTED, $this );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'kdc-wacr-recoveryflow',
			false,
			dirname( KDC_WACR_RECOVERYFLOW_BASENAME ) . '/languages'
		);
	}

	/**
	 * Whether the plugin has booted.
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return $this->booted;
	}
}
