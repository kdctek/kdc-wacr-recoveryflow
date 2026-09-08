<?php
/**
 * A nonce field that does not collide with the next one.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this plugin writes a nonce field.
 *
 * `wp_nonce_field()` gives the hidden input an `id` equal to its `name`, and
 * the name is what `check_admin_referer()` reads -- so every form using the
 * default `_wpnonce` puts `id="_wpnonce"` on the page. That is fine on a core
 * screen with one form. It is not fine here:
 *
 * - one recovery's screen renders a separate form per action, so a recovery
 *   offering four of them carried four elements with the same id;
 * - the WA.cr settings tab renders the settings form, the connection test and
 *   the hook test, and the privacy tab renders the settings form and the
 *   erase-by-phone form.
 *
 * Duplicate ids are a genuine defect and not only a validator's complaint: an
 * `aria-describedby`, a `<label for>` or a fragment link resolves to the FIRST
 * match, so a duplicate id is a live hazard for every future author on the
 * screen even when nothing points at it today.
 *
 * The fix is to drop the attribute rather than to rename the field. The `id` on
 * a hidden input does nothing -- it is not focusable, nothing labels it and no
 * fragment addresses it -- while the `name` is what the verification reads.
 * Renaming would mean carrying a matching name into every
 * `check_admin_referer()`, including one inside a loop where each form would
 * need a different one, and getting that wrong fails closed and silently: the
 * form simply stops saving.
 *
 * Core's own `settings_fields()` still emits its `_wpnonce` with an id, and
 * that is deliberate. Ours no longer collides with it, so on a settings tab
 * there is now exactly one `#_wpnonce` -- core's.
 */
final class Nonce_Field {

	/**
	 * Print a nonce field with no id attribute.
	 *
	 * @param string $action The nonce action, as passed to check_admin_referer().
	 * @param string $name   The field name, if it is not the default.
	 * @return void
	 */
	public static function render( string $action, string $name = '_wpnonce' ): void {
		echo self::markup( $action, $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by core and stripped of one attribute below.
	}

	/**
	 * The markup, so it can be asserted without capturing output.
	 *
	 * The id is removed from what core produced rather than the input being
	 * rebuilt here. Core decides how a nonce field is spelled -- the referer
	 * field, the escaping, the value -- and a hand-written copy of it here
	 * would be a second description of core's format, quietly going stale.
	 *
	 * @param string $action The nonce action.
	 * @param string $name   The field name.
	 * @return string
	 */
	public static function markup( string $action, string $name = '_wpnonce' ): string {
		$field = (string) wp_nonce_field( $action, $name, true, false );

		return (string) preg_replace( '/\sid="[^"]*"/', '', $field, 1 );
	}
}
