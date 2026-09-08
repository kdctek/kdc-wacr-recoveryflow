<?php
/**
 * The verdict on whether somebody may be messaged.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * A yes or no with the reason attached.
 *
 * The reason is not decoration. It is stored on the journey and shown in the
 * admin, because "we did not message this basket" is a question merchants ask
 * constantly, and the difference between "no phone number", "they opted out"
 * and "under your minimum order value" is the difference between a bug, a
 * correct refusal and a setting they should change.
 */
final class Eligibility {

	public const OK             = 'ok';
	public const DISABLED       = 'mode_disabled';
	public const NOT_IDENTIFIED = 'not_identified';
	public const NO_PHONE       = 'no_phone';

	/**
	 * Reachable on no channel the site has switched on.
	 *
	 * Distinct from NO_PHONE, which now means only that a WhatsApp message has
	 * nowhere to go. Somebody who gave an address and no number is not
	 * unreachable, they are reachable by email, and collapsing the two is what
	 * made an email-only customer look unrecoverable.
	 */
	public const NO_CHANNEL     = 'no_channel';
	public const INVALID_PHONE  = 'invalid_phone';
	public const NO_CONSENT     = 'no_consent';
	public const SUPPRESSED     = 'suppressed';
	public const WACR_OPTED_OUT = 'wacr_opted_out';
	public const ALREADY_OPEN   = 'already_open';
	public const FREQUENCY_CAP  = 'frequency_cap';
	public const MONTHLY_CAP    = 'monthly_cap';
	public const EXCLUDED_USER  = 'excluded_user';
	public const BELOW_MIN      = 'below_min_amount';
	public const ANONYMIZED     = 'anonymized';
	public const ALREADY_BOUGHT = 'already_completed';

	/**
	 * Whether a recovery message may be sent.
	 *
	 * @var bool
	 */
	public bool $allowed;

	/**
	 * Why. One of the class constants.
	 *
	 * @var string
	 */
	public string $reason;

	/**
	 * Constructor.
	 *
	 * @param bool   $allowed Whether messaging is permitted.
	 * @param string $reason  Machine-readable reason.
	 */
	private function __construct( bool $allowed, string $reason ) {
		$this->allowed = $allowed;
		$this->reason  = $reason;
	}

	/**
	 * A yes.
	 *
	 * @return Eligibility
	 */
	public static function allow(): self {
		return new self( true, self::OK );
	}

	/**
	 * A no, with a reason.
	 *
	 * @param string $reason One of the class constants.
	 * @return Eligibility
	 */
	public static function deny( string $reason ): self {
		return new self( false, $reason );
	}

	/**
	 * Whether this refusal is permanent for the person rather than this basket.
	 *
	 * A journey refused for a permanent reason is closed rather than retried:
	 * asking again tomorrow would be pestering somebody who has already said no.
	 *
	 * @return bool
	 */
	public function is_permanent(): bool {
		return in_array(
			$this->reason,
			array( self::SUPPRESSED, self::WACR_OPTED_OUT, self::ANONYMIZED, self::NO_CONSENT, self::INVALID_PHONE, self::NO_PHONE ),
			true
		);
	}

	/**
	 * A human explanation for the admin screens.
	 *
	 * @return string
	 */
	public function label(): string {
		$labels = array(
			self::OK             => __( 'Eligible', 'kdc-wacr-recoveryflow' ),
			self::DISABLED       => __( 'Recovery messaging is switched off', 'kdc-wacr-recoveryflow' ),
			self::NOT_IDENTIFIED => __( 'Nobody was identified', 'kdc-wacr-recoveryflow' ),
			self::NO_PHONE       => __( 'No phone number was given', 'kdc-wacr-recoveryflow' ),
			self::INVALID_PHONE  => __( 'The phone number could not be read as an international number', 'kdc-wacr-recoveryflow' ),
			self::NO_CONSENT     => __( 'The customer did not agree to be messaged', 'kdc-wacr-recoveryflow' ),
			self::SUPPRESSED     => __( 'The customer has opted out', 'kdc-wacr-recoveryflow' ),
			self::WACR_OPTED_OUT => __( 'The customer has opted out in WA.cr', 'kdc-wacr-recoveryflow' ),
			self::ALREADY_OPEN   => __( 'This customer already has a recovery in progress', 'kdc-wacr-recoveryflow' ),
			self::FREQUENCY_CAP  => __( 'This customer was messaged too recently', 'kdc-wacr-recoveryflow' ),
			self::MONTHLY_CAP    => __( 'This customer has reached the monthly limit', 'kdc-wacr-recoveryflow' ),
			self::EXCLUDED_USER  => __( 'Store staff are excluded', 'kdc-wacr-recoveryflow' ),
			self::BELOW_MIN      => __( 'Below the minimum order value', 'kdc-wacr-recoveryflow' ),
			self::ANONYMIZED     => __( 'The customer record was erased', 'kdc-wacr-recoveryflow' ),
			self::ALREADY_BOUGHT => __( 'The order was already completed', 'kdc-wacr-recoveryflow' ),
		);

		return $labels[ $this->reason ] ?? $this->reason;
	}
}
