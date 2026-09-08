<?php
/**
 * What every recovery source must provide.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration;

use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * The contract between RecoveryFlow and anything that can be abandoned.
 *
 * A source translates one system's vocabulary into the core's. WooCommerce is
 * the first implementation, not the model: nothing behind this interface knows
 * what a cart is, which is what allows a booking plugin, a form plugin or a
 * ticketing system to be recovered by exactly the same engine.
 *
 * Three of these methods carry most of the weight.
 *
 * is_conversion_complete() is asked again immediately before every send, not
 * just when the event was detected. Between scheduling a reminder and sending
 * it, the customer may have bought the thing in another tab, and the source is
 * the only authority on that. Answering it from stale state is how a shop ends
 * up asking somebody to finish an order they have already paid for.
 *
 * restore() must merge rather than replace. The person following a recovery
 * link may already have a basket, possibly a better one; throwing it away to
 * reinstate an older snapshot destroys the very conversion being recovered.
 *
 * build_recovery_url() exists so a source can point the link somewhere other
 * than its own checkout, but the default is almost always right.
 */
interface Recovery_Source_Interface {

	/**
	 * Stable machine identifier, e.g. 'woocommerce'. Stored on every row.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human name for the admin screens.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * One sentence on what this source recovers.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Whether the system this source integrates with is present and usable.
	 *
	 * Must be cheap and must not throw: it is called on every page load to
	 * decide whether to attach hooks.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Attach the hooks this source needs.
	 *
	 * Called only when the source is available and enabled.
	 *
	 * @return void
	 */
	public function register(): void;

	/**
	 * The kinds of event this source produces, e.g. array( 'cart', 'checkout' ).
	 *
	 * @return string[]
	 */
	public function get_event_types(): array;

	/**
	 * Rule overrides that suit this source, merged under the site's settings.
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_rules(): array;

	/**
	 * Whether the thing this event describes has since been completed.
	 *
	 * Asked again immediately before every send. Answer from live state.
	 *
	 * @param Recovery_Event $event The event.
	 * @return bool
	 */
	public function is_conversion_complete( Recovery_Event $event ): bool;

	/**
	 * The URL a recovery message should link to.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param string           $token   The one-time token for this attempt.
	 * @return string
	 */
	public function build_recovery_url( Recovery_Journey $journey, string $token ): string;

	/**
	 * Put the customer back where they left off, and say where to send them.
	 *
	 * Runs in the clicker's own request, and may only ever touch the clicker's
	 * own session.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param Recovery_Event   $event   The event being recovered.
	 * @return string|\WP_Error Redirect URL, or an error to show the generic page for.
	 */
	public function restore( Recovery_Journey $journey, Recovery_Event $event );

	/**
	 * Settings to render on this source's card, in Settings API field shape.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_settings_fields(): array;
}
