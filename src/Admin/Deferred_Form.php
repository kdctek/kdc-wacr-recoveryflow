<?php
/**
 * Forms that belong to a settings card but cannot be written inside one.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A form declared after the settings form, addressed from inside it.
 *
 * The settings screen is one `<form action="options.php">` wrapping every card
 * on the tab. Four cards carry an action of their own that must post somewhere
 * else -- test the connection, send a test push, generate a webhook secret,
 * erase a customer by phone number -- and each of them used to write its own
 * `<form>` where it stood, which put a form inside a form.
 *
 * HTML has no such thing. The parser drops the inner `<form>` start tag
 * outright and lets the inner `</form>` close the OUTER one, which broke the
 * page in two ways at once and neither of them looked like a markup problem:
 *
 * - the button became part of the settings form, so pressing "Test connection"
 *   posted the whole settings form to `options.php` and landed the user on a
 *   blank options screen;
 * - everything rendered after that point -- the remaining fields, every later
 *   card, and the Save Changes button -- was left outside any form at all, so
 *   the tab could not be saved and three of its settings could never be
 *   changed.
 *
 * The fix is the `form` attribute, which exists for exactly this. A control may
 * sit anywhere in the document and name the form it submits with, so the button
 * stays in the card where it belongs while the form itself is declared after
 * the settings form has closed. No JavaScript, nothing moved at load time, and
 * the control keeps its place in the tab order.
 *
 * A card asks for its form while it renders; the screen prints the ones that
 * were asked for once the settings form is closed. Only what a tab actually
 * used is printed, so a tab does not carry a stray form and a spare nonce for a
 * button that is not on it.
 */
final class Deferred_Form {

	/**
	 * Forms asked for on this request, as id => nonce action.
	 *
	 * @var array<string,string>
	 */
	private static array $pending = array();

	/**
	 * Ask for a form, and get back the id to point a control at.
	 *
	 * Returning the id rather than having each caller repeat it means the
	 * attribute and the form cannot come to disagree: a control pointing at a
	 * form that was never declared submits nothing, silently.
	 *
	 * @param string $id     Element id for the form.
	 * @param string $action Nonce action, as passed to check_admin_referer().
	 * @return string The id, for the caller's `form` attribute.
	 */
	public static function need( string $id, string $action ): string {
		self::$pending[ $id ] = $action;

		return $id;
	}

	/**
	 * Print every form asked for, and forget them.
	 *
	 * Called once, immediately after the settings form is closed.
	 *
	 * @return void
	 */
	public static function flush(): void {
		foreach ( self::$pending as $id => $action ) {
			printf(
				'<form method="post" action="%1$s" id="%2$s" class="recoveryflow-deferred-form">',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $id )
			);

			Nonce_Field::render( $action );

			printf(
				'<input type="hidden" name="action" value="%s" /></form>',
				esc_attr( $action )
			);
		}

		self::$pending = array();
	}

	/**
	 * What has been asked for and not yet printed.
	 *
	 * For the tests: a card that asks for a form on a screen that never flushes
	 * would leave its button pointing at nothing.
	 *
	 * @return array<string,string>
	 */
	public static function pending(): array {
		return self::$pending;
	}
}
