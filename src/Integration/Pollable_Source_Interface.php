<?php
/**
 * Sources that must be asked rather than listened to.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * For systems that never tell you anything.
 *
 * Most WordPress plugins fire hooks, and a source built on them costs nothing
 * until something happens. Some do not -- an external booking system, a plugin
 * that writes straight to its own tables -- and the only way to find an
 * abandoned journey is to go and look for one.
 *
 * Polling is therefore opt-in and cursor-based rather than the default: a
 * source that implements this is asked for a bounded batch on the background
 * tick, and hands back a cursor so the next run starts where this one stopped.
 */
interface Pollable_Source_Interface extends Recovery_Source_Interface {

	/**
	 * Find recovery events that have appeared since the cursor.
	 *
	 * Must return at most $limit drafts and must not scan unboundedly.
	 *
	 * @param int         $limit  Maximum drafts to return.
	 * @param string|null $cursor Where the last run stopped, or null to start.
	 * @return Event_Batch
	 */
	public function detect_recovery_events( int $limit, ?string $cursor ): Event_Batch;
}
