<?php
/**
 * The "have I already handled this?" ledger.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Database;

defined( 'ABSPATH' ) || exit;

/**
 * One table answering one question, asked by several subsystems.
 *
 * WordPress fires the same order hook more than once often enough that it has
 * to be assumed: a payment gateway calls back twice, an admin saves an order
 * that is already complete, a webhook is redelivered. Every handler that must
 * act once therefore claims a receipt first, and the PRIMARY KEY does the
 * excluding -- the second caller's INSERT is ignored and it can see that it
 * lost.
 *
 * The key is chosen by the caller and should describe the event rather than the
 * attempt, e.g. "wc_order:412:processing".
 */
final class Receipt_Repository extends Repository {

	public const KIND_ORDER   = 'order';
	public const KIND_WEBHOOK = 'webhook';
	public const KIND_REVEAL  = 'reveal';
	public const KIND_HOOK    = 'hook';

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::RECEIPTS;
	}

	/**
	 * Claim the right to handle something, once.
	 *
	 * @param string $key  Stable identifier for the event, max 128 characters.
	 * @param string $kind One of the KIND_* constants, for retention and audit.
	 * @return bool True for the one caller that may act; false for every repeat.
	 */
	public function claim( string $key, string $kind ): bool {
		// insert_ignore_wrote(), not insert_ignore(): this table's primary key
		// is receipt_key itself, so there is no AUTO_INCREMENT id and MySQL
		// leaves insert_id at 0. Reading the outcome as an id therefore
		// answered "somebody else got there first" to EVERY caller, including
		// the one whose insert actually wrote the row -- so no claim here has
		// ever succeeded on a real database, and every order event was being
		// discarded as a duplicate of itself.
		return $this->insert_ignore_wrote(
			array(
				'receipt_key' => substr( $key, 0, 128 ),
				'kind'        => substr( $kind, 0, 24 ),
				'created_at'  => $this->clock->now(),
			)
		);
	}

	/**
	 * Whether something has already been handled, without claiming it.
	 *
	 * @param string $key Receipt key.
	 * @return bool
	 */
	public function seen( string $key ): bool {
		$table = $this->table();

		return null !== $this->scalar(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare( "SELECT receipt_key FROM `{$table}` WHERE receipt_key = %s", substr( $key, 0, 128 ) )
		);
	}

	/**
	 * Give up a claim, so the event can be handled again.
	 *
	 * Used when the work a claim was taken for then failed, so that a retry is
	 * not silently swallowed as a duplicate.
	 *
	 * @param string $key Receipt key.
	 * @return void
	 */
	public function release( string $key ): void {
		$this->db()->delete( $this->table(), array( 'receipt_key' => substr( $key, 0, 128 ) ), array( '%s' ) );
	}

	/**
	 * Remove receipts older than a cut-off.
	 *
	 * @param string $before UTC datetime.
	 * @param int    $limit  Maximum rows to remove in this batch.
	 * @return int Rows removed.
	 */
	public function prune( string $before, int $limit = 500 ): int {
		return $this->delete_older_than( 'created_at', $before, $limit );
	}
}
