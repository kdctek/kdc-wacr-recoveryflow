<?php
/**
 * The consent ledger.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

use WAcr\RecoveryFlow\Database\Repository;
use WAcr\RecoveryFlow\Recovery\Channel;
use WAcr\RecoveryFlow\Database\Table_Names;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only evidence that a message was allowed.
 *
 * Nothing here is ever updated or deleted. The question a regulator asks is not
 * "may you message this person" but "could you show that you were allowed to,
 * on the day you did", and only a history answers that. The latest row wins for
 * decisions; the rows behind it are the record.
 *
 * Rows are keyed on the identity HASH rather than the customer id, so the
 * ledger outlives the customer record. That is what lets an erased customer
 * stay suppressed: the person is forgotten, the refusal is not. Keying on the
 * identity row id would break it, because erasure may delete that row.
 *
 * They are also keyed on the channel, because consent is not one decision.
 * Agreeing to a WhatsApp message is not agreeing to a marketing email; the two
 * are different acts under different law, and a refusal of one must neither
 * silence nor licence the other. A decision meant to cover everything -- an
 * erasure, an admin suppression, the workspace-level opt-out -- is recorded
 * against Channel::ALL, so a channel added later cannot escape a refusal that
 * was meant to be total.
 */
final class Consent_Repository extends Repository {

	public const GRANTED    = 'granted';
	public const DENIED     = 'denied';
	public const WITHDRAWN  = 'withdrawn';
	public const SUPPRESSED = 'suppressed';

	/**
	 * Statuses that mean "do not message, and do not ask again".
	 */
	public const BLOCKING = array( self::WITHDRAWN, self::SUPPRESSED );

	/**
	 * Which table this repository owns.
	 *
	 * @return string
	 */
	protected function table_key(): string {
		return Table_Names::CONSENTS;
	}

	/**
	 * Append a consent decision.
	 *
	 * @param string   $identity_kind Identity kind the decision is about.
	 * @param string   $identity_hash Keyed hash of the identity value.
	 * @param string   $channel      Channel being decided, or Channel::ALL.
	 * @param int|null $customer_id  Customer, when one is known.
	 * @param string   $status       One of the class constants.
	 * @param string   $source       Where the decision came from, e.g. 'checkout_classic'.
	 * @param string   $text_version Which wording was shown.
	 * @param string   $ip_hash      Keyed hash of the IP address, or empty.
	 * @return int New row id.
	 */
	public function record( string $identity_kind, string $identity_hash, string $channel, ?int $customer_id, string $status, string $source, string $text_version = '', string $ip_hash = '' ): int {
		if ( '' === $identity_kind || '' === $identity_hash ) {
			return 0;
		}

		if ( Channel::ALL !== $channel && ! Channel::is_channel( $channel ) ) {
			return 0;
		}

		return $this->insert(
			array(
				'identity_kind' => substr( $identity_kind, 0, 16 ),
				'identity_hash' => $identity_hash,
				'channel'       => $channel,
				'customer_id'   => $customer_id,
				'status'        => substr( $status, 0, 12 ),
				'source'        => substr( $source, 0, 32 ),
				'text_version'  => '' === $text_version ? null : substr( $text_version, 0, 16 ),
				'ip_hash'       => '' === $ip_hash ? null : $ip_hash,
				'created_at'    => $this->clock->now(),
			),
			array()
		);
	}

	/**
	 * The decision that currently applies to an identity on a channel.
	 *
	 * Rows recorded against Channel::ALL are considered alongside the channel's
	 * own, and the newest of the two wins rather than the broadest. That order
	 * matters: somebody who opts out of everything and then deliberately opts
	 * back in to WhatsApp has changed their mind, and the ledger has to be able
	 * to say so. A blanket refusal that could never be narrowed again would
	 * make the opt-in link at the bottom of an email a lie.
	 *
	 * @param string $identity_kind Identity kind the decision is about.
	 * @param string $identity_hash Keyed hash of the identity value.
	 * @param string $channel       Channel being decided.
	 * @return array<string,mixed>|null
	 */
	public function latest( string $identity_kind, string $identity_hash, string $channel ): ?array {
		if ( '' === $identity_kind || '' === $identity_hash ) {
			return null;
		}

		$table = $this->table();

		return $this->one(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE identity_kind = %s AND identity_hash = %s AND channel IN ( %s, %s ) ORDER BY id DESC LIMIT 1",
				$identity_kind,
				$identity_hash,
				$channel,
				Channel::ALL
			)
		);
	}

	/**
	 * The current status for an identity on a channel, or 'unknown'.
	 *
	 * @param string $identity_kind Identity kind.
	 * @param string $identity_hash Keyed hash of the identity value.
	 * @param string $channel       Channel being decided.
	 * @return string
	 */
	public function status( string $identity_kind, string $identity_hash, string $channel ): string {
		$row = $this->latest( $identity_kind, $identity_hash, $channel );

		return null === $row ? Customer::CONSENT_UNKNOWN : (string) $row['status'];
	}

	/**
	 * Whether this identity must not be messaged on this channel.
	 *
	 * A suppression is checked before every send and is never overridden by a
	 * later checkout tick-box: somebody who has said stop has to say start
	 * again deliberately, not by filling in a form that happens to be pre-filled.
	 *
	 * @param string $identity_kind Identity kind.
	 * @param string $identity_hash Keyed hash of the identity value.
	 * @param string $channel       Channel being decided.
	 * @return bool
	 */
	public function is_suppressed( string $identity_kind, string $identity_hash, string $channel ): bool {
		return in_array( $this->status( $identity_kind, $identity_hash, $channel ), self::BLOCKING, true );
	}

	/**
	 * Every decision recorded for an identity, on any channel, newest first.
	 *
	 * Unfiltered by channel on purpose: this is the evidence trail, and the
	 * question it answers is what the person was asked and what they said, not
	 * what applies right now.
	 *
	 * @param string $identity_kind Identity kind.
	 * @param string $identity_hash Keyed hash of the identity value.
	 * @param int    $limit         Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function history( string $identity_kind, string $identity_hash, int $limit = 50 ): array {
		if ( '' === $identity_kind || '' === $identity_hash ) {
			return array();
		}

		$table = $this->table();

		return $this->many(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant.
			$this->db()->prepare(
				"SELECT * FROM `{$table}` WHERE identity_kind = %s AND identity_hash = %s ORDER BY id DESC LIMIT %d",
				$identity_kind,
				$identity_hash,
				max( 1, $limit )
			)
		);
	}

	/**
	 * Detach a customer id from the ledger without losing the decisions.
	 *
	 * Called during erasure: the rows stay so the suppression keeps working,
	 * but they stop pointing at a person.
	 *
	 * @param int $customer_id Customer id.
	 * @return int Rows changed.
	 */
	public function detach_customer( int $customer_id ): int {
		return $this->update( array( 'customer_id' => null ), array( 'customer_id' => $customer_id ) );
	}
}
