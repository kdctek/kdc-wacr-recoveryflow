<?php
/**
 * The wp recoveryflow command.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\CLI;

use WAcr\RecoveryFlow\Core\Health;
use WAcr\RecoveryFlow\Core\Plugin;
use WAcr\RecoveryFlow\Customer\Mask;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * RecoveryFlow, from a terminal.
 *
 * There are two reasons this exists and neither is convenience.
 *
 * The first is that the background passes are the part of this plugin nobody
 * can see. A merchant reporting "it has stopped sending" is describing WP-Cron,
 * or Action Scheduler, or a lock nobody released, and the honest way to find
 * out which is to run a pass in the foreground and read what it says. Every
 * command here runs the same code the scheduler runs -- not a copy of it -- so
 * what happens on the command line is what happens at three in the morning.
 *
 * The second is that a site with a hundred thousand journeys cannot be
 * diagnosed through a screen that pages twenty at a time.
 *
 * Nothing here is translated, deliberately. WP-CLI resolves no locale of its
 * own and its entire framework -- every flag, every error, every --help page
 * these commands sit beside -- is English, so a half-translated help screen is
 * harder to read than an English one. See docs/internationalization.md.
 *
 * Contact details are masked, always, with no flag to unmask them. Anybody
 * running WP-CLI can already read the database, so an unmasked column here
 * would not be protecting anything -- it would only add a route to customers'
 * phone numbers that appears in shell history, in CI logs and over anybody's
 * shoulder, and that no audit trail covers. The reveal that IS audited is on
 * the REST route and the one-journey admin screen, where a person holding a
 * named capability asks for it.
 */
final class Command {

	/**
	 * The container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin The container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register the command with WP-CLI.
	 *
	 * @param Plugin $plugin The container.
	 * @return void
	 */
	public static function register( Plugin $plugin ): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'recoveryflow', new self( $plugin ) );
	}

	/**
	 * Says whether RecoveryFlow is working, and what each background pass last did.
	 *
	 * The same checks the status screen and the REST endpoint read, so the three
	 * cannot give different diagnoses of one site.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp recoveryflow status
	 *     wp recoveryflow status --format=json
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );

		$health = $this->plugin->health();
		$rows   = array();

		foreach ( $health->checks() as $check ) {
			$rows[] = array(
				'check'    => (string) ( $check['label'] ?? $check['id'] ?? '' ),
				'severity' => (string) ( $check['severity'] ?? '' ),
				'says'     => (string) ( $check['message'] ?? '' ),
			);
		}

		\WP_CLI\Utils\format_items( $this->format( $assoc_args ), $rows, array( 'check', 'severity', 'says' ) );

		$stages = array();

		foreach ( $health->stage_report() as $stage ) {
			$stages[] = array(
				'pass'     => (string) ( $stage['stage'] ?? '' ),
				'last run' => '' === (string) ( $stage['ran_at'] ?? '' ) ? 'never' : (string) $stage['ran_at'],
				'handled'  => (int) ( $stage['processed'] ?? 0 ),
				'failed'   => (int) ( $stage['failed'] ?? 0 ),
				'backlog'  => ( (int) ( $stage['backlog'] ?? 0 ) ) > 0 ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( $this->format( $assoc_args ), $stages, array( 'pass', 'last run', 'handled', 'failed', 'backlog' ) );

		if ( Health::ERROR === $health->worst() ) {
			\WP_CLI::error( 'Something is stopping RecoveryFlow from working. See the checks above.' );
		}
	}

	/**
	 * Runs the background passes now, in the foreground, and says what they did.
	 *
	 * This is the real scheduler's own code path, under the real locks and the
	 * real time budget -- not a command that imitates it. A pass another process
	 * is already running is skipped rather than run twice, which is the correct
	 * outcome and worth seeing.
	 *
	 * ## OPTIONS
	 *
	 * [--stage=<stage>]
	 * : Run one pass instead of all of them.
	 * ---
	 * options:
	 *   - evaluate
	 *   - dispatch
	 *   - poll
	 *   - expire
	 *   - retention
	 * ---
	 *
	 * [--until-clear]
	 * : Keep running while a pass reports a backlog. Stops after 20 rounds.
	 *
	 * ## EXAMPLES
	 *
	 *     wp recoveryflow tick
	 *     wp recoveryflow tick --stage=evaluate --until-clear
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function tick( array $args, array $assoc_args ): void {
		unset( $args );

		$stage  = (string) ( $assoc_args['stage'] ?? '' );
		$rounds = isset( $assoc_args['until-clear'] ) ? 20 : 1;
		$rows   = array();

		for ( $round = 1; $round <= $rounds; $round++ ) {
			$backlog = false;

			foreach ( $this->plugin->runner()->run_tick( $stage ) as $key => $stats ) {
				$rows[]  = $this->stats_row( $round, $key, $stats );
				$backlog = $backlog || $stats->backlog > 0;
			}

			if ( ! $backlog ) {
				break;
			}
		}

		\WP_CLI\Utils\format_items(
			$this->format( $assoc_args ),
			$rows,
			array( 'round', 'pass', 'handled', 'skipped', 'failed', 'lost race', 'backlog', 'seconds' )
		);
	}

	/**
	 * Lists recovery journeys.
	 *
	 * Contact details are always masked. See the class note: anybody who can run
	 * this can already read the database, so an unmasked column would add a
	 * route to customers' phone numbers through shell history and CI logs
	 * without protecting anything.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only journeys in this state.
	 *
	 * [--source=<source>]
	 * : Only journeys from one integration, for example woocommerce.
	 *
	 * [--search=<reference>]
	 * : A journey's own reference. Never a phone number or an email address.
	 *
	 * [--page=<page>]
	 * : Which page. Default 1.
	 *
	 * [--per-page=<number>]
	 * : How many per page, up to 200. Default 20.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp recoveryflow journeys --status=scheduled
	 *     wp recoveryflow journeys --source=woocommerce --per-page=100 --format=csv
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function journeys( array $args, array $assoc_args ): void {
		unset( $args );

		$result = $this->plugin->journeys()->query(
			array(
				'status'   => (string) ( $assoc_args['status'] ?? '' ),
				'source'   => (string) ( $assoc_args['source'] ?? '' ),
				'search'   => (string) ( $assoc_args['search'] ?? '' ),
				'page'     => (int) ( $assoc_args['page'] ?? 1 ),
				'per_page' => (int) ( $assoc_args['per-page'] ?? 20 ),
			)
		);

		if ( 'count' === $this->format( $assoc_args ) ) {
			\WP_CLI::line( (string) $result['total'] );

			return;
		}

		$rows = array();

		foreach ( $result['rows'] as $journey ) {
			$rows[] = $this->journey_row( $journey );
		}

		\WP_CLI\Utils\format_items(
			$this->format( $assoc_args ),
			$rows,
			array( 'reference', 'status', 'source', 'customer', 'next action', 'attempts', 'created' )
		);

		\WP_CLI::log( sprintf( '%d of %d.', count( $rows ), (int) $result['total'] ) );
	}

	/**
	 * Lists the integrations RecoveryFlow can watch, and whether each one is.
	 *
	 * The status column is Source_Registry's own answer -- the one that decides
	 * whether a source's hooks are attached -- so "active" here means it really
	 * is watching, not merely that it is installed and switched on.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp recoveryflow sources
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function sources( array $args, array $assoc_args ): void {
		unset( $args );

		$registry = $this->plugin->sources();
		$rows     = array();

		foreach ( $registry->all() as $id => $source ) {
			$rows[] = array(
				'id'      => $id,
				'name'    => $source->get_name(),
				'status'  => $registry->status( $id ),
				'watches' => implode( ', ', $source->get_event_types() ),
			);
		}

		\WP_CLI\Utils\format_items( $this->format( $assoc_args ), $rows, array( 'id', 'name', 'status', 'watches' ) );
	}

	/**
	 * One journey, as columns.
	 *
	 * @param Recovery_Journey $journey The journey.
	 * @return array<string,mixed>
	 */
	private function journey_row( Recovery_Journey $journey ): array {
		$customer = 0 === $journey->customer_id ? null : $this->plugin->customers()->find( $journey->customer_id );

		return array(
			'reference'   => $journey->journey_uid,
			'status'      => $journey->status,
			'source'      => $journey->source_id,
			'customer'    => null === $customer ? '-' : Mask::phone( $customer->phone_e164 ) . ' ' . Mask::email( $customer->email ),
			'next action' => (string) ( $journey->next_action_at ?? '-' ),
			'attempts'    => $journey->attempts_count,
			'created'     => $journey->created_at,
		);
	}

	/**
	 * One stage's result, as columns.
	 *
	 * @param int         $round The round it ran in.
	 * @param string      $key   Stage key.
	 * @param Stage_Stats $stats What it did.
	 * @return array<string,mixed>
	 */
	private function stats_row( int $round, string $key, Stage_Stats $stats ): array {
		return array(
			'round'     => $round,
			'pass'      => $key,
			'handled'   => $stats->processed,
			'skipped'   => $stats->skipped,
			'failed'    => $stats->failed,
			'lost race' => $stats->lost_race,
			'backlog'   => $stats->backlog > 0 ? 'yes' : 'no',
			'seconds'   => round( $stats->duration, 2 ),
		);
	}

	/**
	 * The output format asked for.
	 *
	 * @param array<string,string> $assoc_args Flags.
	 * @return string
	 */
	private function format( array $assoc_args ): string {
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		return in_array( $format, array( 'table', 'json', 'yaml', 'csv', 'count' ), true ) ? $format : 'table';
	}
}
