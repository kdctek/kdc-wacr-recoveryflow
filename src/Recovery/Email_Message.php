<?php
/**
 * One recovery email, ready to hand to WordPress.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * What is about to be sent, separated from the deciding and the sending.
 *
 * The composer decides and returns one of these; the sender takes one and posts
 * it. Keeping the finished message as a value is what lets a test assert on the
 * exact words a customer would have received without a mail server anywhere
 * near it -- and this plugin has already been bitten twice by decisions that
 * could only be observed through their side effects.
 */
final class Email_Message {

	/**
	 * The recipient address.
	 *
	 * @var string
	 */
	public string $to;

	/**
	 * The subject line.
	 *
	 * @var string
	 */
	public string $subject;

	/**
	 * The plain-text body, footer included.
	 *
	 * @var string
	 */
	public string $body;

	/**
	 * Constructor.
	 *
	 * @param string $to      Recipient address.
	 * @param string $subject Subject line.
	 * @param string $body    Plain-text body.
	 */
	public function __construct( string $to, string $subject, string $body ) {
		$this->to      = $to;
		$this->subject = $subject;
		$this->body    = $body;
	}

	/**
	 * The headers this message goes out with.
	 *
	 * Deliberately short. There is no From here: the site's own mail
	 * configuration decides that, so a recovery email leaves by the same route
	 * and with the same identity as the shop's order emails. A site that has
	 * set up SMTP, or a sending domain with SPF and DKIM, has already answered
	 * this question, and a From address of the plugin's own choosing would
	 * quietly opt every one of those sites out of their own deliverability
	 * work.
	 *
	 * Content-Type is stated rather than assumed, because WordPress defaults to
	 * text/plain but a filter somewhere on the site may have changed it, and a
	 * plain-text body rendered as HTML loses every line break in the address
	 * the law requires be legible.
	 *
	 * @return string[]
	 */
	public function headers(): array {
		return array( 'Content-Type: text/plain; charset=UTF-8' );
	}
}
