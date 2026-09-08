<?php
/**
 * Just enough Gravity Forms to test the adapter written against it.
 *
 * The adapter touches four Gravity Forms things: GFAPI::get_entry,
 * GFAPI::get_entries, GFAPI::get_form and
 * GFFormsModel::get_draft_submission_values. Everything else it knows comes out
 * of the form and entry arrays Gravity Forms hands to its own hooks, which are
 * plain data and need no stand-in at all.
 *
 * Defining these also makes class_exists( '\GFAPI' ) true, which is what the
 * adapter's is_available() asks -- so loading this file is what puts the site
 * into the state where Gravity Forms is installed.
 *
 * @package WAcr\RecoveryFlow
 */

/**
 * The subset of GFAPI this adapter calls.
 */
class GFAPI {

	/**
	 * Entries to hand back, keyed by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static $entries = array();

	/**
	 * Forms to hand back, keyed by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static $forms = array();

	/**
	 * What get_entries was last asked for, so the query itself can be asserted.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static $asked = array();

	/**
	 * Set when get_entries should behave like a Gravity Forms in trouble.
	 *
	 * @var bool
	 */
	public static $fail = false;

	/**
	 * One entry, or a WP_Error when it does not exist -- as GFAPI does.
	 *
	 * @param int $entry_id Entry id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_entry( $entry_id ) {
		return self::$entries[ (int) $entry_id ] ?? new WP_Error( 'not_found', 'Entry not found' );
	}

	/**
	 * One form, or false.
	 *
	 * @param int $form_id Form id.
	 * @return array<string,mixed>|false
	 */
	public static function get_form( $form_id ) {
		return self::$forms[ (int) $form_id ] ?? false;
	}

	/**
	 * Entries created on or after start_date, oldest first.
	 *
	 * @param mixed $form_ids        Form ids, or 0 for all.
	 * @param array $search_criteria Search criteria.
	 * @param array $sorting         Sorting.
	 * @param array $paging          Paging.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function get_entries( $form_ids = 0, $search_criteria = array(), $sorting = null, $paging = null ) {
		self::$asked[] = array(
			'forms'  => $form_ids,
			'search' => $search_criteria,
			'sort'   => $sorting,
			'paging' => $paging,
		);

		if ( self::$fail ) {
			return new WP_Error( 'gf_down', 'Gravity Forms is unhappy' );
		}

		$since = (string) ( $search_criteria['start_date'] ?? '' );
		$found = array();

		foreach ( self::$entries as $entry ) {
			if ( '' === $since || (string) $entry['date_created'] >= $since ) {
				$found[] = $entry;
			}
		}

		usort( $found, static fn ( $a, $b ): int => strcmp( (string) $a['date_created'], (string) $b['date_created'] ) );

		return array_slice( $found, 0, (int) ( $paging['page_size'] ?? 20 ) );
	}
}

/**
 * The one GFFormsModel method the adapter calls.
 */
class GFFormsModel {

	/**
	 * Live drafts, keyed by resume token.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public static $drafts = array();

	/**
	 * A draft's stored values, or nothing once it has been submitted or purged.
	 *
	 * @param string $resume_token The token.
	 * @return array<string,mixed>|false
	 */
	public static function get_draft_submission_values( $resume_token ) {
		return self::$drafts[ (string) $resume_token ] ?? false;
	}
}
