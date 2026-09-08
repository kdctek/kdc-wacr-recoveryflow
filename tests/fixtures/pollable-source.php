<?php
/**
 * A pollable source that exists only to prove the poll loop runs.
 *
 * Lives in tests/fixtures rather than in the suite because a file may hold
 * functions or classes but not both, and the suite is built out of functions.
 *
 * @package WAcr\RecoveryFlow
 */

use WAcr\RecoveryFlow\Integration\Abstract_Source;
use WAcr\RecoveryFlow\Integration\Event_Batch;
use WAcr\RecoveryFlow\Integration\Pollable_Source_Interface;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

/**
 * A source with nothing to push from, which is the whole point of polling.
 */
final class Recoveryflow_Fake_Pollable extends Abstract_Source implements Pollable_Source_Interface {

	public array $asked  = array();
	public array $pages  = array();
	public bool $explode = false;
	public bool $available = true;
	private string $id;

	public function __construct( Event_Ingest $ingest, string $id = 'fake_pollable' ) {
		parent::__construct( $ingest );
		$this->id = $id;
	}

	public function get_id(): string { return $this->id; }
	public function get_name(): string { return 'Fake pollable'; }
	public function is_available(): bool { return $this->available; }
	public function register(): void {}
	public function get_event_types(): array { return array( 'booking' ); }
	public function is_conversion_complete( Recovery_Event $event ): bool { return false; }
	public function restore( Recovery_Journey $journey, Recovery_Event $event ) { return 'https://example.test/'; }

	public function detect_recovery_events( int $limit, ?string $cursor ): Event_Batch {
		$this->asked[] = $cursor;

		if ( $this->explode ) {
			throw new \RuntimeException( 'the booking system is down' );
		}

		return array_shift( $this->pages ) ?: Event_Batch::empty_batch();
	}
}
