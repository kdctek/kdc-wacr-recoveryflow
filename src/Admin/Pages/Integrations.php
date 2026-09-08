<?php
/**
 * Which parts of this site RecoveryFlow watches.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Core\Feature_Gate;
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
 *
 * Every sentence here comes from Source_Registry::status(), which is also what
 * decides whether the source's hooks are attached. That is not tidiness. This
 * screen used to work out its own answer from two of the three facts the
 * registry considers, and so announced "Active. Abandoned baskets from here are
 * being recorded." about an integration that was installed, switched on, and
 * excluded by the plan -- watching nothing at all. Both sentences were true of
 * what each one read; neither was true of the site. Asking the same method the
 * behaviour asks is the only arrangement in which the screen cannot be
 * confidently wrong.
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
		$id     = $source->get_id();
		$status = $this->sources->status( $id );

		printf(
			'<div class="card recoveryflow-card recoveryflow-integration recoveryflow-integration--%1$s"><h2>%2$s</h2>',
			esc_attr( str_replace( '_', '-', $status ) ),
			esc_html( $source->get_name() )
		);

		printf( '<p>%s</p>', esc_html( $source->get_description() ) );

		printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Status:', 'kdc-wacr-recoveryflow' ),
			esc_html( $this->status_sentence( $status ) )
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

		// The switch, and any settings the adapter declares, are fields in the
		// settings tree like everything else, so the card links to them rather
		// than growing a second form with a second set of rules about what a
		// valid setting is.
		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( Screen::settings_url( 'sources', $id, Source_Registry::enabled_key( $id ) ) ),
			esc_html(
				sprintf(
					/* translators: %s: the name of an integration, for example WooCommerce. */
					__( 'Settings for %s', 'kdc-wacr-recoveryflow' ),
					$source->get_name()
				)
			)
		);

		echo '</div>';
	}

	/**
	 * What an integration's state means, in one sentence.
	 *
	 * @param string $status One of the Source_Registry constants.
	 * @return string
	 */
	private function status_sentence( string $status ): string {
		switch ( $status ) {
			case Source_Registry::UNAVAILABLE:
				return __( 'Not available. Whatever this integration needs is not installed or not active on this site.', 'kdc-wacr-recoveryflow' );

			case Source_Registry::SWITCHED_OFF:
				return __( 'Available, but switched off here. Nothing from this integration is being recorded.', 'kdc-wacr-recoveryflow' );

			case Source_Registry::NOT_INCLUDED:
				return __( 'Installed and switched on, but not included in this WA.cr plan, so nothing from it is being recorded. Integrations beyond WooCommerce are included with the WA.cr Scale plan and above.', 'kdc-wacr-recoveryflow' );

			default:
				return __( 'Active. Abandoned baskets from here are being recorded.', 'kdc-wacr-recoveryflow' );
		}
	}
}
