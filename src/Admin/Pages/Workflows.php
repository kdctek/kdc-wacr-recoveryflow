<?php
/**
 * The list of recovery workflows.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Admin\Step_Describer;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Workflow\Workflow;
use WAcr\RecoveryFlow\Workflow\Workflow_Definition;
use WAcr\RecoveryFlow\Workflow\Workflow_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Every workflow on the site, as a card that reads as a sequence.
 *
 * Not a WP_List_Table. A list table is the right shape when there are hundreds
 * of rows to sort, filter and page through; a site has two workflows, or five,
 * and the question a merchant arrives with is "what does this one actually do"
 * rather than "which of these many rows is the one I want". So each workflow is
 * a card whose body is its steps in order, in words -- the same words the
 * editor uses, from Step_Describer, so that reading the list and opening the
 * editor never disagree.
 *
 * The screen is shown in full to a workspace that cannot author workflows. A
 * plan below Scale cannot use the developer API and so cannot edit or run the
 * direct-send workflow, but hiding the screen from those merchants would tell
 * them nothing about why, and hiding the direct-send workflow specifically
 * would leave them looking at a feature list that does not match the product
 * they are reading about. Instead the cards they cannot act on say so, and say
 * what would change it.
 */
final class Workflows {

	/**
	 * The workflow store.
	 *
	 * @var Workflow_Repository
	 */
	private Workflow_Repository $workflows;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Repository $workflows The workflow store.
	 */
	public function __construct( Workflow_Repository $workflows ) {
		$this->workflows = $workflows;
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_WORKFLOWS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage recovery workflows.', 'kdc-wacr-recoveryflow' ), 403 );
		}

		$workflows = $this->workflows->all();
		$editable  = Feature_Gate::is_enabled( Feature_Gate::WORKFLOW_EDITOR );

		echo '<div class="wrap recoveryflow-workflows">';
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Workflows', 'kdc-wacr-recoveryflow' ) );

		/*
		 * Always offered. This button used to be hidden whenever the workspace
		 * had no developer API, which was stricter than the rule the save path
		 * actually applies: Workflow_Form::may_write() accepts any definition
		 * whose steps do not call /v1, and a workflow of email steps calls WA.cr
		 * for nothing at all. So the screen refused to let a merchant build
		 * something the plugin was perfectly willing to save.
		 */
		printf(
			' <a href="%1$s" class="page-title-action">%2$s</a>',
			esc_url( Screen::workflow_url( 0 ) ),
			esc_html__( 'Add workflow', 'kdc-wacr-recoveryflow' )
		);

		echo '<hr class="wp-header-end">';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'A workflow is what happens after a basket is left behind: what is checked, how long is waited, and what is sent. One workflow runs for each recovery, chosen when the recovery starts, and it keeps running the version it started on even if you edit it afterwards.', 'kdc-wacr-recoveryflow' )
		);

		if ( ! $editable ) {
			$this->render_gate_notice();
		}

		if ( array() === $workflows ) {
			$this->render_empty();
			echo '</div>';

			return;
		}

		foreach ( $workflows as $workflow ) {
			$this->render_card( $workflow, $editable );
		}

		echo '</div>';
	}

	/**
	 * Say which step is refused, and what still works.
	 *
	 * The editor is not closed and never should have been described as closed.
	 * Only a step that sends a template through the WA.cr developer API is
	 * refused; waits, checks, Auto Flow hand-offs and email steps all save
	 * normally, and a workflow built from those needs no WA.cr credential.
	 *
	 * @return void
	 */
	private function render_gate_notice(): void {
		echo '<div class="notice notice-info inline recoveryflow-gate">';
		printf( '<p><strong>%s</strong></p>', esc_html__( 'One kind of step cannot be saved on this workspace', 'kdc-wacr-recoveryflow' ) );
		printf( '<p>%s</p>', esc_html( Feature_Gate::unavailable_reason() ) );
		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( Screen::settings_url( 'wacr', 'connection' ) ),
			esc_html__( 'Check the WA.cr connection', 'kdc-wacr-recoveryflow' )
		);
		echo '</div>';
	}

	/**
	 * The screen with nothing on it.
	 *
	 * Reachable only if somebody deletes both seeded workflows, which is why it
	 * offers the way back rather than merely observing that the list is empty.
	 *
	 * @return void
	 */
	private function render_empty(): void {
		echo '<div class="card recoveryflow-card">';
		printf( '<h2>%s</h2>', esc_html__( 'There are no workflows', 'kdc-wacr-recoveryflow' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'Nothing will be sent for an abandoned basket until at least one workflow exists and is active. RecoveryFlow installs two when it is activated; if both have been deleted, add one here.', 'kdc-wacr-recoveryflow' )
		);
		echo '</div>';
	}

	/**
	 * One workflow, as a card.
	 *
	 * @param Workflow $workflow The workflow.
	 * @param bool     $editable Whether the editor is available on this workspace.
	 * @return void
	 */
	private function render_card( Workflow $workflow, bool $editable ): void {
		$steps    = isset( $workflow->definition['steps'] ) && is_array( $workflow->definition['steps'] )
			? $workflow->definition['steps']
			: array();
		$can_edit = $editable || ! Workflow_Definition::needs_developer_api( $workflow->definition );
		$edit_url = Screen::workflow_url( $workflow->id );

		echo '<div class="card recoveryflow-card recoveryflow-workflow-card">';

		printf( '<h2 class="recoveryflow-workflow-name">%s</h2>', esc_html( $workflow->name ) );

		echo '<p class="recoveryflow-workflow-meta">';
		echo esc_html( $this->status_text( $workflow ) );
		echo ' &middot; ';
		printf(
			/* translators: %d: a version number. */
			esc_html__( 'version %d', 'kdc-wacr-recoveryflow' ),
			(int) $workflow->version
		);
		echo '</p>';

		if ( array() === $steps ) {
			printf( '<p>%s</p>', esc_html__( 'This workflow has no steps, so it does nothing.', 'kdc-wacr-recoveryflow' ) );
		} else {
			echo '<ol class="recoveryflow-step-summary">';

			foreach ( $steps as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}

				printf(
					'<li><span class="recoveryflow-step-kind">%1$s</span> %2$s</li>',
					esc_html( Step_Describer::label( $step ) ),
					esc_html( Step_Describer::describe( $step ) )
				);
			}

			echo '</ol>';
		}

		echo '<p class="recoveryflow-workflow-actions">';

		if ( $can_edit ) {
			printf(
				'<a href="%1$s" class="button">%2$s</a>',
				esc_url( $edit_url ),
				esc_html(
					sprintf(
						/* translators: %s: a workflow name. */
						__( 'Edit %s', 'kdc-wacr-recoveryflow' ),
						$workflow->name
					)
				)
			);
		} else {
			printf(
				'<span class="description">%s</span>',
				esc_html__( 'This workflow sends from WordPress, which this workspace\'s plan does not include, so it cannot be edited or run here.', 'kdc-wacr-recoveryflow' )
			);
		}

		echo '</p>';
		echo '</div>';
	}

	/**
	 * How a workflow's status reads.
	 *
	 * @param Workflow $workflow The workflow.
	 * @return string
	 */
	private function status_text( Workflow $workflow ): string {
		if ( Workflow::STATUS_ACTIVE !== $workflow->status ) {
			return __( 'Draft, so it never runs', 'kdc-wacr-recoveryflow' );
		}

		if ( $workflow->is_default ) {
			return __( 'Active, and used for new recoveries', 'kdc-wacr-recoveryflow' );
		}

		return __( 'Active, but not the one new recoveries start on', 'kdc-wacr-recoveryflow' );
	}
}
