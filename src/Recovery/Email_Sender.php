<?php
/**
 * Handing a composed recovery email to WordPress.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this plugin sends mail, and the one place that learns why it did
 * not.
 *
 * `wp_mail()` answers false and says nothing about the reason, which on a shop
 * whose SMTP credentials expired is the difference between a merchant fixing it
 * this morning and noticing in a fortnight. The reason arrives separately, on
 * the `wp_mail_failed` action, as a WP_Error raised during the call -- so it is
 * captured by listening for exactly the duration of the send and letting go
 * afterwards. A listener left attached would collect the failures of every
 * other email the site sends, which is both wrong and a way to end up logging
 * somebody else's recipient addresses.
 *
 * What comes back is deliberately coarse: sent, or not sent with a reason code.
 * There is no "probably sent" here, unlike the WhatsApp path where an HTTP
 * request can time out after the body is written and genuinely leave the
 * outcome unknown. wp_mail() either handed the message to a transport or it did
 * not, and treating a definite no as a maybe would leave recoveries parked
 * waiting for a resolution that can never arrive.
 */
final class Email_Sender {

	/**
	 * Log sink.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Log sink.
	 */
	public function __construct( ?Logger $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Send one message.
	 *
	 * @param Email_Message $message  The composed message.
	 * @param int           $journey  The journey this belongs to, for the log.
	 * @return true|\WP_Error True when WordPress accepted it.
	 */
	public function send( Email_Message $message, int $journey = 0 ) {
		/*
		 * Captured into a local held by reference rather than onto the object.
		 * The failure belongs to the send in progress, not to the sender, so a
		 * property would have to be cleared at the start of every call -- and
		 * that clearing is exactly what hid a leaked listener from the tests
		 * until the assertion was changed to count them instead.
		 */
		$failure = null;

		$listener = static function ( $error ) use ( &$failure ): void {
			if ( $error instanceof \WP_Error ) {
				$failure = $error;
			}
		};

		add_action( 'wp_mail_failed', $listener );

		try {
			$accepted = wp_mail( $message->to, $message->subject, $message->body, $message->headers() );
		} catch ( \Throwable $error ) {
			// A transport that throws rather than returning false: some SMTP
			// plugins do. Only the class is recorded -- a PHPMailer exception
			// message quotes the recipient address.
			remove_action( 'wp_mail_failed', $listener );

			$this->log( 'Sending a recovery email stopped on ' . get_class( $error ), $journey );

			return new \WP_Error( 'mail_exception', __( 'The site could not send the email.', 'kdc-wacr-recoveryflow' ) );
		}

		remove_action( 'wp_mail_failed', $listener );

		if ( true === $accepted ) {
			return true;
		}

		$code = 'mail_refused';

		if ( $failure instanceof \WP_Error && '' !== (string) $failure->get_error_code() ) {
			$code = substr( (string) $failure->get_error_code(), 0, 32 );
		}

		// The reason code is kept; the message is not. wp_mail_failed carries
		// the recipient addresses in its error data, and this log is readable
		// by anybody who can reach the admin and is exportable besides.
		$this->log( sprintf( 'A recovery email was not accepted for sending (%s)', $code ), $journey );

		return new \WP_Error( $code, __( 'The site could not send the email.', 'kdc-wacr-recoveryflow' ) );
	}

	/**
	 * Record why, without recording who.
	 *
	 * @param string $message What happened.
	 * @param int    $journey The journey id.
	 * @return void
	 */
	private function log( string $message, int $journey ): void {
		if ( null === $this->logger ) {
			return;
		}

		$this->logger->error( 'workflow', $message, array(), $journey > 0 ? $journey : null );
	}
}
