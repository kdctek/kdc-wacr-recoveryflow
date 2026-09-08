<?php
/**
 * WA.cr connection settings.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Security\Crypto;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Where to call, with what credential, as whom.
 *
 * The key is encrypted at rest and never leaves this class in one piece: the
 * settings screen renders a mask, the status report omits it, and the logger
 * would redact it even if something tried.
 */
final class Credentials {

	public const HOST_PRODUCTION = 'https://api.wa.cr';
	public const HOST_STAGING    = 'https://api.wacart.dev';

	/**
	 * The environments a merchant can choose.
	 *
	 * @return array<string,string>
	 */
	public static function environments(): array {
		/*
		 * The hostname is a value, not part of the sentence: baking it into the
		 * translatable string invites a translator to "translate" a domain
		 * name, and means every host change reopens every locale.
		 */
		return array(
			'production' => sprintf(
				/* translators: %s: the WA.cr API hostname, which is not translated. */
				_x( 'Production (%s)', 'WA.cr API environment', 'kdc-wacr-recoveryflow' ),
				self::host_of( self::HOST_PRODUCTION )
			),
			'staging'    => sprintf(
				/* translators: %s: the WA.cr API hostname, which is not translated. */
				_x( 'Staging (%s)', 'WA.cr API environment', 'kdc-wacr-recoveryflow' ),
				self::host_of( self::HOST_STAGING )
			),
		);
	}

	/**
	 * The hostname part of one of the environment base URLs.
	 *
	 * @param string $url Base URL.
	 * @return string
	 */
	private static function host_of( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? $host : $url;
	}

	/**
	 * Hosts the plugin is allowed to talk to.
	 *
	 * An allow-list rather than a free-text base URL: a settings field that
	 * accepts any host is a way to point a site's API credential, and its
	 * customers' phone numbers, at somebody else's server.
	 *
	 * @return string[]
	 */
	public static function allowed_hosts(): array {
		$hosts = array( 'api.wa.cr', 'api.wacart.dev' );

		/**
		 * Filter the hosts RecoveryFlow may call.
		 *
		 * White-label deployments of WA.cr answer on their own hostname; adding
		 * one here is how a Partner points the plugin at it.
		 *
		 * @param string[] $hosts Allowed hostnames.
		 */
		return array_values( array_unique( array_map( 'strtolower', (array) apply_filters( Hooks::FILTER_ALLOWED_HOSTS, $hosts ) ) ) );
	}

	/**
	 * The API host to call.
	 *
	 * @return string
	 */
	public function base_url(): string {
		$environment = (string) Options::get( 'wacr_environment', 'production' );
		$custom      = trim( (string) Options::get( 'wacr_base_url', '' ) );

		if ( '' !== $custom ) {
			$host     = wp_parse_url( $custom, PHP_URL_HOST );
			$is_https = 'https' === wp_parse_url( $custom, PHP_URL_SCHEME );

			if ( is_string( $host ) && $is_https && in_array( strtolower( $host ), self::allowed_hosts(), true ) ) {
				return untrailingslashit( $custom );
			}
		}

		return 'staging' === $environment ? self::HOST_STAGING : self::HOST_PRODUCTION;
	}

	/**
	 * The API key, in the clear.
	 *
	 * @return string
	 */
	public function api_key(): string {
		return Crypto::decrypt( (string) get_option( Options::API_KEY, '' ) );
	}

	/**
	 * Store an API key.
	 *
	 * @param string $key The key, or '' to remove it.
	 * @return void
	 */
	public function set_api_key( string $key ): void {
		$key = trim( $key );

		if ( '' === $key ) {
			delete_option( Options::API_KEY );
			delete_option( Options::ME_SNAPSHOT );

			return;
		}

		/*
		 * A snapshot describes one credential, so it stops being true the
		 * moment a different key is stored. Clearing it only when the key was
		 * REMOVED left the previous credential's tenant name, scopes and plan
		 * standing against the new key: the settings screen would report
		 * "Connected to <the old workspace>, ending <the new key>" -- one
		 * sentence built from two credentials -- and Feature_Gate would go on
		 * granting direct sends on scopes the stored key may not hold.
		 *
		 * Compared in the clear rather than by ciphertext: encryption is
		 * nonce-based, so re-saving the same key produces a different string
		 * at rest and every save would look like a change.
		 */
		if ( $this->api_key() !== $key ) {
			delete_option( Options::ME_SNAPSHOT );
		}

		update_option( Options::API_KEY, Crypto::encrypt( $key ), false );
	}

	/**
	 * Whether a key is stored at all.
	 *
	 * @return bool
	 */
	public function has_api_key(): bool {
		return '' !== (string) get_option( Options::API_KEY, '' );
	}

	/**
	 * Whether a key is stored but can no longer be decrypted.
	 *
	 * @return bool
	 */
	public function is_key_unreadable(): bool {
		return Crypto::is_undecryptable( (string) get_option( Options::API_KEY, '' ) );
	}

	/**
	 * Whether the plugin has everything it needs to call the API.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key();
	}

	/**
	 * A key as it is safe to display.
	 *
	 * Shows the prefix, which tells a merchant whether they pasted a live or a
	 * test key, and the last four characters so they can tell two keys apart.
	 *
	 * @return string
	 */
	public function masked_key(): string {
		$key = $this->api_key();

		if ( '' === $key ) {
			return '';
		}

		if ( 1 === preg_match( '/^((?:wacr|waht)_(?:live|test)_)(.+)$/', $key, $found ) ) {
			return $found[1] . '••••' . substr( $found[2], -4 );
		}

		return '••••' . substr( $key, -4 );
	}

	/**
	 * Whether a string is shaped like a WA.cr credential.
	 *
	 * Checked before saving so an obvious paste error is reported at the field
	 * rather than as a mysterious refusal later.
	 *
	 * @param string $key Candidate.
	 * @return bool
	 */
	public static function looks_like_key( string $key ): bool {
		return 1 === preg_match( '/^(?:wacr|waht)_(?:live|test)_[A-Za-z0-9_-]{16,128}$/', trim( $key ) );
	}

	/**
	 * The configured sender, as a channel id or WhatsApp Business Account id.
	 *
	 * @return string
	 */
	public function sender(): string {
		return trim( (string) Options::get( 'wacr_sender', '' ) );
	}

	/**
	 * The Auto Flow hook URL a journey is handed to.
	 *
	 * @return string
	 */
	public function hook_url(): string {
		return trim( (string) Options::get( 'wacr_hook_url', '' ) );
	}

	/**
	 * Whether a hook URL is set and points at an allowed host.
	 *
	 * @return bool
	 */
	public function has_hook(): bool {
		$url = $this->hook_url();

		if ( '' === $url ) {
			return false;
		}

		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return is_string( $host )
			&& 'https' === $scheme
			&& in_array( strtolower( $host ), self::allowed_hosts(), true );
	}

	/**
	 * The shared secret an Auto Flow hook is signed with.
	 *
	 * @return string
	 */
	public function hook_secret(): string {
		return Crypto::decrypt( (string) get_option( Options::HOOK_SECRET, '' ) );
	}

	/**
	 * Store the hook signing secret.
	 *
	 * @param string $secret Secret, or '' to remove it.
	 * @return void
	 */
	public function set_hook_secret( string $secret ): void {
		$secret = trim( $secret );

		if ( '' === $secret ) {
			delete_option( Options::HOOK_SECRET );

			return;
		}

		update_option( Options::HOOK_SECRET, Crypto::encrypt( $secret ), false );
	}

	/**
	 * Record what the last successful connection check found.
	 *
	 * @param array $snapshot ok, tenant_name, mode, scopes, reason, checked_at.
	 * @return void
	 */
	public function remember_snapshot( array $snapshot ): void {
		update_option( Options::ME_SNAPSHOT, $snapshot, false );
	}
}
