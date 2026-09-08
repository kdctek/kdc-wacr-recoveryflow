<?php
/**
 * The approved-templates REST collection.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\REST;

use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\WAcr\Template_Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The WhatsApp templates this workspace has had approved.
 *
 * Everything here comes from Template_Catalog, which is what the workflow
 * editor's picker reads. That matters more than it looks: the catalogue is
 * where a template is judged sendable or not, and a template RecoveryFlow
 * cannot fill -- one wanting a header image, a carousel, a button payload -- is
 * listed and marked rather than hidden. An endpoint that filtered those out
 * would disagree with the picker about which templates exist, and a merchant
 * who cannot find the template they approved last week concludes the connection
 * is broken.
 *
 * **A refusal is not an empty list.** No API key, a plan below Scale, a network
 * that is down and a workspace with genuinely no approved templates are four
 * different situations needing four different responses from whoever is
 * reading, and all four look identical as `[]`. So the response always carries
 * `ok` and, when it is false, the reason in WA.cr's own words -- it explains
 * its own refusals better than a sentence of ours would.
 *
 * **Refreshing is opt-in.** The catalogue is cached by the client, and a GET
 * that always went to the network would make a listing screen depend on
 * somebody else's uptime. `?refresh=true` bypasses the cache for the caller who
 * knows they have just approved something.
 *
 * The capability is MANAGE_WORKFLOWS rather than MANAGE_SETTINGS: choosing the
 * message a recovery sends is the workflow author's job, and this is the list
 * they choose from. It carries no customer data and no credential.
 */
final class Templates_Controller extends Abstract_Controller {

	/**
	 * The template catalogue.
	 *
	 * @var Template_Catalog
	 */
	private Template_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Template_Catalog $catalog The template catalogue.
	 */
	public function __construct( Template_Catalog $catalog ) {
		$this->catalog = $catalog;
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Routes::REST_NAMESPACE,
			Routes::path( 'templates' ),
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->require_cap( Capabilities::MANAGE_WORKFLOWS ),
					'args'                => array(
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Every approved template, or the reason there is no list.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$catalogue = $this->catalog->all( (bool) $request->get_param( 'refresh' ) );

		return new \WP_REST_Response(
			array(
				'ok'        => $catalogue['ok'],
				'reason'    => $catalogue['reason'],
				'templates' => $catalogue['templates'],
				// Stated rather than left to be counted, because the number a
				// caller actually wants is "how many can I use", and a list in
				// which some entries are unusable does not answer that by
				// having a length.
				'usable'    => count(
					array_filter(
						$catalogue['templates'],
						static fn ( array $template ): bool => ! empty( $template['usable'] )
					)
				),
			)
		);
	}
}
