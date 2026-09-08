<?php
/**
 * The list of things that can be recovered.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration;

use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the registered sources and attaches the ones that can run.
 *
 * Built-in sources are added by the core; anything else arrives through the
 * registration filter, which is the whole extension mechanism -- a third-party
 * adapter needs no core change and no privileged access, only an implementation
 * of the interface.
 *
 * A source is asked to attach its hooks only when it is both available and
 * switched on. Availability is the source's own answer about its dependency;
 * being switched on is the merchant's. Keeping them separate is what lets the
 * Integrations screen say "WooCommerce is installed but not enabled here"
 * rather than silently showing nothing.
 */
final class Source_Registry {

	/**
	 * Registered sources, by id.
	 *
	 * @var array<string,Recovery_Source_Interface>
	 */
	private array $sources = array();

	/**
	 * Whether the registration filter has run.
	 *
	 * @var bool
	 */
	private bool $collected = false;

	/**
	 * Whether the available sources have been asked to attach hooks.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Add a source.
	 *
	 * A later registration of the same id replaces the earlier one, so a site
	 * can substitute its own implementation for a built-in.
	 *
	 * @param Recovery_Source_Interface $source The source.
	 * @return Source_Registry
	 */
	public function add( Recovery_Source_Interface $source ): self {
		$this->sources[ $source->get_id() ] = $source;

		return $this;
	}

	/**
	 * Fetch one source.
	 *
	 * @param string $id Source id.
	 * @return Recovery_Source_Interface|null
	 */
	public function get( string $id ): ?Recovery_Source_Interface {
		$this->collect();

		return $this->sources[ $id ] ?? null;
	}

	/**
	 * Every registered source.
	 *
	 * @return array<string,Recovery_Source_Interface>
	 */
	public function all(): array {
		$this->collect();

		return $this->sources;
	}

	/**
	 * Sources whose dependency is present.
	 *
	 * @return array<string,Recovery_Source_Interface>
	 */
	public function available(): array {
		return array_filter( $this->all(), static fn ( Recovery_Source_Interface $s ): bool => $s->is_available() );
	}

	/**
	 * Whether the merchant has switched a source on.
	 *
	 * Absent a saved preference an available source is on, because a merchant
	 * who installs a recovery plugin on a WooCommerce site has already said
	 * what they want it to do.
	 *
	 * @param string $id Source id.
	 * @return bool
	 */
	public function is_enabled( string $id ): bool {
		$enabled = Options::get( 'enabled_sources', null );

		if ( ! is_array( $enabled ) ) {
			return true;
		}

		return ! array_key_exists( $id, $enabled ) || (bool) $enabled[ $id ];
	}

	/**
	 * Sources that are available, switched on, and permitted by the plan.
	 *
	 * @return array<string,Recovery_Source_Interface>
	 */
	public function active(): array {
		$active  = array();
		$builtin = array( 'woocommerce' );

		foreach ( $this->available() as $id => $source ) {
			if ( ! $this->is_enabled( $id ) ) {
				continue;
			}

			// Sources beyond the built-in set are a paid feature; a site that
			// has one registered but no entitlement keeps it visible on the
			// Integrations screen rather than having it vanish.
			if ( ! in_array( $id, $builtin, true ) && ! Feature_Gate::is_enabled( Feature_Gate::EXTRA_SOURCES ) ) {
				continue;
			}

			$active[ $id ] = $source;
		}

		return $active;
	}

	/**
	 * Ask every active source to attach its hooks. Runs once.
	 *
	 * @return void
	 */
	public function register_all(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		foreach ( $this->active() as $source ) {
			$source->register();
		}
	}

	/**
	 * Run the registration filter, once.
	 *
	 * @return void
	 */
	private function collect(): void {
		if ( $this->collected ) {
			return;
		}

		$this->collected = true;

		/**
		 * Registers recovery sources.
		 *
		 * Add an integration by calling add() on the registry passed in:
		 *
		 *     add_action( 'recoveryflow_register_sources', function ( $registry ) {
		 *         $registry->add( new My_Source() );
		 *     } );
		 *
		 * @param Source_Registry $registry The registry.
		 */
		do_action( Hooks::REGISTER_SOURCES, $this );
	}
}
