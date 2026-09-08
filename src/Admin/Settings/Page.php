<?php
/**
 * The settings screen.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Settings;

use WAcr\RecoveryFlow\Admin\Connection_Test;
use WAcr\RecoveryFlow\Admin\Hook_Test;
use WAcr\RecoveryFlow\Admin\Webhook_Setup;
use WAcr\RecoveryFlow\Privacy\Erase_By_Phone;
use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Recovery\Channel;
use WAcr\RecoveryFlow\Recovery\Email_Compliance;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\WAcr\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the settings screen and owns its save round trip.
 *
 * Saving goes through core's options.php rather than through a handler of this
 * plugin's own. That is not laziness: it means the nonce, the capability check,
 * the redirect, the "Settings saved" notice and the settings_errors() plumbing
 * are WordPress's, tested by WordPress, and behave the way every other settings
 * screen on the site behaves. What this class adds is the shape core has no
 * opinion about -- tabs, sections, cards and expandable panels -- and the
 * deeplink that can address any one control inside them.
 *
 * One trap in that arrangement is worth spelling out, because it bites quietly.
 * Registering a sanitise callback for an option makes WordPress run it on every
 * update_option() for that option, not only on a settings-form save. This
 * plugin writes its own settings programmatically in several places, and a
 * sanitiser that assumed a posted form would have silently discarded every one
 * of those writes. So the callback establishes first whether it is looking at a
 * form submission at all, and passes anything else through untouched.
 */
final class Page {

	/**
	 * Prefix for the per-tab settings group.
	 */
	private const GROUP_PREFIX = 'recoveryflow_settings_';

	/**
	 * Register the settings with WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( array_keys( Schema::tabs() ) as $tab ) {
			$group = self::group( $tab );

			register_setting(
				$group,
				Options::SETTINGS,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( self::class, 'sanitize_settings' ),
					'default'           => Options::defaults(),
					'show_in_rest'      => false,
				)
			);

			/*
			 * Without this, options.php insists on manage_options and a role
			 * that was deliberately granted recoveryflow_manage_settings and
			 * nothing else is shown the screen, allowed to edit it, and then
			 * refused at the moment it saves.
			 */
			add_filter(
				'option_page_capability_' . $group,
				static fn (): string => Capabilities::MANAGE_SETTINGS
			);
		}//end foreach
	}

	/**
	 * The settings group for one tab.
	 *
	 * @param string $tab Tab id.
	 * @return string
	 */
	public static function group( string $tab ): string {
		return self::GROUP_PREFIX . $tab;
	}

	/**
	 * Clean a settings write.
	 *
	 * @param mixed $value What is being written.
	 * @return mixed
	 */
	public static function sanitize_settings( $value ) {
		// options.php has already checked the group nonce and the capability
		// before any sanitise callback runs; this only reads which tab the form
		// belonged to.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tab = isset( $_POST[ Sanitizer::TAB_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ Sanitizer::TAB_FIELD ] ) ) : '';

		if ( '' === $tab || ! Schema::has_tab( $tab ) ) {
			// Not a settings-form save -- a programmatic update_option(). Let
			// it through as written rather than rewriting it from a form that
			// was never posted.
			return $value;
		}

		if ( 'wacr' === $tab ) {
			self::store_api_key( is_array( $value ) ? $value : array() );
		}

		return Sanitizer::sanitize( $value, $tab );
	}

	/**
	 * Put a newly entered API key into its own encrypted option.
	 *
	 * An empty box means "leave the saved key alone", which is what makes it
	 * safe to re-save the WA.cr tab without re-typing a credential nobody can
	 * read off the screen.
	 *
	 * @param array<string,mixed> $posted Posted settings.
	 * @return void
	 */
	private static function store_api_key( array $posted ): void {
		$key = isset( $posted[ Schema::FIELD_API_KEY ] ) ? trim( (string) $posted[ Schema::FIELD_API_KEY ] ) : '';

		if ( '' === $key ) {
			return;
		}

		( new Credentials() )->set_api_key( $key );
	}

	/**
	 * Which tab the request is asking for.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which tab to display changes nothing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return Schema::has_tab( $tab ) ? $tab : Schema::DEFAULT_TAB;
	}

	/**
	 * Which setting the request wants the cursor in.
	 *
	 * @return string
	 */
	public static function focused_field(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which field to focus changes nothing.
		$field = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : '';

		return null === Schema::field( $field ) ? '' : $field;
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to change RecoveryFlow settings.', 'kdc-wacr-recoveryflow' ), 403 );
		}

		$tabs     = Schema::tabs();
		$current  = self::current_tab();
		$focused  = self::focused_field();
		$settings = Options::all();
		$renderer = new Field_Renderer( $settings, $focused );

		echo '<div class="wrap recoveryflow-settings">';
		printf( '<h1>%s</h1>', esc_html__( 'RecoveryFlow settings', 'kdc-wacr-recoveryflow' ) );

		settings_errors();
		Connection_Test::notice();
		Hook_Test::notice();
		Erase_By_Phone::notice();

		self::tab_bar( $tabs, $current );

		printf(
			'<form method="post" action="%s" class="recoveryflow-settings__form">',
			esc_url( admin_url( 'options.php' ) )
		);

		settings_fields( self::group( $current ) );

		printf(
			'<input type="hidden" name="%1$s" value="%2$s" />',
			esc_attr( Sanitizer::TAB_FIELD ),
			esc_attr( $current )
		);

		// Core reads this to come back to the tab that was saved, so "Settings
		// saved" appears on the screen the person was actually looking at
		// rather than on the first tab.
		printf(
			'<input type="hidden" name="_wp_http_referer" value="%s" />',
			esc_attr( Screen::settings_url( $current ) )
		);

		foreach ( $tabs[ $current ]['sections'] as $section_id => $section ) {
			self::section( $section_id, $section, $renderer, $settings );
		}

		submit_button();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * The tab strip.
	 *
	 * Real links, so every tab has an address that can be bookmarked, quoted in
	 * a support reply and linked to from a notice. aria-current marks the one
	 * being read, which is what tells a screen reader which of six identical
	 * links is the page it is on.
	 *
	 * @param array<string,array<string,mixed>> $tabs    All tabs.
	 * @param string                            $current Current tab id.
	 * @return void
	 */
	private static function tab_bar( array $tabs, string $current ): void {
		printf(
			'<nav class="nav-tab-wrapper wp-clearfix" aria-label="%s">',
			esc_attr__( 'RecoveryFlow settings sections', 'kdc-wacr-recoveryflow' )
		);

		foreach ( $tabs as $tab_id => $tab ) {
			$is_current = $tab_id === $current;

			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( Screen::settings_url( $tab_id ) ),
				$is_current ? ' nav-tab-active' : '',
				$is_current ? ' aria-current="page"' : '',
				esc_html( (string) $tab['label'] )
			);
		}

		echo '</nav>';
	}

	/**
	 * One section, and the cards inside it.
	 *
	 * @param string              $id       Section id.
	 * @param array<string,mixed> $section  Section spec.
	 * @param Field_Renderer      $renderer Field renderer.
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return void
	 */
	private static function section( string $id, array $section, Field_Renderer $renderer, array $settings ): void {
		$anchor = Screen::section_anchor( $id );

		printf( '<section class="recoveryflow-section" aria-labelledby="%s">', esc_attr( $anchor ) );

		printf(
			'<h2 id="%1$s" class="recoveryflow-section__title">%2$s <a class="recoveryflow-section__link" href="%3$s" data-copy-link="1">%4$s<span class="screen-reader-text"> %5$s</span></a></h2>',
			esc_attr( $anchor ),
			esc_html( (string) $section['title'] ),
			esc_url( Screen::settings_url( self::current_tab(), $id ) ),
			esc_html__( 'Link', 'kdc-wacr-recoveryflow' ),
			/* translators: %s: the name of a settings section. */
			esc_html( sprintf( __( 'to the section "%s"', 'kdc-wacr-recoveryflow' ), (string) $section['title'] ) )
		);

		if ( '' !== (string) ( $section['description'] ?? '' ) ) {
			printf( '<p class="recoveryflow-section__description">%s</p>', esc_html( (string) $section['description'] ) );
		}

		foreach ( $section['cards'] as $card_id => $card ) {
			self::card( $card_id, $card, $renderer, $settings );
		}

		echo '</section>';
	}

	/**
	 * One card.
	 *
	 * @param string              $id       Card id.
	 * @param array<string,mixed> $card     Card spec.
	 * @param Field_Renderer      $renderer Field renderer.
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return void
	 */
	private static function card( string $id, array $card, Field_Renderer $renderer, array $settings ): void {
		printf( '<div class="card recoveryflow-card recoveryflow-card--%s">', esc_attr( sanitize_html_class( $id ) ) );
		printf( '<h3 class="recoveryflow-card__title">%s</h3>', esc_html( (string) $card['title'] ) );

		if ( '' !== (string) ( $card['description'] ?? '' ) ) {
			printf( '<p class="recoveryflow-card__description">%s</p>', esc_html( (string) $card['description'] ) );
		}

		// A card may prepend something the schema cannot express -- the list of
		// email compliance blockers, the state of the WA.cr connection. It is
		// drawn before the fields, because in both cases it says why the fields
		// below it matter.
		self::extra( (string) ( $card['renderer'] ?? '' ), $settings );

		$fields   = (array) ( $card['fields'] ?? array() );
		$advanced = (array) ( $card['advanced'] ?? array() );

		if ( array() !== $fields ) {
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $fields as $key => $spec ) {
				$renderer->row( (string) $key, (array) $spec );
			}

			echo '</tbody></table>';
		}

		if ( array() !== $advanced ) {
			printf(
				'<details class="recoveryflow-details" data-remember="%s"><summary>%s</summary>',
				esc_attr( $id ),
				esc_html__( 'Advanced settings', 'kdc-wacr-recoveryflow' )
			);

			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $advanced as $key => $spec ) {
				$renderer->row( (string) $key, (array) $spec );
			}

			echo '</tbody></table></details>';
		}

		echo '</div>';
	}

	/**
	 * The bespoke part of a card, if it has one.
	 *
	 * @param string              $renderer Renderer name from the schema.
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return void
	 */
	private static function extra( string $renderer, array $settings ): void {
		switch ( $renderer ) {
			case 'email_compliance':
				self::email_compliance( $settings );
				break;

			case 'wacr_connection':
				self::wacr_connection();
				break;

			case 'auto_flow':
				self::auto_flow();
				break;

			case 'signposts':
				self::signposts();
				break;

			case 'erase_by_phone':
				Erase_By_Phone::form();
				break;

			case 'webhook_setup':
				Webhook_Setup::render();
				break;
		}//end switch
	}

	/**
	 * What is standing between this site and a lawful recovery email.
	 *
	 * The gate on the email channel has existed since the channel did; until
	 * now nothing drew it, so a merchant could read that email was unavailable
	 * and have no way to find out what to do about it. Each blocker is listed
	 * with the setting that clears it, as a link straight to that control.
	 *
	 * Status is carried by words -- "settled", "still to do" -- and by
	 * position, never by colour alone.
	 *
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return void
	 */
	private static function email_compliance( array $settings ): void {
		$blockers = Email_Compliance::blockers( $settings );
		$fields   = array(
			Email_Compliance::NO_POSTAL_ADDRESS            => Email_Compliance::SETTING_ADDRESS,
			Email_Compliance::NO_POSTAL_COUNTRY            => Email_Compliance::SETTING_COUNTRY,
			Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT => 'recovery_link_ttl_days',
		);

		if ( array() === $blockers ) {
			printf(
				'<p class="recoveryflow-compliance recoveryflow-compliance--met"><strong>%s</strong> %s</p>',
				esc_html__( 'Settled.', 'kdc-wacr-recoveryflow' ),
				esc_html__( 'Email reminders are permissible from this site. Whether they are actually sent is the switch below.', 'kdc-wacr-recoveryflow' )
			);

			return;
		}

		printf(
			'<div class="recoveryflow-compliance recoveryflow-compliance--blocked"><p><strong>%s</strong></p><ol class="recoveryflow-compliance__list">',
			esc_html(
				sprintf(
					/* translators: %d: how many things are still to be settled. */
					_n(
						'Still to do before email reminders can be sent: %d thing.',
						'Still to do before email reminders can be sent: %d things.',
						count( $blockers ),
						'kdc-wacr-recoveryflow'
					),
					count( $blockers )
				)
			)
		);

		foreach ( $blockers as $code ) {
			$key  = $fields[ $code ] ?? '';
			$link = '' === $key ? '' : Schema::deeplink( $key );

			echo '<li>';
			echo esc_html( Email_Compliance::reason_label( $code ) );

			if ( '' !== $link ) {
				printf(
					' <a href="%1$s">%2$s</a>',
					esc_url( $link ),
					esc_html__( 'Go to this setting', 'kdc-wacr-recoveryflow' )
				);
			}

			echo '</li>';
		}

		echo '</ol></div>';
	}

	/**
	 * The state of the WA.cr connection, above the key field.
	 *
	 * @return void
	 */
	private static function wacr_connection(): void {
		$credentials = new Credentials();

		if ( $credentials->is_key_unreadable() ) {
			printf(
				'<p class="recoveryflow-connection recoveryflow-connection--broken"><strong>%s</strong> %s</p>',
				esc_html__( 'The saved key cannot be read.', 'kdc-wacr-recoveryflow' ),
				esc_html__( 'This happens when a site\'s security keys are rotated. Enter the key again to fix it.', 'kdc-wacr-recoveryflow' )
			);

			return;
		}

		if ( ! $credentials->has_api_key() ) {
			printf(
				'<p class="recoveryflow-connection recoveryflow-connection--absent"><strong>%s</strong> %s</p>',
				esc_html__( 'No key saved yet.', 'kdc-wacr-recoveryflow' ),
				esc_html__( 'RecoveryFlow records abandoned baskets without one, but cannot send anything.', 'kdc-wacr-recoveryflow' )
			);

			return;
		}

		$snapshot = Feature_Gate::snapshot();
		$tenant   = isset( $snapshot['tenant_name'] ) ? (string) $snapshot['tenant_name'] : '';

		printf(
			'<p class="recoveryflow-connection recoveryflow-connection--saved"><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'A key is saved.', 'kdc-wacr-recoveryflow' ),
			esc_html(
				'' === $tenant
					? sprintf(
						/* translators: %s: the last few characters of the saved API key. */
						__( 'Ending %s. It has not been checked against WA.cr yet.', 'kdc-wacr-recoveryflow' ),
						$credentials->masked_key()
					)
					: sprintf(
						/* translators: 1: WA.cr workspace name. 2: the last few characters of the saved API key. */
						__( 'Connected to %1$s, ending %2$s.', 'kdc-wacr-recoveryflow' ),
						$tenant,
						$credentials->masked_key()
					)
			)
		);

		if ( ! Feature_Gate::has_developer_api() ) {
			printf( '<p class="description">%s</p>', esc_html( Feature_Gate::unavailable_reason() ) );
		}

		Connection_Test::button();
	}

	/**
	 * How to build the Auto Flow on the other end of the hook.
	 *
	 * The hand-off is the path that works on every WA.cr plan, which makes it
	 * the path most merchants take -- and it is the one where everything that
	 * matters happens somewhere else. A merchant pastes an address here and
	 * then has to go and build the thing that receives it, with no statement
	 * anywhere of what will arrive. Guessing wrong shows up as recovery
	 * quietly not happening.
	 *
	 * So the payload is written down, exactly, next to the address. WA.cr seeds
	 * every top-level scalar of a hook body as a run variable named
	 * `hook_<key>`, so this list IS the list of variables the flow will find
	 * waiting for it -- the names are not an example, they are the names.
	 *
	 * @return void
	 */
	private static function auto_flow(): void {
		Hook_Test::button();

		echo '<details class="recoveryflow-details recoveryflow-recipe">';
		printf( '<summary>%s</summary>', esc_html__( 'What to build in WA.cr, and what this site sends it', 'kdc-wacr-recoveryflow' ) );

		echo '<ol class="recoveryflow-recipe__steps">';

		foreach ( self::recipe_steps() as $step ) {
			printf( '<li>%s</li>', esc_html( $step ) );
		}

		echo '</ol>';

		printf(
			'<p>%s</p>',
			esc_html__( 'Every push carries the same keys, always, even when a value is empty -- a flow that refers to a variable the payload left out prints the placeholder to the customer instead of a name. WA.cr turns each one into a variable called hook_ plus the key, so "first_name" reaches your flow as hook_first_name.', 'kdc-wacr-recoveryflow' )
		);

		echo '<table class="widefat striped recoveryflow-recipe__payload"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Variable in your flow', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'What it holds', 'kdc-wacr-recoveryflow' ) );
		echo '</tr></thead><tbody>';

		foreach ( self::payload_keys() as $key => $meaning ) {
			printf(
				'<tr><th scope="row"><code>hook_%1$s</code></th><td>%2$s</td></tr>',
				esc_html( (string) $key ),
				esc_html( (string) $meaning )
			);
		}

		echo '</tbody></table>';
		echo '</details>';
	}

	/**
	 * The steps for building the receiving flow, in order.
	 *
	 * @return string[]
	 */
	private static function recipe_steps(): array {
		return array(
			__( 'In WA.cr, create an Auto Flow and give it a Webhook trigger.', 'kdc-wacr-recoveryflow' ),
			__( 'Copy the webhook address it shows you and paste it into the box above, then save.', 'kdc-wacr-recoveryflow' ),
			__( 'Turn on signing in WA.cr, copy the secret it generates, and paste that in as well. Without it, the address on its own is enough for anybody who learns it to start your flow.', 'kdc-wacr-recoveryflow' ),
			__( 'Add whatever the flow should do -- usually one Send Template step, then a wait, then another. The timing and the wording live in WA.cr on this path; this site only says that a basket was left behind.', 'kdc-wacr-recoveryflow' ),
			__( 'Use "Send a test push" above to prove the address before a real basket depends on it.', 'kdc-wacr-recoveryflow' ),
		);
	}

	/**
	 * Every key a push carries, and what it means.
	 *
	 * @return array<string,string>
	 */
	public static function payload_keys(): array {
		return array(
			'event'         => __( 'Always recovery.journey_eligible, so one flow can serve more than one sender.', 'kdc-wacr-recoveryflow' ),
			'journey_uid'   => __( 'This recovery\'s own reference. Worth logging: it is what a support question will be about.', 'kdc-wacr-recoveryflow' ),
			'source'        => __( 'Which integration noticed, such as woocommerce.', 'kdc-wacr-recoveryflow' ),
			'source_type'   => __( 'What was left behind, such as cart or checkout.', 'kdc-wacr-recoveryflow' ),
			'phone'         => __( 'The customer\'s number in full international form. Empty on a test push.', 'kdc-wacr-recoveryflow' ),
			'first_name'    => __( 'The customer\'s first name, if the shop has one.', 'kdc-wacr-recoveryflow' ),
			'currency'      => __( 'The three-letter currency code.', 'kdc-wacr-recoveryflow' ),
			'total'         => __( 'What the basket comes to, as a plain number with two decimals and no symbol.', 'kdc-wacr-recoveryflow' ),
			'item_count'    => __( 'How many items are in it.', 'kdc-wacr-recoveryflow' ),
			'items_summary' => __( 'A short readable list of what is in it.', 'kdc-wacr-recoveryflow' ),
			'recovery_url'  => __( 'The link that puts the basket back. This is the one to put in your button.', 'kdc-wacr-recoveryflow' ),
			'opt_out_url'   => __( 'The link that stops the reminders. Include it: a recovery message is marketing.', 'kdc-wacr-recoveryflow' ),
			'site_name'     => __( 'The shop\'s name.', 'kdc-wacr-recoveryflow' ),
			'site_url'      => __( 'The shop\'s address.', 'kdc-wacr-recoveryflow' ),
			'abandoned_at'  => __( 'When the basket was last touched, as an ISO 8601 timestamp in UTC.', 'kdc-wacr-recoveryflow' ),
		);
	}

	/**
	 * Links to the screens the settings cannot answer for.
	 *
	 * @return void
	 */
	private static function signposts(): void {
		$rules = Rule_Set::for_source();
		$links = array(
			array(
				'url'  => Screen::url( Screen::STATUS ),
				'text' => __( 'System status -- whether the background jobs are running and the connection works', 'kdc-wacr-recoveryflow' ),
			),
			array(
				'url'  => Screen::url( Screen::JOURNEYS ),
				'text' => __( 'Journeys -- every basket being chased, and what happened to it', 'kdc-wacr-recoveryflow' ),
			),
			array(
				'url'  => Screen::url( Screen::INTEGRATIONS ),
				'text' => __( 'Integrations -- which parts of this site RecoveryFlow watches', 'kdc-wacr-recoveryflow' ),
			),
		);

		echo '<ul class="recoveryflow-signposts">';

		foreach ( $links as $link ) {
			printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $link['url'] ), esc_html( $link['text'] ) );
		}

		echo '</ul>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				$rules->channel_enabled( Channel::EMAIL )
					? __( 'Email reminders are on.', 'kdc-wacr-recoveryflow' )
					: __( 'Email reminders are off. The Channels tab says what would need settling first.', 'kdc-wacr-recoveryflow' )
			)
		);
	}
}
