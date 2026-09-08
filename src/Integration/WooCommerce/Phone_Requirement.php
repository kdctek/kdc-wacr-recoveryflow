<?php
/**
 * Making the checkout's phone number compulsory, without owning the setting.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\WooCommerce;

use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One filter, and the reason it is a filter is the entire point of the class.
 *
 * WooCommerce ships the billing phone as optional, so a shopper can reach the
 * thank-you page without ever leaving a number -- and an abandoned checkout that
 * got as far as payment can still be unreachable. A merchant who would rather
 * require it can, and this makes that one switch rather than a snippet.
 *
 * **`woocommerce_checkout_phone_field` is an OPTION, not a hook**, which is the
 * trap here and the thing to re-check if this ever stops working. It is read by
 * `CartCheckoutUtils::get_phone_field_visibility()` and holds `hidden`,
 * `optional` or `required`; both the classic and the block checkout read that
 * one value, which is why filtering it covers both without a line of
 * block-specific code.
 *
 * **This must never call `update_option()` on it.** Writing WooCommerce's own
 * setting would mean a RecoveryFlow switch silently editing a WooCommerce
 * screen, disagreeing with what that screen displays, and -- worst -- surviving
 * this plugin being deactivated, leaving a shop with a requirement nobody chose
 * and no control that explains it. Filtering the read leaves the merchant's
 * stored value untouched: switch this off and WooCommerce's own answer comes
 * back, whatever it was.
 *
 * Note that `required` also un-hides a phone field a merchant had set to
 * `hidden`. That is what "make it required" has to mean -- there is no way to
 * require something nobody is shown -- and the setting's help text says so.
 */
final class Phone_Requirement {

	/**
	 * The WooCommerce option this filters.
	 */
	public const OPTION = 'woocommerce_checkout_phone_field';

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'option_' . self::OPTION, array( $this, 'require_phone' ) );
		add_filter( 'default_option_' . self::OPTION, array( $this, 'require_phone' ) );
	}

	/**
	 * Answer `required` while the setting is on, and get out of the way when it is not.
	 *
	 * The switch is re-read on every call rather than cached at registration,
	 * because the settings screen saves and then renders inside one request:
	 * a value decided when hooks were attached would show the merchant the
	 * state before their own save.
	 *
	 * @param mixed $value WooCommerce's stored value.
	 * @return mixed
	 */
	public function require_phone( $value ) {
		if ( ! self::wanted() ) {
			return $value;
		}

		return 'required';
	}

	/**
	 * Whether the merchant asked for this, on a shop that is recovering.
	 *
	 * @return bool
	 */
	public static function wanted(): bool {
		return (bool) Options::get( 'checkout_phone_required', false ) && Rule_Set::for_source()->is_enabled();
	}
}
