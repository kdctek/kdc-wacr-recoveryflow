<?php
/**
 * The parts of the source contract that are the same for every adapter.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration;

use WAcr\RecoveryFlow\Core\Rewrites;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
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
	 * Report something a visitor started and did not finish.
	 *
	 * The only way an adapter is allowed to say anything, and the reason it is
	 * a method here rather than a repository somewhere is that everything
	 * behind it runs inside a real person's page request. It writes one indexed
	 * row and stops; deciding whether the thing is worth chasing, working out
	 * who the visitor is and composing a message all happen later, on the
	 * background tick, where taking a second longer costs nobody a slow page.
	 *
	 * It cannot throw. A recovery plugin that breaks a checkout because its own
	 * table is missing has done far more damage than the sale it was trying to
	 * save.
	 *
	 * @param Event_Draft $draft What was seen.
	 * @return int The event row id, or 0 if nothing was written.
	 */
	protected function report( Event_Draft $draft ): int {
		return $this->ingest->ingest( $draft );
	}

	/**
	 * Say that the thing behind one of your keys is finished.
	 *
	 * Call this the moment your system knows -- the booking was paid, the form
	 * was submitted, the ticket was issued. The engine also asks
	 * is_conversion_complete() immediately before every send, so a missed call
	 * here is not a message going out wrongly; it is a row staying open until
	 * something notices. Both exist because either alone is not enough.
	 *
	 * @param string $dedupe_key The key you reported it under.
	 * @param string $reason     Short machine-readable note, e.g. 'paid'.
	 * @return bool Whether an open event was closed.
	 */
	protected function report_completed( string $dedupe_key, string $reason = 'completed' ): bool {
		return $this->ingest->close( $this->get_id(), $dedupe_key, Recovery_Event::COMPLETED, $reason );
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

	/**
	 * What a merchant has to do for this source to have consent.
	 *
	 * Nothing, by default. A source that collects consent itself, or that
	 * cannot be used without it, says so by overriding this.
	 *
	 * @return string
	 */
	public function consent_note(): string {
		return '';
	}
}
