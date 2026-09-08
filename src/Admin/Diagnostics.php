<?php
/**
 * The report somebody pastes into a support conversation.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Core\Health;
use WAcr\RecoveryFlow\Privacy\Redactor;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Support\Options;
use WAcr\RecoveryFlow\WAcr\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Everything worth knowing about this install, and nothing that identifies
 * anybody.
 *
 * A support conversation about a recovery that did not happen otherwise runs on
 * screenshots and a dozen rounds of "and what does the status screen say". This
 * is the same facts in one block a merchant can paste.
 *
 * The whole design constraint is what must NOT be in it. A diagnostic report
 * gets pasted into email, chat and public forums by people who have no way to
 * audit what they are sending, so it is built from an allow-list rather than by
 * dumping state and removing the bad parts: nothing reaches it unless a line
 * here put it there deliberately, and the result is passed through the same
 * Redactor every log entry goes through as a second, independent guarantee.
 *
 * Three things are excluded that somebody would otherwise reasonably include:
 *
 * - The API key, in any form. Not masked, not its length -- a report that says
 *   how long a secret is has told you something about the secret.
 * - The Auto Flow hook address. It looks like a setting and it is a credential:
 *   the address alone is enough to start a merchant's flow, which is exactly
 *   why it is 32 random bytes. Whether one is SAVED is the useful fact, and
 *   that is what appears.
 * - Anything from the logs table. It is already redacted going in, but it is
 *   about individual customers, and a support report is not the place to find
 *   out how well that redaction works.
 */
final class Diagnostics {

	/**
	 * Settings safe to state verbatim.
	 *
	 * An allow-list, because the alternative -- printing every setting and
	 * removing the sensitive ones -- means every setting added later is public
	 * by default and somebody has to remember. This way a new setting is absent
	 * until somebody decides it belongs, which is the failure that costs
	 * nothing.
	 *
	 * @return string[]
	 */
	private static function safe_settings(): array {
		return array(
			'channel_whatsapp_enabled',
			'channel_email_enabled',
			'eligibility_mode',
			'inactivity_minutes',
			'max_age_days',
			'min_amount',
			'max_touches',
			'frequency_cap_hours',
			'max_journeys_per_month',
			'quiet_hours_enabled',
			'quiet_hours_start',
			'quiet_hours_end',
			'attribution_window_days',
			'exclude_admins',
			'recovery_link_ttl_days',
			'prefill_guest_checkout',
			'wacr_environment',
			'wacr_dispatch',
			'wacr_push_optout',
			'wacr_sync_optout',
			'wacr_share_last_name',
			'wacr_share_email',
			'retention_days',
			'delete_data_on_uninstall',
		);
	}

	/**
	 * The stored credential.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * The health checks.
	 *
	 * @var Health
	 */
	private Health $health;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials The stored credential.
	 * @param Health      $health      The health checks.
	 */
	public function __construct( Credentials $credentials, Health $health ) {
		$this->credentials = $credentials;
		$this->health      = $health;
	}

	/**
	 * The report, as plain text.
	 *
	 * Plain text rather than JSON because of where it goes: a chat window, an
	 * email, a forum post. JSON survives none of those intact and reads badly
	 * to the person being asked for help.
	 *
	 * @return string
	 */
	public function report(): string {
		$lines = array( '=== RecoveryFlow diagnostic report ===' );

		foreach ( $this->sections() as $heading => $rows ) {
			$lines[] = '';
			$lines[] = $heading;

			foreach ( $rows as $label => $value ) {
				$lines[] = sprintf( '  %s: %s', $label, self::stringify( $value ) );
			}
		}

		$lines[] = '';
		$lines[] = '(No API key, hook address, customer details or log entries are included.)';

		// The allow-list above is the guarantee; this is the second one. The
		// Redactor is what every log entry already passes through, so a value
		// that slipped past a reviewer still has to get past the thing whose
		// whole job is catching it.
		return (string) Redactor::scrub_string( implode( "\n", $lines ) );
	}

	/**
	 * The report's contents, section by section.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function sections(): array {
		global $wp_version;

		$snapshot = Feature_Gate::snapshot();

		return array(
			'Environment' => array(
				'RecoveryFlow' => defined( 'KDC_WACR_RECOVERYFLOW_VERSION' ) ? constant( 'KDC_WACR_RECOVERYFLOW_VERSION' ) : 'unknown',
				'WordPress'    => isset( $wp_version ) ? $wp_version : 'unknown',
				'WooCommerce'  => defined( 'WC_VERSION' ) ? constant( 'WC_VERSION' ) : 'not active',
				'PHP'          => PHP_VERSION,
				'Multisite'    => is_multisite(),
				'Locale'       => get_locale(),
				'Timezone'     => wp_timezone_string(),
			),
			'WA.cr'       => array(
				'API key saved'    => $this->credentials->has_api_key(),
				'API key readable' => ! $this->credentials->is_key_unreadable(),
				'Hook address set' => $this->credentials->has_hook(),
				'Hook signed'      => '' !== $this->credentials->hook_secret(),
				'Workspace'        => isset( $snapshot['tenant_name'] ) ? (string) $snapshot['tenant_name'] : 'unknown',
				'Developer API'    => Feature_Gate::has_developer_api(),
				'Scopes'           => isset( $snapshot['scopes'] ) && is_array( $snapshot['scopes'] ) ? implode( ' ', $snapshot['scopes'] ) : 'unknown',
				'Last checked'     => isset( $snapshot['checked_at'] ) ? (string) $snapshot['checked_at'] : 'never',
			),
			'Settings'    => $this->settings(),
			'Health'      => $this->health_rows(),
			'Background'  => $this->stage_rows(),
		);
	}

	/**
	 * The settings that may be stated.
	 *
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		$rows = array();

		foreach ( self::safe_settings() as $key ) {
			$rows[ $key ] = Options::get( $key, null );
		}

		return $rows;
	}

	/**
	 * Each health check and what it said.
	 *
	 * @return array<string,mixed>
	 */
	private function health_rows(): array {
		$rows = array();

		foreach ( $this->health->checks() as $check ) {
			$label          = isset( $check['id'] ) ? (string) $check['id'] : 'check';
			$rows[ $label ] = isset( $check['severity'] ) ? (string) $check['severity'] : 'unknown';
		}

		return $rows;
	}

	/**
	 * When each background pass last ran, and how it went.
	 *
	 * @return array<string,mixed>
	 */
	private function stage_rows(): array {
		$rows = array();

		foreach ( $this->health->stage_report() as $stage ) {
			if ( ! is_array( $stage ) ) {
				continue;
			}

			$name = isset( $stage['stage'] ) ? (string) $stage['stage'] : 'stage';

			// Counts and whether the last run errored -- never the error text.
			// A message from a failed send is the one place in this structure
			// that could carry a customer's details, and a report is not where
			// to find out whether the redactor caught it.
			$rows[ $name ] = sprintf(
				'ran %s, processed %d, failed %d, backlog %d, last run errored: %s',
				isset( $stage['ran_at'] ) && '' !== $stage['ran_at'] ? (string) $stage['ran_at'] : 'never',
				isset( $stage['processed'] ) ? (int) $stage['processed'] : 0,
				isset( $stage['failed'] ) ? (int) $stage['failed'] : 0,
				isset( $stage['backlog'] ) ? (int) $stage['backlog'] : 0,
				empty( $stage['last_error'] ) ? 'no' : 'yes'
			);
		}

		return $rows;
	}

	/**
	 * One value, as it should read in a report.
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	private static function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( null === $value ) {
			return 'not set';
		}

		if ( is_array( $value ) ) {
			return implode( ' ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * The report, and the way to take a copy of it.
	 *
	 * A read-only textarea rather than a button alone: selecting text in a box
	 * works in every browser, with scripts off, and for somebody who would
	 * rather read what they are about to send than trust a button that says it
	 * copied something. The button is an enhancement on top and says when it
	 * has worked.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_STATUS ) ) {
			return;
		}

		echo '<div class="card recoveryflow-card recoveryflow-diagnostics">';
		printf( '<h2>%s</h2>', esc_html__( 'Diagnostic report', 'kdc-wacr-recoveryflow' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Everything below is safe to send to support: it holds no API key, no hook address, no customer details and nothing from the logs. Read it before you send it, the way you would anything else.', 'kdc-wacr-recoveryflow' )
		);
		printf(
			'<label class="screen-reader-text" for="recoveryflow-diagnostics">%s</label>',
			esc_html__( 'Diagnostic report', 'kdc-wacr-recoveryflow' )
		);
		printf(
			'<textarea id="recoveryflow-diagnostics" class="widefat code" rows="18" readonly>%s</textarea>',
			esc_textarea( $this->report() )
		);
		printf(
			'<p><button type="button" class="button" data-copy-text="recoveryflow-diagnostics">%s</button></p>',
			esc_html__( 'Copy the report', 'kdc-wacr-recoveryflow' )
		);
		echo '</div>';
	}
}
