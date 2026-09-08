<?php
/**
 * Finding the unpaid entries nobody was there to see.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\GravityForms;

use WAcr\RecoveryFlow\Integration\Event_Batch;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The backfill, and the reason this adapter is pollable at all.
 *
 * Hooks only ever tell you about the future. A merchant who installs
 * RecoveryFlow onto a site with four hundred unpaid entries gets nothing from
 * any of them: the submissions happened, the hooks fired, and nothing was
 * listening. The same hole opens every time the integration is switched off and
 * on again, and every time a payment completes through a route that fires no
 * hook at all.
 *
 * So the entries are also read. Not instead of the hooks -- a poll on a
 * five-minute tick is a poor substitute for knowing at the moment it happened
 * -- but behind them, catching what a hook could not have caught.
 *
 * The cursor is a timestamp rather than a row id, because date_created is the
 * one column GFAPI::get_entries lets you both filter and sort on without
 * knowing anything about the entry table. Its cost is that the boundary second
 * is read twice, which costs nothing: ingest is an upsert keyed on
 * (source, entry id), so re-reporting an entry writes the row it already wrote.
 *
 * The value of this is bounded on purpose. Old unpaid entries are old for a
 * reason, and the maximum age in the recovery rules will refuse most of what is
 * found here -- which is correct, and much better than the alternative of
 * deciding, in this class, which of a merchant's history is worth chasing.
 */
final class Entry_Poller {

	/**
	 * The furthest back a first poll will look, in days.
	 *
	 * A site being connected for the first time should not have its entire
	 * history read; the recovery rules would refuse nearly all of it, after
	 * paying to load every row. Anything older is somebody else's problem, and
	 * a merchant who wants it can widen the window with a filter.
	 */
	private const FIRST_LOOK_DAYS = 30;

	/**
	 * Reading a person out of a form.
	 *
	 * @var Field_Map
	 */
	private Field_Map $fields;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Field_Map $fields Field reading.
	 * @param Logger    $logger Logger.
	 */
	public function __construct( Field_Map $fields, Logger $logger ) {
		$this->fields = $fields;
		$this->logger = $logger;
	}

	/**
	 * Read the next page of unpaid entries.
	 *
	 * @param int         $limit  Most drafts to return.
	 * @param string|null $cursor Where the last run stopped, as a UTC timestamp.
	 * @return Event_Batch
	 */
	public function poll( int $limit, ?string $cursor ): Event_Batch {
		if ( ! Settings::unpaid_enabled() || ! is_callable( array( '\GFAPI', 'get_entries' ) ) ) {
			return Event_Batch::empty_batch();
		}

		$since   = $this->since( $cursor );
		$entries = $this->entries( $limit, $since );

		if ( array() === $entries ) {
			// Nothing new. The cursor is carried forward rather than cleared,
			// or the next run would start again from thirty days ago and read
			// the whole window a second time.
			return new Event_Batch( array(), $since, false );
		}

		$drafts = array();
		$last   = $since;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$last  = (string) ( $entry['date_created'] ?? $last );
			$draft = $this->draft( $entry );

			if ( null !== $draft ) {
				$drafts[] = $draft;
			}
		}

		return new Event_Batch( $drafts, $last, count( $entries ) >= $limit );
	}

	/**
	 * Ask Gravity Forms for entries created since the cursor.
	 *
	 * @param int    $limit Page size.
	 * @param string $since UTC timestamp.
	 * @return array<int,mixed>
	 */
	private function entries( int $limit, string $since ): array {
		$entries = \GFAPI::get_entries(
			0,
			array(
				'status'     => 'active',
				'start_date' => $since,
			),
			array(
				'key'       => 'date_created',
				'direction' => 'ASC',
			),
			array(
				'offset'    => 0,
				'page_size' => $limit,
			)
		);

		if ( $entries instanceof \WP_Error ) {
			$this->logger->warning(
				'gravityforms',
				'Gravity Forms refused to list entries.',
				array( 'code' => $entries->get_error_code() )
			);

			return array();
		}

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Turn one entry into a draft, or decide it is not worth one.
	 *
	 * The rule is Unpaid_Entry's, deliberately: a backfill that applied a
	 * looser one than the live path would chase exactly the people the live
	 * path had decided to leave alone.
	 *
	 * @param array<string,mixed> $entry The entry.
	 * @return Event_Draft|null
	 */
	private function draft( array $entry ): ?Event_Draft {
		$form_id = (int) ( $entry['form_id'] ?? 0 );

		if ( 0 === $form_id || ! is_callable( array( '\GFAPI', 'get_form' ) ) ) {
			return null;
		}

		$form = \GFAPI::get_form( $form_id );

		if ( ! is_array( $form ) ) {
			return null;
		}

		return Unpaid_Entry::draft( $entry, $form, $this->fields, 'poll' );
	}

	/**
	 * Where to start reading from.
	 *
	 * @param string|null $cursor The stored cursor.
	 * @return string UTC timestamp.
	 */
	private function since( ?string $cursor ): string {
		if ( null !== $cursor && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursor ) ) {
			return $cursor;
		}

		/**
		 * How far back the first poll of a site looks, in days.
		 *
		 * @param int $days Days.
		 */
		$days = (int) apply_filters( 'recoveryflow_gf_first_look_days', self::FIRST_LOOK_DAYS );

		return gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
	}
}
