<?php
/**
 * The recovery queue screen.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Customer\Customer_Repository;
use WAcr\RecoveryFlow\Recovery\Event_Repository;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the list table in a screen.
 *
 * The one thing worth noting is the require: WP_List_Table lives in
 * wp-admin/includes and is not loaded on every admin request, so the subclass
 * has to be reached only after it exists. Loading it here, immediately before
 * the autoloader is asked for the subclass, is what keeps a fatal out of any
 * request that merely touches this file's class name.
 */
final class Journeys {

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
	 * Customer storage.
	 *
	 * @var Customer_Repository
	 */
	private Customer_Repository $customers;

	/**
	 * Constructor.
	 *
	 * @param Journey_Repository  $journeys  Journey storage.
	 * @param Event_Repository    $events    Event storage.
	 * @param Customer_Repository $customers Customer storage.
	 */
	public function __construct( Journey_Repository $journeys, Event_Repository $events, Customer_Repository $customers ) {
		$this->journeys  = $journeys;
		$this->events    = $events;
		$this->customers = $customers;
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_JOURNEYS ) ) {
			wp_die( esc_html__( 'You do not have permission to view recoveries.', 'kdc-wacr-recoveryflow' ), 403 );
		}

		if ( ! class_exists( '\WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new Journeys_Table( $this->journeys, $this->events, $this->customers );
		$table->prepare_items();

		echo '<div class="wrap recoveryflow-journeys">';
		printf( '<h1>%s</h1>', esc_html__( 'Recoveries', 'kdc-wacr-recoveryflow' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Every basket a shopper left behind, and what has been done about it. Contact details are shortened here; open a recovery to see them in full.', 'kdc-wacr-recoveryflow' )
		);

		$table->views();

		printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( Screen::JOURNEYS ) );

		$table->search_box( __( 'Search by reference', 'kdc-wacr-recoveryflow' ), 'recoveryflow-search' );
		$table->display();

		echo '</form>';
		echo '</div>';
	}
}
