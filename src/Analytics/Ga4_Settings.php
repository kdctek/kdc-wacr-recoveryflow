<?php
/**
 * The merchant's Google Analytics 4 settings, read in one place.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Analytics;

use WAcr\RecoveryFlow\Security\Crypto;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Whether RecoveryFlow tags its links and reports to GA4, and with what.
 *
 * Two features with two different defaults, and the difference is the point.
 *
 * **Tagging the restore link is on by default.** It adds utm_* parameters to
 * the address the shopper lands on and sends nothing anywhere: the merchant's
 * own analytics reads them, if the merchant has any, and nobody else ever sees
 * them.
 *
 * **Reporting journey milestones is off until the merchant asks for it.** It is
 * a request from this site to Google, so it needs the merchant to paste a
 * Measurement ID and an API secret and tick the box; until all three are true
 * nothing is read from a visitor and nothing is sent.
 *
 * The API secret is a credential, so it is stored encrypted in its own
 * non-autoloaded option rather than in the settings array, exactly as the
 * WA.cr API key is.
 */
final class Ga4_Settings {

	/**
	 * The option the encrypted API secret is stored in.
	 */
	public const API_SECRET_OPTION = 'recoveryflow_ga4_api_secret';

	/**
	 * The pseudo-key the API secret field is addressed by in the settings tree.
	 */
	public const FIELD_API_SECRET = 'ga4_api_secret';

	/**
	 * Google's global collection endpoint.
	 */
	public const REGION_GLOBAL = 'global';

	/**
	 * Google's EU collection endpoint.
	 */
	public const REGION_EU = 'eu';

	/**
	 * Reporting is off because the box is not ticked.
	 */
	public const STATUS_OFF = 'off';

	/**
	 * The box is ticked but the Measurement ID or the secret is missing.
	 */
	public const STATUS_INCOMPLETE = 'incomplete';

	/**
	 * The box is ticked but the stored secret cannot be decrypted.
	 */
	public const STATUS_UNREADABLE = 'unreadable';

	/**
	 * Reporting is on and configured.
	 */
	public const STATUS_ON = 'on';

	/**
	 * What a GA4 web stream's Measurement ID looks like.
	 */
	private const MEASUREMENT_ID_PATTERN = '/^G-[A-Z0-9]{4,20}$/';

	/**
	 * Whether restore links carry utm_* parameters.
	 *
	 * @return bool
	 */
	public static function utm_enabled(): bool {
		return (bool) Options::get( 'ga4_utm_enabled', true );
	}

	/**
	 * Whether the merchant has switched milestone reporting on.
	 *
	 * Not the same as is_reporting(): this is the box, that is the box plus
	 * everything the box needs.
	 *
	 * @return bool
	 */
	public static function events_enabled(): bool {
		return (bool) Options::get( 'ga4_events_enabled', false );
	}

	/**
	 * The stored Measurement ID, or '' when there is none or it is malformed.
	 *
	 * @return string
	 */
	public static function measurement_id(): string {
		$id = self::normalize_measurement_id( (string) Options::get( 'ga4_measurement_id', '' ) );

		return self::is_valid_measurement_id( $id ) ? $id : '';
	}

	/**
	 * Tidy a Measurement ID as typed: trimmed and upper-cased.
	 *
	 * @param string $id As entered.
	 * @return string
	 */
	public static function normalize_measurement_id( string $id ): string {
		return strtoupper( trim( $id ) );
	}

	/**
	 * Whether a string is shaped like a GA4 Measurement ID.
	 *
	 * @param string $id Candidate, already normalised.
	 * @return bool
	 */
	public static function is_valid_measurement_id( string $id ): bool {
		return 1 === preg_match( self::MEASUREMENT_ID_PATTERN, $id );
	}

	/**
	 * Which Google collection endpoint to send to.
	 *
	 * @return string One of the REGION_* constants.
	 */
	public static function region(): string {
		return self::REGION_EU === (string) Options::get( 'ga4_region', self::REGION_GLOBAL ) ? self::REGION_EU : self::REGION_GLOBAL;
	}

	/**
	 * The API secret, in the clear, or '' when none is stored or it is unreadable.
	 *
	 * @return string
	 */
	public static function api_secret(): string {
		return Crypto::decrypt( (string) get_option( self::API_SECRET_OPTION, '' ) );
	}

	/**
	 * Store an API secret.
	 *
	 * @param string $secret The secret, or '' to remove it.
	 * @return void
	 */
	public static function set_api_secret( string $secret ): void {
		$secret = trim( $secret );

		if ( '' === $secret ) {
			delete_option( self::API_SECRET_OPTION );

			return;
		}

		update_option( self::API_SECRET_OPTION, Crypto::encrypt( $secret ), false );
	}

	/**
	 * Whether a secret is stored at all, readable or not.
	 *
	 * @return bool
	 */
	public static function has_api_secret(): bool {
		return '' !== (string) get_option( self::API_SECRET_OPTION, '' );
	}

	/**
	 * Whether a secret is stored but can no longer be decrypted.
	 *
	 * Happens when the site's salts change. Reported rather than treated as
	 * "no secret", so the screen can say what to do about it.
	 *
	 * @return bool
	 */
	public static function is_secret_unreadable(): bool {
		return Crypto::is_undecryptable( (string) get_option( self::API_SECRET_OPTION, '' ) );
	}

	/**
	 * Whether milestone reporting is switched on AND has what it needs to work.
	 *
	 * The one answer every part of the feature asks: the cookie reader, the
	 * reporter that queues, the stage that sends and the Integrations card that
	 * describes all of it. Four places reading four different subsets of these
	 * facts is how a screen comes to say "reporting" about a site that is not.
	 *
	 * @return bool
	 */
	public static function is_reporting(): bool {
		return self::STATUS_ON === self::status();
	}

	/**
	 * Where reporting stands, as one of the STATUS_* constants.
	 *
	 * @return string
	 */
	public static function status(): string {
		if ( ! self::events_enabled() ) {
			return self::STATUS_OFF;
		}

		if ( self::has_api_secret() && self::is_secret_unreadable() ) {
			return self::STATUS_UNREADABLE;
		}

		if ( '' === self::measurement_id() || '' === self::api_secret() ) {
			return self::STATUS_INCOMPLETE;
		}

		return self::STATUS_ON;
	}

	/**
	 * Where reporting stands, as a sentence for the merchant.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @return string
	 */
	public static function status_message( string $status ): string {
		switch ( $status ) {
			case self::STATUS_ON:
				return __( 'Reporting. Journeys that sent a message are reported to your GA4 property when they are messaged, recovered, expire or opt out.', 'kdc-wacr-recoveryflow' );

			case self::STATUS_INCOMPLETE:
				return __( 'Switched on but not reporting: a Measurement ID and an API secret are both needed.', 'kdc-wacr-recoveryflow' );

			case self::STATUS_UNREADABLE:
				return __( 'Switched on but not reporting: the saved API secret can no longer be read, usually because the site\'s security keys changed. Enter it again.', 'kdc-wacr-recoveryflow' );

			default:
				return __( 'Not reporting. Journey milestones are not sent to Google Analytics.', 'kdc-wacr-recoveryflow' );
		}
	}
}
