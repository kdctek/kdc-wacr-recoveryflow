<?php
/**
 * The integrations REST collection.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\REST;

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Integration\Recovery_Source_Interface;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * What RecoveryFlow is watching, and what it is not.
 *
 * The question this answers is the one a fleet operator asks about a hundred
 * sites at once: which of them are actually recording anything? That cannot be
 * read off "is WooCommerce active" -- an integration can be installed, switched
 * on and still recording nothing because the workspace's plan does not include
 * it -- so every row carries the verdict itself rather than the facts somebody
 * would have to combine to reach it.
 *
 * **The verdict comes from Source_Registry::status(), and the sentence from
 * Source_Registry::status_message().** Both are the same ones the Integrations
 * screen prints. This plugin has four times shipped a screen that worked its
 * answer out from neighbouring facts and stated it confidently while being
 * wrong -- most memorably telling a merchant an integration was "Active.
 * Abandoned baskets from here are being recorded" when its hooks had never been
 * attached. An endpoint deriving the verdict a second time would be the fifth.
 *
 * **It is read-only, for the same reason the settings endpoint is.** A switch
 * written here would be a second way to write a setting, and so a second set of
 * rules about what a valid setting is -- and the one nobody exercised would be
 * the one that disagreed. Switching an integration off goes through the
 * settings tree, and each row carries the deeplink to its own control.
 */
final class Integrations_Controller extends Abstract_Controller {

	/**
	 * The registry of sources.
	 *
	 * @var Source_Registry
	 */
	private Source_Registry $sources;

	/**
	 * Constructor.
	 *
	 * @param Source_Registry $sources The registry of sources.
	 */
	public function __construct( Source_Registry $sources ) {
		$this->sources = $sources;
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Routes::REST_NAMESPACE,
			Routes::path( 'integrations' ),
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->require_cap( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);
	}

	/**
	 * Every registered integration.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$rows = array();

		foreach ( $this->sources->all() as $source ) {
			$rows[] = $this->describe( $source );
		}

		return new \WP_REST_Response(
			array(
				'integrations' => $rows,
				// Counted here rather than left to the caller: "how many of my
				// sites are recording nothing" is the question, and a caller
				// filtering on a status string would have to know which of the
				// four counts as working.
				'active'       => count(
					array_filter( $rows, static fn ( array $row ): bool => Source_Registry::ACTIVE === $row['status'] )
				),
			)
		);
	}

	/**
	 * One integration, in the shape that goes out.
	 *
	 * @param Recovery_Source_Interface $source The source.
	 * @return array<string,mixed>
	 */
	private function describe( Recovery_Source_Interface $source ): array {
		$id     = $source->get_id();
		$status = $this->sources->status( $id );

		return array(
			'id'          => $id,
			'name'        => $source->get_name(),
			'description' => $source->get_description(),
			'status'      => $status,
			// The machine code above is what a script should branch on; this is
			// what a human reading the response is owed. Both, always, because
			// a status code with no sentence is how a support thread turns into
			// a guessing game about what "switched_off" meant.
			'message'     => Source_Registry::status_message( $status ),
			'available'   => $source->is_available(),
			'enabled'     => $this->sources->is_enabled( $id ),
			'built_in'    => $this->sources->is_built_in( $id ),
			'watches'     => $source->get_event_types(),
			'settings'    => Screen::settings_url( 'sources', $id, Source_Registry::enabled_key( $id ) ),
		);
	}
}
