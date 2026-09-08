<?php
/**
 * The system status screen.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Admin\Diagnostics;
use WAcr\RecoveryFlow\Admin\Run_Now;
use WAcr\RecoveryFlow\Core\Health;
use WAcr\RecoveryFlow\Jobs\Stage_Label;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Every health check, and what the background jobs last did.
 *
 * The checks come from the same Health object the REST endpoint reads, so the
 * screen and the API cannot end up giving different diagnoses of the same site.
 *
 * Status is stated in words in a column of its own -- "Working", "Check this",
 * "Stopped" -- rather than being carried by a coloured dot. That is not only an
 * accessibility rule: a screenshot pasted into a support thread keeps its
 * meaning, and so does a printed page.
 */
final class System_Status {

	/**
	 * The health checks.
	 *
	 * @var Health
	 */
	private Health $health;

	/**
	 * The support report.
	 *
	 * @var Diagnostics
	 */
	private Diagnostics $diagnostics;

	/**
	 * Constructor.
	 *
	 * @param Health      $health      The health checks.
	 * @param Diagnostics $diagnostics The support report. The health checks.
	 */
	public function __construct( Health $health, Diagnostics $diagnostics ) {
		$this->health      = $health;
		$this->diagnostics = $diagnostics;
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_STATUS ) ) {
			wp_die( esc_html__( 'You do not have permission to view RecoveryFlow\'s status.', 'kdc-wacr-recoveryflow' ), 403 );
		}

		echo '<div class="wrap recoveryflow-status">';
		printf( '<h1>%s</h1>', esc_html__( 'RecoveryFlow status', 'kdc-wacr-recoveryflow' ) );

		Run_Now::notice();

		$this->checks();
		$this->stages();
		Run_Now::button();
		$this->diagnostics->render();

		echo '</div>';
	}

	/**
	 * The checks themselves.
	 *
	 * @return void
	 */
	private function checks(): void {
		printf( '<h2>%s</h2>', esc_html__( 'Checks', 'kdc-wacr-recoveryflow' ) );

		echo '<table class="widefat striped recoveryflow-checks"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Check', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Result', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'What this means', 'kdc-wacr-recoveryflow' ) );
		echo '</tr></thead><tbody>';

		foreach ( $this->health->checks() as $check ) {
			printf(
				'<tr class="recoveryflow-check recoveryflow-check--%1$s"><th scope="row">%2$s</th><td>%3$s</td><td>%4$s%5$s</td></tr>',
				esc_attr( (string) $check['severity'] ),
				esc_html( (string) $check['label'] ),
				esc_html( $this->severity_label( (string) $check['severity'] ) ),
				esc_html( (string) $check['message'] ),
				'' === (string) $check['link']
					? ''
					: sprintf(
						' <a href="%1$s">%2$s</a>',
						esc_url( (string) $check['link'] ),
						esc_html__( 'Go to this setting', 'kdc-wacr-recoveryflow' )
					)
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * What each background stage did when it last ran.
	 *
	 * @return void
	 */
	private function stages(): void {
		printf( '<h2>%s</h2>', esc_html__( 'Background jobs', 'kdc-wacr-recoveryflow' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'RecoveryFlow works in five small passes rather than one long one, so a busy shop finishes each of them. A backlog means the pass ran out of time and will carry on next run; it is only a problem if it never clears.', 'kdc-wacr-recoveryflow' )
		);

		echo '<table class="widefat striped recoveryflow-stages"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Pass', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Last run', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Handled', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Failed', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'More to do', 'kdc-wacr-recoveryflow' ) );
		echo '</tr></thead><tbody>';

		foreach ( $this->health->stage_report() as $stage ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( Stage_Label::for_stage( (string) $stage['stage'] ) ),
				esc_html( '' === (string) $stage['ran_at'] ? __( 'Not yet', 'kdc-wacr-recoveryflow' ) : (string) $stage['ran_at'] ),
				esc_html( number_format_i18n( (int) $stage['processed'] ) ),
				esc_html( number_format_i18n( (int) $stage['failed'] ) ),
				esc_html( $stage['backlog'] > 0 ? __( 'Yes', 'kdc-wacr-recoveryflow' ) : __( 'No', 'kdc-wacr-recoveryflow' ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * A severity, in words.
	 *
	 * The stored value is a machine code and stays one; this is the label, made
	 * at the moment of rendering.
	 *
	 * @param string $severity One of the Health constants.
	 * @return string
	 */
	private function severity_label( string $severity ): string {
		switch ( $severity ) {
			case Health::ERROR:
				return _x( 'Stopped', 'the result of a system check', 'kdc-wacr-recoveryflow' );

			case Health::WARNING:
				return _x( 'Check this', 'the result of a system check', 'kdc-wacr-recoveryflow' );

			default:
				return _x( 'Working', 'the result of a system check', 'kdc-wacr-recoveryflow' );
		}
	}
}
