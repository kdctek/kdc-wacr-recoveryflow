<?php
/**
 * Turning a workflow step into the recovery email a person reads.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Recovery\Email_Compliance;
use WAcr\RecoveryFlow\Recovery\Email_Message;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The email half of Message_Composer, and deliberately not the same class.
 *
 * A WhatsApp message is an approved template: Meta owns the wording, the
 * merchant picks which of their approved templates to send and maps values into
 * its numbered blanks, and the plugin's job is to fill those blanks without
 * tripping the platform's validation. An email is the opposite in every one of
 * those respects. Nobody approves it, the merchant writes the words themselves,
 * there are no numbered slots, and the message carries obligations of its own
 * that a WhatsApp message does not -- a postal address and a working
 * unsubscribe, in the body, every time.
 *
 * So the two share the variable context and nothing else. Folding email into
 * Message_Composer would have produced one class whose parameters, validation
 * and failure modes all forked on a channel flag, which is two implementations
 * wearing one name.
 *
 * **The body is plain text.** Not a stopgap: a recovery email is a short
 * personal-sounding note with one link in it, HTML buys nothing a customer can
 * see, and it costs an escaping surface, a layout to maintain in every client,
 * and remote images that Gmail fetches through its own proxy the moment the
 * message is opened. Plain text also means the footer this class is required to
 * append cannot be hidden by a stylesheet, which is the point of it.
 *
 * **The footer is not optional and not the merchant's to remove.** It is
 * appended here rather than left to the wording, because a template a merchant
 * edits is a template from which the unsubscribe can be deleted -- and the
 * first thing anybody does with a message template is shorten it.
 */
final class Email_Composer {

	/**
	 * The longest subject that will be sent.
	 *
	 * Not a protocol limit -- RFC 5322 has none worth quoting -- but every
	 * client truncates somewhere around here, and a subject that runs past it
	 * is one the recipient reads the first half of.
	 */
	public const MAX_SUBJECT_LENGTH = 200;

	/**
	 * The longest body that will be sent.
	 */
	public const MAX_BODY_LENGTH = 20000;

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
	 * Build the message, or refuse to.
	 *
	 * Every refusal here is permanent for this step as written: a missing
	 * subject, an empty body and an unlawful footer are all things only the
	 * merchant can fix, so the caller fails the step rather than retrying it.
	 *
	 * @param Recovery_Journey    $journey    The journey being recovered.
	 * @param Customer            $customer   Who is being written to.
	 * @param array<string,mixed> $parameters The step's "with" block.
	 * @param Variable_Context    $context    The allow-listed values.
	 * @param array<string,mixed> $settings   The settings snapshot in force.
	 * @return Email_Message|\WP_Error
	 */
	public function compose( Recovery_Journey $journey, Customer $customer, array $parameters, Variable_Context $context, array $settings ) {
		unset( $journey );

		$to = trim( $customer->email );

		if ( '' === $to || ! is_email( $to ) ) {
			// Eligibility should have caught this long before here. It is
			// re-asked because this is the last place that can, and sending a
			// message to nobody is worse than failing the step.
			return new \WP_Error( 'no_email', __( 'This customer has no email address to write to.', 'kdc-wacr-recoveryflow' ) );
		}

		$subject = Template_Renderer::render(
			trim( (string) ( $parameters['subject'] ?? '' ) ),
			$context,
			Template_Renderer::MODE_TEXT
		);

		// Flattened rather than merely trimmed: a newline in a subject is a
		// header injection, and the value came from an administrator-authored
		// document that a compromised account could have written.
		$subject = Template_Renderer::sanitize_param( $subject, self::MAX_SUBJECT_LENGTH );

		if ( '' === $subject ) {
			return new \WP_Error( 'no_subject', __( 'This step has no subject line, so there is nothing to send.', 'kdc-wacr-recoveryflow' ) );
		}

		$body = Template_Renderer::render(
			trim( (string) ( $parameters['body'] ?? '' ) ),
			$context,
			Template_Renderer::MODE_TEXT
		);

		$body = self::normalise_body( $body );

		if ( '' === $body ) {
			return new \WP_Error( 'no_body', __( 'This step has no message body, so there is nothing to send.', 'kdc-wacr-recoveryflow' ) );
		}

		$footer = Email_Compliance::footer( $context->get( 'recovery.opt_out_url' ), $settings );

		if ( '' === $footer ) {
			/*
			 * An empty footer means the postal address or the unsubscribe link
			 * is missing, and either one makes the message unlawful to send.
			 * Rule_Set::channel_enabled() already refuses the channel for the
			 * same reasons, so reaching this is a fault rather than a
			 * configuration state -- but it is checked here too, because this
			 * is the last point at which the message can be stopped and the
			 * cost of the two mistakes is not remotely equal.
			 */
			if ( null !== $this->logger ) {
				$this->logger->error(
					'workflow',
					'A recovery email was composed with no lawful footer and was not sent',
					array( 'blockers' => implode( ',', Email_Compliance::blockers( $settings ) ) )
				);
			}

			return new \WP_Error( 'no_footer', __( 'A recovery email must carry a postal address and an unsubscribe link, and one of them is missing.', 'kdc-wacr-recoveryflow' ) );
		}

		return new Email_Message( $to, $subject, $body . "\n\n-- \n" . $footer );
	}

	/**
	 * Tidy a body without changing what it says.
	 *
	 * Line breaks are the author's and are kept; runs of blank lines are not,
	 * because a template with a placeholder alone on a line leaves one behind
	 * whenever that value is empty -- a customer with no first name should not
	 * receive a message with a hole in it.
	 *
	 * @param string $body The rendered body.
	 * @return string
	 */
	private static function normalise_body( string $body ): string {
		$body = str_replace( array( "\r\n", "\r" ), "\n", $body );
		$body = (string) preg_replace( '/[ \t]+$/m', '', $body );
		$body = (string) preg_replace( '/\n{3,}/', "\n\n", $body );

		return trim( substr( $body, 0, self::MAX_BODY_LENGTH ) );
	}
}
