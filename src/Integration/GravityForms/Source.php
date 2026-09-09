<?php
/**
 * The Gravity Forms recovery source.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\GravityForms;

use WAcr\RecoveryFlow\Integration\Abstract_Source;
use WAcr\RecoveryFlow\Integration\Event_Batch;
use WAcr\RecoveryFlow\Integration\Pollable_Source_Interface;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * The adapter that had to prove the abstraction was one.
 *
 * WooCommerce came first, so every seam in the core was cut where WooCommerce
 * happened to need one. This is the class that finds out whether those seams
 * were general or merely fitted: it recovers two things that are nothing like a
 * basket, through a plugin with no session, no cart and no orders, and it does
 * it without a single change to Recovery_Event, the workflow engine, the
 * journey state machine or the dispatcher.
 *
 * The two things are deliberately different from each other.
 *
 * A save-and-continue draft is the clearest abandonment signal in the plugin.
 * A shopper leaving a basket is inferred; somebody pressing "Save and continue
 * later" has said it out loud and been handed a token for exactly that purpose.
 *
 * An unpaid entry is the opposite: the person finished, and the money did not
 * arrive. That is a refused card or a closed gateway tab, and it is the case
 * where a reminder most often completes the sale.
 *
 * This is also the first source that is pollable, and the reason is the honest
 * one rather than a demonstration. Hooks only tell you about the future: a
 * merchant installing this onto a site with four hundred unpaid entries would
 * otherwise get nothing from any of them, and the same hole opens every time
 * the integration is switched off and on again.
 *
 * ## The seven questions
 *
 * 1. **What is recoverable?** A Gravity Forms save-and-continue draft, from the
 *    moment it is saved until it is resumed and submitted or Gravity Forms
 *    purges it; and an entry whose payment_status is neither empty nor paid.
 *    Forms with no phone and no email field are skipped, as are spam and
 *    trashed entries.
 * 2. **How is the customer identified?** By field type, read off the form's own
 *    definition -- the first email, phone, name and address field -- because
 *    Gravity Forms has no fixed key for any of them and the merchant may rename
 *    every label. A logged-in submitter's user id is used when there is one.
 *    Pinnable per form through recoveryflow_gf_field_overrides.
 * 3. **When is it abandoned?** A draft is abandoned the moment it is saved: the
 *    person said so. An unpaid entry after the site's inactivity threshold.
 *    Maximum age defaults to seven days because Gravity Forms purges drafts
 *    after thirty and the resume token dies with the row.
 * 4. **How is completion detected?** A draft, by the submission that consumes
 *    its resume token. An entry, by gform_post_payment_completed, or by any
 *    payment action that reports a paid status. Both re-checked live before
 *    every send.
 * 5. **Where do we send the customer?** A draft, to its own form page with
 *    Gravity Forms' gf_token on it -- the resume link the plugin itself mints.
 *    An entry, back to the form page it was submitted from.
 * 6. **What value information exists?** payment_amount and currency where the
 *    form takes money, and the form's title as the single line item. A draft
 *    usually has no amount at all, which is why the minimum-amount rule
 *    defaults to nothing here.
 * 7. **What consent constraints apply?** The same as everywhere else, with one
 *    difference that matters: Gravity Forms has no checkout and no consent
 *    field of ours, so in explicit_consent mode a form must carry the
 *    merchant's own consent question and record it. Until it does, this source
 *    identifies people it may not message -- which is the correct failure, and
 *    is stated on the Integrations screen rather than left to be discovered.
 */
final class Source extends Abstract_Source implements Pollable_Source_Interface {

	/**
	 * Stable machine identifier, written on every row this adapter produces.
	 */
	public const ID = 'gravityforms';

	/**
	 * Save-and-continue drafts.
	 *
	 * @var Draft_Watcher
	 */
	private Draft_Watcher $drafts;

	/**
	 * Entries waiting for money.
	 *
	 * @var Entry_Watcher
	 */
	private Entry_Watcher $entries;

	/**
	 * The backfill.
	 *
	 * @var Entry_Poller
	 */
	private Entry_Poller $poller;

	/**
	 * Constructor.
	 *
	 * @param Event_Ingest  $ingest  Event ingestion.
	 * @param Draft_Watcher $drafts  Save-and-continue drafts.
	 * @param Entry_Watcher $entries Entries waiting for money.
	 * @param Entry_Poller  $poller  The backfill.
	 */
	public function __construct( Event_Ingest $ingest, Draft_Watcher $drafts, Entry_Watcher $entries, Entry_Poller $poller ) {
		parent::__construct( $ingest );

		$this->drafts  = $drafts;
		$this->entries = $entries;
		$this->poller  = $poller;
	}

	/**
	 * Stable machine identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * Human name for the admin screens.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Gravity Forms', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * One sentence on what this source recovers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Recovers half-finished forms somebody saved to come back to, and entries whose payment never went through.', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * Whether Gravity Forms is present.
	 *
	 * One class_exists and nothing else: this is asked on every page load, so a
	 * version check or an option read here would be paid for by every request
	 * on the site whether or not anybody is filling in a form.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( '\GFAPI' );
	}

	/**
	 * Attach every hook this adapter needs.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->drafts->register();
		$this->entries->register();
	}

	/**
	 * The kinds of event this source produces.
	 *
	 * @return string[]
	 */
	public function get_event_types(): array {
		return array( Draft_Watcher::TYPE, Entry_Watcher::TYPE );
	}

	/**
	 * Rule overrides that suit a form, merged under the site's settings.
	 *
	 * Seven days rather than WooCommerce's thirty, because Gravity Forms purges
	 * save-and-continue drafts after thirty days by default and the resume token
	 * dies with the row: a reminder sent on day thirty-one links to a form that
	 * no longer knows anything about the person who filled it in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_rules(): array {
		return array(
			'inactivity_minutes' => 30,
			'max_age_days'       => 7,
			'min_amount'         => 0,
		);
	}

	/**
	 * Settings for this adapter's card.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_settings_fields(): array {
		return Settings::fields();
	}

	/**
	 * What a merchant has to do for a form to have consent.
	 *
	 * Gravity Forms has no checkout and RecoveryFlow adds no field of its own
	 * to anybody's form, so the merchant's own consent question is the only
	 * place a yes can come from. Gravity Forms' Consent field is read wherever
	 * a watched form carries one.
	 *
	 * @return string
	 */
	public function consent_note(): string {
		return __( 'RecoveryFlow adds no consent question to a form. Put Gravity Forms\' own Consent field on any form you want recovered and its answer is recorded with the entry. Until a form has one, people who fill it in are identified but never messaged.', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * Whether the thing behind this event has since been finished.
	 *
	 * Answered from live Gravity Forms state every time, because it is asked
	 * again immediately before each send.
	 *
	 * The two kinds fail closed in opposite-looking but identical ways. A draft
	 * that has gone was either submitted or purged, and neither is worth a
	 * message. An entry that cannot be read is an entry that has been deleted.
	 * In both cases the honest answer is "there is nothing here to recover",
	 * and the engine treats that as done.
	 *
	 * @param Recovery_Event $event The event.
	 * @return bool
	 */
	public function is_conversion_complete( Recovery_Event $event ): bool {
		if ( ! $event->is_open() ) {
			return true;
		}

		$external = null === $event->external_id ? '' : trim( $event->external_id );

		if ( '' === $external ) {
			return true;
		}

		if ( Draft_Watcher::TYPE === $event->source_type ) {
			return $this->draft_finished( $external );
		}

		return $this->entry_finished( (int) $external );
	}

	/**
	 * Put the person back where they left off, and say where to send them.
	 *
	 * Nothing is restored: Gravity Forms holds the half-finished form itself and
	 * hands it back on its own resume link, which is a far better arrangement
	 * than this plugin rebuilding somebody's answers from a snapshot it took.
	 * All that is needed is the address.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param Recovery_Event   $event   The event being recovered.
	 * @return string|\WP_Error
	 */
	public function restore( Recovery_Journey $journey, Recovery_Event $event ) {
		unset( $journey );

		$stored = isset( $event->metadata['resume_url'] ) ? (string) $event->metadata['resume_url'] : '';

		if ( '' !== $stored ) {
			return $stored;
		}

		return new \WP_Error(
			'recoveryflow_gf_gone',
			__( 'That form is no longer available.', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * Find unpaid entries that appeared while nothing was listening.
	 *
	 * @param int         $limit  Most drafts to return.
	 * @param string|null $cursor Where the last run stopped.
	 * @return Event_Batch
	 */
	public function detect_recovery_events( int $limit, ?string $cursor ): Event_Batch {
		return $this->poller->poll( $limit, $cursor );
	}

	/**
	 * Where a recovery link should land, with no query string on it.
	 *
	 * The query string is dropped deliberately. Gravity Forms records the URL
	 * the entry was submitted from, and a form's prefill parameters are exactly
	 * where somebody's name and email address end up -- which would put personal
	 * data into the event's metadata, where the exporter, the eraser and the
	 * redactor would never look for it.
	 *
	 * @param array<string,mixed> $entry The entry or partial entry.
	 * @param string              $token Resume token, for a draft.
	 * @return string
	 */
	public static function resume_url( array $entry, string $token ): string {
		$source = isset( $entry['source_url'] ) ? (string) $entry['source_url'] : '';
		$url    = '' === $source ? home_url( '/' ) : strtok( $source, '?' );
		$url    = is_string( $url ) ? $url : home_url( '/' );

		return '' === $token ? esc_url_raw( $url ) : esc_url_raw( add_query_arg( 'gf_token', $token, $url ) );
	}

	/**
	 * A form's title, or something honest in its place.
	 *
	 * @param array<string,mixed> $form The form.
	 * @return string
	 */
	public static function form_title( array $form ): string {
		$title = isset( $form['title'] ) ? trim( (string) $form['title'] ) : '';

		return '' === $title ? __( 'A form', 'kdc-wacr-recoveryflow' ) : $title;
	}

	/**
	 * Whether a save-and-continue draft has gone.
	 *
	 * @param string $token The resume token.
	 * @return bool
	 */
	private function draft_finished( string $token ): bool {
		if ( ! is_callable( array( '\GFFormsModel', 'get_draft_submission_values' ) ) ) {
			// Cannot tell, so nothing is sent. An adapter that guessed here
			// would be guessing about whether to message somebody.
			return true;
		}

		$draft = \GFFormsModel::get_draft_submission_values( $token );

		return empty( $draft );
	}

	/**
	 * Whether an entry's money has arrived, or the entry has gone.
	 *
	 * @param int $entry_id The entry.
	 * @return bool
	 */
	private function entry_finished( int $entry_id ): bool {
		if ( $entry_id <= 0 || ! is_callable( array( '\GFAPI', 'get_entry' ) ) ) {
			return true;
		}

		$entry = \GFAPI::get_entry( $entry_id );

		if ( ! is_array( $entry ) ) {
			// GFAPI answers WP_Error for an entry that does not exist.
			return true;
		}

		if ( in_array( (string) ( $entry['status'] ?? 'active' ), array( 'spam', 'trash' ), true ) ) {
			return true;
		}

		return Unpaid_Entry::is_paid( (string) ( $entry['payment_status'] ?? '' ) );
	}
}
