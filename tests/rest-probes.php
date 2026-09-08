<?php
/**
 * A test double for the REST base class.
 *
 * In its own file because the coding standard allows one class per file, and
 * kept out of the runner so that stays a list of assertions.
 *
 * @package WAcr\RecoveryFlow
 */

/**
 * Exposes the REST base's masking rule for testing.
 *
 * The rule is that revealing a customer's real contact details needs BOTH the
 * capability and an explicit ask, and that asking is recorded. That is three
 * conditions in one small method, and it guards every route that returns a
 * phone number -- so it is asserted directly rather than only through the
 * endpoints that happen to call it.
 */
class Reveal_Probe extends \WAcr\RecoveryFlow\REST\Abstract_Controller {

	/**
	 * Constructor.
	 *
	 * @param \WAcr\RecoveryFlow\Database\Receipt_Repository $receipts Receipt ledger.
	 */
	public function __construct( \WAcr\RecoveryFlow\Database\Receipt_Repository $receipts ) {
		$this->receipts = $receipts;
	}

	/**
	 * Not used: this probe registers nothing.
	 *
	 * @return void
	 */
	public function register_routes(): void {}

	/**
	 * Ask the masking rule directly.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public function probe( \WP_REST_Request $request ): bool {
		return $this->may_reveal( $request, 'probe' );
	}
}
