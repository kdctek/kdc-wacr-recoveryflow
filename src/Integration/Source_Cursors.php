<?php
/**
 * Where each pollable source stopped reading.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers, per source, where the last poll got to.
 *
 * A pollable source is asked for a bounded page of drafts and hands back an
 * opaque cursor. Storing it is the whole reason a hundred thousand unread rows
 * drain rather than being rescanned from the top on every tick, so the store
 * has to survive between requests -- which makes it an option, not a transient.
 * A transient may be evicted by an object cache at any moment, and an evicted
 * cursor means the source starts again at the beginning, re-ingesting
 * everything it has already reported.
 *
 * The cursor is opaque on purpose: a source may use a row id, a timestamp or an
 * API page token. Nothing here interprets it, so nothing here can be wrong
 * about what it means. It is capped, because a source that hands back a
 * megabyte of state would otherwise put it in an autoloaded option on every
 * page load of the site.
 */
final class Source_Cursors {

	/**
	 * The option holding every source's cursor.
	 */
	public const OPTION = 'recoveryflow_source_cursors';

	/**
	 * The longest cursor that will be stored.
	 *
	 * Beyond this the source is asked to start again rather than the site
	 * carrying its state around. A cursor this long is a source misusing the
	 * field to store its working set.
	 */
	private const MAX_LENGTH = 500;

	/**
	 * Where this source stopped.
	 *
	 * @param string $source_id Source id.
	 * @return string|null Null when it has never run, or finished.
	 */
	public function get( string $source_id ): ?string {
		$all = $this->all();

		return isset( $all[ $source_id ] ) ? (string) $all[ $source_id ] : null;
	}

	/**
	 * Record where this source stopped.
	 *
	 * @param string      $source_id Source id.
	 * @param string|null $cursor    Resume point, or null to start again.
	 * @return void
	 */
	public function set( string $source_id, ?string $cursor ): void {
		$all = $this->all();

		if ( null === $cursor || '' === $cursor || strlen( $cursor ) > self::MAX_LENGTH ) {
			unset( $all[ $source_id ] );
		} else {
			$all[ $source_id ] = $cursor;
		}

		// autoload off: this is read by the background tick, never by a page.
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Forget a source's position, so its next poll starts from the beginning.
	 *
	 * @param string $source_id Source id.
	 * @return void
	 */
	public function reset( string $source_id ): void {
		$this->set( $source_id, null );
	}

	/**
	 * Every stored cursor.
	 *
	 * @return array<string,string>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}
}
