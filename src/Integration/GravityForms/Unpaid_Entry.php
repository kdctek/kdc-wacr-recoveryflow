<?php
/**
 * What "an unpaid Gravity Forms entry" means, in one place.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\GravityForms;

use WAcr\RecoveryFlow\Recovery\Event_Draft;

defined( 'ABSPATH' ) || exit;

/**
 * The single description of a recoverable entry, asked by both routes into it.
 *
 * There are two ways this adapter finds an unpaid entry: the submission hook,
 * which sees it happen, and the poll, which finds the ones no hook was
 * listening for. They must apply the same rule. Written twice, they would
 * eventually differ -- and the way they would differ is the worst way round: a
 * backfill chasing people the live path had deliberately decided to leave
 * alone, because a spam check or a status was added to one copy and not the
 * other.
 *
 * Every reason to refuse is here, and each is a reason somebody should not be
 * messaged rather than a tidiness rule:
 *
 * - No payment status at all means a form that asks for no money. Submitting it
 *   IS the conversion; there is nothing left to recover.
 * - A paid status means the money is in.
 * - Spam and trash are the shop's own moderation decisions. Messaging about one
 *   means that decision reaching the person it was made about.
 * - A form with neither a phone nor an email field describes somebody who could
 *   never be contacted, so a row for them is a row the eligibility rules would
 *   spend the rest of their life refusing.
 */
final class Unpaid_Entry {

	/**
	 * Statuses that mean the money is in, or as good as.
	 *
	 * Authorized is here because an authorisation is a commitment the merchant
	 * captures on their own schedule; chasing somebody whose card is already on
	 * the hook for the amount would be asking them to pay twice.
	 */
	private const PAID = array( 'Paid', 'Active', 'Approved', 'Authorized' );

	/**
	 * Whether a Gravity Forms payment status means the money is in.
	 *
	 * Stated as a list rather than as "anything that is not Failed", because a
	 * negative rule would classify every status a future payment add-on invents
	 * as money received -- and the cost of that mistake is a customer never
	 * being reminded about a sale that never completed.
	 *
	 * @param string $status The entry's payment_status.
	 * @return bool
	 */
	public static function is_paid( string $status ): bool {
		/**
		 * The Gravity Forms payment statuses that count as a completed sale.
		 *
		 * @param string[] $statuses Payment statuses.
		 */
		$paid = apply_filters( 'recoveryflow_gf_paid_statuses', self::PAID );

		return in_array( $status, is_array( $paid ) ? $paid : self::PAID, true );
	}

	/**
	 * Describe an entry as something worth recovering, or refuse it.
	 *
	 * @param array<string,mixed> $entry    The entry.
	 * @param array<string,mixed> $form     The form it belongs to.
	 * @param Field_Map           $fields   Field reading.
	 * @param string              $seen_by  'hook' or 'poll', for the record.
	 * @return Event_Draft|null Null when this entry must not be chased.
	 */
	public static function draft( array $entry, array $form, Field_Map $fields, string $seen_by ): ?Event_Draft {
		$id      = (int) ( $entry['id'] ?? 0 );
		$form_id = (int) ( $form['id'] ?? 0 );
		$status  = (string) ( $entry['payment_status'] ?? '' );

		if ( 0 === $id || 0 === $form_id ) {
			return null;
		}

		if ( '' === $status || self::is_paid( $status ) ) {
			return null;
		}

		if ( in_array( (string) ( $entry['status'] ?? 'active' ), array( 'spam', 'trash' ), true ) ) {
			return null;
		}

		if ( ! Settings::unpaid_enabled() || ! Settings::watches( $form_id ) ) {
			return null;
		}

		$pinned = Settings::field_overrides( $form );

		if ( ! $fields->is_messageable( $form, $pinned ) ) {
			return null;
		}

		$hints = $fields->hints( $form, $entry, $pinned );

		// The form asks for a contact detail and this entry came back with
		// none. A row here is a customer nothing could ever be sent to.
		if ( ! $fields->has_contact( $hints ) ) {
			return null;
		}

		$draft = new Event_Draft( Source::ID, Entry_Watcher::TYPE, Entry_Watcher::KEY . $id );

		$draft->external_id      = (string) $id;
		$draft->session_key      = Entry_Watcher::KEY . $id;
		$draft->last_activity_at = (string) ( $entry['date_created'] ?? gmdate( 'Y-m-d H:i:s' ) );

		$draft->with_value(
			(string) ( $entry['payment_amount'] ?? '0' ),
			(string) ( $entry['currency'] ?? '' )
		);

		$draft->with_items(
			array(
				array(
					'name' => Source::form_title( $form ),
					'ref'  => 'form:' . $form_id,
					'qty'  => 1,
				),
			)
		);

		// Never personal data: a form id, a payment status, how the row was
		// last seen and the address of the form's own page with any query
		// string removed -- prefill parameters are exactly where somebody's
		// name ends up.
		//
		// seen_by is deliberately not called found_by. Ingest is an upsert, so
		// the backfill rewrites this key on a row the live hook wrote first,
		// and a field named for the FIRST sighting would be wrong within one
		// pass. Last-seen is both true and the more useful diagnostic: rows
		// that all say poll are a site whose hooks are not firing.
		$draft->metadata = array(
			'form_id'    => $form_id,
			'kind'       => 'entry',
			'status'     => $status,
			'seen_by'    => 'poll' === $seen_by ? 'poll' : 'hook',
			'resume_url' => Source::resume_url( $entry, '' ),
		);

		$draft->with_identity( $hints );

		return $draft;
	}
}
