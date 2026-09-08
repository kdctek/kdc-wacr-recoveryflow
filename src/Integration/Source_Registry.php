<?php
/**
 * The list of things that can be recovered.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration;

use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
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
	 * The dependency this source needs is not installed or not active.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Present and working, but switched off on this site.
	 */
	public const SWITCHED_OFF = 'switched_off';

	/**
	 * Present and switched on, but not included in this WA.cr plan.
	 */
	public const NOT_INCLUDED = 'not_included';

	/**
	 * Watching.
	 */
	public const ACTIVE = 'active';

	/**
	 * The sources that ship inside the plugin and need no entitlement.
	 *
	 * @var string[]
	 */
	private const BUILT_IN = array( 'woocommerce' );

	/**
	 * The one route any adapter has for reporting what it saw.
	 *
	 * Held here so that a third-party source can be constructed inside the
	 * registration hook without reaching for the container singleton. An
	 * extension point that requires knowledge of the plugin's internals is one
	 * that only its authors can use.
	 *
	 * @var Event_Ingest|null
	 */
	private ?Event_Ingest $ingest;

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
	 * Constructor.
	 *
	 * @param Event_Ingest|null $ingest Event ingestion.
	 */
	public function __construct( ?Event_Ingest $ingest = null ) {
		$this->ingest = $ingest;
	}

	/**
	 * The event ingest, for building a source inside the registration hook.
	 *
	 * An extension point that requires a third party to reach for the container
	 * singleton is one only this plugin's own authors can use comfortably, so
	 * the registry carries what an adapter's constructor needs:
	 *
	 *     add_action( 'recoveryflow_register_sources', function ( $registry ) {
	 *         $registry->add( new My_Source( $registry->ingest() ) );
	 *     } );
	 *
	 * @return Event_Ingest|null
	 */
	public function ingest(): ?Event_Ingest {
		return $this->ingest;
	}

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
	 * The settings key holding whether one source is switched on.
	 *
	 * @param string $id Source id.
	 * @return string
	 */
	public static function enabled_key( string $id ): string {
		return 'source_' . $id . '_enabled';
	}

	/**
	 * The settings key for one of a source's own fields.
	 *
	 * Namespaced by source id, because the settings live in one option and
	 * "phone_field" is a name two form plugins would both reach for.
	 *
	 * @param string $id  Source id.
	 * @param string $key The source's own key.
	 * @return string
	 */
	public static function setting_key( string $id, string $key ): string {
		return 'source_' . $id . '_' . $key;
	}

	/**
	 * Whether the merchant has switched a source on.
	 *
	 * Absent a saved preference an available source is on, because a merchant
	 * who installs a recovery plugin on a WooCommerce site has already said
	 * what they want it to do. That default is stated here rather than in
	 * Options::defaults(), which cannot know about a source registered by
	 * somebody else's plugin.
	 *
	 * @param string $id Source id.
	 * @return bool
	 */
	public function is_enabled( string $id ): bool {
		$stored = Options::get( self::enabled_key( $id ), null );

		if ( null !== $stored ) {
			return (bool) $stored;
		}

		// The shape this used to be stored in. Nothing in the plugin ever wrote
		// it -- there was no control -- but a site may have set it in code, and
		// a switch somebody threw deliberately should not be quietly reversed
		// by an upgrade that gave it a screen.
		$legacy = Options::get( 'enabled_sources', null );

		if ( is_array( $legacy ) && array_key_exists( $id, $legacy ) ) {
			return (bool) $legacy[ $id ];
		}

		return true;
	}

	/**
	 * Sources that are available, switched on, and permitted by the plan.
	 *
	 * @return array<string,Recovery_Source_Interface>
	 */
	public function active(): array {
		$active = array();

		foreach ( $this->all() as $id => $source ) {
			if ( self::ACTIVE === $this->status( $id ) ) {
				$active[ $id ] = $source;
			}
		}

		return $active;
	}

	/**
	 * Why a source is or is not watching anything.
	 *
	 * The screen and the registry ask the same question of the same method, so
	 * a card cannot say "Active. Abandoned baskets from here are being
	 * recorded." about a source whose hooks were never attached. That is not a
	 * hypothetical tidiness: the entitlement check below silently excludes a
	 * source that is installed, switched on and, as far as any screen reading
	 * only those two facts could tell, working.
	 *
	 * @param string $id Source id.
	 * @return string One of the class constants.
	 */
	public function status( string $id ): string {
		$source = $this->get( $id );

		if ( null === $source || ! $source->is_available() ) {
			return self::UNAVAILABLE;
		}

		if ( ! $this->is_enabled( $id ) ) {
			return self::SWITCHED_OFF;
		}

		// Sources beyond the built-in set are a paid feature; a site that has
		// one registered but no entitlement keeps it visible on the
		// Integrations screen rather than having it vanish.
		if ( ! in_array( $id, self::BUILT_IN, true ) && ! Feature_Gate::is_enabled( Feature_Gate::EXTRA_SOURCES ) ) {
			return self::NOT_INCLUDED;
		}

		return self::ACTIVE;
	}

	/**
	 * What a status means, in a sentence somebody can act on.
	 *
	 * Kept beside status() rather than in the screen that first printed it. The
	 * REST collection and the Integrations screen now both answer "is this
	 * integration working", and a status code carried in two places with the
	 * wording in only one is how an API and a screen end up giving different
	 * accounts of the same site -- which is the exact fault this plugin has
	 * already shipped four times.
	 *
	 * @param string $status One of the class constants.
	 * @return string
	 */
	public static function status_message( string $status ): string {
		switch ( $status ) {
			case self::UNAVAILABLE:
				return __( 'Not available. Whatever this integration needs is not installed or not active on this site.', 'kdc-wacr-recoveryflow' );

			case self::SWITCHED_OFF:
				return __( 'Available, but switched off here. Nothing from this integration is being recorded.', 'kdc-wacr-recoveryflow' );

			case self::NOT_INCLUDED:
				return __( 'Installed and switched on, but not included in this WA.cr plan, so nothing from it is being recorded. Integrations beyond WooCommerce are included with the WA.cr Scale plan and above.', 'kdc-wacr-recoveryflow' );

			default:
				return __( 'Active. Abandoned baskets from here are being recorded.', 'kdc-wacr-recoveryflow' );
		}
	}

	/**
	 * Whether a source ships inside the plugin.
	 *
	 * @param string $id Source id.
	 * @return bool
	 */
	public function is_built_in( string $id ): bool {
		return in_array( $id, self::BUILT_IN, true );
	}

	/**
	 * Switch a source on or off for this site.
	 *
	 * Stored under a key of its own rather than in a list of the enabled ones,
	 * so a source registered later is on by default and a source somebody has
	 * deliberately switched off stays off if it is briefly deactivated.
	 *
	 * @param string $id Source id.
	 * @param bool   $on Whether it should watch.
	 * @return void
	 */
	public function set_enabled( string $id, bool $on ): void {
		Options::set( self::enabled_key( $id ), $on );
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
