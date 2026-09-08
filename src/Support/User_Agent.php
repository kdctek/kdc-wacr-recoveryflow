<?php
/**
 * Recognising link-preview fetchers.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tells a robot fetching a link preview apart from a person tapping a link.
 *
 * This class exists because of one specific, expensive mistake. WhatsApp fetches
 * every URL in a message to build the little preview card, and it does that once
 * per delivered message. A recovery link that counted a click on a bare GET
 * would therefore record a click for every single recipient the instant the
 * messages were delivered -- every journey would look engaged, the click-through
 * figure on the Overview screen would be exactly the send count, and nobody
 * would ever be able to tell a real tap from a preview again.
 *
 * The same fetch is why the opt-out link cannot act on a GET. If it did, the
 * preview fetcher would opt every recipient out of messaging before a single
 * person had read a word.
 *
 * The match is deliberately loose -- a substring, case-insensitively -- because
 * the two failures are not equal. Missing a bot invents a click that never
 * happened and corrupts the merchant's reporting permanently; misreading a
 * person as a bot loses one number from a counter. Facebook alone ships at
 * least three casings of its crawler token.
 *
 * **Email is a weaker case than WhatsApp, and the list cannot fix that.** The
 * mail proxies below identify themselves and are caught. Corporate link
 * scanners -- Microsoft's Safe Links, and the equivalents from Proofpoint,
 * Mimecast and Barracuda -- routinely fetch every URL in an incoming message
 * while presenting an ordinary browser's user agent, and no substring can tell
 * one of those from a person. Guessing at their tokens would be worse than
 * leaving them out: it would look like coverage while catching nothing.
 *
 * So the honest position, and it is written into the docs rather than left for
 * somebody to infer: **a click figure on the email channel is softer evidence
 * than one on WhatsApp.** Two things make that survivable rather than
 * dangerous. A click sets no state -- only reading a WhatsApp conversation back
 * ever marks a journey ENGAGED, so a scanner cannot make a customer look like
 * they replied. And the opt-out refuses a GET outright, so a scanner that
 * follows every link in a message cannot unsubscribe the person it was
 * protecting.
 */
final class User_Agent {

	/**
	 * Substrings that identify a preview fetcher or crawler.
	 *
	 * 'WhatsApp/' keeps its slash on purpose: the preview fetcher always sends
	 * a version after it, so the needle cannot match an in-app browser that
	 * merely mentions the app.
	 *
	 * @var string[]
	 */
	public const PREVIEW_AGENTS = array(
		'WhatsApp/',
		'facebookexternalhit',
		'Twitterbot',
		'TelegramBot',
		'Slackbot',
		'LinkedInBot',
		'Discordbot',
		'SkypeUriPreview',
		'Googlebot',
		// Mail proxies, which arrived with the email channel. Gmail fetches
		// every remote image through its own proxy the moment a message is
		// opened, which on a plain-text recovery email is nothing -- but the
		// same proxy identifies itself on anything else it pulls.
		'GoogleImageProxy',
		'YahooMailProxy',
		'BingPreview',
	);

	/**
	 * Whether this user agent belongs to a link-preview fetcher or a crawler.
	 *
	 * @param string $ua The User-Agent header, or '' when there was none.
	 * @return bool
	 */
	public static function is_link_preview( string $ua ): bool {
		if ( '' === $ua ) {
			return false;
		}

		foreach ( self::PREVIEW_AGENTS as $needle ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The user agent on the current request.
	 *
	 * Kept here so the one place that reads the header is the one place that
	 * knows it is attacker-controlled: it is trimmed to a sane length before
	 * anything else sees it, and it is never stored or logged.
	 *
	 * @return string
	 */
	public static function current(): string {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );

		return substr( $ua, 0, 512 );
	}
}
