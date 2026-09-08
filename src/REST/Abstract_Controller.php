<?php
/**
 * What every RecoveryFlow REST route has in common.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\REST;

use WAcr\RecoveryFlow\Database\Receipt_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the plugin's REST controllers.
 *
 * Three things live here because getting any of them wrong on one route would
 * be worth more than getting them right on all the others.
 *
 * **Permission callbacks are capabilities, never `is_user_logged_in()`.** A
 * subscriber is logged in. The routes below carry customer contact details and
 * the ability to cancel somebody's journey, and the difference between "has an
 * account on this shop" and "works here" is the whole of the access control.
 *
 * **Personal data is masked by default and revealing it is audited.** The
 * journeys screen is one a shop worker leaves open on a counter, so a column of
 * customer phone numbers should not be readable by whoever walks past. The full
 * value is available to somebody holding the reveal capability, and asking for
 * it writes a row saying who asked and when -- which is what makes it a
 * deliberate act rather than a default.
 *
 * **A refusal says which capability was missing.** An operator debugging "why
 * can my shop manager not see this" gets an answer instead of a bare 403.
 */
abstract class Abstract_Controller {

	/**
	 * The query argument that asks for unmasked values.
	 */
	public const REVEAL_ARG = 'reveal';

	/**
	 * Receipt ledger, used to record a reveal.
	 *
	 * @var Receipt_Repository|null
	 */
	protected ?Receipt_Repository $receipts = null;

	/**
	 * Register this controller's routes.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * A permission callback demanding one capability.
	 *
	 * Returned as a closure rather than checked inline so a route declares who
	 * may call it in the same array that declares what it accepts -- the two
	 * facts that must never drift apart.
	 *
	 * @param string $capability Capability name.
	 * @return callable
	 */
	protected function require_cap( string $capability ): callable {
		return static function () use ( $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}

			return new \WP_Error(
				'recoveryflow_forbidden',
				sprintf(
					/* translators: %s: the name of a permission, e.g. recoveryflow_view_journeys. */
					__( 'This needs the "%s" permission.', 'kdc-wacr-recoveryflow' ),
					$capability
				),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		};
	}

	/**
	 * Whether this request may see unmasked contact details, and has asked to.
	 *
	 * Both halves are required. Holding the capability does not unmask a
	 * listing that nobody asked to unmask, which keeps the default safe for the
	 * shop manager who leaves the screen open all day and only occasionally
	 * needs to read a number.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param string           $context What is being revealed, for the audit row.
	 * @return bool
	 */
	protected function may_reveal( \WP_REST_Request $request, string $context = '' ): bool {
		if ( ! $request->get_param( self::REVEAL_ARG ) ) {
			return false;
		}

		if ( ! current_user_can( \WAcr\RecoveryFlow\Security\Capabilities::REVEAL_PII ) ) {
			return false;
		}

		$this->record_reveal( $context );

		return true;
	}

	/**
	 * Note that somebody read a customer's real contact details.
	 *
	 * @param string $context What was revealed.
	 * @return void
	 */
	protected function record_reveal( string $context ): void {
		if ( null === $this->receipts ) {
			return;
		}

		$this->receipts->claim(
			'reveal:' . get_current_user_id() . ':' . $context . ':' . gmdate( 'YmdHi' ),
			Receipt_Repository::KIND_REVEAL
		);
	}

	/**
	 * The arguments every collection route accepts.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function collection_args(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 200,
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * A paged collection response, with the totals in headers.
	 *
	 * Headers rather than the body, because that is where every WordPress REST
	 * client already looks for them.
	 *
	 * @param array<int,mixed> $items    The page.
	 * @param int              $total    Total matching rows.
	 * @param int              $per_page Page size.
	 * @return \WP_REST_Response
	 */
	protected function paged( array $items, int $total, int $per_page ): \WP_REST_Response {
		$response = new \WP_REST_Response( $items );

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 ) );

		return $response;
	}
}
