<?php
/**
 * What a source knows about who is shopping.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Customer;

defined( 'ABSPATH' ) || exit;

/**
 * Everything an adapter has managed to learn about the person, and nothing more.
 *
 * Adapters collect identity opportunistically -- a phone typed into checkout, a
 * logged-in user's profile, an email from a form -- so every field here is
 * optional and none of it is trusted. The resolver decides what any of it means.
 *
 * These are hints rather than a customer record on purpose: an adapter must not
 * be able to assert "this is customer 41". It reports what it saw, and identity
 * resolution stays in one place where the rules about which field may merge two
 * people can be enforced.
 */
final class Identity_Hints {

	/**
	 * WordPress user id, when the visitor is logged in.
	 *
	 * @var int|null
	 */
	public ?int $wp_user_id = null;

	/**
	 * Email address as entered.
	 *
	 * @var string
	 */
	public string $email = '';

	/**
	 * Phone number exactly as entered, before normalisation.
	 *
	 * @var string
	 */
	public string $phone_raw = '';

	/**
	 * ISO 3166-1 alpha-2 country, used as the hint for normalising the phone.
	 *
	 * @var string
	 */
	public string $country = '';

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
	 * The source's own customer identifier, if it has one.
	 *
	 * @var string
	 */
	public string $external_id = '';

	/**
	 * Whether the visitor agreed to be messaged: true, false, or null for "not asked".
	 *
	 * Null and false mean different things and must not be collapsed. False is a
	 * refusal and is recorded as one; null means the question was never put, and
	 * whether that is enough depends on the site's eligibility mode.
	 *
	 * @var bool|null
	 */
	public ?bool $consent = null;

	/**
	 * Where the consent answer came from, e.g. 'checkout_classic'.
	 *
	 * @var string
	 */
	public string $consent_source = '';

	/**
	 * Which wording the visitor agreed to, so the record stays evidence.
	 *
	 * @var string
	 */
	public string $consent_text_version = '';

	/**
	 * Build from an associative array, ignoring anything unrecognised.
	 *
	 * @param array<string,mixed> $data Hint values.
	 * @return Identity_Hints
	 */
	public static function from_array( array $data ): self {
		$hints = new self();

		$hints->wp_user_id           = isset( $data['wp_user_id'] ) && $data['wp_user_id'] ? (int) $data['wp_user_id'] : null;
		$hints->email                = self::text( $data['email'] ?? '', 191 );
		$hints->phone_raw            = self::text( $data['phone_raw'] ?? ( $data['phone'] ?? '' ), 32 );
		$hints->country              = strtoupper( substr( self::text( $data['country'] ?? '', 2 ), 0, 2 ) );
		$hints->first_name           = self::text( $data['first_name'] ?? '', 100 );
		$hints->last_name            = self::text( $data['last_name'] ?? '', 100 );
		$hints->external_id          = self::text( $data['external_id'] ?? '', 191 );
		$hints->consent_source       = self::text( $data['consent_source'] ?? '', 32 );
		$hints->consent_text_version = self::text( $data['consent_text_version'] ?? '', 16 );

		if ( array_key_exists( 'consent', $data ) && null !== $data['consent'] ) {
			$hints->consent = (bool) $data['consent'];
		}

		return $hints;
	}

	/**
	 * Whether there is anything here worth resolving.
	 *
	 * @return bool
	 */
	public function has_identity(): bool {
		return null !== $this->wp_user_id || '' !== $this->phone_raw || '' !== $this->email;
	}

	/**
	 * Whether there is a phone number to message.
	 *
	 * @return bool
	 */
	public function has_phone(): bool {
		return '' !== $this->phone_raw;
	}

	/**
	 * A value that changes whenever the contact details change.
	 *
	 * Adapters use this to avoid re-resolving identity on every request: if the
	 * fingerprint is unchanged, nothing about the person has changed and the
	 * write can be skipped. It is a hash rather than the values themselves so it
	 * is safe to keep in a session.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		return hash(
			'sha256',
			implode(
				'|',
				array(
					(string) ( $this->wp_user_id ?? 0 ),
					strtolower( $this->email ),
					$this->phone_raw,
					$this->country,
					$this->first_name,
					$this->last_name,
					null === $this->consent ? 'null' : ( $this->consent ? '1' : '0' ),
				)
			)
		);
	}

	/**
	 * Sanitise and cap one incoming string.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $length Maximum characters to keep.
	 * @return string
	 */
	private static function text( $value, int $length ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return substr( sanitize_text_field( (string) $value ), 0, $length );
	}
}
