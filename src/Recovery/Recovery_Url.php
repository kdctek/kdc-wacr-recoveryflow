<?php
/**
 * Building the links that go into a recovery message.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Core\Rewrites;

defined( 'ABSPATH' ) || exit;

/**
 * The two public links a recovery message can carry.
 *
 * Both are built from the token and nothing else. That is the whole point of
 * the class, and it is worth being blunt about why: these URLs travel through
 * WhatsApp, sit in a message history on a phone that may be shared or lost,
 * are read in full by every link-preview fetcher that touches the message, and
 * are pasted into support tickets. Anything identifying in the query string --
 * an email address, an order number, a customer id, even a cart total -- would
 * be handed to all of that for free.
 *
 * So the token carries no meaning. It identifies the send attempt, the attempt
 * knows the journey, and the journey knows the person. Nothing about the person
 * is recoverable from the URL by anyone who cannot already read the database.
 */
final class Recovery_Url {

	/**
	 * The link that restores what the customer left behind.
	 *
	 * The journey is a parameter for one reason only: it is the argument a
	 * source's build_recovery_url() is handed, and a source that wants the
	 * default behaviour should be able to pass its arguments straight through.
	 * Nothing from the journey is allowed into the URL.
	 *
	 * @param Recovery_Journey $journey The journey this link belongs to.
	 * @param string           $token   The plaintext token minted for this attempt.
	 * @return string
	 */
	public static function build( Recovery_Journey $journey, string $token ): string {
		return Rewrites::url( $token, 'restore' );
	}

	/**
	 * The link that offers to stop the messages.
	 *
	 * A GET on it only ever shows a confirmation page; the opt-out itself needs
	 * a POST. See Recovery_Controller for why that is not optional.
	 *
	 * @param string $token The plaintext token minted for this attempt.
	 * @return string
	 */
	public static function opt_out( string $token ): string {
		return Rewrites::url( $token, 'opt-out' );
	}
}
