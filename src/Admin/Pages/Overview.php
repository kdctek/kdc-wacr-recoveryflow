<?php
/**
 * The landing screen.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Core\Health;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * What is happening, and what needs attention.
 *
 * Deliberately short. A first screen that tries to be a dashboard becomes a
 * screen nobody reads, and the two questions somebody actually opens this to
 * answer are "is it working" and "is it recovering anything". Everything else
 * is a link away.
 *
 * The counters are read straight from the journeys table with an indexed GROUP
 * BY. There is no cache, because a wrong number on a screen somebody opened to
 * check whether recovery is running is worse than the query.
 */
final class Overview {

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * The health checks.
	 *
	 * @var Health
	 */
	private Health $health;

	/**
	 * Constructor.
	 *
	 * @param Journey_Repository $journeys Journey storage.
	 * @param Health             $health   The health checks.
	 */
	public function __construct( Journey_Repository $journeys, Health $health ) {
		$this->journeys = $journeys;
		$this->health   = $health;
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_STATUS ) ) {
			wp_die( esc_html__( 'You do not have permission to view RecoveryFlow.', 'kdc-wacr-recoveryflow' ), 403 );
		}

		$counts = $this->journeys->counts_by_status();

		echo '<div class="wrap recoveryflow-overview">';
		printf( '<h1>%s</h1>', esc_html__( 'RecoveryFlow', 'kdc-wacr-recoveryflow' ) );

		$this->attention();
		$this->numbers( $counts );

		echo '</div>';
	}

	/**
	 * Anything standing between this site and working recovery.
	 *
	 * Only the checks that did not pass. A list of ticks is something people
	 * learn to scroll past, and the one line that matters is then in the middle
	 * of it.
	 *
	 * @return void
	 */
	private function attention(): void {
		$problems = array();

		foreach ( $this->health->checks() as $check ) {
			if ( Health::OK !== $check['severity'] ) {
				$problems[] = $check;
			}
		}

		printf( '<h2>%s</h2>', esc_html__( 'Needs attention', 'kdc-wacr-recoveryflow' ) );

		if ( array() === $problems ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html__( 'Everything is set up and running.', 'kdc-wacr-recoveryflow' )
			);

			return;
		}

		echo '<div class="card recoveryflow-card"><ul class="recoveryflow-attention">';

		foreach ( $problems as $check ) {
			printf(
				'<li class="recoveryflow-attention__item recoveryflow-attention__item--%1$s"><strong>%2$s</strong> %3$s%4$s</li>',
				esc_attr( (string) $check['severity'] ),
				esc_html(
					Health::ERROR === $check['severity']
						/* translators: prefixes a problem that stops recovery working. */
						? __( 'Stopped:', 'kdc-wacr-recoveryflow' )
						/* translators: prefixes something working but perhaps not as expected. */
						: __( 'Check:', 'kdc-wacr-recoveryflow' )
				),
				esc_html( (string) $check['message'] ),
				'' === (string) $check['link']
					? ''
					: sprintf(
						' <a href="%1$s">%2$s</a>',
						esc_url( (string) $check['link'] ),
						esc_html__( 'Go to this setting', 'kdc-wacr-recoveryflow' )
					)
			);
		}//end foreach

		echo '</ul></div>';
	}

	/**
	 * How much is in flight, and how much came back.
	 *
	 * @param array<string,int> $counts Journey counts by state.
	 * @return void
	 */
	private function numbers( array $counts ): void {
		$active = 0;

		foreach ( Journey_State::active() as $state ) {
			$active += $counts[ $state ] ?? 0;
		}

		$rows = array(
			array(
				'label'  => __( 'Being chased now', 'kdc-wacr-recoveryflow' ),
				'value'  => $active,
				'status' => '',
			),
			array(
				'label'  => __( 'Recovered', 'kdc-wacr-recoveryflow' ),
				'value'  => $counts[ Journey_State::RECOVERED ] ?? 0,
				'status' => Journey_State::RECOVERED,
			),
			array(
				'label'  => __( 'Expired without an order', 'kdc-wacr-recoveryflow' ),
				'value'  => $counts[ Journey_State::EXPIRED ] ?? 0,
				'status' => Journey_State::EXPIRED,
			),
			array(
				'label'  => __( 'Asked not to be messaged', 'kdc-wacr-recoveryflow' ),
				'value'  => $counts[ Journey_State::OPTED_OUT ] ?? 0,
				'status' => Journey_State::OPTED_OUT,
			),
		);

		printf( '<h2>%s</h2>', esc_html__( 'Recoveries', 'kdc-wacr-recoveryflow' ) );

		echo '<div class="card recoveryflow-card"><table class="widefat striped recoveryflow-counts"><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				'' === $row['status']
					? esc_html( $row['label'] )
					: sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( Screen::url( Screen::JOURNEYS, array( 'status' => $row['status'] ) ) ),
						esc_html( $row['label'] )
					),
				esc_html( number_format_i18n( $row['value'] ) )
			);
		}

		echo '</tbody></table></div>';

		printf(
			'<p><a href="%1$s" class="button button-primary">%2$s</a> <a href="%3$s" class="button">%4$s</a></p>',
			esc_url( Screen::url( Screen::JOURNEYS ) ),
			esc_html__( 'View all recoveries', 'kdc-wacr-recoveryflow' ),
			esc_url( Screen::settings_url() ),
			esc_html__( 'Settings', 'kdc-wacr-recoveryflow' )
		);
	}
}
