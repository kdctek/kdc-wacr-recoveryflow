<?php
/**
 * What a site must have settled before it may send a recovery email.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The gate on the email channel, and the footer every recovery email carries.
 *
 * A recovery email is commercial mail. It is not a receipt, not a shipping
 * notice and not a reply to something the customer wrote: it is an unsolicited
 * message about a sale that has not happened, which is the exact thing CAN-SPAM
 * and its equivalents legislate. Two obligations follow, and neither is
 * satisfiable by writing better code:
 *
 * - the message must carry the sender's real physical postal address, and only
 *   the merchant knows what that is;
 * - it must carry a working unsubscribe that keeps working for at least thirty
 *   days after the message was sent.
 *
 * So the plugin cannot ship a compliant email channel; it can only refuse to
 * send until the merchant has supplied what the law requires. That refusal is
 * what this class is. `Rule_Set::channel_enabled()` asks it before allowing the
 * email channel, which means a site cannot turn email on -- by settings screen,
 * by WP-CLI or by writing the option directly -- and discover afterwards that
 * every message it sent was unlawful.
 *
 * The blockers are reason codes, not sentences. They are compared, logged and
 * shown next to a setting, so they are stored machine values and are never
 * translated; `reason_label()` is where the merchant-facing wording lives.
 *
 * **The address itself is a stored value, not a string of the software.** It is
 * never translated, never extracted into the .pot, and never reformatted to
 * suit the admin's locale: an address is formatted according to the country it
 * is in, not the language of whoever happens to be reading the settings screen.
 * That is why the country is asked for separately rather than parsed back out
 * of the text -- a machine-readable country is the one thing a formatter can
 * rely on, and guessing it from free text is how a Japanese address ends up
 * printed back to front.
 */
final class Email_Compliance {

	/**
	 * The merchant's physical postal address, as free text.
	 */
	public const SETTING_ADDRESS = 'merchant_postal_address';

	/**
	 * The country that address is in, ISO 3166-1 alpha-2.
	 */
	public const SETTING_COUNTRY = 'merchant_postal_country';

	/**
	 * Nothing has been entered as a postal address.
	 */
	public const NO_POSTAL_ADDRESS = 'no_postal_address';

	/**
	 * The address has no country, so nothing can format or verify it.
	 */
	public const NO_POSTAL_COUNTRY = 'no_postal_country';

	/**
	 * The unsubscribe link would stop working before the law says it may.
	 */
	public const UNSUBSCRIBE_WINDOW_TOO_SHORT = 'unsubscribe_window_too_short';

	/**
	 * How long an unsubscribe link must keep working, in days.
	 *
	 * Thirty is CAN-SPAM's floor, and it is the number the recovery link's own
	 * ceiling was already set to, so a merchant can satisfy it.
	 *
	 * A plain integer rather than an expression over DAY_IN_SECONDS: a class
	 * constant is evaluated when the class is loaded, which in this plugin can
	 * happen before WordPress has defined its time constants.
	 */
	public const MIN_UNSUBSCRIBE_DAYS = 30;

	/**
	 * The longest postal address that will be stored.
	 */
	public const MAX_ADDRESS_LENGTH = 500;

	/**
	 * The most lines a postal address may have.
	 */
	public const MAX_ADDRESS_LINES = 8;

	/**
	 * Everything that stands between this site and a lawful recovery email.
	 *
	 * Takes the settings rather than reading them, so a caller that resolved a
	 * snapshot -- Rule_Set does, deliberately, so a save halfway through a batch
	 * cannot change the rules for half of it -- gets an answer about that
	 * snapshot rather than about the database as it is right now.
	 *
	 * @param array<string,mixed>|null $settings A settings snapshot, or null to read the stored ones.
	 * @return string[] Reason codes, in the order a merchant should fix them; empty when nothing blocks.
	 */
	public static function blockers( ?array $settings = null ): array {
		$settings = null === $settings ? Options::all() : $settings;
		$blockers = array();

		if ( '' === self::address( $settings ) ) {
			$blockers[] = self::NO_POSTAL_ADDRESS;
		}

		if ( '' === self::country( $settings ) ) {
			$blockers[] = self::NO_POSTAL_COUNTRY;
		}

		if ( self::unsubscribe_days( $settings ) < self::MIN_UNSUBSCRIBE_DAYS ) {
			$blockers[] = self::UNSUBSCRIBE_WINDOW_TOO_SHORT;
		}

		return $blockers;
	}

	/**
	 * Whether a recovery email may lawfully be sent from this site.
	 *
	 * @param array<string,mixed>|null $settings A settings snapshot, or null to read the stored ones.
	 * @return bool
	 */
	public static function is_satisfied( ?array $settings = null ): bool {
		return array() === self::blockers( $settings );
	}

	/**
	 * The merchant's postal address, normalised for storage and for printing.
	 *
	 * @param array<string,mixed>|null $settings A settings snapshot, or null to read the stored ones.
	 * @return string Empty when none has been entered.
	 */
	public static function address( ?array $settings = null ): string {
		$settings = null === $settings ? Options::all() : $settings;

		return self::sanitize_address( (string) ( $settings[ self::SETTING_ADDRESS ] ?? '' ) );
	}

	/**
	 * The address as separate lines, for a settings screen or an email footer.
	 *
	 * @param array<string,mixed>|null $settings A settings snapshot, or null to read the stored ones.
	 * @return string[]
	 */
	public static function address_lines( ?array $settings = null ): array {
		$address = self::address( $settings );

		return '' === $address ? array() : explode( "\n", $address );
	}

	/**
	 * The country the address is in.
	 *
	 * @param array<string,mixed>|null $settings A settings snapshot, or null to read the stored ones.
	 * @return string Two upper-case letters, or '' when unset.
	 */
	public static function country( ?array $settings = null ): string {
		$settings = null === $settings ? Options::all() : $settings;

		return self::sanitize_country( (string) ( $settings[ self::SETTING_COUNTRY ] ?? '' ) );
	}

	/**
	 * Clean a postal address on its way into storage.
	 *
	 * Line endings are normalised, blank lines and surrounding space go, and the
	 * whole thing is capped. Nothing else is touched: the order of the lines,
	 * the punctuation and the script are the merchant's business, and a plugin
	 * that "tidied" an address into a shape it recognised would corrupt every
	 * address that is not laid out the way its author's is.
	 *
	 * @param string $raw What the merchant typed.
	 * @return string
	 */
	public static function sanitize_address( string $raw ): string {
		// Control characters, but not the newlines that make an address an
		// address. \p{C} would take those too.
		$clean = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw );
		$clean = str_replace( array( "\r\n", "\r" ), "\n", $clean );

		$lines = array();

		foreach ( explode( "\n", $clean ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$lines[] = $line;

			if ( count( $lines ) >= self::MAX_ADDRESS_LINES ) {
				break;
			}
		}

		$address = implode( "\n", $lines );

		if ( self::length( $address ) <= self::MAX_ADDRESS_LENGTH ) {
			return $address;
		}

		return function_exists( 'mb_substr' )
			? mb_substr( $address, 0, self::MAX_ADDRESS_LENGTH )
			: substr( $address, 0, self::MAX_ADDRESS_LENGTH );
	}

	/**
	 * Clean a country code on its way into storage.
	 *
	 * The list of valid codes is not checked here. ISO 3166-1 changes, a stale
	 * allow-list would refuse a real country, and refusing to store a merchant's
	 * own country is a worse failure than storing one nobody recognises.
	 *
	 * @param string $raw What the merchant chose.
	 * @return string Two upper-case letters, or ''.
	 */
	public static function sanitize_country( string $raw ): string {
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', $raw ) );

		return 2 === strlen( $code ) ? $code : '';
	}

	/**
	 * The block of text every recovery email has to end with.
	 *
	 * Returned as plain text with no markup, because the caller knows whether it
	 * is building a plain-text part or an HTML one and only the caller can
	 * escape for its own format.
	 *
	 * The address is printed exactly as stored and is not translated. The line
	 * offering the unsubscribe is translated, because it is the plugin's own
	 * words; the URL in it is not.
	 *
	 * @param string                   $opt_out_url The unsubscribe link minted for this message.
	 * @param array<string,mixed>|null $settings    A settings snapshot, or null to read the stored ones.
	 * @return string Empty when there is nothing lawful to print, which is itself the signal not to send.
	 */
	public static function footer( string $opt_out_url, ?array $settings = null ): string {
		$address = self::address( $settings );
		$url     = trim( $opt_out_url );

		if ( '' === $address || '' === $url ) {
			return '';
		}

		return $address . "\n\n" . sprintf(
			/* translators: %s: the web address of the unsubscribe page. */
			__( 'To stop receiving these reminders, visit %s', 'kdc-wacr-recoveryflow' ),
			$url
		);
	}

	/**
	 * What to tell the merchant about one blocker.
	 *
	 * @param string $code One of the reason-code constants.
	 * @return string
	 */
	public static function reason_label( string $code ): string {
		$labels = array(
			self::NO_POSTAL_ADDRESS            => __( 'Enter the postal address of the business sending these emails. Commercial email has to carry a real physical address, and this one is printed at the foot of every recovery email.', 'kdc-wacr-recoveryflow' ),
			self::NO_POSTAL_COUNTRY            => __( 'Choose the country that postal address is in. An address is laid out according to its own country, so this cannot be guessed from the address text.', 'kdc-wacr-recoveryflow' ),
			self::UNSUBSCRIBE_WINDOW_TOO_SHORT => sprintf(
				/* translators: %d: the number of days an unsubscribe link must keep working. */
				__( 'Set the recovery link lifetime to at least %d days. The unsubscribe link in an email is the same link, and it has to keep working for that long after the email was sent.', 'kdc-wacr-recoveryflow' ),
				self::MIN_UNSUBSCRIBE_DAYS
			),
		);

		return $labels[ $code ] ?? __( 'This has to be settled before email reminders can be switched on.', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * How long an unsubscribe link would keep working, in days.
	 *
	 * Rule_Set::link_ttl_seconds() clamps the same setting to between one and
	 * thirty days before using it, and this deliberately does not repeat that
	 * clamp: thirty is also the ceiling, so anything the clamp could produce is
	 * on the same side of the threshold as the number it came from, and a
	 * second copy of the arithmetic would be a place for the two to disagree
	 * later for no benefit now.
	 *
	 * A value that is not a number reads as zero and blocks, which is the right
	 * way round -- an unreadable lifetime is not evidence of a lawful one.
	 *
	 * @param array<string,mixed> $settings A settings snapshot.
	 * @return int
	 */
	private static function unsubscribe_days( array $settings ): int {
		return (int) ( $settings['recovery_link_ttl_days'] ?? 7 );
	}

	/**
	 * Character length, counting a multi-byte character once.
	 *
	 * @param string $value The string.
	 * @return int
	 */
	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
