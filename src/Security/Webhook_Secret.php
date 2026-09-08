<?php
/**
 * The shared secret an Auto Flow presents when it calls this site.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Security;

use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * What proves a request to the webhook receiver really came from your flow.
 *
 * **It is a shared secret and not an HMAC signature, and that is forced by the
 * platform rather than chosen.** RecoveryFlow signs what it PUSHES to WA.cr:
 * the Auto Flow hook is called with `x-wacr-signature: sha256=<hex>` over the
 * raw body. The reverse direction has no equivalent. WA.cr's outbound webhook
 * node sends `content-type: application/json` and whatever headers the merchant
 * typed into the flow, and computes no signature over the body at all -- so a
 * receiver here that verified a signature would be verifying a header nothing
 * sends, and would reject every real request while looking thorough. Reading
 * the platform source settles this; guessing at it would have produced exactly
 * the kind of dead contract this plugin has already had to close three times.
 *
 * A shared secret is weaker than a signature in one specific way: it is
 * replayable by anyone who has seen it, whereas a signature is bound to the
 * body. Two things narrow that. The receiver requires HTTPS, so the secret is
 * not on the wire in the clear; and every event it accepts is deduplicated
 * through the receipt ledger, so a replayed request is recognised and does
 * nothing the first one did not already do.
 *
 * **Only the hash is stored**, which is why the option has always been named
 * `..._secret_hash`. The plaintext is shown once, at the moment it is
 * generated, and then exists only in the merchant's Auto Flow. A stolen
 * database backup therefore does not yield a working secret. Losing it means
 * generating a new one and pasting that into the flow -- the same bargain
 * WordPress makes with application passwords, and one merchants already
 * understand.
 *
 * The value is compared with hash_equals(), so a caller cannot learn the secret
 * one byte at a time from how long the comparison took.
 */
final class Webhook_Secret {

	/**
	 * The header an Auto Flow puts the secret in.
	 *
	 * Named for this plugin rather than for WA.cr, because WA.cr does not
	 * define it: the merchant types this header into their flow themselves, so
	 * the name has to be one thing that both ends agree on and this is where it
	 * is written down.
	 */
	public const HEADER = 'x-recoveryflow-secret';

	/**
	 * Generate a new secret, store its hash, and hand back the plaintext.
	 *
	 * The return value is the only time the plaintext exists. Nothing logs it
	 * and nothing can recover it afterwards.
	 *
	 * @return string The new secret, to be shown once.
	 */
	public static function generate(): string {
		$secret = Token_Service::mint();

		update_option( Options::WEBHOOK_SECRET, Token_Service::hash( $secret ), false );

		return $secret;
	}

	/**
	 * Whether a secret has been generated at all.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		return '' !== (string) get_option( Options::WEBHOOK_SECRET, '' );
	}

	/**
	 * Stop accepting webhook calls entirely.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_option( Options::WEBHOOK_SECRET );
	}

	/**
	 * Whether a presented secret is the one this site generated.
	 *
	 * Refuses everything while no secret exists, rather than accepting
	 * everything: an endpoint that is open until somebody configures it is open
	 * on every site that never got round to configuring it.
	 *
	 * @param string $presented The secret from the request header.
	 * @return bool
	 */
	public static function matches( string $presented ): bool {
		$stored = (string) get_option( Options::WEBHOOK_SECRET, '' );

		if ( '' === $stored || '' === $presented ) {
			return false;
		}

		return hash_equals( $stored, Token_Service::hash( $presented ) );
	}
}
