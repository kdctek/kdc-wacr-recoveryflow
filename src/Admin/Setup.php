<?php
/**
 * The screen a merchant lands on when RecoveryFlow is switched on.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Admin\Settings\Schema;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\WAcr\Client;
use WAcr\RecoveryFlow\WAcr\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Connecting a WA.cr workspace, as the first thing that happens.
 *
 * Everything else RecoveryFlow does is already working when the plugin is
 * activated: baskets are recorded, identities resolved, consent stored. The one
 * thing it cannot do unaided is send, and that needs a WA.cr workspace. So the
 * setup screen has exactly one step on it rather than four -- a wizard whose
 * later steps only restate settings that already have a screen is a wizard
 * people learn to click through.
 *
 * Two decisions in here are worth keeping.
 *
 * SAVING AND CHECKING ARE ONE ACTION. On the settings screen they are two, and
 * that is the right shape there: a merchant editing settings may not want to
 * spend a call on somebody else's service. On first run it is the wrong shape,
 * because a key that has been saved and not checked is indistinguishable, to
 * the person who just typed it, from one that does not work. This form stores
 * the key and asks WA.cr about it in the same submit, and reports what came
 * back.
 *
 * A PLAN THAT IS TOO SMALL IS NOT A FAILED CONNECTION. `GET /v1/me` answers
 * `403 plan_upgrade_required` for a workspace below Scale, and it does so
 * AFTER authenticating the credential -- the API resolves the principal and
 * then consults the gate, so that refusal is proof the key is real. Reporting
 * it as "connection test failed" would tell a merchant whose key is perfectly
 * good to go and find a better one. It is reported here as a working key on a
 * plan that does not include sending from WordPress, alongside the thing that
 * does work without the developer API: handing each journey to a WA.cr Auto
 * Flow, which needs no API key at all -- on a plan that can run one, which is
 * Growth and above. That path is the whole reason the free tier is a
 * product rather than a trial, and this is the screen where a merchant either
 * finds it or concludes the plugin is not for them.
 */
final class Setup {

	/**
	 * The screen slug.
	 */
	public const PAGE = 'recoveryflow-setup';

	/**
	 * The option that says a fresh activation has not been greeted yet.
	 */
	public const PENDING_OPTION = 'recoveryflow_setup_pending';

	/**
	 * The admin-post action behind the connect form.
	 */
	public const ACTION = 'recoveryflow_setup_connect';

	/**
	 * Where the result of a connect attempt waits, and for how long.
	 */
	private const RESULT_TTL = 60;

	/**
	 * The WA.cr client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * The stored credential.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructor.
	 *
	 * @param Client      $client      The WA.cr client.
	 * @param Credentials $credentials The stored credential.
	 */
	public function __construct( Client $client, Credentials $credentials ) {
		$this->client      = $client;
		$this->credentials = $credentials;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_init', array( $this, 'maybe_greet' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Mark a fresh activation as needing the setup screen.
	 *
	 * Called from the activator. It is an option rather than a transient
	 * because a transient may be evicted by an object cache under memory
	 * pressure, and the one-and-only chance to greet somebody is a poor thing
	 * to leave to a cache eviction policy.
	 *
	 * @return void
	 */
	public static function mark_pending(): void {
		update_option( self::PENDING_OPTION, 1, false );
	}

	/**
	 * Send somebody to the setup screen, once, after they switch the plugin on.
	 *
	 * @return void
	 */
	public function maybe_greet(): void {
		if ( ! get_option( self::PENDING_OPTION ) ) {
			return;
		}

		/*
		 * The flag is cleared whether or not the greeting actually happens: a
		 * greeting that keeps trying on every admin request until it finds a
		 * moment it likes is worse than one that is missed once.
		 */
		delete_option( self::PENDING_OPTION );

		if ( ! self::may_greet() ) {
			return;
		}

		wp_safe_redirect( Screen::url( self::PAGE ) );

		exit;
	}

	/**
	 * Whether this request is one it is reasonable to redirect out of.
	 *
	 * Split from maybe_greet() because that one ends in exit(), and a decision
	 * worth getting right is a decision worth being able to test without
	 * ending the process that is testing it.
	 *
	 * Activating several plugins at once passes activate-multi: redirecting out
	 * of that loses every other plugin's activation notice and strands somebody
	 * halfway through a bulk action. A background request has nobody to greet,
	 * and a network admin activating for a whole network is not the person who
	 * will type a key.
	 *
	 * @return bool
	 */
	public static function may_greet(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading WordPress's own activation redirect argument.
		if ( isset( $_GET['activate-multi'] ) ) {
			return false;
		}

		if ( wp_doing_ajax() || is_network_admin() ) {
			return false;
		}

		return current_user_can( Capabilities::MANAGE_SETTINGS );
	}

	/**
	 * Store the key and ask WA.cr about it, in one submit.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to connect a WA.cr workspace on this site.', 'kdc-wacr-recoveryflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an API key is an opaque credential; sanitising it would silently corrupt it. Trimmed, length-checked and never echoed.
		$key = isset( $_POST['recoveryflow_api_key'] ) ? trim( (string) wp_unslash( $_POST['recoveryflow_api_key'] ) ) : '';

		set_transient( self::result_key(), $this->connect( $key ), self::RESULT_TTL );

		wp_safe_redirect( Screen::url( self::PAGE ) );

		exit;
	}

	/**
	 * Store a key, check it, and describe what came back.
	 *
	 * @param string $key The key as typed.
	 * @return array{state:string,message:string}
	 */
	private function connect( string $key ): array {
		if ( '' === $key ) {
			return array(
				'state'   => 'empty',
				'message' => __( 'Enter the API key from your WA.cr workspace, then choose Connect.', 'kdc-wacr-recoveryflow' ),
			);
		}

		if ( strlen( $key ) > 255 ) {
			return array(
				'state'   => 'empty',
				'message' => __( 'That does not look like a WA.cr API key. Copy it again from WA.cr, under Developers.', 'kdc-wacr-recoveryflow' ),
			);
		}

		$this->credentials->set_api_key( $key );

		return self::describe( $this->client->verify() );
	}

	/**
	 * Read what WA.cr said about a key as one of three outcomes.
	 *
	 * Separated from the call that produces it so the decision can be tested
	 * against each answer WA.cr actually gives, rather than only through the
	 * screen that renders it. The distinction between the second and third
	 * outcomes is the whole product decision on this screen, and a test that
	 * cannot tell them apart is not testing it: an earlier version of this file
	 * asserted only on the rendered notice, and reversing the branch entirely
	 * changed nothing any assertion could see.
	 *
	 * @param \WAcr\RecoveryFlow\WAcr\Result $result What verify() returned.
	 * @return array{state:string,message:string}
	 */
	public static function describe( $result ): array {
		if ( $result->ok ) {
			$tenant = (string) $result->get( 'tenant_name', '' );

			return array(
				'state'   => 'connected',
				'message' => '' === $tenant
					? __( 'Connected. RecoveryFlow can send reminders from this site.', 'kdc-wacr-recoveryflow' )
					: sprintf(
						/* translators: %s: the name of the connected WA.cr workspace. */
						__( 'Connected to %s. RecoveryFlow can send reminders from this site.', 'kdc-wacr-recoveryflow' ),
						$tenant
					),
			);
		}

		/*
		 * The key authenticated and the plan is short. Not a failure: /v1/me
		 * resolves the credential BEFORE consulting the plan gate, so this
		 * refusal is positive proof the key is real. The hand-off path is open,
		 * and it is open on every plan.
		 */
		if ( null !== $result->error && 'plan_upgrade_required' === $result->error->code ) {
			return array(
				'state'   => 'handoff',
				'message' => __( 'Your key works. This workspace\'s plan does not include sending from WordPress, so RecoveryFlow will hand each abandoned basket to a WA.cr Auto Flow instead, which needs no API key -- on a plan that can run one, which is Growth and above.', 'kdc-wacr-recoveryflow' ),
			);
		}

		return array(
			'state'   => 'refused',
			'message' => null === $result->error
				? __( 'WA.cr could not be reached, so the key could not be checked. It has been saved; check the site can make outbound requests and try again.', 'kdc-wacr-recoveryflow' )
				: $result->error->message,
		);
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to set RecoveryFlow up on this site.', 'kdc-wacr-recoveryflow' ) );
		}

		$result = get_transient( self::result_key() );
		$result = is_array( $result ) ? $result : array();

		if ( array() !== $result ) {
			delete_transient( self::result_key() );
		}

		echo '<div class="wrap recoveryflow-setup">';

		printf( '<h1>%s</h1>', esc_html__( 'Set RecoveryFlow up', 'kdc-wacr-recoveryflow' ) );

		printf(
			'<p class="recoveryflow-setup__intro">%s</p>',
			esc_html__( 'RecoveryFlow is already watching for abandoned baskets. Connecting a WA.cr workspace is what lets it send the reminders.', 'kdc-wacr-recoveryflow' )
		);

		self::outcome( $result );

		$this->connect_card( $result );

		echo '</div>';
	}

	/**
	 * What the last attempt said, if anything.
	 *
	 * @param array<string,mixed> $result The stored outcome.
	 * @return void
	 */
	private static function outcome( array $result ): void {
		$state = isset( $result['state'] ) ? (string) $result['state'] : '';

		if ( '' === $state ) {
			return;
		}

		$classes = array(
			'connected' => 'notice-success',
			'handoff'   => 'notice-warning',
			'refused'   => 'notice-error',
			'empty'     => 'notice-error',
		);

		$titles = array(
			'connected' => __( 'Connected.', 'kdc-wacr-recoveryflow' ),
			'handoff'   => __( 'Key accepted.', 'kdc-wacr-recoveryflow' ),
			'refused'   => __( 'Not connected.', 'kdc-wacr-recoveryflow' ),
			'empty'     => __( 'Nothing to connect.', 'kdc-wacr-recoveryflow' ),
		);

		printf(
			'<div class="notice %1$s" role="status"><p><strong>%2$s</strong> %3$s</p>',
			esc_attr( $classes[ $state ] ?? 'notice-info' ),
			esc_html( $titles[ $state ] ?? '' ),
			esc_html( isset( $result['message'] ) ? (string) $result['message'] : '' )
		);

		if ( 'handoff' === $state ) {
			self::handoff_next_step();
		} elseif ( 'connected' === $state ) {
			self::next_step();
		}

		echo '</div>';
	}

	/**
	 * Where somebody goes after a successful connection.
	 *
	 * @return void
	 */
	private static function next_step(): void {
		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( Screen::settings_url( 'wacr', 'sending' ) ),
			esc_html__( 'Choose how reminders are sent', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * The hand-off route, for a workspace whose plan stops at it.
	 *
	 * Deliberately concrete. "Upgrade your plan" is not an instruction, it is a
	 * shrug; the merchant needs the two things that actually make the free path
	 * work, and a link to the box each one goes in.
	 *
	 * @return void
	 */
	private static function handoff_next_step(): void {
		printf(
			'<ol class="recoveryflow-setup__steps"><li>%1$s</li><li>%2$s</li></ol><p><a href="%3$s" class="button">%4$s</a></p>',
			esc_html__( 'In WA.cr, create an Auto Flow that starts from a webhook and sends your reminder.', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'Copy its hook address into RecoveryFlow, and set reminders to be sent by handing the journey over.', 'kdc-wacr-recoveryflow' ),
			esc_url( Screen::settings_url( 'wacr', 'sending', 'wacr_hook_url' ) ),
			esc_html__( 'Set up the hand-off', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * The one step on the screen.
	 *
	 * @param array<string,mixed> $result The stored outcome.
	 * @return void
	 */
	private function connect_card( array $result ): void {
		$state     = isset( $result['state'] ) ? (string) $result['state'] : '';
		$connected = Feature_Gate::has_developer_api() || 'handoff' === $state;

		echo '<div class="card recoveryflow-card">';

		printf( '<h2 class="recoveryflow-card__title">%s</h2>', esc_html__( 'Connect WA.cr', 'kdc-wacr-recoveryflow' ) );

		if ( $connected ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'This site is connected to WA.cr. You can change the key at any time on the settings screen.', 'kdc-wacr-recoveryflow' )
			);
		}

		printf(
			'<form method="post" action="%s" class="recoveryflow-setup__form">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		Nonce_Field::render( self::ACTION );

		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION ) );

		printf(
			'<p><label for="recoveryflow-setup-key">%1$s</label><br />
			<input type="password" id="recoveryflow-setup-key" name="recoveryflow_api_key" class="regular-text" autocomplete="off" spellcheck="false" aria-describedby="recoveryflow-setup-key-help" value="" />
			</p>
			<p class="description" id="recoveryflow-setup-key-help">%2$s</p>
			<p><button type="submit" class="button button-primary">%3$s</button>
			<a class="button-link" href="%4$s">%5$s</a></p>',
			esc_html__( 'WA.cr API key', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'In WA.cr, open Developers and copy an API key. RecoveryFlow stores it encrypted and never shows it again.', 'kdc-wacr-recoveryflow' ),
			esc_html__( 'Connect', 'kdc-wacr-recoveryflow' ),
			esc_url( Screen::url() ),
			esc_html__( 'Skip for now', 'kdc-wacr-recoveryflow' )
		);

		echo '</form>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Skipping changes nothing that is already running: abandoned baskets go on being recorded, and nothing is sent until a workspace is connected.', 'kdc-wacr-recoveryflow' )
		);

		echo '</div>';
	}

	/**
	 * Where one user's pending outcome lives.
	 *
	 * @return string
	 */
	private static function result_key(): string {
		return 'recoveryflow_setup_result_' . get_current_user_id();
	}
}
