<?php
/**
 * The one script the plugin puts on a shop's checkout.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

use WAcr\RecoveryFlow\Recovery\Rule_Set;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the classic checkout's capture script, and only where it does something.
 *
 * The script asks WooCommerce to re-read the checkout when the shopper leaves
 * the phone or email field, because WooCommerce watches address fields and
 * nothing else. See assets/js/checkout-capture.js for why that is necessary.
 *
 * The conditions below are the whole point of this class. A shop that has not
 * switched recovery on, a shop on the block checkout, and every page that is
 * not the checkout all get nothing -- a plugin that is doing no work should not
 * be adding a request to somebody's shop, and the checkout is the last page on
 * a store where it is acceptable to be careless about that.
 */
final class Checkout_Script {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'kdc-wacr-recoveryflow-checkout';

	/**
	 * Path to the script, relative to the plugin root.
	 */
	public const PATH = 'assets/js/checkout-capture.js';

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Put the script on the page, if this page is one that needs it.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_wanted() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			KDC_WACR_RECOVERYFLOW_URL . self::PATH,
			array( 'jquery' ),
			KDC_WACR_RECOVERYFLOW_VERSION,
			true
		);
	}

	/**
	 * Whether this request is a classic checkout on a shop that is recovering carts.
	 *
	 * @return bool
	 */
	public function is_wanted(): bool {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}

		// The thank-you and order-pay screens are checkout pages as far as
		// WooCommerce is concerned, and there is nothing left to recover on
		// either of them.
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return false;
		}

		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return false;
		}

		if ( ! Rule_Set::for_source()->is_enabled() ) {
			return false;
		}

		return ! self::is_block_checkout();
	}

	/**
	 * Whether the shop's checkout page is the block checkout.
	 *
	 * The script bails on its own when it finds no form.checkout, so this is
	 * belt and braces rather than the safeguard -- but not enqueuing at all is
	 * better than enqueuing something that will do nothing, and a block
	 * checkout is a deliberate choice a shop has made rather than an edge case.
	 *
	 * @return bool
	 */
	public static function is_block_checkout(): bool {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Blocks\\Utils\\BlocksUtils' ) && ! class_exists( '\\WC_Blocks_Utils' ) ) {
			return false;
		}

		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}

		$page_id = wc_get_page_id( 'checkout' );

		if ( ! is_numeric( $page_id ) || (int) $page_id <= 0 ) {
			return false;
		}

		if ( ! class_exists( '\\WC_Blocks_Utils' ) || ! method_exists( '\\WC_Blocks_Utils', 'has_block_in_page' ) ) {
			return false;
		}

		return (bool) \WC_Blocks_Utils::has_block_in_page( (int) $page_id, 'woocommerce/checkout' );
	}
}
