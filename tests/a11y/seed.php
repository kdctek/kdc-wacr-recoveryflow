<?php
/**
 * Puts enough realistic data on a site for the accessibility run to see every
 * screen in its populated state.
 *
 * Load it into WP-CLI, which is the only way to run it:
 *
 *     wp --require=tests/a11y/seed.php recoveryflow-a11y seed
 *     wp --require=tests/a11y/seed.php recoveryflow-a11y clear --yes
 *
 * It exists because an empty screen and a full one are different documents. A
 * WP_List_Table with no rows renders none of the status badges, none of the
 * masked contact columns and none of the row actions the accessibility
 * checklist makes claims about, and a run over the empty state would report a
 * clean pass on markup it never loaded. Three screens do not exist at all
 * without a row to address -- one recovery, one workflow's editor, and the
 * public opt-out page, which needs a live token.
 *
 * Everything it writes goes through the plugin's own repositories and the real
 * evaluation pass, not hand-built INSERTs, so the rows are shaped the way the
 * running plugin shapes them. Where a state cannot be reached by waiting -- a
 * recovered sale, a failure, an opt-out -- the journey is moved with
 * Journey_Repository::transition(), which is the same method the engine uses
 * and refuses the same illegal moves.
 *
 * Like tests/perf/seed.php it lives in tests/ and never ships: `.distignore`
 * excludes the whole directory. Its invented people come from the same
 * reserved ranges, so nothing it writes can reach a real person -- phone
 * numbers from Ofcom's drama range (+4470009xxxxx, never allocated) and
 * addresses on example.test.
 *
 * @package WAcr\RecoveryFlow
 */

use WAcr\RecoveryFlow\Admin\Setup;
use WAcr\RecoveryFlow\Core\Plugin;
use WAcr\RecoveryFlow\Core\Rewrites;
use WAcr\RecoveryFlow\Recovery\Attempt;
use WAcr\RecoveryFlow\Recovery\Channel;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Security\Token_Service;
use WAcr\RecoveryFlow\Workflow\Workflow_Repository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Demo data for the accessibility run. Never shipped.
 */
final class RecoveryFlow_A11y_Command {

	/**
	 * What every seeded row is marked with, so clear() never has to guess.
	 */
	private const TAG = 'a11yseed';

	/**
	 * Where the substitutions for .pa11yci.json are written.
	 */
	private const URLS_FILE = 'tests/a11y/urls.generated.json';

	/**
	 * Fills the site with demo recoveries and writes the URL substitutions.
	 *
	 * The states are chosen for what they RENDER, not for coverage of the state
	 * machine: one row per distinct badge, one per distinct set of row actions,
	 * and one still-live journey so the public opt-out page has a token that
	 * resolves. A state that looks identical to another on screen is not worth
	 * a row here.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function seed( array $args, array $assoc_args ): void {
		unset( $args );

		WP_CLI::confirm( 'Write demo recovery data into this database?', $assoc_args );

		$plugin = Plugin::instance();

		$this->clear_rows();

		$events = $this->ingest_drafts( $plugin );
		WP_CLI::log( sprintf( 'Events ingested: %d', count( $events ) ) );

		$this->run_evaluate( $plugin );

		$journeys = $this->journeys_for_events( $plugin, $events );
		WP_CLI::log( sprintf( 'Journeys enrolled: %d', count( $journeys ) ) );

		if ( count( $journeys ) < 2 ) {
			WP_CLI::error(
				'The evaluation pass enrolled fewer journeys than there are drafts. '
				. 'The screens would render an emptier state than a real site, so the run would '
				. 'check markup a merchant never sees. Check that recovery is enabled and that '
				. 'the abandonment cut-off is shorter than the seeded drafts are old.'
			);
		}

		$this->spread_states( $plugin, $journeys );

		$this->settle_first_run();

		$tokens = array(
			'view'   => $this->live_token( $plugin, $journeys, 0 ),
			'submit' => $this->live_token( $plugin, $journeys, 1 ),
		);

		$this->write_urls( $plugin, $journeys, $tokens );

		WP_CLI::success( sprintf( 'Seeded. Substitutions written to %s', self::URLS_FILE ) );
	}

	/**
	 * Removes everything this command wrote.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function clear( array $args, array $assoc_args ): void {
		unset( $args );

		WP_CLI::confirm( 'Delete every row this seeder wrote?', $assoc_args );

		$removed = $this->clear_rows();

		$path = self::path( self::URLS_FILE );

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		WP_CLI::success( sprintf( 'Removed %d rows.', $removed ) );
	}

	/**
	 * Report the drafts through the real ingest path.
	 *
	 * Each is dated well before the abandonment cut-off, because the evaluation
	 * pass only looks at events that have gone quiet; a draft stamped "now" is
	 * correctly ignored and would leave every screen empty.
	 *
	 * @param Plugin $plugin The container.
	 * @return array<int,int> The event ids that were created.
	 */
	private function ingest_drafts( Plugin $plugin ): array {
		$accepted = array();

		foreach ( $this->people() as $index => $person ) {
			$key   = self::TAG . '-' . ( $index + 1 );
			$draft = new Event_Draft( 'woocommerce', 'cart', $key );

			$draft->session_key      = $key;
			$draft->currency         = 'GBP';
			$draft->amount           = $person['amount'];
			$draft->item_count       = $person['items'];
			$draft->last_activity_at = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			$draft->items            = array(
				array(
					'name'     => $person['item'],
					'quantity' => $person['items'],
				),
			);

			$draft->identity->email                = $person['email'];
			$draft->identity->phone_raw            = $person['phone'];
			$draft->identity->country              = 'GB';
			$draft->identity->first_name           = $person['first'];
			$draft->identity->last_name            = $person['last'];
			$draft->identity->consent              = true;
			$draft->identity->consent_source       = 'checkout_classic';
			$draft->identity->consent_text_version = '1';

			$event_id = $plugin->ingest()->ingest( $draft );

			if ( 0 < $event_id ) {
				$accepted[] = $event_id;
			}
		}

		return $accepted;
	}

	/**
	 * Run the evaluation pass so the drafts become journeys.
	 *
	 * @param Plugin $plugin The container.
	 * @return void
	 */
	private function run_evaluate( Plugin $plugin ): void {
		$stats = $plugin->runner()->run( 'evaluate' );

		WP_CLI::log( sprintf( 'Evaluate: %s', wp_json_encode( $stats->to_array() ) ) );
	}

	/**
	 * Find the journeys the pass created for our events.
	 *
	 * @param Plugin         $plugin The container.
	 * @param array<int,int> $events Event ids that were ingested.
	 * @return array<int,int> Journey ids, in the order the drafts were reported.
	 */
	private function journeys_for_events( Plugin $plugin, array $events ): array {
		$ids = array();

		foreach ( $events as $event_id ) {
			$journey = $plugin->journeys()->find_by_event( $event_id );

			if ( null !== $journey ) {
				$ids[] = $journey->id;
			}
		}

		return $ids;
	}

	/**
	 * Move journeys into the states the screens render differently.
	 *
	 * The first two journeys stay non-terminal on purpose. The public opt-out
	 * page refuses a terminal journey, and it is checked twice -- once as it is
	 * presented and once after its button has been pressed -- so it needs two
	 * live tokens on two live journeys. pa11y-ci visits URLs concurrently, so
	 * one token used for both would have the second check racing the first and
	 * sometimes finding a journey that had already opted out.
	 *
	 * @param Plugin         $plugin   The container.
	 * @param array<int,int> $journeys Journey ids.
	 * @return void
	 */
	private function spread_states( Plugin $plugin, array $journeys ): void {
		$wanted = array(
			1 => array( Journey_State::MESSAGE_SENT, 'seeded' ),
			2 => array( Journey_State::RECOVERED, 'seeded' ),
			3 => array( Journey_State::FAILED, 'seeded' ),
			4 => array( Journey_State::OPTED_OUT, 'seeded' ),
			5 => array( Journey_State::EXPIRED, 'seeded' ),
		);

		foreach ( $wanted as $offset => $target ) {
			if ( ! isset( $journeys[ $offset ] ) ) {
				continue;
			}

			$this->move( $plugin, $journeys[ $offset ], (string) $target[0], (string) $target[1] );
		}
	}

	/**
	 * Walk a journey to a state, one legal transition at a time.
	 *
	 * The state machine refuses a jump, and it is right to: going straight to
	 * RECOVERED would skip the send gate, which is the guarantee that stops a
	 * retry double-billing. So the path is walked rather than short-circuited,
	 * and a refusal here means the seeder asked for something the engine would
	 * also refuse -- worth hearing about rather than working around.
	 *
	 * @param Plugin $plugin The container.
	 * @param int    $id     Journey id.
	 * @param string $target Where it should end up.
	 * @param string $reason Recorded against each move.
	 * @return void
	 */
	private function move( Plugin $plugin, int $id, string $target, string $reason ): void {
		$route = array(
			Journey_State::MESSAGE_SENT => array( Journey_State::MESSAGE_SENT ),
			Journey_State::RECOVERED    => array( Journey_State::MESSAGE_SENT, Journey_State::ENGAGED, Journey_State::RECOVERED ),
			Journey_State::FAILED       => array( Journey_State::FAILED ),
			Journey_State::OPTED_OUT    => array( Journey_State::OPTED_OUT ),
			Journey_State::EXPIRED      => array( Journey_State::EXPIRED ),
		);

		foreach ( (array) ( $route[ $target ] ?? array() ) as $step ) {
			$journey = $plugin->journeys()->find( $id );

			if ( null === $journey ) {
				return;
			}

			$patch = array();

			if ( Journey_State::MESSAGE_SENT === $step ) {
				$patch['first_sent_at'] = $plugin->clock()->now();
			}

			if ( Journey_State::RECOVERED === $step ) {
				$patch['recovered_at']     = $plugin->clock()->now();
				$patch['recovered_amount'] = '84.5000';
				$patch['attribution']      = 'link';
			}

			if ( Journey_State::FAILED === $step ) {
				$patch['last_error_code'] = 'transport_error';
				$patch['last_error_at']   = $plugin->clock()->now();
			}

			$moved = $plugin->journeys()->transition( $id, $journey->status, (string) $step, $patch, $reason );

			if ( ! $moved ) {
				WP_CLI::warning( sprintf( 'Journey %d refused %s -> %s.', $id, $journey->status, (string) $step ) );

				return;
			}
		}
	}

	/**
	 * Spend every one-time redirect a fresh activation is holding.
	 *
	 * Setup sends the first admin request to its own screen and then clears the
	 * flag, so on an unseeded site the FIRST url pa11y visits is checked as the
	 * setup screen and every later one is checked as itself. That is a run whose
	 * results depend on which URL happened to go first, and pa11y-ci visits
	 * concurrently, so which one that is varies between runs. Spending it here
	 * makes the run deterministic; the setup screen is still checked, because it
	 * is listed in its own right.
	 *
	 * WooCommerce holds one of exactly the same shape, and it is worse in two
	 * ways. It is a transient with a THIRTY SECOND life, so whether it fires at
	 * all depends on how long the site has been up when the run starts: on a
	 * developer's machine wp-env has been running for hours and it has always
	 * expired, while CI starts the site and checks it seconds later, where it
	 * has not. And where our redirect lands on a screen this suite lists,
	 * WooCommerce's lands on `wc-admin`, which has a `#wpbody-content` of its
	 * own -- so the run would have found a root element, checked WooCommerce's
	 * onboarding wizard, and reported it as one of our screens passing.
	 *
	 * Both are spent here rather than filtered off, because the site the suite
	 * checks should be a site somebody has already opened, which is the state
	 * every one of these screens is really used in.
	 *
	 * @return void
	 */
	private function settle_first_run(): void {
		delete_option( Setup::PENDING_OPTION );
		delete_transient( '_wc_activation_redirect' );
	}

	/**
	 * Mint a recovery link against a live journey.
	 *
	 * The token's plaintext exists only here and in the URL this command
	 * prints, exactly as it does when one is put into a message: only the hash
	 * is stored, so nothing can recover it afterwards and a second run mints a
	 * new one.
	 *
	 * @param Plugin         $plugin   The container.
	 * @param array<int,int> $journeys Journey ids.
	 * @param int            $offset   Which journey to mint against.
	 * @return string The plaintext token, or '' if none could be minted.
	 */
	private function live_token( Plugin $plugin, array $journeys, int $offset ): string {
		if ( ! isset( $journeys[ $offset ] ) ) {
			return '';
		}

		$journey_id = $journeys[ $offset ];
		$token      = Token_Service::mint();

		$attempt = $plugin->attempts()->reserve(
			array(
				'journey_id'       => $journey_id,
				'step_index'       => 0,
				'attempt_no'       => 1,
				'idempotency_key'  => self::TAG . '-' . $journey_id . '-' . substr( $token, 0, 8 ),
				'action_type'      => 'wacr.send_template',
				'channel'          => Channel::WHATSAPP,
				'token_hash'       => Token_Service::hash( $token ),
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) ),
				'status'           => Attempt::SENDING,
			)
		);

		if ( null === $attempt ) {
			WP_CLI::warning( 'No attempt could be reserved, so the public opt-out page has no link to serve.' );

			return '';
		}

		$plugin->attempts()->mark_sent( $attempt->id );

		return $token;
	}

	/**
	 * Write the values .pa11yci.json's placeholders stand for.
	 *
	 * The list of screens lives in .pa11yci.json where it can be read and
	 * reviewed; only the three ids that cannot be known until something has
	 * been created are written here.
	 *
	 * @param Plugin               $plugin   The container.
	 * @param array<int,int>       $journeys Journey ids.
	 * @param array<string,string> $tokens   Live recovery tokens, keyed view and submit.
	 * @return void
	 */
	private function write_urls( Plugin $plugin, array $journeys, array $tokens ): void {
		$workflow = $plugin->workflows()->find_by_slug( Workflow_Repository::SLUG_DIRECT );
		$journey  = isset( $journeys[0] ) ? $plugin->journeys()->find( $journeys[0] ) : null;
		$view     = (string) ( $tokens['view'] ?? '' );
		$submit   = (string) ( $tokens['submit'] ?? '' );

		$values = array(
			'JOURNEY_UID'         => null === $journey ? '' : $journey->journey_uid,
			'WORKFLOW_ID'         => (string) ( null === $workflow ? 0 : $workflow->id ),
			'OPT_OUT_URL'         => '' === $view ? '' : Rewrites::url( $view, 'opt-out' ),
			'OPT_OUT_SUBMIT_URL'  => '' === $submit ? '' : Rewrites::url( $submit, 'opt-out' ),
			/*
			 * Where the opt-out form POSTs to, as a path.
			 *
			 * The accessibility run presses that button and then has to know
			 * the page it landed on is the one after the press, not the one
			 * before it. Waiting for an element is no good -- a POST replaces
			 * the document, which destroys the observer that was watching for
			 * it -- but waiting for the LOCATION works across a navigation.
			 *
			 * It works here because the two differ: WordPress canonicalises
			 * the link into a trailing slash, while the form posts to the
			 * address without one. If that ever stops being true this wait
			 * will hang rather than mislead, which is the failure to prefer.
			 */
			'OPT_OUT_SUBMIT_PATH' => '' === $submit ? '' : (string) wp_parse_url( Rewrites::url( $submit, 'opt-out' ), PHP_URL_PATH ),
			'INVALID_URL'         => Rewrites::url( str_repeat( 'A', Token_Service::LENGTH ), 'restore' ),
			'SITE_URL'            => home_url( '/' ),
		);

		foreach ( $values as $name => $value ) {
			if ( '' === $value || '0' === $value ) {
				WP_CLI::warning( sprintf( '%s is empty, so the screen it addresses will not be checked.', $name ) );
			}
		}

		$written = file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- development tooling, never shipped.
			self::path( self::URLS_FILE ),
			(string) wp_json_encode( $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);

		if ( false === $written ) {
			WP_CLI::error( sprintf( 'Could not write %s.', self::URLS_FILE ) );
		}

		foreach ( $values as $name => $value ) {
			WP_CLI::log( sprintf( '%-12s %s', $name, $value ) );
		}
	}

	/**
	 * Delete every row this seeder wrote, in every table it reached.
	 *
	 * THE CONSENT LEDGER IS THE PART THAT MATTERS, and leaving it behind was a
	 * real defect for a while. The accessibility run presses the opt-out button
	 * -- that is the whole point of checking that page -- and an opt-out is
	 * recorded against the IDENTITY, which outlives the journey. So a clear-out
	 * that removed journeys and events but not consents left that person
	 * suppressed, the next seeding could not enrol them, and the queue came
	 * back one row shorter every time the suite was run. Six journeys became
	 * four in two runs. Nothing failed; the suite just quietly checked a
	 * thinner page each time, which is the same shape of defect as a gate that
	 * reports success while checking nothing.
	 *
	 * Order matters. Journeys and attempts go before events, because they point
	 * at them; identities and consents are collected before the customers they
	 * hang off are deleted, because afterwards there is nothing left to find
	 * them by.
	 *
	 * @return int How many rows went.
	 */
	private function clear_rows(): int {
		global $wpdb;

		$prefix  = $wpdb->prefix . 'recoveryflow_';
		$removed = 0;
		$like    = $wpdb->esc_like( self::TAG ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$event_ids = array_map(
			'intval',
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$prefix}events WHERE dedupe_key LIKE %s", $like ) )
		);

		if ( array() === $event_ids ) {
			return 0;
		}

		$events = implode( ',', $event_ids );

		$customer_ids = array_filter(
			array_map(
				'intval',
				(array) $wpdb->get_col( "SELECT DISTINCT customer_id FROM {$prefix}events WHERE id IN ({$events}) AND customer_id > 0" )
			)
		);

		$journey_ids = array_map(
			'intval',
			(array) $wpdb->get_col( "SELECT id FROM {$prefix}journeys WHERE event_id IN ({$events})" )
		);

		if ( array() !== $journey_ids ) {
			$journeys = implode( ',', $journey_ids );

			$removed += (int) $wpdb->query( "DELETE FROM {$prefix}attempts WHERE journey_id IN ({$journeys})" );
			$removed += (int) $wpdb->query( "DELETE FROM {$prefix}journeys WHERE id IN ({$journeys})" );
		}

		if ( array() !== $customer_ids ) {
			$customers = implode( ',', $customer_ids );

			$identities = (array) $wpdb->get_results( "SELECT kind, value_hash FROM {$prefix}identities WHERE customer_id IN ({$customers})", ARRAY_A );

			foreach ( $identities as $identity ) {
				$removed += (int) $wpdb->delete(
					$prefix . 'consents',
					array(
						'identity_kind' => (string) $identity['kind'],
						'identity_hash' => (string) $identity['value_hash'],
					)
				);
			}

			$removed += (int) $wpdb->query( "DELETE FROM {$prefix}consents WHERE customer_id IN ({$customers})" );
			$removed += (int) $wpdb->query( "DELETE FROM {$prefix}identities WHERE customer_id IN ({$customers})" );
			$removed += (int) $wpdb->query( "DELETE FROM {$prefix}customers WHERE id IN ({$customers})" );
		}

		$removed += (int) $wpdb->query( "DELETE FROM {$prefix}events WHERE id IN ({$events})" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return $removed;
	}

	/**
	 * The invented shoppers.
	 *
	 * Names are long enough and short enough to show the queue's column
	 * behaviour at both ends, and one carries a diacritic, because a table that
	 * only ever held ASCII in testing is a table nobody checked for mangled
	 * output.
	 *
	 * @return array<int,array<string,string|int>>
	 */
	private function people(): array {
		return array(
			array(
				'first'  => 'Aoife',
				'last'   => 'Ó Braonáin',
				'email'  => 'aoife@example.test',
				'phone'  => '07700 900101',
				'amount' => '84.50',
				'items'  => 2,
				'item'   => 'Reclaimed oak shelf',
			),
			array(
				'first'  => 'Bo',
				'last'   => 'Lin',
				'email'  => 'bo@example.test',
				'phone'  => '07700 900102',
				'amount' => '19.99',
				'items'  => 1,
				'item'   => 'Enamel mug',
			),
			array(
				'first'  => 'Constance',
				'last'   => 'Featherstonehaugh',
				'email'  => 'constance@example.test',
				'phone'  => '07700 900103',
				'amount' => '1250.00',
				'items'  => 7,
				'item'   => 'Wool rug, 2m x 3m',
			),
			array(
				'first'  => 'Devi',
				'last'   => 'Raman',
				'email'  => 'devi@example.test',
				'phone'  => '07700 900104',
				'amount' => '42.00',
				'items'  => 3,
				'item'   => 'Beeswax candles',
			),
			array(
				'first'  => 'Emeka',
				'last'   => 'Okafor',
				'email'  => 'emeka@example.test',
				'phone'  => '07700 900105',
				'amount' => '7.25',
				'items'  => 1,
				'item'   => 'Seed packet',
			),
			array(
				'first'  => 'Fatima',
				'last'   => 'Al-Sayegh',
				'email'  => 'fatima@example.test',
				'phone'  => '07700 900106',
				'amount' => '310.75',
				'items'  => 4,
				'item'   => 'Linen bedding set',
			),
		);
	}

	/**
	 * Resolve a path inside the plugin.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private static function path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}
}

WP_CLI::add_command( 'recoveryflow-a11y', 'RecoveryFlow_A11y_Command' );
