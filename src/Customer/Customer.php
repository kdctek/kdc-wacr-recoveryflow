<?php
/**
 * A person the store might message.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

defined( 'ABSPATH' ) || exit;

/**
 * One person, however many carts they abandon.
 *
 * The record is keyed on the phone number, because the phone number is what a
 * recovery message is addressed to: two records with the same number are the
 * same recipient whatever else differs. Email is matched but never merged on --
 * shared household addresses and role addresses like orders@ would otherwise
 * fuse strangers into one person and send one of them the other's basket.
 *
 * The hashes are keyed HMACs rather than bare digests, and they outlive the
 * plaintext. When a customer is erased the identifying columns are emptied and
 * the hashes stay, because the suppression list has to keep working: forgetting
 * that somebody opted out is the one thing an erasure must not do.
 */
final class Customer {

	public const CONSENT_UNKNOWN    = 'unknown';
	public const CONSENT_GRANTED    = 'granted';
	public const CONSENT_DENIED     = 'denied';
	public const CONSENT_WITHDRAWN  = 'withdrawn';
	public const CONSENT_SUPPRESSED = 'suppressed';

	public const PHONE_UNKNOWN = 'unknown';
	public const PHONE_VALID   = 'valid';
	public const PHONE_INVALID = 'invalid';

	/**
	 * Row id.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * WordPress user, when there is one.
	 *
	 * @var int|null
	 */
	public ?int $wp_user_id = null;

	/**
	 * Email address, or empty once erased.
	 *
	 * @var string
	 */
	public string $email = '';

	/**
	 * Keyed hash of the email, which survives erasure.
	 *
	 * @var string
	 */
	public string $email_hash = '';

	/**
	 * Normalised international number, or empty once erased.
	 *
	 * @var string
	 */
	public string $phone_e164 = '';

	/**
	 * The number exactly as the customer typed it, kept so an unparseable one
	 * can be corrected later rather than silently discarded.
	 *
	 * @var string
	 */
	public string $phone_raw = '';

	/**
	 * Whether the number could be normalised: unknown, valid or invalid.
	 *
	 * @var string
	 */
	public string $phone_status = self::PHONE_UNKNOWN;

	/**
	 * Keyed hash of the number. The identity key, and the suppression key.
	 *
	 * @var string
	 */
	public string $phone_hash = '';

	/**
	 * Given name.
	 *
	 * @var string
	 */
	public string $first_name = '';

	/**
	 * Family name.
	 *
	 * @var string
	 */
	public string $last_name = '';

	/**
	 * ISO 3166-1 alpha-2 country.
	 *
	 * @var string
	 */
	public string $country_iso2 = '';

	/**
	 * The matching contact in WA.cr, once one is known.
	 *
	 * @var string
	 */
	public string $wacr_contact_id = '';

	/**
	 * When the WA.cr contact was last checked or written, UTC.
	 *
	 * @var string|null
	 */
	public ?string $wacr_synced_at = null;

	/**
	 * When the record was anonymised, UTC.
	 *
	 * @var string|null
	 */
	public ?string $anonymized_at = null;

	/**
	 * When the record was created, UTC.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * When the record last changed, UTC.
	 *
	 * @var string
	 */
	public string $updated_at = '';

	/**
	 * Build one from a database row.
	 *
	 * @param array<string,mixed> $row Row as returned by the repository.
	 * @return Customer
	 */
	public static function from_row( array $row ): self {
		$customer = new self();

		$customer->id              = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$customer->wp_user_id      = isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : null;
		$customer->first_name      = (string) ( $row['first_name'] ?? '' );
		$customer->last_name       = (string) ( $row['last_name'] ?? '' );
		$customer->country_iso2    = (string) ( $row['country_iso2'] ?? '' );
		$customer->wacr_contact_id = (string) ( $row['wacr_contact_id'] ?? '' );
		$customer->wacr_synced_at  = isset( $row['wacr_synced_at'] ) ? (string) $row['wacr_synced_at'] : null;
		$customer->anonymized_at   = isset( $row['anonymized_at'] ) ? (string) $row['anonymized_at'] : null;
		$customer->created_at      = (string) ( $row['created_at'] ?? '' );
		$customer->updated_at      = (string) ( $row['updated_at'] ?? '' );

		return $customer;
	}

	/**
	 * Fill in the ways of reaching this person from their identity rows.
	 *
	 * The phone and email properties are no longer columns on the customer:
	 * identity lives in rows of its own, because a person may be reachable in
	 * several ways and the set changes over the relationship. They are kept as
	 * properties, and populated here, because they are what the code that
	 * actually sends a message reads -- composing a template, prefilling a
	 * checkout, syncing a contact -- and making every one of those callers
	 * assemble the set itself would spread the schema through the whole plugin
	 * for no gain.
	 *
	 * Read them, do not write them: the identity rows are the record.
	 *
	 * @param array<int,array<string,mixed>> $rows Identity rows for this customer.
	 * @return void
	 */
	public function with_identities( array $rows ): void {
		foreach ( $rows as $row ) {
			$kind  = (string) ( $row['kind'] ?? '' );
			$value = (string) ( $row['value_raw'] ?? '' );
			$hash  = (string) ( $row['value_hash'] ?? '' );

			if ( Identity::E164 === $kind ) {
				$this->phone_e164   = $value;
				$this->phone_hash   = $hash;
				$this->phone_status = (string) ( $row['status'] ?? self::PHONE_UNKNOWN );
				continue;
			}

			if ( Identity::EMAIL === $kind ) {
				$this->email      = $value;
				$this->email_hash = $hash;
			}
		}
	}

	/**
	 * Whether there is any identity a recovery message could be addressed to.
	 *
	 * Deliberately says nothing about consent. Whether this person may be
	 * messaged is a separate question, decided per channel against the consent
	 * ledger, and answering both here is what previously made "no phone number"
	 * and "not allowed" indistinguishable.
	 *
	 * @return bool
	 */
	public function is_messageable(): bool {
		return $this->has_valid_phone() || '' !== $this->email;
	}

	/**
	 * Whether there is a number a WhatsApp message could be addressed to.
	 *
	 * @return bool
	 */
	public function has_valid_phone(): bool {
		return self::PHONE_VALID === $this->phone_status && '' !== $this->phone_e164;
	}

	/**
	 * Whether this record has been anonymised.
	 *
	 * @return bool
	 */
	public function is_anonymized(): bool {
		return null !== $this->anonymized_at;
	}

	/**
	 * The name to greet them by, which may legitimately be empty.
	 *
	 * @return string
	 */
	public function display_first_name(): string {
		return $this->first_name;
	}
}
