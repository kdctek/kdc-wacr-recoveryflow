<?php
/**
 * Is this working, and if not, what do I do about it?
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Core;

use WAcr\RecoveryFlow\Admin\Settings\Schema as Settings_Schema;
use WAcr\RecoveryFlow\Database\Schema as Db_Schema;
use WAcr\RecoveryFlow\Jobs\Scheduler_Factory;
use WAcr\RecoveryFlow\Jobs\Scheduler_Interface;
use WAcr\RecoveryFlow\Jobs\Stage_Stats;
use WAcr\RecoveryFlow\Recovery\Channel;
use WAcr\RecoveryFlow\Recovery\Email_Compliance;
use WAcr\RecoveryFlow\Recovery\Rule_Set;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\WAcr\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Every health check the plugin can make, in one place.
 *
 * Both the status screen and the REST endpoint read from here, and that is the
 * whole point of the class existing. Two lists of checks would drift, and the
 * way they drift is predictable: somebody fixes the wording of a diagnosis on
 * the screen they were looking at, and the API keeps telling integrators the
 * old thing.
 *
 * Every check reports the same four things -- what was checked, whether it
 * passed, a sentence somebody running a shop can act on, and where there is
 * one, a deeplink to the exact setting that fixes it. That last field is why
 * this is worth building rather than printing a list of ticks: "missing postal
 * address" is a diagnosis, and a link to the box you type it into is a fix.
 *
 * Severity is ok, warning or error, and always travels with the sentence. The
 * colour a screen paints it is decoration; the sentence is the information.
 */
final class Health {

	/**
	 * Everything passed.
	 */
	public const OK = 'ok';

	/**
	 * Working, but not doing what somebody probably expects.
	 */
	public const WARNING = 'warning';

	/**
	 * Not working.
	 */
	public const ERROR = 'error';

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Scheduler factory.
	 *
	 * @var Scheduler_Factory
	 */
	private Scheduler_Factory $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Credentials       $credentials Credentials.
	 * @param Scheduler_Factory $scheduler   Scheduler factory.
	 */
	public function __construct( Credentials $credentials, Scheduler_Factory $scheduler ) {
		$this->credentials = $credentials;
		$this->scheduler   = $scheduler;
	}

	/**
	 * Every check, in the order somebody should read them.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function checks(): array {
		return array_merge(
			$this->plugin_checks(),
			$this->connection_checks(),
			$this->channel_checks(),
			$this->schedule_checks()
		);
	}

	/**
	 * The worst severity among the checks.
	 *
	 * @return string One of the class constants.
	 */
	public function worst(): string {
		$worst = self::OK;

		foreach ( $this->checks() as $check ) {
			if ( self::ERROR === $check['severity'] ) {
				return self::ERROR;
			}

			if ( self::WARNING === $check['severity'] ) {
				$worst = self::WARNING;
			}
		}

		return $worst;
	}

	/**
	 * Is the plugin switched on and installed properly?
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function plugin_checks(): array {
		$rules   = Rule_Set::for_source();
		$missing = Capabilities::missing_grants();

		$checks = array(
			$this->check(
				'enabled',
				__( 'Recovery is switched on', 'kdc-wacr-recoveryflow' ),
				$rules->is_enabled(),
				$rules->is_enabled()
					? __( 'Abandoned baskets are being recorded and worked.', 'kdc-wacr-recoveryflow' )
					: __( 'Nothing is being recorded or sent. Switch recovery on to start.', 'kdc-wacr-recoveryflow' ),
				Settings_Schema::deeplink( 'enabled' )
			),
			$this->check(
				'tables',
				__( 'Database tables', 'kdc-wacr-recoveryflow' ),
				Db_Schema::is_installed(),
				Db_Schema::is_installed()
					? __( 'All of the plugin\'s tables are present.', 'kdc-wacr-recoveryflow' )
					: __( 'Some tables are missing. Deactivating and reactivating the plugin creates them.', 'kdc-wacr-recoveryflow' ),
				''
			),
		);

		$checks[] = $this->check(
			'capabilities',
			__( 'Staff permissions', 'kdc-wacr-recoveryflow' ),
			array() === $missing,
			array() === $missing
				? __( 'Shop managers can work the recovery queue.', 'kdc-wacr-recoveryflow' )
				: __( 'Some roles are missing RecoveryFlow permissions, which is why staff may not be able to see the journeys. Deactivating and reactivating the plugin re-applies them.', 'kdc-wacr-recoveryflow' ),
			''
		);

		return $checks;
	}

	/**
	 * Is there a working WA.cr connection?
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function connection_checks(): array {
		$link = Settings_Schema::deeplink( Settings_Schema::FIELD_API_KEY );

		if ( $this->credentials->is_key_unreadable() ) {
			return array(
				$this->check(
					'wacr_key',
					__( 'WA.cr connection', 'kdc-wacr-recoveryflow' ),
					false,
					__( 'The saved API key can no longer be read, which happens when a site\'s security keys are rotated. Enter the key again.', 'kdc-wacr-recoveryflow' ),
					$link
				),
			);
		}

		if ( ! $this->credentials->has_api_key() ) {
			return array(
				$this->check(
					'wacr_key',
					__( 'WA.cr connection', 'kdc-wacr-recoveryflow' ),
					false,
					__( 'No API key is saved. Baskets are still recorded, but nothing can be sent.', 'kdc-wacr-recoveryflow' ),
					$link
				),
			);
		}

		return array(
			$this->check(
				'wacr_key',
				__( 'WA.cr connection', 'kdc-wacr-recoveryflow' ),
				true,
				__( 'An API key is saved. Use "Test connection" to check it against WA.cr.', 'kdc-wacr-recoveryflow' ),
				$link
			),
			$this->check(
				'wacr_plan',
				__( 'WA.cr developer API', 'kdc-wacr-recoveryflow' ),
				Feature_Gate::has_developer_api(),
				Feature_Gate::has_developer_api()
					? __( 'This workspace can author workflows in WordPress and send directly.', 'kdc-wacr-recoveryflow' )
					: Feature_Gate::unavailable_reason(),
				'',
				Feature_Gate::has_developer_api() ? 'ok' : 'warning'
			),
		);
	}

	/**
	 * Can each channel actually send?
	 *
	 * The email check is the one that earns its place. The channel has refused
	 * to send since it was built, and until the settings screen existed there
	 * was nowhere to see why. Here the reason is named and linked.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function channel_checks(): array {
		$rules    = Rule_Set::for_source();
		$settings = Options::all();
		$blockers = Email_Compliance::blockers( $settings );

		$checks = array(
			$this->check(
				'channel_whatsapp',
				__( 'WhatsApp reminders', 'kdc-wacr-recoveryflow' ),
				$rules->channel_enabled( Channel::WHATSAPP ),
				$rules->channel_enabled( Channel::WHATSAPP )
					? __( 'Workflow steps set to WhatsApp will be sent.', 'kdc-wacr-recoveryflow' )
					: __( 'WhatsApp is switched off, so steps set to it are skipped.', 'kdc-wacr-recoveryflow' ),
				Settings_Schema::deeplink( 'channel_whatsapp_enabled' ),
				$rules->channel_enabled( Channel::WHATSAPP ) ? 'ok' : 'warning'
			),
		);

		if ( array() !== $blockers ) {
			$first = $blockers[0];

			$checks[] = $this->check(
				'channel_email',
				__( 'Email reminders', 'kdc-wacr-recoveryflow' ),
				false,
				sprintf(
					/* translators: %s: what is stopping email from being sent. */
					__( 'Email cannot be sent yet. %s', 'kdc-wacr-recoveryflow' ),
					Email_Compliance::reason_label( $first )
				),
				Settings_Schema::deeplink( $this->blocker_field( $first ) ),
				'warning'
			);

			return $checks;
		}

		$checks[] = $this->check(
			'channel_email',
			__( 'Email reminders', 'kdc-wacr-recoveryflow' ),
			$rules->channel_enabled( Channel::EMAIL ),
			$rules->channel_enabled( Channel::EMAIL )
				? __( 'Workflow steps set to email will be sent.', 'kdc-wacr-recoveryflow' )
				: __( 'Everything the law asks for is settled, and email is still switched off. Turning it on is your decision.', 'kdc-wacr-recoveryflow' ),
			Settings_Schema::deeplink( 'channel_email_enabled' ),
			$rules->channel_enabled( Channel::EMAIL ) ? 'ok' : 'warning'
		);

		return $checks;
	}

	/**
	 * Which setting clears one email blocker.
	 *
	 * @param string $code Blocker reason code.
	 * @return string
	 */
	private function blocker_field( string $code ): string {
		$map = array(
			Email_Compliance::NO_POSTAL_ADDRESS            => Email_Compliance::SETTING_ADDRESS,
			Email_Compliance::NO_POSTAL_COUNTRY            => Email_Compliance::SETTING_COUNTRY,
			Email_Compliance::UNSUBSCRIBE_WINDOW_TOO_SHORT => 'recovery_link_ttl_days',
		);

		return $map[ $code ] ?? '';
	}

	/**
	 * Is anything actually running in the background?
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function schedule_checks(): array {
		$scheduler = $this->scheduler->scheduler();
		$next      = $scheduler->next_run( Scheduler_Interface::EVALUATE );
		$scheduled = $next > 0;

		return array(
			$this->check(
				'scheduler',
				__( 'Background processing', 'kdc-wacr-recoveryflow' ),
				$scheduled,
				$scheduled
					? sprintf(
						/* translators: 1: the name of the scheduler in use. 2: a human time difference, e.g. "3 mins". */
						__( 'Running on %1$s. The next check is due in %2$s.', 'kdc-wacr-recoveryflow' ),
						$scheduler->id(),
						human_time_diff( time(), $next )
					)
					: __( 'Nothing is scheduled, so no reminders will be sent. Deactivating and reactivating the plugin re-schedules the background jobs.', 'kdc-wacr-recoveryflow' ),
				''
			),
		);
	}

	/**
	 * What each background stage did when it last ran.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function stage_report(): array {
		$stored = Stage_Stats::stored();
		$report = array();

		foreach ( Scheduler_Interface::STAGES as $stage ) {
			$stats = $stored[ $stage ] ?? new Stage_Stats( $stage );

			$report[] = array(
				'stage'      => $stage,
				'processed'  => $stats->processed,
				'failed'     => $stats->failed,
				'backlog'    => $stats->backlog,
				'duration'   => $stats->duration,
				'last_error' => $stats->last_error,
				'ran_at'     => $stats->ran_at,
			);
		}

		return $report;
	}

	/**
	 * One check, in the shape every check takes.
	 *
	 * @param string $id       Machine name, never translated: it is compared and logged.
	 * @param string $label    What was checked.
	 * @param bool   $passed   Whether it passed.
	 * @param string $message  What to do about it.
	 * @param string $link     Deeplink to the setting that fixes it, or ''.
	 * @param string $severity Severity when it did not pass: error or warning.
	 * @return array<string,mixed>
	 */
	private function check( string $id, string $label, bool $passed, string $message, string $link = '', string $severity = 'error' ): array {
		return array(
			'id'       => $id,
			'label'    => $label,
			'passed'   => $passed,
			'severity' => $passed ? 'ok' : $severity,
			'message'  => $message,
			'link'     => $link,
		);
	}
}
