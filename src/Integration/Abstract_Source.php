<?php
/**
 * The parts of the source contract that are the same for every adapter.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration;

use WAcr\RecoveryFlow\Core\Rewrites;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * A starting point for adapters, so a new one is only the interesting half.
 *
 * Three of the interface's methods have one right answer for almost everybody:
 * an adapter that adds no rules of its own, wants the plugin's own recovery
 * endpoint, and has nothing to put on its settings card should not have to say
 * so three times. What is left abstract is exactly the part that differs
 * between a cart, a booking and a half-finished form.
 *
 * The one dependency held here is Event_Ingest, because it is the only way an
 * adapter is allowed to report anything. Handing it to the base class rather
 * than letting each adapter reach for a global is what keeps a source testable
 * without a database.
 */
abstract class Abstract_Source implements Recovery_Source_Interface {

	/**
	 * The one route an adapter has for reporting what it saw.
	 *
	 * @var Event_Ingest
	 */
	protected Event_Ingest $ingest;

	/**
	 * Constructor.
	 *
	 * @param Event_Ingest $ingest Event ingestion.
	 */
	public function __construct( Event_Ingest $ingest ) {
		$this->ingest = $ingest;
	}

	/**
	 * One sentence on what this source recovers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * Rule overrides that suit this source, merged under the site's settings.
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_rules(): array {
		return array();
	}

	/**
	 * The URL a recovery message should link to.
	 *
	 * The plugin's own endpoint is the default because it is the only one that
	 * can verify the token before anything is restored. A source that points
	 * somewhere else takes on that check itself.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $token   The one-time token for this attempt.
	 * @return string
	 */
	public function build_recovery_url( Recovery_Journey $journey, string $token ): string {
		return Rewrites::url( $token );
	}

	/**
	 * Settings to render on this source's card, in Settings API field shape.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_settings_fields(): array {
		return array();
	}
}
