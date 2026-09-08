<?php
/**
 * Building an outbound template message from a workflow step.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\WAcr\Send_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Turns "body_1 = {{customer.first_name}}" into what the Cloud API expects.
 *
 * WhatsApp template parameters are positional, not named: the API takes a list
 * per component and fills the template's {{1}}, {{2}} in order. The workflow
 * names them (body_1, button_0_url_1) because a merchant editing JSON needs to
 * see which slot they are filling, so the mapping back to positions happens
 * here, once, with the gaps checked.
 *
 * A gap is refused rather than silently closed up. If a merchant maps body_1
 * and body_3, shifting body_3 into position two would put the order total where
 * the customer's name goes and send that to a real person; refusing the step
 * fails one journey and tells them why.
 *
 * The customer's last name and email are attached only when the merchant has
 * switched those on. They are not needed to send -- WA.cr uses them to name the
 * contact in its own inbox -- so the default is to send neither.
 */
final class Message_Composer {

	/**
	 * The error code every refusal carries.
	 */
	public const ERROR_CODE = 'recoveryflow_message_invalid';

	/**
	 * Meta allows ten buttons on a template, addressed 0 to 9.
	 */
	private const MAX_BUTTON_INDEX = 9;

	/**
	 * Logger.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Logger.
	 */
	public function __construct( ?Logger $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Build the request for one send_template step.
	 *
	 * @param Recovery_Journey    $journey    The journey being messaged.
	 * @param Customer            $customer   Who is being messaged.
	 * @param array<string,mixed> $parameters The step's "with" block.
	 * @param Variable_Context    $context    The allow-listed values.
	 * @return Send_Request|\WP_Error
	 */
	public function compose( Recovery_Journey $journey, Customer $customer, array $parameters, Variable_Context $context ) {
		$template = trim( (string) ( $parameters['template'] ?? '' ) );
		$language = trim( (string) ( $parameters['language'] ?? 'en' ) );

		if ( 1 !== preg_match( '/^[a-z0-9_]{1,512}$/', $template ) ) {
			return $this->refuse(
				__( 'This step has no approved template name, or the name contains characters WhatsApp does not allow. Template names are lowercase letters, digits and underscores.', 'kdc-wacr-recoveryflow' ),
				$journey
			);
		}

		if ( 1 !== preg_match( '/^[a-zA-Z]{2,3}(?:_[a-zA-Z]{2,4})?$/', $language ) ) {
			return $this->refuse(
				__( 'This step has no valid template language. Use a code such as en or en_GB.', 'kdc-wacr-recoveryflow' ),
				$journey
			);
		}

		if ( '' === $customer->phone_e164 ) {
			return $this->refuse(
				__( 'There is no international phone number to send this to.', 'kdc-wacr-recoveryflow' ),
				$journey
			);
		}

		$variables  = isset( $parameters['variables'] ) && is_array( $parameters['variables'] ) ? $parameters['variables'] : array();
		$components = $this->components( $variables, $context );

		if ( is_wp_error( $components ) ) {
			return $components;
		}

		$this->warn_about_unknown_keys( $parameters );

		$request = new Send_Request( $customer->phone_e164, $template, $language, $components );

		return $request->with_contact(
			$customer->first_name,
			Options::get( 'wacr_share_last_name', false ) ? $customer->last_name : '',
			Options::get( 'wacr_share_email', false ) ? $customer->email : ''
		);
	}

	/**
	 * Turn the named variable map into Cloud API components.
	 *
	 * @param array<int|string,mixed> $variables The step's variables block.
	 * @param Variable_Context        $context   The allow-listed values.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function components( array $variables, Variable_Context $context ) {
		$header  = array();
		$body    = array();
		$buttons = array();

		foreach ( $variables as $slot => $template ) {
			$slot    = (string) $slot;
			$matches = array();

			if ( 1 === preg_match( '/^header_([1-9][0-9]?)$/', $slot, $matches ) ) {
				$header[ (int) $matches[1] ] = Template_Renderer::render(
					(string) $template,
					$context,
					Template_Renderer::MODE_PARAM,
					Template_Renderer::MAX_HEADER_LENGTH
				);

				continue;
			}

			if ( 1 === preg_match( '/^body_([1-9][0-9]?)$/', $slot, $matches ) ) {
				$body[ (int) $matches[1] ] = Template_Renderer::render(
					(string) $template,
					$context,
					Template_Renderer::MODE_PARAM,
					Template_Renderer::MAX_PARAM_LENGTH
				);

				continue;
			}

			if ( 1 === preg_match( '/^button_([0-9])_url_([1-9])$/', $slot, $matches ) ) {
				$index = (int) $matches[1];

				if ( $index > self::MAX_BUTTON_INDEX ) {
					return $this->refuse( __( 'A template may have at most ten buttons.', 'kdc-wacr-recoveryflow' ) );
				}

				$buttons[ $index ][ (int) $matches[2] ] = Template_Renderer::render(
					(string) $template,
					$context,
					Template_Renderer::MODE_URL
				);

				continue;
			}

			return $this->refuse(
				sprintf(
					/* translators: %s: the variable name the merchant typed. */
					__( 'RecoveryFlow does not recognise the template variable "%s". Use header_1, body_1 or button_0_url_1.', 'kdc-wacr-recoveryflow' ),
					substr( $slot, 0, 64 )
				)
			);
		}//end foreach

		$components = array();

		$ordered = $this->in_order( $header, 'header' );

		if ( is_wp_error( $ordered ) ) {
			return $ordered;
		}

		if ( array() !== $ordered ) {
			$components[] = array(
				'type'       => 'header',
				'parameters' => $ordered,
			);
		}

		$ordered = $this->in_order( $body, 'body' );

		if ( is_wp_error( $ordered ) ) {
			return $ordered;
		}

		if ( array() !== $ordered ) {
			$components[] = array(
				'type'       => 'body',
				'parameters' => $ordered,
			);
		}

		ksort( $buttons );

		foreach ( $buttons as $index => $values ) {
			$ordered = $this->in_order( $values, 'button_' . $index . '_url' );

			if ( is_wp_error( $ordered ) ) {
				return $ordered;
			}

			$components[] = array(
				'type'       => 'button',
				'sub_type'   => 'url',
				'index'      => (string) $index,
				'parameters' => $ordered,
			);
		}

		return $components;
	}

	/**
	 * Put one component's parameters in positional order, refusing gaps.
	 *
	 * @param array<int,string> $values Position to rendered value.
	 * @param string            $label  What to name in the error.
	 * @return array<int,array<string,string>>|\WP_Error
	 */
	private function in_order( array $values, string $label ) {
		ksort( $values );

		$expected   = 1;
		$parameters = array();

		foreach ( $values as $position => $value ) {
			if ( $position !== $expected ) {
				return $this->refuse(
					sprintf(
						/* translators: 1: component name such as body, 2: the missing position number. */
						__( 'The template variables for %1$s skip a position: %2$d is missing. WhatsApp fills these in order, so a gap would send the wrong value to the customer.', 'kdc-wacr-recoveryflow' ),
						$label,
						$expected
					)
				);
			}

			$parameters[] = array(
				'type' => 'text',
				'text' => $value,
			);

			++$expected;
		}

		return $parameters;
	}

	/**
	 * Note settings in "with" that this action does not read.
	 *
	 * A typo such as "variabels" would otherwise send a template with no
	 * parameters at all, which WhatsApp refuses with a message that names
	 * nothing the merchant typed.
	 *
	 * @param array<string,mixed> $parameters The step's "with" block.
	 * @return void
	 */
	private function warn_about_unknown_keys( array $parameters ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}

		$unknown = array_diff( array_keys( $parameters ), array( 'template', 'language', 'variables' ) );

		if ( array() === $unknown ) {
			return;
		}

		$this->logger->warning(
			'workflow',
			'Send step has settings this action does not read',
			array( 'keys' => implode( ',', array_map( 'strval', array_slice( $unknown, 0, 5 ) ) ) )
		);
	}

	/**
	 * Build the refusal.
	 *
	 * @param string                $message What to tell the administrator.
	 * @param Recovery_Journey|null $journey The journey, when there is one to attribute it to.
	 * @return \WP_Error
	 */
	private function refuse( string $message, ?Recovery_Journey $journey = null ): \WP_Error {
		if ( $this->logger instanceof Logger ) {
			$this->logger->warning(
				'workflow',
				'Recovery message could not be composed',
				array(),
				null === $journey ? null : $journey->id
			);
		}

		return new \WP_Error( self::ERROR_CODE, $message );
	}
}
