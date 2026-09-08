<?php
/**
 * An outbound template message.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that goes to WA.cr when a recovery message is sent.
 *
 * Built as an object rather than an array so that the exact set of fields
 * leaving the site is declared in one readable place. The plugin's external
 * service disclosure is written from this class, and if a field is added here
 * it has to be added there.
 *
 * A recovery message is always an approved template. WhatsApp only allows a
 * business to open a conversation with one, and a customer who abandoned a cart
 * has not written to the business, so there is no open window to reply into.
 * Free text is deliberately not supported here.
 */
final class Send_Request {

	/**
	 * Recipient, in E.164.
	 *
	 * @var string
	 */
	public string $to;

	/**
	 * Approved template name.
	 *
	 * @var string
	 */
	public string $template;

	/**
	 * Template language code, e.g. en or en_GB.
	 *
	 * @var string
	 */
	public string $language;

	/**
	 * Cloud API components, already built from the variable mapping.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $components;

	/**
	 * Customer's first name, when the merchant chose to share it.
	 *
	 * @var string
	 */
	public string $first_name = '';

	/**
	 * Customer's last name, when the merchant chose to share it.
	 *
	 * @var string
	 */
	public string $last_name = '';

	/**
	 * Customer's email, when the merchant chose to share it.
	 *
	 * @var string
	 */
	public string $email = '';

	/**
	 * Constructor.
	 *
	 * @param string $to         Recipient in E.164.
	 * @param string $template   Approved template name.
	 * @param string $language   Language code.
	 * @param array  $components Cloud API components.
	 */
	public function __construct( string $to, string $template, string $language, array $components = array() ) {
		$this->to         = $to;
		$this->template   = $template;
		$this->language   = '' !== trim( $language ) ? $language : 'en';
		$this->components = $components;
	}

	/**
	 * Attach the customer's name and email, where sharing them is switched on.
	 *
	 * WA.cr names the contact in its own inbox from these, so a reply arrives
	 * against a person rather than a bare number. Nothing is required, and the
	 * defaults share nothing but the first name.
	 *
	 * @param string $first_name First name, or ''.
	 * @param string $last_name  Last name, or ''.
	 * @param string $email      Email, or ''.
	 * @return Send_Request
	 */
	public function with_contact( string $first_name = '', string $last_name = '', string $email = '' ): self {
		$this->first_name = trim( $first_name );
		$this->last_name  = trim( $last_name );
		$this->email      = trim( $email );

		return $this;
	}

	/**
	 * The request body, exactly as it goes over the wire.
	 *
	 * The workspace is not in it: WA.cr resolves that from the credential.
	 *
	 * @param string $sender Channel or business-account id to send from, or ''.
	 * @return array<string,mixed>
	 */
	public function to_payload( string $sender = '' ): array {
		$payload = array(
			'channel'      => 'whatsapp',
			'to'           => $this->to,
			'templateName' => $this->template,
			'languageCode' => $this->language,
		);

		if ( '' !== trim( $sender ) ) {
			$payload['from'] = trim( $sender );
		}

		// An empty components array is omitted rather than sent: a template
		// with no variables is refused if it is handed an empty list.
		if ( array() !== $this->components ) {
			$payload['components'] = array_values( $this->components );
		}

		if ( '' !== $this->first_name ) {
			$payload['firstName'] = $this->first_name;
		}

		if ( '' !== $this->last_name ) {
			$payload['lastName'] = $this->last_name;
		}

		if ( '' !== $this->email ) {
			$payload['email'] = $this->email;
		}

		return $payload;
	}

	/**
	 * The field names this request can send, for the disclosure screen.
	 *
	 * @return string[]
	 */
	public static function disclosed_fields(): array {
		return array(
			'channel'      => __( 'That the message is for WhatsApp', 'kdc-wacr-recoveryflow' ),
			'to'           => __( 'The customer\'s WhatsApp number', 'kdc-wacr-recoveryflow' ),
			'templateName' => __( 'Which approved template to send', 'kdc-wacr-recoveryflow' ),
			'languageCode' => __( 'The template\'s language', 'kdc-wacr-recoveryflow' ),
			'components'   => __( 'The values filled into the template: first name, order value, currency, item count and the recovery link', 'kdc-wacr-recoveryflow' ),
			'from'         => __( 'Which of your WhatsApp numbers to send from', 'kdc-wacr-recoveryflow' ),
			'firstName'    => __( 'The customer\'s first name, if sharing names is switched on', 'kdc-wacr-recoveryflow' ),
			'lastName'     => __( 'The customer\'s last name, if sharing names is switched on', 'kdc-wacr-recoveryflow' ),
			'email'        => __( 'The customer\'s email address, if sharing it is switched on', 'kdc-wacr-recoveryflow' ),
		);
	}
}
