<?php
/**
 * Which parts of this site RecoveryFlow watches.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Integration\Recovery_Source_Interface;
use WAcr\RecoveryFlow\Integration\Source_Registry;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * One card per integration, saying whether it is working and why not.
 *
 * An integration that is present but unavailable is shown rather than hidden.
 * "WooCommerce integration: not available, because WooCommerce is not active"
 * is an answer; a screen that simply does not mention WooCommerce leaves
 * somebody wondering whether the plugin supports it at all.
 */
final class Integrations {

	/**
	 * The registry of sources.
	 *
	 * @var Source_Registry
	 */
	private Source_Registry $sources;

	/**
	 * Constructor.
	 *
	 * @param Source_Registry $sources The registry.
	 */
	public function __construct( Source_Registry $sources ) {
		$this->sources = $sources;
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view RecoveryFlow integrations.', 'kdc-wacr-recoveryflow' ), 403 );
		}

		$sources = $this->sources->all();

		echo '<div class="wrap recoveryflow-integrations">';
		printf( '<h1>%s</h1>', esc_html__( 'Integrations', 'kdc-wacr-recoveryflow' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Where RecoveryFlow looks for something a visitor started and did not finish. Each one works on its own; you do not need all of them.', 'kdc-wacr-recoveryflow' )
		);

		if ( array() === $sources ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div></div>',
				esc_html__( 'Nothing is registered, so nothing is being watched. WooCommerce provides the integration RecoveryFlow ships with.', 'kdc-wacr-recoveryflow' )
			);

			return;
		}

		foreach ( $sources as $source ) {
			$this->card( $source );
		}

		echo '</div>';
	}

	/**
	 * One integration.
	 *
	 * @param Recovery_Source_Interface $source The source.
	 * @return void
	 */
	private function card( Recovery_Source_Interface $source ): void {
		$available = $source->is_available();
		$active    = $this->sources->is_enabled( $source->get_id() );

		printf(
			'<div class="card recoveryflow-card recoveryflow-integration recoveryflow-integration--%1$s"><h2>%2$s</h2>',
			esc_attr( $available ? 'available' : 'unavailable' ),
			esc_html( $source->get_name() )
		);

		printf( '<p>%s</p>', esc_html( $source->get_description() ) );

		printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Status:', 'kdc-wacr-recoveryflow' ),
			esc_html( $this->status_sentence( $available, $active ) )
		);

		$types = $source->get_event_types();

		if ( array() !== $types ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: a comma-separated list of the kinds of thing an integration watches. */
						__( 'Watches: %s', 'kdc-wacr-recoveryflow' ),
						implode( ', ', $types )
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * What an integration's state means, in one sentence.
	 *
	 * @param bool $available Whether its requirements are met.
	 * @param bool $active    Whether it is switched on.
	 * @return string
	 */
	private function status_sentence( bool $available, bool $active ): string {
		if ( ! $available ) {
			return __( 'Not available. Whatever this integration needs is not installed or not active on this site.', 'kdc-wacr-recoveryflow' );
		}

		return $active
			? __( 'Active. Abandoned baskets from here are being recorded.', 'kdc-wacr-recoveryflow' )
			: __( 'Available but switched off.', 'kdc-wacr-recoveryflow' );
	}
}
