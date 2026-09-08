<?php
/**
 * Reading settings, and remembering how somebody left a screen.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\REST;

use WAcr\RecoveryFlow\Admin\Settings\Schema as Settings_Schema;
use WAcr\RecoveryFlow\Recovery\Email_Compliance;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The settings, over REST, for reading.
 *
 * Deliberately read-only. The settings screen saves through core's options.php,
 * which already carries the nonce, the capability check, the per-tab merge and
 * the "Settings saved" round trip -- and a second way to write the same option
 * would be a second set of rules about what a valid setting is. The one that
 * disagreed would be the one nobody tested.
 *
 * The credential is never returned in any form. Not masked, not partially, not
 * as a length: an endpoint that reports facts about a secret is an endpoint
 * that helps somebody guess it.
 */
final class Settings_Controller extends Abstract_Controller {

	/**
	 * Where a person's screen preferences are kept.
	 */
	public const UI_META = 'recoveryflow_settings_ui';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Routes::REST_NAMESPACE,
			Routes::path( 'settings' ),
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->require_cap( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);

		register_rest_route(
			Routes::REST_NAMESPACE,
			Routes::path( 'ui-state' ),
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_ui_state' ),
					'permission_callback' => $this->require_cap( Capabilities::VIEW_STATUS ),
					'args'                => array(
						'panel' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'open'  => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * The settings, and what is currently blocking email.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$settings = Options::all();

		// Built from the schema rather than by listing keys to hide: a new
		// setting is exposed only by being given a control, so adding one
		// cannot accidentally publish it here first.
		$public = array();

		foreach ( Settings_Schema::fields() as $key => $spec ) {
			if ( ! empty( $spec['virtual'] ) ) {
				continue;
			}

			$public[ $key ] = $settings[ $key ] ?? null;
		}

		$blockers = array();

		foreach ( Email_Compliance::blockers( $settings ) as $code ) {
			$blockers[] = array(
				'code'    => $code,
				'message' => Email_Compliance::reason_label( $code ),
			);
		}

		return new \WP_REST_Response(
			array(
				'settings'        => $public,
				'email_blockers'  => $blockers,
				'email_permitted' => array() === $blockers,
			)
		);
	}

	/**
	 * Remember whether somebody had an expandable panel open.
	 *
	 * Stored per user rather than per browser, so the shape of the screen
	 * follows them between their laptop and the shop's back-office machine.
	 * It is a preference about a screen and nothing more -- which is why the
	 * capability is only the one needed to look at a RecoveryFlow screen at
	 * all, and why the panel name is reduced to a key before it is stored.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function save_ui_state( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$panel   = (string) $request->get_param( 'panel' );
		$open    = (bool) $request->get_param( 'open' );

		$state = get_user_meta( $user_id, self::UI_META, true );
		$state = is_array( $state ) ? $state : array();

		if ( $open ) {
			$state[ $panel ] = true;
		} else {
			unset( $state[ $panel ] );
		}

		// Bounded, so a caller looping over generated panel names cannot grow
		// a user's meta row without limit.
		if ( count( $state ) > 50 ) {
			$state = array_slice( $state, -50, null, true );
		}

		update_user_meta( $user_id, self::UI_META, $state );

		return new \WP_REST_Response( array( 'panels' => array_keys( $state ) ) );
	}
}
