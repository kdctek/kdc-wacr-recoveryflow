<?php
/**
 * Settings storage.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's settings.
 *
 * Everything lives in one autoloaded option except the credentials, which are
 * stored separately with autoload off so they are not read into memory on every
 * page load of every request.
 */
final class Options {

	public const SETTINGS       = 'recoveryflow_settings';
	public const API_KEY        = 'recoveryflow_api_key';
	public const HOOK_SECRET    = 'recoveryflow_hook_secret';
	public const WEBHOOK_SECRET = 'recoveryflow_webhook_secret_hash';
	public const ME_SNAPSHOT    = 'recoveryflow_wacr_me';
	public const STAGE_STATS    = 'recoveryflow_stage_stats';

	/**
	 * Default settings.
	 *
	 * Defaults are deliberately conservative: the plugin does nothing until
	 * somebody switches it on, and it messages nobody who has not agreed to be
	 * messaged.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// General.
			'enabled'                  => false,
			'logging_level'            => 'warning',
			'log_retention_days'       => 14,

			/*
			 * Channels.
			 *
			 * These two repeat, as stored values, exactly what
			 * Rule_Set::channel_enabled() already falls back to when the keys
			 * are absent -- WhatsApp on, because it is what the plugin is for,
			 * and email off, because a recovery email is commercial mail and
			 * this site has not yet been asked for the postal address the law
			 * wants on one. Storing them changes no behaviour; it gives the
			 * settings screen something to render, so the merchant's switch is
			 * a switch they can see rather than one they have to know about.
			 *
			 * Email still passes a second gate after this one. Turning it on
			 * here does not make it send -- see Email_Compliance.
			 */
			'channel_whatsapp_enabled' => true,
			'channel_email_enabled'    => false,

			/*
			 * Where a contact detail may be collected.
			 *
			 * All three off, and the reason is the same for each: every one of
			 * them puts a field in front of a shopper who is trying to get
			 * through a page, and two of them can cost a sale outright -- a
			 * required phone number turns "I would rather not" into "I cannot
			 * buy here". A plugin does not get to make that trade on a
			 * merchant's behalf by shipping it switched on.
			 *
			 * The checkout is not in this list because capture there is not
			 * optional: it is where the details are typed anyway, and reading
			 * what somebody has already entered costs them nothing.
			 */
			'capture_at_cart'          => false,
			'capture_at_add_to_cart'   => false,
			'checkout_phone_required'  => false,

			// Recovery rules.
			'inactivity_minutes'       => 30,
			'max_age_days'             => 7,
			'min_amount'               => 0,
			'max_touches'              => 3,
			'quiet_hours_enabled'      => true,
			'quiet_hours_start'        => '21:00',
			'quiet_hours_end'          => '09:00',
			'frequency_cap_hours'      => 24,
			'max_journeys_per_month'   => 3,
			'attribution_window_days'  => 30,
			'exclude_admins'           => true,

			// WA.cr.
			'wacr_environment'         => 'production',
			'wacr_base_url'            => '',
			'wacr_sender'              => '',
			'wacr_dispatch'            => 'start_flow',
			'wacr_hook_url'            => '',
			'wacr_push_optout'         => false,
			'wacr_share_last_name'     => false,
			'wacr_share_email'         => false,
			'wacr_sync_optout'         => true,

			/*
			 * Email compliance.
			 *
			 * Both are empty because only the merchant knows them, and both are
			 * stored values rather than strings of the software: an address is
			 * never translated and never appears in the .pot. While either is
			 * empty the email channel cannot be switched on at all -- see
			 * Email_Compliance, which is what refuses it.
			 */
			'merchant_postal_address'  => '',
			'merchant_postal_country'  => '',

			// Privacy.
			'eligibility_mode'         => 'explicit_consent',
			'consent_label'            => '',
			'retention_days'           => 90,
			'delete_data_on_uninstall' => false,

			// Recovery links.
			'recovery_link_ttl_days'   => 7,
			'prefill_guest_checkout'   => true,
		);
	}

	/**
	 * All settings, defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::SETTINGS, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Returned when the setting is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Write one setting.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function set( string $key, $value ): void {
		$all         = self::all();
		$all[ $key ] = $value;

		update_option( self::SETTINGS, $all );
	}

	/**
	 * Seed the option so the settings screen has something to read.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( false === get_option( self::SETTINGS, false ) ) {
			add_option( self::SETTINGS, self::defaults() );
		}
	}

	/**
	 * Every option name the plugin owns, for uninstall.
	 *
	 * @return string[]
	 */
	public static function all_option_names(): array {
		return array(
			self::SETTINGS,
			self::API_KEY,
			self::HOOK_SECRET,
			self::WEBHOOK_SECRET,
			self::ME_SNAPSHOT,
			self::STAGE_STATS,
			\WAcr\RecoveryFlow\Database\Schema::VERSION_OPTION,
			\WAcr\RecoveryFlow\Security\Capabilities::VERSION_OPTION,
			\WAcr\RecoveryFlow\Security\Hash_Key::OPTION,
			'recoveryflow_scheduler_driver',
			'recoveryflow_lawful_basis_ack',
			'recoveryflow_ui_state',
			\WAcr\RecoveryFlow\Integration\Source_Cursors::OPTION,
			\WAcr\RecoveryFlow\Admin\Setup::PENDING_OPTION,
		);
	}
	/**
	 * Whether this site only messages people who ticked a box.
	 *
	 * Asked by the WooCommerce consent field, which renders only in this mode,
	 * and by the Integrations screen, which explains what a source needs in it.
	 * Two callers reading one option through the same sentence, because the
	 * pair disagreeing is how a screen ends up describing a rule the engine is
	 * not applying.
	 *
	 * @return bool
	 */
	public static function requires_explicit_consent(): bool {
		return 'explicit_consent' === (string) self::get( 'eligibility_mode', 'explicit_consent' );
	}
}
