<?php
/**
 * The RecoveryFlow admin menu.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Admin\Pages\Integrations;
use WAcr\RecoveryFlow\Admin\Pages\Journey_Detail;
use WAcr\RecoveryFlow\Admin\Pages\Journeys;
use WAcr\RecoveryFlow\Admin\Pages\Overview;
use WAcr\RecoveryFlow\Admin\Pages\System_Status;
use WAcr\RecoveryFlow\Admin\Pages\Workflow_Edit;
use WAcr\RecoveryFlow\Admin\Pages\Workflows;
use WAcr\RecoveryFlow\Admin\Settings\Page as Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's screens and remembers which ones are ours.
 *
 * Each entry names the capability WordPress should check before showing it,
 * taken from Screen rather than repeated here, so the menu and the screen it
 * opens can never disagree about who is allowed in.
 *
 * The hook suffixes WordPress hands back are kept, because they are the only
 * reliable way to tell "this request is on a RecoveryFlow screen" later. Asset
 * loading depends on that answer: a plugin that enqueues its stylesheet on
 * every admin page is a plugin that breaks somebody else's.
 */
final class Menu {

	/**
	 * Hook suffixes of the screens this plugin registered.
	 *
	 * @var string[]
	 */
	private array $hooks = array();

	/**
	 * The overview screen.
	 *
	 * @var Overview
	 */
	private Overview $overview;

	/**
	 * The journeys screen.
	 *
	 * @var Journeys
	 */
	private Journeys $journeys;

	/**
	 * One journey in full.
	 *
	 * @var Journey_Detail
	 */
	private Journey_Detail $journey;

	/**
	 * The integrations screen.
	 *
	 * @var Integrations
	 */
	private Integrations $integrations;

	/**
	 * The status screen.
	 *
	 * @var System_Status
	 */
	private System_Status $status;

	/**
	 * The workflows screen.
	 *
	 * @var Workflows
	 */
	private Workflows $workflows;

	/**
	 * One workflow's editor.
	 *
	 * @var Workflow_Edit
	 */
	private Workflow_Edit $workflow;

	/**
	 * The first-run setup screen.
	 *
	 * @var Setup
	 */
	private Setup $setup;

	/**
	 * Constructor.
	 *
	 * @param Overview       $overview     Overview screen.
	 * @param Journeys       $journeys     Journeys screen.
	 * @param Journey_Detail $journey      Journey detail screen.
	 * @param Integrations   $integrations Integrations screen.
	 * @param System_Status  $status       Status screen.
	 * @param Workflows      $workflows    Workflows screen.
	 * @param Workflow_Edit  $workflow     One workflow's editor.
	 * @param Setup          $setup        First-run setup screen.
	 */
	public function __construct(
		Overview $overview,
		Journeys $journeys,
		Journey_Detail $journey,
		Integrations $integrations,
		System_Status $status,
		Workflows $workflows,
		Workflow_Edit $workflow,
		Setup $setup
	) {
		$this->overview     = $overview;
		$this->journeys     = $journeys;
		$this->journey      = $journey;
		$this->integrations = $integrations;
		$this->status       = $status;
		$this->workflows    = $workflows;
		$this->workflow     = $workflow;
		$this->setup        = $setup;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_init', array( Settings_Page::class, 'register' ) );
	}

	/**
	 * Register the menu and its screens.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->hooks = array();

		$this->remember(
			add_menu_page(
				__( 'RecoveryFlow', 'kdc-wacr-recoveryflow' ),
				__( 'RecoveryFlow', 'kdc-wacr-recoveryflow' ),
				Screen::capability( Screen::OVERVIEW ),
				Screen::OVERVIEW,
				array( $this->overview, 'render' ),
				'dashicons-cart',
				57
			)
		);

		foreach ( $this->submenus() as $slug => $submenu ) {
			$this->remember(
				add_submenu_page(
					Screen::OVERVIEW,
					$submenu['page_title'],
					$submenu['menu_title'],
					Screen::capability( $slug ),
					$slug,
					$submenu['callback']
				)
			);
		}

		/*
		 * One journey's own screen, registered with no parent so it has an
		 * address and a capability check but no menu entry of its own. A
		 * submenu item for "a recovery" would be meaningless: it is always
		 * reached from the list.
		 */
		$this->remember(
			add_submenu_page(
				'',
				__( 'Recovery', 'kdc-wacr-recoveryflow' ),
				__( 'Recovery', 'kdc-wacr-recoveryflow' ),
				Screen::capability( Screen::JOURNEY ),
				Screen::JOURNEY,
				array( $this->journey, 'render' )
			)
		);

		/*
		 * One workflow's editor, with no menu entry of its own: it is always
		 * reached from the list, and "Workflows" plus "A workflow" as two
		 * neighbouring menu items would be a menu that explains nothing.
		 */
		$this->remember(
			add_submenu_page(
				'',
				__( 'Edit workflow', 'kdc-wacr-recoveryflow' ),
				__( 'Edit workflow', 'kdc-wacr-recoveryflow' ),
				Screen::capability( Screen::WORKFLOW ),
				Screen::WORKFLOW,
				array( $this->workflow, 'render' )
			)
		);

		/*
		 * The setup screen, likewise with no menu entry: it is reached by being
		 * sent there on activation, and from the status screen when the
		 * connection is what is wrong. Leaving a permanent "Setup" item in the
		 * menu of a plugin that has been running for a year says the setup
		 * never finished.
		 */
		$this->remember(
			add_submenu_page(
				'',
				__( 'Set RecoveryFlow up', 'kdc-wacr-recoveryflow' ),
				__( 'Set RecoveryFlow up', 'kdc-wacr-recoveryflow' ),
				Screen::capability( Screen::SETUP ),
				Screen::SETUP,
				array( $this->setup, 'render' )
			)
		);
	}

	/**
	 * The submenu entries, in the order they are shown.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function submenus(): array {
		return array(
			Screen::OVERVIEW     => array(
				'page_title' => __( 'RecoveryFlow', 'kdc-wacr-recoveryflow' ),
				'menu_title' => __( 'Overview', 'kdc-wacr-recoveryflow' ),
				'callback'   => array( $this->overview, 'render' ),
			),
			Screen::JOURNEYS     => array(
				'page_title' => __( 'Recoveries', 'kdc-wacr-recoveryflow' ),
				'menu_title' => __( 'Recoveries', 'kdc-wacr-recoveryflow' ),
				'callback'   => array( $this->journeys, 'render' ),
			),
			Screen::WORKFLOWS    => array(
				'page_title' => __( 'Recovery workflows', 'kdc-wacr-recoveryflow' ),
				'menu_title' => __( 'Workflows', 'kdc-wacr-recoveryflow' ),
				'callback'   => array( $this->workflows, 'render' ),
			),
			Screen::INTEGRATIONS => array(
				'page_title' => __( 'Integrations', 'kdc-wacr-recoveryflow' ),
				'menu_title' => __( 'Integrations', 'kdc-wacr-recoveryflow' ),
				'callback'   => array( $this->integrations, 'render' ),
			),
			Screen::SETTINGS     => array(
				'page_title' => __( 'RecoveryFlow settings', 'kdc-wacr-recoveryflow' ),
				'menu_title' => __( 'Settings', 'kdc-wacr-recoveryflow' ),
				'callback'   => array( Settings_Page::class, 'render' ),
			),
			Screen::STATUS       => array(
				'page_title' => __( 'RecoveryFlow status', 'kdc-wacr-recoveryflow' ),
				'menu_title' => __( 'Status', 'kdc-wacr-recoveryflow' ),
				'callback'   => array( $this->status, 'render' ),
			),
		);
	}

	/**
	 * Keep a hook suffix WordPress handed back.
	 *
	 * WordPress returns false from add_submenu_page() when the current user may
	 * not see the screen, which is not an error -- it is the check working. Only
	 * a real suffix is kept, so asset loading is asked about screens that
	 * actually exist for this user.
	 *
	 * @param string|false $suffix What WordPress returned.
	 * @return void
	 */
	private function remember( $suffix ): void {
		if ( is_string( $suffix ) && '' !== $suffix ) {
			$this->hooks[] = $suffix;
		}
	}

	/**
	 * The hook suffixes of this plugin's screens.
	 *
	 * @return string[]
	 */
	public function hook_suffixes(): array {
		return $this->hooks;
	}

	/**
	 * Whether a hook suffix belongs to one of this plugin's screens.
	 *
	 * @param string $hook Hook suffix WordPress passed to admin_enqueue_scripts.
	 * @return bool
	 */
	public function owns( string $hook ): bool {
		return in_array( $hook, $this->hooks, true );
	}
}
