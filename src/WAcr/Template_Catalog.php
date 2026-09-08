<?php
/**
 * The approved templates a workflow step may choose from.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

use WAcr\RecoveryFlow\Workflow\Message_Composer;

defined( 'ABSPATH' ) || exit;

/**
 * What WA.cr says the workspace has, in the shape a picker needs.
 *
 * `GET /v1/templates` answers `{ ok, templates: [ ... ] }`, and each template
 * carries a `variables` array in which every value a send must supply is
 * already resolved: its `id` (`body_1`, `header_2`, `button_0_url_1`), the
 * template's own wording either side of the placeholder, and Meta's sample.
 * The platform resolves it there deliberately, so that each client stops
 * reimplementing the placeholder scan and drifting from the others. This class
 * reads that and adds nothing to it.
 *
 * What it does add is a verdict. RecoveryFlow can fill a text slot and a URL
 * button; it cannot supply a header image, a button payload, a carousel card
 * or a limited-time-offer expiry. A template needing one of those can never be
 * sent by this plugin, and the moment to say so is when somebody is choosing
 * it -- not hours later, in a dispatch log, against a customer who received
 * nothing. So an unusable template is listed, marked, and given the reason,
 * rather than hidden: a merchant who cannot find the template they approved
 * last week concludes the connection is broken.
 */
final class Template_Catalog {

	/**
	 * The WA.cr client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client The WA.cr client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Every approved template, or a reason there is no list.
	 *
	 * @param bool $force Bypass the client's cache.
	 * @return array{ok:bool,reason:string,templates:array<int,array<string,mixed>>}
	 */
	public function all( bool $force = false ): array {
		$result = $this->client->templates( '', $force );

		if ( ! $result->ok ) {
			return array(
				'ok'        => false,
				'reason'    => null === $result->error
					? __( 'WA.cr could not be reached, so the list of approved templates is not available right now.', 'kdc-wacr-recoveryflow' )
					: $result->error->message,
				'templates' => array(),
			);
		}

		$raw = isset( $result->value['templates'] ) && is_array( $result->value['templates'] )
			? $result->value['templates']
			: array();

		$templates = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$template = self::describe( $row );

			if ( '' !== $template['name'] ) {
				$templates[] = $template;
			}
		}

		usort(
			$templates,
			static fn ( array $a, array $b ): int => strcmp( (string) $a['name'], (string) $b['name'] )
		);

		return array(
			'ok'        => true,
			'reason'    => '',
			'templates' => $templates,
		);
	}

	/**
	 * One template, by name.
	 *
	 * @param string $name  Template name.
	 * @param bool   $force Bypass the client's cache.
	 * @return array<string,mixed>|null
	 */
	public function find( string $name, bool $force = false ): ?array {
		if ( '' === $name ) {
			return null;
		}

		foreach ( $this->all( $force )['templates'] as $template ) {
			if ( (string) $template['name'] === $name ) {
				return $template;
			}
		}

		return null;
	}

	/**
	 * Read one row of the API's answer.
	 *
	 * @param array<string,mixed> $row One template as WA.cr returned it.
	 * @return array<string,mixed>
	 */
	private static function describe( array $row ): array {
		$slots       = array();
		$unsupported = array();
		$variables   = isset( $row['variables'] ) && is_array( $row['variables'] ) ? $row['variables'] : array();

		foreach ( $variables as $variable ) {
			if ( ! is_array( $variable ) ) {
				continue;
			}

			$id = isset( $variable['id'] ) ? (string) $variable['id'] : '';

			if ( '' === $id ) {
				continue;
			}

			if ( ! Message_Composer::supports_slot( $id ) ) {
				// Only a slot that MUST be supplied makes a template unusable.
				// Meta marks the two decorative location-header slots optional,
				// and refusing a template over a value nobody has to send would
				// hide a template that works perfectly well.
				if ( ! isset( $variable['required'] ) || false !== $variable['required'] ) {
					$unsupported[] = $id;
				}

				continue;
			}

			$slots[] = array(
				'id'     => $id,
				'before' => isset( $variable['before'] ) ? (string) $variable['before'] : '',
				'after'  => isset( $variable['after'] ) ? (string) $variable['after'] : '',
				'sample' => isset( $variable['sample'] ) ? (string) $variable['sample'] : '',
			);
		}//end foreach

		return array(
			'name'        => isset( $row['name'] ) ? (string) $row['name'] : '',
			'language'    => isset( $row['language'] ) ? (string) $row['language'] : '',
			'category'    => isset( $row['category'] ) ? (string) $row['category'] : '',
			'slots'       => $slots,
			'unsupported' => $unsupported,
			'usable'      => array() === $unsupported,
		);
	}

	/**
	 * Why a template cannot be used, in words a merchant can act on.
	 *
	 * @param array<string,mixed> $template A described template.
	 * @return string Empty when it is usable.
	 */
	public static function refusal( array $template ): string {
		if ( ! empty( $template['usable'] ) ) {
			return '';
		}

		$unsupported = isset( $template['unsupported'] ) && is_array( $template['unsupported'] )
			? $template['unsupported']
			: array();

		return sprintf(
			/* translators: %s: comma-separated list of template slot names, e.g. header_media_image. */
			__( 'RecoveryFlow cannot send this template, because it needs values it has no way to supply: %s. A recovery message is text and a link back to the basket, so a template with an image header, a button payload or a carousel cannot be used. Choose another, or approve one with text and a URL button.', 'kdc-wacr-recoveryflow' ),
			implode( ', ', array_map( 'strval', $unsupported ) )
		);
	}
}
