<?php
/**
 * A complete, working, deliberately unregistered example adapter.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Integration\Custom;

use WAcr\RecoveryFlow\Customer\Identity_Hints;
use WAcr\RecoveryFlow\Integration\Abstract_Source;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a Recovery Source has to do, in one file, for a plugin that does
 * not exist.
 *
 * This class is never registered and never runs. It is here rather than in the
 * documentation because an example in a Markdown file rots: the one that used
 * to be in docs/developer-api.md called a constructor with the wrong signature
 * and two methods that had never existed, and it said so confidently for three
 * slices, because nothing anywhere could tell. This one is linted, type-checked
 * and instantiated by the test suite against the real interfaces, so it cannot
 * describe an API the plugin does not have.
 *
 * It recovers unpaid bookings from a fictional plugin that fires two hooks and
 * has one lookup function. Copy it, rename it, change the four places that say
 * "mybookings", and use your own text domain rather than this plugin's. It
 * keeps ours here so that the i18n audit holds it to the same rule as every
 * other shipped file: an example exempted from the checks is an example free to
 * drift out of date, which is precisely how the one it replaced went wrong.
 *
 * ## What you have to get right
 *
 * **is_available() runs on every page load.** One class_exists or
 * function_exists, and nothing else. A version check, an option read or a
 * database query here is a cost every request on the site pays whether or not
 * anybody is booking anything.
 *
 * **dedupe_key is one key per thing-in-progress, reused as it changes.** It is
 * UNIQUE with your source id, so reporting the same booking twice updates one
 * row rather than making a second. Getting this wrong is how somebody gets
 * three reminders about one booking.
 *
 * **Never put personal data in metadata or items.** Contact details go in
 * identity hints, which is the one field the exporter, the eraser and the
 * redactor know to look at. A phone number tucked into metadata to save a
 * lookup is a phone number that survives an erasure request.
 *
 * **is_conversion_complete() must answer from live state, every time.** It is
 * asked again immediately before every send, not just when the event was
 * detected: between scheduling a reminder and sending it, the person may have
 * paid in another tab. If you cannot tell, return true. The engine fails closed
 * and sends nothing, which is the right way round -- the cost of a missed
 * reminder is a reminder; the cost of a wrong one is asking somebody to pay
 * twice.
 *
 * **restore() runs in the clicker's own request and may only touch their own
 * session.** It must merge rather than replace: the person following a recovery
 * link may already have started again, and throwing that away to reinstate an
 * older snapshot destroys the very conversion being recovered.
 *
 * ## Registering it
 *
 *     add_action( 'recoveryflow_register_sources', function ( $registry ) {
 *         $registry->add( new My_Bookings_Source( $registry->ingest() ) );
 *     } );
 *
 * Sources beyond the ones RecoveryFlow ships with need a WA.cr plan that
 * includes them. An unregistered source is invisible; one registered without
 * the entitlement appears on the Integrations screen saying exactly that,
 * rather than silently doing nothing.
 */
final class Example_Source extends Abstract_Source {

	/**
	 * Stable machine identifier. It is written onto every row this adapter
	 * produces and never changes afterwards, so pick it once.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'mybookings';
	}

	/**
	 * Human name for the admin screens.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'My Bookings', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * One sentence on what this recovers, shown on the Integrations screen.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Recovers bookings that were reserved and never paid for.', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * Whether the system this adapter integrates with is here.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'mybookings_get_booking' );
	}

	/**
	 * Attach your hooks. Called only when available and switched on.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'mybookings_booking_reserved', array( $this, 'on_reserved' ), 10, 1 );
		add_action( 'mybookings_booking_paid', array( $this, 'on_paid' ), 10, 1 );
	}

	/**
	 * The kinds of event this produces. Used for validation and on screens.
	 *
	 * @return string[]
	 */
	public function get_event_types(): array {
		return array( 'booking' );
	}

	/**
	 * Rules that suit this source. The site's own settings still outrank them.
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_rules(): array {
		return array(
			'inactivity_minutes' => 60,
			'max_age_days'       => 3,
		);
	}

	/**
	 * Somebody reserved a slot and has not paid for it.
	 *
	 * @param object $booking The fictional plugin's booking object.
	 * @return void
	 */
	public function on_reserved( $booking ): void {
		if ( ! is_object( $booking ) || ! isset( $booking->id ) ) {
			return;
		}

		$draft = new Event_Draft( $this->get_id(), 'booking', 'booking:' . (int) $booking->id );

		$draft->external_id = (string) $booking->id;
		$draft->session_key = 'booking:' . (int) $booking->id;

		$draft->with_value( (string) ( $booking->total ?? '0' ), (string) ( $booking->currency ?? 'GBP' ) );

		// Names and references only. Never an address, never a note somebody
		// typed, never anything that identifies a person.
		$draft->with_items(
			array(
				array(
					'name' => (string) ( $booking->service_name ?? '' ),
					'ref'  => (string) ( $booking->slot ?? '' ),
					'qty'  => 1,
				),
			)
		);

		$hints = new Identity_Hints();

		$hints->phone_raw  = (string) ( $booking->phone ?? '' );
		$hints->email      = (string) ( $booking->email ?? '' );
		$hints->first_name = (string) ( $booking->first_name ?? '' );
		$hints->country    = (string) ( $booking->country ?? '' );

		// Only set this where the person actually agreed to be messaged, and
		// say where they agreed. Leaving it null means "nothing was asked",
		// which in explicit_consent mode is a refusal rather than a yes.
		if ( ! empty( $booking->marketing_opt_in ) ) {
			$hints->consent              = true;
			$hints->consent_source       = 'mybookings_form';
			$hints->consent_text_version = 'v1';
		}

		$draft->with_identity( $hints );

		$this->report( $draft );
	}

	/**
	 * The money arrived, so stop.
	 *
	 * @param object $booking The booking.
	 * @return void
	 */
	public function on_paid( $booking ): void {
		if ( ! is_object( $booking ) || ! isset( $booking->id ) ) {
			return;
		}

		$this->report_completed( 'booking:' . (int) $booking->id, 'paid' );
	}

	/**
	 * Whether this booking has since been paid for. Asked before every send.
	 *
	 * @param Recovery_Event $event The event.
	 * @return bool
	 */
	public function is_conversion_complete( Recovery_Event $event ): bool {
		if ( ! $event->is_open() ) {
			return true;
		}

		if ( ! function_exists( 'mybookings_get_booking' ) ) {
			// Cannot tell. Fail closed: nothing is sent.
			return true;
		}

		$booking = mybookings_get_booking( (int) $event->external_id );

		// A booking that has been deleted is not a booking worth chasing, and
		// answering false here would retry it for the rest of its life.
		return ! is_object( $booking ) || 'paid' === ( $booking->status ?? '' );
	}

	/**
	 * Where the recovery link should land.
	 *
	 * Returning a WP_Error shows the generic invalid-link page. Do that rather
	 * than sending somebody to a URL you are not sure about: the page they land
	 * on is the last thing that happens in a recovery.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @param Recovery_Event   $event   The event.
	 * @return string|\WP_Error
	 */
	public function restore( Recovery_Journey $journey, Recovery_Event $event ) {
		unset( $journey );

		if ( ! function_exists( 'mybookings_get_payment_url' ) ) {
			return new \WP_Error( 'mybookings_gone', __( 'That booking is no longer available.', 'kdc-wacr-recoveryflow' ) );
		}

		$url = (string) mybookings_get_payment_url( (int) $event->external_id );

		if ( '' === $url ) {
			return new \WP_Error( 'mybookings_gone', __( 'That booking is no longer available.', 'kdc-wacr-recoveryflow' ) );
		}

		return $url;
	}

	/**
	 * Settings to show on this adapter's section of the Integrations tab.
	 *
	 * They are folded into the plugin's own settings tree, so they are
	 * rendered, validated and deeplinked by the same code as everything else,
	 * and stored under source_{your id}_{your key}. Read one back with
	 * Options::get( Source_Registry::setting_key( $this->get_id(), 'key' ) ).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_settings_fields(): array {
		return array(
			'include_free' => array(
				'type'    => 'checkbox',
				'label'   => __( 'Also chase bookings that cost nothing', 'kdc-wacr-recoveryflow' ),
				'help'    => __( 'A free booking cannot be paid for, so there is nothing to recover unless your site treats confirming one as the conversion.', 'kdc-wacr-recoveryflow' ),
				'default' => false,
			),
		);
	}
}
