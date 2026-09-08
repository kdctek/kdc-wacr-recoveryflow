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
	 * Cached verdict from the consent ledger.
	 *
	 * @var string
	 */
	public string $consent_status = self::CONSENT_UNKNOWN;

	/**
	 * When the customer opted out, UTC.
	 *
	 * @var string|null
	 */
	public ?string $opted_out_at = null;

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
		$customer->email           = (string) ( $row['email'] ?? '' );
		$customer->email_hash      = (string) ( $row['email_hash'] ?? '' );
		$customer->phone_e164      = (string) ( $row['phone_e164'] ?? '' );
		$customer->phone_raw       = (string) ( $row['phone_raw'] ?? '' );
		$customer->phone_status    = (string) ( $row['phone_status'] ?? self::PHONE_UNKNOWN );
		$customer->phone_hash      = (string) ( $row['phone_hash'] ?? '' );
		$customer->first_name      = (string) ( $row['first_name'] ?? '' );
		$customer->last_name       = (string) ( $row['last_name'] ?? '' );
		$customer->country_iso2    = (string) ( $row['country_iso2'] ?? '' );
		$customer->wacr_contact_id = (string) ( $row['wacr_contact_id'] ?? '' );
		$customer->wacr_synced_at  = isset( $row['wacr_synced_at'] ) ? (string) $row['wacr_synced_at'] : null;
		$customer->consent_status  = (string) ( $row['consent_status'] ?? self::CONSENT_UNKNOWN );
		$customer->opted_out_at    = isset( $row['opted_out_at'] ) ? (string) $row['opted_out_at'] : null;
		$customer->anonymized_at   = isset( $row['anonymized_at'] ) ? (string) $row['anonymized_at'] : null;
		$customer->created_at      = (string) ( $row['created_at'] ?? '' );
		$customer->updated_at      = (string) ( $row['updated_at'] ?? '' );

		return $customer;
	}

	/**
	 * Whether there is a number that can actually be messaged.
	 *
	 * @return bool
	 */
	public function is_messageable(): bool {
		return self::PHONE_VALID === $this->phone_status && '' !== $this->phone_e164 && null === $this->opted_out_at;
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
