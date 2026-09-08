<?php
/**
 * The public /recovery/{token} endpoint.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Core\Rewrites;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Security\Hash_Key;
use WAcr\RecoveryFlow\Security\Rate_Limiter;
use WAcr\RecoveryFlow\Security\Token_Service;
use WAcr\RecoveryFlow\Support\Logger;
use WAcr\RecoveryFlow\Support\User_Agent;

defined( 'ABSPATH' ) || exit;

/**
 * The only unauthenticated surface this plugin has, and the only one that
 * touches customer data. It is written to give a stranger nothing.
 *
 * Every rule in here answers a specific way of getting something for nothing
 * out of a public URL.
 *
 * **The shape is checked before the database is.** A scanner walking the
 * endpoint costs one regular expression per request, not one indexed query.
 * The rewrite already enforces the alphabet and length; this checks again
 * because the query-string fallback exists and because a second cheap check is
 * cheaper than trusting a rule somebody may later edit.
 *
 * **One page for every failure.** Unknown, expired, revoked and finished all
 * render the same page with the same status. Distinguishing them would answer
 * "is this number enrolled?" and "was this link ever real?" for anybody with a
 * list of guesses -- and the person asking is, by construction, not the person
 * the link was sent to.
 *
 * **But revoked and finished are not failures for the unsubscribe.** They are
 * failures for `restore` only. Tokens are revoked and journeys go terminal when
 * the customer converts, so judging both actions by one rule left the shopper
 * who bought unable to unsubscribe from the mail that brought them back --
 * while `Email_Compliance` refused to send at all without a thirty-day
 * unsubscribe. Expiry still closes both, and that is what keeps the thirty days
 * honest rather than merely asserted. See `Attempt::opt_out_is_usable()`.
 *
 * **The lookup is by hash.** The plaintext token is never written anywhere: not
 * to the database, not to a log, not to an error message.
 *
 * **Headers, every time.** Referrer-Policy: no-referrer stops the token being
 * handed to the shop's own analytics, its ad pixels and every third-party
 * script on the page it redirects to -- the classic way a bearer token in a URL
 * escapes. X-Robots-Tag keeps the link out of search results if it is ever
 * posted publicly, and nocache_headers() keeps it out of a shared proxy.
 *
 * **A GET changes nothing.** WhatsApp fetches every URL in a message to build
 * its preview card, once per delivered message. If a GET on the opt-out link
 * opted somebody out, the delivery of a campaign would opt out every recipient
 * of it before a single person had read a word. The opt-out is therefore a POST
 * carrying a confirmation field, which no preview fetcher will ever send. It is
 * deliberately nonce-free: the recipient has no WordPress session and never
 * will, so the token in the URL is the credential, and it is bound to one
 * person's one message.
 *
 * **Nothing thrown ever reaches the shopper.** A customer who taps a link from
 * a shop they trust gets a page, never a stack trace.
 */
final class Recovery_Controller {

	/**
	 * Requests allowed per window, per IP and again per token.
	 */
	public const RATE_LIMIT = 30;

	/**
	 * Length of that window, in seconds.
	 */
	public const RATE_WINDOW = 600;

	/**
	 * Directory a theme may put overrides in.
	 */
	public const TEMPLATE_DIR = 'kdc-wacr-recoveryflow/';

	/**
	 * Attempt ledger, which owns the token hashes.
	 *
	 * @var Attempt_Repository
	 */
	private Attempt_Repository $attempts;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * Event storage.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * The registered sources.
	 *
	 * @var Source_Registry
	 */
	private Source_Registry $sources;

	/**
	 * Request throttle.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $limiter;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * The one implementation of "stop messaging me".
	 *
	 * @var Suppressor
	 */
	private Suppressor $suppressor;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Attempt_Repository $attempts  Attempt ledger.
	 * @param Journey_Repository $journeys  Journey storage.
	 * @param Event_Repository   $events    Event storage.
	 * @param Source_Registry    $sources   Registered sources.
	 * @param Rate_Limiter       $limiter   Request throttle.
	 * @param Logger             $logger    Logger.
	 * @param Clock              $clock     Clock.
	 * @param Suppressor         $suppressor The one implementation of "stop messaging me".
	 */
	public function __construct(
		Attempt_Repository $attempts,
		Journey_Repository $journeys,
		Event_Repository $events,
		Source_Registry $sources,
		Rate_Limiter $limiter,
		Logger $logger,
		Clock $clock,
		Suppressor $suppressor
	) {
		$this->attempts   = $attempts;
		$this->journeys   = $journeys;
		$this->events     = $events;
		$this->sources    = $sources;
		$this->limiter    = $limiter;
		$this->logger     = $logger;
		$this->clock      = $clock;
		$this->suppressor = $suppressor;
	}

	/**
	 * Attach to WordPress.
	 *
	 * Hooked on template_redirect: the last moment before a theme starts
	 * producing output, and after the query has been parsed, so the query vars
	 * the rewrite set are readable and nothing has been printed yet.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	/**
	 * Serve a recovery link, if this request is one.
	 *
	 * @return void
	 */
	public function handle(): void {
		$request = Rewrites::current_request();

		if ( null === $request ) {
			return;
		}

		try {
			$this->route( (string) $request['token'], (string) $request['action'] );
		} catch ( \Throwable $error ) {
			// The class and code only. An exception message can carry whatever
			// it was constructed from, and this one is one query away from a
			// customer's basket.
			$this->logger->error(
				'recovery',
				'The recovery endpoint failed: ' . get_class( $error ),
				array( 'code' => (string) $error->getCode() )
			);

			if ( headers_sent() ) {
				return;
			}

			$this->render_generic();
		}
	}

	/**
	 * Validate, throttle, resolve, and hand off.
	 *
	 * @param string $token  Candidate token.
	 * @param string $action 'restore' or 'opt-out'.
	 * @return void
	 */
	private function route( string $token, string $action ): void {
		// Every render below ends the request, but each one is still followed
		// by a return: a page that stopped exiting would otherwise turn a
		// refusal into a null dereference on the very next line.
		if ( ! Token_Service::is_well_formed( $token ) ) {
			$this->render_generic();

			return;
		}

		$hash = Token_Service::hash( $token );

		if ( ! $this->within_limits( $hash ) ) {
			$this->render_throttled();

			return;
		}

		$attempt = $this->attempts->find_by_token_hash( $hash );

		// hash_equals on a row we fetched by that same hash proves nothing an
		// attacker could exploit, but it costs nothing and it means a future
		// change to the lookup cannot quietly turn into a prefix match.
		if ( null === $attempt || null === $attempt->token_hash || ! Token_Service::matches( $token, $attempt->token_hash ) ) {
			$this->render_generic();

			return;
		}

		$is_opt_out = 'opt-out' === $action;

		/*
		 * The two actions are judged by different rules from here down, and the
		 * asymmetry is deliberate: a dead recovery link must still open a live
		 * unsubscribe.
		 *
		 * Both of the refusals below are correct for `restore` -- a revoked
		 * token must not rebuild a basket, and a journey that has finished has
		 * nothing to restore. Applied to `opt-out` they broke the one link the
		 * law requires to keep working. A token is revoked when the customer
		 * converts, and the journey is terminal for the same reason, so the
		 * person most likely to want out of the reminders was the only person
		 * who could not get out of them.
		 */
		$usable = $is_opt_out
			? $attempt->opt_out_is_usable( $this->clock->now() )
			: $attempt->link_is_usable( $this->clock->now() );

		if ( ! $usable ) {
			$this->render_generic();

			return;
		}

		$journey = $this->journeys->find( $attempt->journey_id );

		if ( null === $journey ) {
			$this->render_generic();

			return;
		}

		/*
		 * An opt-out on a finished journey is not a no-op. Suppression is
		 * recorded against the CUSTOMER's identities rather than this journey
		 * -- which is what makes it meaningful long after this recovery closed,
		 * and what stops the NEXT one being created.
		 */
		if ( ! $is_opt_out && $journey->is_terminal() ) {
			$this->render_generic();

			return;
		}

		if ( $is_opt_out ) {
			$this->opt_out( $journey, $token );

			return;
		}

		$this->restore( $journey );
	}

	/**
	 * Whether this request is inside both allowances.
	 *
	 * Two independent counters. The per-IP one stops one machine walking the
	 * endpoint; the per-token one stops a single leaked link -- forwarded into
	 * a group chat, say -- being replayed thousands of times from everywhere.
	 *
	 * @param string $token_hash Hash of the presented token.
	 * @return bool
	 */
	private function within_limits( string $token_hash ): bool {
		// Only REMOTE_ADDR. X-Forwarded-For is written by the client, so
		// honouring it would let one machine present a fresh allowance on every
		// request and defeat the limit it is supposed to be subject to.
		$ip = self::client_ip();

		if ( ! $this->limiter->hit( 'ip:' . Hash_Key::hash( $ip ), self::RATE_LIMIT, self::RATE_WINDOW ) ) {
			return false;
		}

		return $this->limiter->hit( 'token:' . $token_hash, self::RATE_LIMIT, self::RATE_WINDOW );
	}

	/**
	 * Put the customer back where they left off and send them there.
	 *
	 * @param Recovery_Journey $journey The journey the token belongs to.
	 * @return void
	 */
	private function restore( Recovery_Journey $journey ): void {
		// Counted only for a real browser. WhatsApp fetches this URL once per
		// delivered message to build its preview card, so counting the fetch
		// would record a click for every recipient at the moment of delivery
		// and every journey would look engaged before anybody had tapped
		// anything.
		if ( ! User_Agent::is_link_preview( User_Agent::current() ) ) {
			$this->journeys->record_click( $journey->id );
		}

		$source = $this->sources->get( $journey->source_id );

		// Availability, not the merchant's on/off switch. Somebody holding a
		// valid link should get their basket back even if new detection has
		// since been turned off; if the shop plugin itself is gone there is
		// nothing to restore into.
		if ( null === $source || ! $source->is_available() ) {
			$this->render_generic();

			return;
		}

		$event = $this->events->find( $journey->event_id );

		if ( null === $event ) {
			$this->render_generic();

			return;
		}

		$target = $source->restore( $journey, $event );

		if ( is_wp_error( $target ) || ! is_string( $target ) || '' === $target ) {
			$this->logger->warning(
				'recovery',
				'A source could not restore a journey from its recovery link.',
				array( 'source' => $journey->source_id ),
				$journey->id
			);

			$this->render_generic();

			return;
		}

		$safe = (string) wp_validate_redirect( $target, '' );

		/*
		 * The target is computed by the adapter and never read from the
		 * request, so this is defence in depth against an adapter that is one
		 * day less careful. It is checked rather than left to wp_safe_redirect
		 * because that function's own fallback is wp-admin, and dropping a
		 * shopper on a login screen is a worse answer than a page that
		 * explains itself. A shop whose checkout genuinely lives on another
		 * domain adds it through core's allowed_redirect_hosts filter.
		 */
		if ( '' === $safe ) {
			$this->logger->warning(
				'recovery',
				'A source returned a redirect outside this site and it was refused.',
				array( 'source' => $journey->source_id ),
				$journey->id
			);

			$this->render_generic();

			return;
		}

		$this->send_headers();

		wp_safe_redirect( $safe, 302 );

		exit;
	}

	/**
	 * Show, or perform, an opt-out.
	 *
	 * @param Recovery_Journey $journey The journey the token belongs to.
	 * @param string           $token   The plaintext token, for the form's action.
	 * @return void
	 */
	private function opt_out( Recovery_Journey $journey, string $token ): void {
		if ( ! self::is_confirmed_post() ) {
			$this->render_confirm( $token );

			return;
		}

		$this->suppress( $journey );
		$this->render_done();
	}

	/**
	 * Stop every recovery for the person who asked.
	 *
	 * The work itself lives in Suppressor, because there are now three ways to
	 * say "stop messaging me" -- this link, a shopkeeper acting on a phone
	 * call, and the same act over REST -- and three implementations of what
	 * happens next is three chances to forget one of the identities. That is
	 * not hypothetical: suppression used to be written against the phone number
	 * alone here, so a shopper who had given an address and no number was told
	 * their reminders had stopped while nothing at all was recorded.
	 *
	 * @param Recovery_Journey $journey The journey whose link was used.
	 * @return void
	 */
	private function suppress( Recovery_Journey $journey ): void {
		$this->suppressor->suppress( $journey->customer_id, Suppressor::SOURCE_LINK );
	}

	/**
	 * Whether this request is the opt-out form being submitted.
	 *
	 * @return bool
	 */
	private static function is_confirmed_post(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		if ( 'POST' !== $method ) {
			return false;
		}

		/*
		 * No nonce, on purpose. The recipient is not logged in and has no
		 * WordPress session to carry one, so a nonce would only ever fail. The
		 * token in the URL is the credential: it is 256 bits of randomness, it
		 * was sent to exactly one person, and no preview fetcher submits forms.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; the request is authenticated by the token in its own URL.
		return isset( $_POST['rf_confirm'] );
	}

	/**
	 * The visitor's address, or '' when there is nothing trustworthy.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;
	}

	/**
	 * The headers every response from this endpoint carries.
	 *
	 * @return void
	 */
	private function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/**
	 * The one page every failure renders. Never says which failure it was.
	 *
	 * @return void
	 */
	private function render_generic(): void {
		$this->render(
			'recovery-invalid.php',
			404,
			__( 'This link is no longer available', 'kdc-wacr-recoveryflow' ),
			__( 'The link you followed has expired or has already been used. Your basket may still be waiting for you on the shop.', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * The page shown when somebody is asking too often.
	 *
	 * @return void
	 */
	private function render_throttled(): void {
		if ( ! headers_sent() ) {
			header( 'Retry-After: ' . self::RATE_WINDOW );
		}

		$this->render(
			'recovery-invalid.php',
			429,
			__( 'Too many requests', 'kdc-wacr-recoveryflow' ),
			__( 'This link has been opened a great many times in a short period. Please wait a few minutes and try again.', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * The page that asks whether they really mean it.
	 *
	 * @param string $token The plaintext token, so the form posts to this same link.
	 * @return void
	 */
	private function render_confirm( string $token ): void {
		$this->send_headers();
		status_header( 200 );

		$recoveryflow_site_name   = self::site_name();
		$recoveryflow_home_url    = home_url( '/' );
		$recoveryflow_form_action = Recovery_Url::opt_out( $token );

		$this->include_template(
			'opt-out-confirm.php',
			array(
				'recoveryflow_site_name'   => $recoveryflow_site_name,
				'recoveryflow_home_url'    => $recoveryflow_home_url,
				'recoveryflow_form_action' => $recoveryflow_form_action,
			)
		);

		exit;
	}

	/**
	 * The page shown once the opt-out has been recorded.
	 *
	 * @return void
	 */
	private function render_done(): void {
		$this->send_headers();
		status_header( 200 );

		$recoveryflow_site_name = self::site_name();
		$recoveryflow_home_url  = home_url( '/' );

		$this->include_template(
			'opt-out-done.php',
			array(
				'recoveryflow_site_name' => $recoveryflow_site_name,
				'recoveryflow_home_url'  => $recoveryflow_home_url,
			)
		);

		exit;
	}

	/**
	 * Render one of the message pages and stop.
	 *
	 * @param string $template File name in the templates directory.
	 * @param int    $status   HTTP status to send.
	 * @param string $heading  Page heading.
	 * @param string $message  Body text.
	 * @return void
	 */
	private function render( string $template, int $status, string $heading, string $message ): void {
		$this->send_headers();
		status_header( $status );

		$recoveryflow_site_name = self::site_name();
		$recoveryflow_home_url  = home_url( '/' );
		$recoveryflow_heading   = $heading;
		$recoveryflow_message   = $message;

		$this->include_template(
			$template,
			array(
				'recoveryflow_site_name' => $recoveryflow_site_name,
				'recoveryflow_home_url'  => $recoveryflow_home_url,
				'recoveryflow_heading'   => $recoveryflow_heading,
				'recoveryflow_message'   => $recoveryflow_message,
			)
		);

		exit;
	}

	/**
	 * Load a template file with its variables in scope.
	 *
	 * @param string               $template File name in the templates directory.
	 * @param array<string,string> $vars     Variables the template reads.
	 * @return void
	 */
	private function include_template( string $template, array $vars ): void {
		$path = self::template_path( $template );

		if ( ! is_readable( $path ) ) {
			$this->logger->error( 'recovery', 'A public template could not be read.', array( 'template' => $template ) );

			return;
		}

		// Named locals rather than extract(), so a template can only ever see
		// the variables listed at the call site.
		$recoveryflow_site_name   = $vars['recoveryflow_site_name'] ?? '';
		$recoveryflow_home_url    = $vars['recoveryflow_home_url'] ?? '';
		$recoveryflow_heading     = $vars['recoveryflow_heading'] ?? '';
		$recoveryflow_message     = $vars['recoveryflow_message'] ?? '';
		$recoveryflow_form_action = $vars['recoveryflow_form_action'] ?? '';

		include $path;
	}

	/**
	 * Where to read a template from: the theme first, then the plugin.
	 *
	 * @param string $template File name.
	 * @return string Absolute path.
	 */
	private static function template_path( string $template ): string {
		$plugin = self::plugin_templates() . $template;
		$theme  = function_exists( 'locate_template' ) ? (string) locate_template( array( self::TEMPLATE_DIR . $template ) ) : '';
		$path   = '' !== $theme ? $theme : $plugin;

		/**
		 * Filters the file a public RecoveryFlow page is rendered from.
		 *
		 * @param string $path     Absolute path resolved so far.
		 * @param string $template File name, e.g. 'opt-out-confirm.php'.
		 */
		$path = (string) apply_filters( Hooks::FILTER_TEMPLATE, $path, $template );

		// A filter pointing at a file that is not there must not blank the
		// page for a customer; the shipped template is always the fallback.
		return is_readable( $path ) ? $path : $plugin;
	}

	/**
	 * The plugin's own templates directory, with a trailing slash.
	 *
	 * @return string
	 */
	private static function plugin_templates(): string {
		if ( defined( 'KDC_WACR_RECOVERYFLOW_DIR' ) ) {
			return (string) constant( 'KDC_WACR_RECOVERYFLOW_DIR' ) . 'templates/';
		}

		return dirname( __DIR__, 2 ) . '/templates/';
	}

	/**
	 * The site's name, which is the only thing these pages are allowed to name.
	 *
	 * @return string
	 */
	private static function site_name(): string {
		return (string) get_bloginfo( 'name' );
	}
}
