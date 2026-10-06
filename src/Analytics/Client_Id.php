<?php
/**
 * Reads the visitor's Google Analytics client id, when it is allowed to.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Analytics;

use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Recovery\Event_Draft;

defined( 'ABSPATH' ) || exit;

/**
 * The `_ga` cookie, read on the server, behind the site's own cookie consent.
 *
 * **On the server, not in a script.** `_ga` is a first-party cookie, so it
 * arrives on every request the shopper's browser makes to this site --
 * including the Store API calls the block checkout makes, where the plugin's
 * classic-checkout script is never loaded. Reading it where the cart is
 * already being recorded costs nothing and covers both checkouts.
 *
 * **The whole value, kept as it is.** The Measurement Protocol accepts the full
 * cookie value as a client id, which Google documents; taking the last two
 * dot-separated numbers out of it is common practice that Google does not
 * document. So the value is checked against the shape and kept whole, and
 * anything that does not look like a GA cookie is treated as no cookie at all.
 *
 * **The site's cookie consent decides, not ours.** Three gates, in order:
 *
 * 1. The merchant has switched GA4 reporting on and configured it. Until then
 *    nothing is read, because an identifier that will never be used should
 *    never be collected.
 * 2. Where the site runs the WP Consent API, statistics consent is granted.
 *    That API answers "yes" to everything when no banner has set a consent
 *    type, so it can only refine the answer, never prove it.
 * 3. The cookie exists. Google's own Consent Mode does not set it when the
 *    visitor refused analytics storage, so a refused visitor fails soft here
 *    without this plugin having to know which banner the site uses.
 *
 * The recovery tick-box is deliberately not one of the gates: it is consent to
 * be messaged on WhatsApp, a different purpose, and stretching it to cover
 * analytics would make it consent to two things at once.
 *
 * **Only in the visitor's own request.** Background work never reads it, and
 * not only because cron has no visitor: Action Scheduler's async runner posts
 * back to the site carrying the cookies of whichever request triggered it --
 * usually a logged-in admin's -- so a background pass that read `_ga` would
 * stamp the shop manager's browser onto a customer's basket.
 */
final class Client_Id {

	/**
	 * The cookie Google's tag stores the client id in.
	 */
	public const COOKIE = '_ga';

	/**
	 * What a GA client id cookie looks like: GA1.<depth>.<random>.<timestamp>.
	 */
	private const PATTERN = '/^GA1\.[1-9][0-9]?\.[0-9]{1,20}\.[0-9]{1,20}$/';

	/**
	 * The longest value accepted; also the column width.
	 */
	public const MAX_LENGTH = 64;

	/**
	 * The client id for this request, or '' when there is none or it may not be read.
	 *
	 * @return string
	 */
	public static function from_request(): string {
		if ( ! Ga4_Settings::is_reporting() || ! self::is_visitor_request() || ! self::statistics_allowed() ) {
			return '';
		}

		/**
		 * Filters the name of the cookie the GA client id is read from.
		 *
		 * A site whose tag sets `cookie_prefix` stores it under a different
		 * name, for example "shop_ga".
		 *
		 * @param string $name Cookie name. Default '_ga'.
		 */
		$name = (string) apply_filters( Hooks::FILTER_GA_COOKIE, self::COOKIE );

		if ( '' === $name || ! isset( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
			return '';
		}

		return self::normalize( sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) );
	}

	/**
	 * Put this request's client id, and any marketing refusal, on a draft.
	 *
	 * The one call an adapter makes. A request with no readable id leaves the
	 * draft as it was, and the repository keeps whatever an earlier request
	 * stored.
	 *
	 * @param Event_Draft $draft The draft about to be ingested.
	 * @return void
	 */
	public static function attach( Event_Draft $draft ): void {
		$id = self::from_request();

		if ( '' === $id ) {
			return;
		}

		$draft->ga_client_id  = $id;
		$draft->ga_ads_denied = self::marketing_denied();
	}

	/**
	 * Whether this request was made by a visitor's browser, as far as can be told.
	 *
	 * Front-end pages, the Store API and admin-ajax all qualify -- some themes
	 * add to cart over admin-ajax. Cron, WP-CLI and wp-admin screens do not.
	 *
	 * @return bool
	 */
	private static function is_visitor_request(): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		return ! is_admin() || wp_doing_ajax();
	}

	/**
	 * A cookie value if it is shaped like a GA client id, otherwise ''.
	 *
	 * @param string $value Raw cookie value.
	 * @return string
	 */
	public static function normalize( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || strlen( $value ) > self::MAX_LENGTH ) {
			return '';
		}

		return 1 === preg_match( self::PATTERN, $value ) ? $value : '';
	}

	/**
	 * Whether the site's consent tool, if it has one, allows statistics cookies.
	 *
	 * @return bool
	 */
	public static function statistics_allowed(): bool {
		if ( ! function_exists( 'wp_has_consent' ) ) {
			return true;
		}

		return (bool) wp_has_consent( 'statistics' );
	}

	/**
	 * Whether the site's consent tool has refused marketing use for this visitor.
	 *
	 * Only a refusal counts. A site without the WP Consent API has said
	 * nothing, and a report that says nothing lets Google apply what the site's
	 * own tag recorded for the same browser.
	 *
	 * @return bool
	 */
	public static function marketing_denied(): bool {
		if ( ! function_exists( 'wp_has_consent' ) ) {
			return false;
		}

		return ! wp_has_consent( 'marketing' );
	}
}
