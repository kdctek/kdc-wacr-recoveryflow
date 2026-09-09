<?php
/**
 * Save and continue, watched.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\GravityForms;

use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Event_Ingest;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The clearest abandonment signal any of these integrations gets.
 *
 * A shopper leaving a basket is inferred: they went quiet, and after thirty
 * minutes the plugin decides they are not coming back. Somebody pressing "Save
 * and continue later" has said it out loud. They asked to be sent back to a
 * half-finished form, Gravity Forms minted a token for exactly that, and the
 * only thing missing is anybody reminding them.
 *
 * So the inactivity threshold means something different here and the default
 * says so: the moment a draft is saved it is already abandoned by the person's
 * own account of it.
 *
 * The maximum age is the one number that is not ours to choose. Gravity Forms
 * purges drafts after thirty days by default and the token dies with the row,
 * so a reminder sent on day thirty-one links to a form that no longer knows
 * anything. The default here is deliberately well inside that.
 */
final class Draft_Watcher {

	/**
	 * The event type a saved draft produces.
	 */
	public const TYPE = 'form';

	/**
	 * The adapter key prefix for a draft.
	 */
	public const KEY = 'draft:';

	/**
	 * Event ingestion.
	 *
	 * @var Event_Ingest
	 */
	private Event_Ingest $ingest;

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
	 * @param Event_Ingest $ingest Event ingestion.
	 * @param Field_Map    $fields Field reading.
	 * @param Logger       $logger Logger.
	 */
	public function __construct( Event_Ingest $ingest, Field_Map $fields, Logger $logger ) {
		$this->ingest = $ingest;
		$this->fields = $fields;
		$this->logger = $logger;
	}

	/**
	 * Attach to Gravity Forms.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'gform_incomplete_submission_post_save', array( $this, 'on_saved' ), 10, 4 );
		add_action( 'gform_post_submission', array( $this, 'on_submitted' ), 10, 2 );
	}

	/**
	 * Somebody saved a half-finished form.
	 *
	 * @param mixed               $submission    The submission data.
	 * @param string              $resume_token  The token that resumes it.
	 * @param array<string,mixed> $form          The form.
	 * @param mixed               $entry         The partial entry.
	 * @return void
	 */
	public function on_saved( $submission, $resume_token, $form, $entry = array() ): void {
		$token = sanitize_key( (string) $resume_token );

		if ( '' === $token || ! is_array( $form ) ) {
			return;
		}

		if ( ! Settings::drafts_enabled() || ! Settings::watches( (int) ( $form['id'] ?? 0 ) ) ) {
			return;
		}

		$partial = $this->partial_entry( $entry, $submission );
		$pinned  = Settings::field_overrides( $form );

		if ( ! $this->fields->is_messageable( $form, $pinned ) ) {
			// A form with no phone and no email field describes somebody who
			// could never be contacted. Recording it would create a row the
			// eligibility rules would spend the rest of its life refusing.
			// Said out loud, at debug level, because "why is my form not being
			// recovered?" is otherwise a question with no evidence attached.
			$this->logger->debug(
				'gravityforms',
				'Skipped a form with no phone or email field: nobody on it could be messaged.',
				array( 'form' => (int) ( $form['id'] ?? 0 ) )
			);

			return;
		}

		$hints = $this->fields->hints( $form, $partial, $pinned );

		if ( ! $this->fields->has_contact( $hints ) ) {
			// The form asks for a contact detail and this entry came back with
			// none -- most often a phone number Gravity Forms itself dropped.
			// Recording it would write down somebody no message can reach.
			$this->logger->debug(
				'gravityforms',
				'Skipped a saved form with no contact detail on it: the fields are there, the answers are not.',
				array( 'form' => (int) ( $form['id'] ?? 0 ) )
			);

			return;
		}

		$draft = new Event_Draft( Source::ID, self::TYPE, self::KEY . $token );

		$draft->external_id      = $token;
		$draft->session_key      = $token;
		$draft->last_activity_at = gmdate( 'Y-m-d H:i:s' );

		$draft->with_items(
			array(
				array(
					'name' => Source::form_title( $form ),
					'ref'  => 'form:' . (int) ( $form['id'] ?? 0 ),
					'qty'  => 1,
				),
			)
		);

		$draft->metadata = array(
			'form_id'    => (int) ( $form['id'] ?? 0 ),
			'kind'       => 'draft',
			'resume_url' => Source::resume_url( $partial, $token ),
		);

		$draft->with_identity( $hints );

		$this->ingest->ingest( $draft );
	}

	/**
	 * A form was submitted for real, so any draft behind it is finished.
	 *
	 * The token is in the request rather than on the entry: that is how
	 * Gravity Forms itself finds the draft to delete. Reading it here is what
	 * stops a reminder going out about a form somebody has just completed.
	 *
	 * @param mixed $entry The entry.
	 * @param mixed $form  The form.
	 * @return void
	 */
	public function on_submitted( $entry, $form ): void {
		unset( $entry, $form );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Gravity
		// Forms has already accepted this submission on its own nonce; the token
		// is read only to close a row of ours that names it, and nothing is
		// written on the strength of it. A nonce of our own here would refuse
		// exactly the submissions we are trying to notice.
		$token = isset( $_POST['gform_resume_token'] )
			? sanitize_key( wp_unslash( (string) $_POST['gform_resume_token'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $token ) {
			return;
		}

		$this->ingest->close( Source::ID, self::KEY . $token, Recovery_Event::COMPLETED, 'form_submitted' );
	}

	/**
	 * The half-filled values, whichever shape Gravity Forms handed them over in.
	 *
	 * @param mixed $entry      The partial entry argument.
	 * @param mixed $submission The submission argument.
	 * @return array<string,mixed>
	 */
	private function partial_entry( $entry, $submission ): array {
		if ( is_array( $entry ) && array() !== $entry ) {
			return $entry;
		}

		if ( is_string( $submission ) ) {
			$submission = json_decode( $submission, true );
		}

		if ( is_array( $submission ) && isset( $submission['partial_entry'] ) && is_array( $submission['partial_entry'] ) ) {
			return $submission['partial_entry'];
		}

		return is_array( $submission ) ? $submission : array();
	}
}
