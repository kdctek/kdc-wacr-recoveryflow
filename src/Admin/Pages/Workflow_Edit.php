<?php
/**
 * One workflow's editor.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Admin\Step_Describer;
use WAcr\RecoveryFlow\Admin\Workflow_Form;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Workflow\Step_Registry;
use WAcr\RecoveryFlow\Workflow\Workflow;
use WAcr\RecoveryFlow\Workflow\Workflow_Definition;
use WAcr\RecoveryFlow\Workflow\Workflow_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Building a recovery sequence without writing any.
 *
 * The screen is one form of server-rendered HTML. Adding a step, removing one
 * and moving one are submit buttons, so each costs a page load and each works
 * with scripts switched off -- which is the point. A merchant configuring the
 * one feature that sends messages to their customers should not be able to
 * lose that configuration to a blocked script.
 *
 * Every choice is a select or a number, never free text that has to parse:
 * conditions and actions come from the registry, so a step can only name
 * something that exists; stop states come from the journey state machine;
 * waits are a number and a unit assembled into an ISO duration rather than an
 * ISO duration typed by hand. The validator still refuses bad definitions,
 * because a filter can register a condition that later goes away and because a
 * form is not a security boundary -- but a merchant should not meet the
 * validator in normal use, and if the only way to fail is to be told off, the
 * screen has already failed.
 *
 * Each step is a <details> panel: native, keyboard-reachable, needing no ARIA
 * and no script, and open by default so nothing is hidden from somebody who
 * arrived to read rather than to edit.
 */
final class Workflow_Edit {

	/**
	 * The workflow store.
	 *
	 * @var Workflow_Repository
	 */
	private Workflow_Repository $workflows;

	/**
	 * The step registry.
	 *
	 * @var Step_Registry
	 */
	private Step_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Repository $workflows The workflow store.
	 * @param Step_Registry       $registry  The step registry.
	 */
	public function __construct( Workflow_Repository $workflows, Step_Registry $registry ) {
		$this->workflows = $workflows;
		$this->registry  = $registry;
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_WORKFLOWS ) ) {
			wp_die( esc_html__( 'You do not have permission to edit recovery workflows.', 'kdc-wacr-recoveryflow' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which workflow to show, not acting.
		$id       = isset( $_GET['workflow'] ) ? absint( $_GET['workflow'] ) : 0;
		$workflow = 0 === $id ? null : $this->workflows->find( $id );
		$draft    = Workflow_Form::draft();

		if ( 0 !== $id && null === $workflow ) {
			$this->render_missing();

			return;
		}

		$definition = $this->definition_for( $workflow, $draft );

		echo '<div class="wrap recoveryflow-workflow-edit">';
		printf(
			'<h1>%s</h1>',
			esc_html(
				null === $workflow
					? __( 'Add workflow', 'kdc-wacr-recoveryflow' )
					: __( 'Edit workflow', 'kdc-wacr-recoveryflow' )
			)
		);

		$this->render_result();

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The steps run in order, from the top, once a basket has been abandoned long enough to count. A recovery that is already running keeps the version of this workflow it started on, so saving here never changes what is already in flight.', 'kdc-wacr-recoveryflow' )
		);

		printf(
			'<form method="post" action="%s" class="recoveryflow-workflow-form">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( Workflow_Form::ACTION );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( Workflow_Form::ACTION ) );
		printf( '<input type="hidden" name="workflow_id" value="%d">', (int) $id );

		$this->render_details( $definition, $workflow );
		$this->render_steps( $definition );
		$this->render_actions();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * A workflow that is not there.
	 *
	 * @return void
	 */
	private function render_missing(): void {
		echo '<div class="wrap recoveryflow-workflow-edit">';
		printf( '<h1>%s</h1>', esc_html__( 'Workflow not found', 'kdc-wacr-recoveryflow' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'This workflow no longer exists. It may have been deleted since the link you followed was made.', 'kdc-wacr-recoveryflow' )
		);
		printf(
			'<p><a href="%1$s" class="button">%2$s</a></p>',
			esc_url( Screen::url( Screen::WORKFLOWS ) ),
			esc_html__( 'Back to workflows', 'kdc-wacr-recoveryflow' )
		);
		echo '</div>';
	}

	/**
	 * Say how the last save went.
	 *
	 * @return void
	 */
	private function render_result(): void {
		$result = Workflow_Form::result();

		if ( null === $result ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			$result['ok'] ? 'success' : 'error',
			esc_html( $result['message'] )
		);
	}

	/**
	 * Which definition to draw.
	 *
	 * An unsaved edit wins over the stored one, because it is what the person
	 * was looking at a moment ago: sending somebody back to the stored version
	 * after a refusal throws away the work they were doing and tells them the
	 * refusal was about something they can no longer see.
	 *
	 * @param Workflow|null            $workflow The stored workflow, if any.
	 * @param array<string,mixed>|null $draft    An unsaved edit, if any.
	 * @return array<string,mixed>
	 */
	private function definition_for( ?Workflow $workflow, ?array $draft ): array {
		if ( null !== $draft ) {
			return $draft;
		}

		if ( null !== $workflow ) {
			return $workflow->definition;
		}

		return array(
			'name'  => '',
			'steps' => array(
				array(
					'type' => Workflow_Definition::TYPE_CONDITION,
					'if'   => 'journey.not_completed',
					'else' => 'stop:recovered',
				),
			),
		);
	}

	/**
	 * The name and the two switches that decide whether it runs.
	 *
	 * @param array<string,mixed> $definition The definition being edited.
	 * @param Workflow|null       $workflow   The stored workflow, if any.
	 * @return void
	 */
	private function render_details( array $definition, ?Workflow $workflow ): void {
		$active     = null === $workflow || Workflow::STATUS_ACTIVE === $workflow->status;
		$is_default = null !== $workflow && $workflow->is_default;

		echo '<div class="card recoveryflow-card">';
		printf( '<h2>%s</h2>', esc_html__( 'This workflow', 'kdc-wacr-recoveryflow' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">';
		printf( '<label for="recoveryflow-workflow-name">%s</label>', esc_html__( 'Name', 'kdc-wacr-recoveryflow' ) );
		echo '</th><td>';
		printf(
			'<input type="text" id="recoveryflow-workflow-name" name="workflow_name" value="%1$s" class="regular-text" maxlength="191" required aria-describedby="recoveryflow-workflow-name-help">',
			esc_attr( (string) ( $definition['name'] ?? '' ) )
		);
		printf(
			'<p class="description" id="recoveryflow-workflow-name-help">%s</p>',
			esc_html__( 'Only you and your colleagues see this. It appears on the workflows list and on each recovery, so name it after what it does.', 'kdc-wacr-recoveryflow' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'When it runs', 'kdc-wacr-recoveryflow' ) . '</th><td>';
		echo '<fieldset>';

		printf(
			'<label class="recoveryflow-checkbox" for="recoveryflow-workflow-active"><input type="checkbox" id="recoveryflow-workflow-active" name="workflow_active" value="1"%1$s> <span>%2$s</span></label>',
			checked( $active, true, false ),
			esc_html__( 'Active. An inactive workflow is kept but never runs.', 'kdc-wacr-recoveryflow' )
		);

		printf(
			'<label class="recoveryflow-checkbox" for="recoveryflow-workflow-default"><input type="checkbox" id="recoveryflow-workflow-default" name="workflow_default" value="1"%1$s> <span>%2$s</span></label>',
			checked( $is_default, true, false ),
			esc_html__( 'Use this for new recoveries. Only one workflow can be, so choosing this takes it off whichever has it now.', 'kdc-wacr-recoveryflow' )
		);

		echo '</fieldset></td></tr>';
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Every step, in order.
	 *
	 * @param array<string,mixed> $definition The definition being edited.
	 * @return void
	 */
	private function render_steps( array $definition ): void {
		$steps = isset( $definition['steps'] ) && is_array( $definition['steps'] ) ? array_values( $definition['steps'] ) : array();
		$last  = count( $steps ) - 1;

		printf( '<h2>%s</h2>', esc_html__( 'Steps', 'kdc-wacr-recoveryflow' ) );

		if ( array() === $steps ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'This workflow has no steps yet, so it would do nothing. Add one below.', 'kdc-wacr-recoveryflow' )
			);
		}

		foreach ( $steps as $index => $step ) {
			$this->render_step( (int) $index, is_array( $step ) ? $step : array(), $last );
		}

		printf(
			'<p><button type="submit" name="add_step" value="1" class="button">%s</button></p>',
			esc_html__( 'Add a step', 'kdc-wacr-recoveryflow' )
		);
	}

	/**
	 * One step, as an expandable panel.
	 *
	 * @param int                 $index Zero-based position.
	 * @param array<string,mixed> $step  The step.
	 * @param int                 $last  Index of the last step.
	 * @return void
	 */
	private function render_step( int $index, array $step, int $last ): void {
		$type   = isset( $step['type'] ) ? (string) $step['type'] : Workflow_Definition::TYPE_WAIT;
		$number = $index + 1;

		echo '<details class="card recoveryflow-card recoveryflow-details recoveryflow-step" open>';

		printf(
			'<summary>%1$s</summary>',
			esc_html(
				sprintf(
					/* translators: 1: a step number, 2: what the step does, in a sentence. */
					__( 'Step %1$d. %2$s', 'kdc-wacr-recoveryflow' ),
					$number,
					Step_Describer::describe( $step )
				)
			)
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">';
		printf(
			'<label for="recoveryflow-step-%1$d-type">%2$s</label>',
			(int) $index,
			esc_html__( 'This step', 'kdc-wacr-recoveryflow' )
		);
		echo '</th><td>';
		$this->render_select(
			sprintf( 'step[%d][type]', $index ),
			sprintf( 'recoveryflow-step-%d-type', $index ),
			array(
				Workflow_Definition::TYPE_CONDITION => __( 'Checks something, and can stop the recovery', 'kdc-wacr-recoveryflow' ),
				Workflow_Definition::TYPE_WAIT      => __( 'Waits', 'kdc-wacr-recoveryflow' ),
				Workflow_Definition::TYPE_ACTION    => __( 'Sends something', 'kdc-wacr-recoveryflow' ),
			),
			$type
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Change this, then use Save to redraw the step with the right settings.', 'kdc-wacr-recoveryflow' )
		);
		echo '</td></tr>';

		if ( Workflow_Definition::TYPE_CONDITION === $type ) {
			$this->render_condition_fields( $index, $step );
		} elseif ( Workflow_Definition::TYPE_WAIT === $type ) {
			$this->render_wait_fields( $index, $step );
		} elseif ( Workflow_Definition::TYPE_ACTION === $type ) {
			$this->render_action_fields( $index, $step );
		}

		echo '</tbody></table>';

		echo '<p class="recoveryflow-step-controls">';

		if ( $index > 0 ) {
			printf(
				'<button type="submit" name="move_up" value="%1$d" class="button">%2$s</button> ',
				(int) $index,
				esc_html(
					sprintf(
						/* translators: %d: a step number. */
						__( 'Move step %d up', 'kdc-wacr-recoveryflow' ),
						$number
					)
				)
			);
		}

		if ( $index < $last ) {
			printf(
				'<button type="submit" name="move_down" value="%1$d" class="button">%2$s</button> ',
				(int) $index,
				esc_html(
					sprintf(
						/* translators: %d: a step number. */
						__( 'Move step %d down', 'kdc-wacr-recoveryflow' ),
						$number
					)
				)
			);
		}

		printf(
			'<button type="submit" name="remove_step" value="%1$d" class="button button-link-delete">%2$s</button>',
			(int) $index,
			esc_html(
				sprintf(
					/* translators: %d: a step number. */
					__( 'Remove step %d', 'kdc-wacr-recoveryflow' ),
					$number
				)
			)
		);

		echo '</p>';
		echo '</details>';
	}

	/**
	 * The two choices a condition step needs.
	 *
	 * @param int                 $index Zero-based position.
	 * @param array<string,mixed> $step  The step.
	 * @return void
	 */
	private function render_condition_fields( int $index, array $step ): void {
		$conditions = array();

		foreach ( $this->registry->conditions() as $name => $condition ) {
			unset( $condition );
			$conditions[ $name ] = Step_Describer::condition_label( (string) $name );
		}

		$stops = array( '' => __( 'Carry on to the next step', 'kdc-wacr-recoveryflow' ) );

		foreach ( Workflow_Definition::stop_states() as $state ) {
			$stops[ 'stop:' . $state ] = Step_Describer::stop_label( (string) $state );
		}

		echo '<tr><th scope="row">';
		printf( '<label for="recoveryflow-step-%1$d-if">%2$s</label>', (int) $index, esc_html__( 'Check that', 'kdc-wacr-recoveryflow' ) );
		echo '</th><td>';
		$this->render_select(
			sprintf( 'step[%d][if]', $index ),
			sprintf( 'recoveryflow-step-%d-if', $index ),
			$conditions,
			isset( $step['if'] ) ? Workflow_Definition::condition_name( (string) $step['if'] ) : ''
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">';
		printf( '<label for="recoveryflow-step-%1$d-else">%2$s</label>', (int) $index, esc_html__( 'If it is not true', 'kdc-wacr-recoveryflow' ) );
		echo '</th><td>';
		$this->render_select(
			sprintf( 'step[%d][else]', $index ),
			sprintf( 'recoveryflow-step-%d-else', $index ),
			$stops,
			isset( $step['else'] ) ? (string) $step['else'] : ''
		);
		echo '</td></tr>';
	}

	/**
	 * How long a wait step waits.
	 *
	 * @param int                 $index Zero-based position.
	 * @param array<string,mixed> $step  The step.
	 * @return void
	 */
	private function render_wait_fields( int $index, array $step ): void {
		$split = Workflow_Form::split_duration( isset( $step['for'] ) ? (string) $step['for'] : '' );

		echo '<tr><th scope="row">';
		printf( '<label for="recoveryflow-step-%1$d-amount">%2$s</label>', (int) $index, esc_html__( 'Wait for', 'kdc-wacr-recoveryflow' ) );
		echo '</th><td>';
		printf(
			'<input type="number" id="recoveryflow-step-%1$d-amount" name="step[%1$d][wait_amount]" value="%2$d" min="1" max="43200" step="1" class="small-text" aria-describedby="recoveryflow-step-%1$d-wait-help"> ',
			(int) $index,
			(int) $split['amount']
		);
		$this->render_select(
			sprintf( 'step[%d][wait_unit]', $index ),
			sprintf( 'recoveryflow-step-%d-unit', $index ),
			array(
				'minutes' => __( 'minutes', 'kdc-wacr-recoveryflow' ),
				'hours'   => __( 'hours', 'kdc-wacr-recoveryflow' ),
				'days'    => __( 'days', 'kdc-wacr-recoveryflow' ),
			),
			(string) $split['unit'],
			__( 'Unit of time', 'kdc-wacr-recoveryflow' )
		);
		printf(
			'<p class="description" id="recoveryflow-step-%1$d-wait-help">%2$s</p>',
			(int) $index,
			esc_html__( 'At least one minute and at most 30 days. Quiet hours can push a send later than this, but never earlier.', 'kdc-wacr-recoveryflow' )
		);
		echo '</td></tr>';
	}

	/**
	 * What an action step sends, and over what.
	 *
	 * @param int                 $index Zero-based position.
	 * @param array<string,mixed> $step  The step.
	 * @return void
	 */
	private function render_action_fields( int $index, array $step ): void {
		$actions = array();

		foreach ( $this->registry->actions() as $name => $action ) {
			unset( $action );
			$actions[ $name ] = Step_Describer::action_label( (string) $name );
		}

		$chosen = isset( $step['do'] ) ? (string) $step['do'] : '';
		$with   = isset( $step['with'] ) && is_array( $step['with'] ) ? $step['with'] : array();

		echo '<tr><th scope="row">';
		printf( '<label for="recoveryflow-step-%1$d-do">%2$s</label>', (int) $index, esc_html__( 'Send by', 'kdc-wacr-recoveryflow' ) );
		echo '</th><td>';
		$this->render_select(
			sprintf( 'step[%d][do]', $index ),
			sprintf( 'recoveryflow-step-%d-do', $index ),
			$actions,
			$chosen
		);

		if ( ! Feature_Gate::is_enabled( Feature_Gate::WORKFLOW_EDITOR ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Only handing the recovery to a WA.cr Auto Flow can be saved on this workspace\'s plan. It needs no API key and works on every plan.', 'kdc-wacr-recoveryflow' )
			);
		}

		echo '</td></tr>';

		if ( 'wacr.send_template' === $chosen ) {
			echo '<tr><th scope="row">';
			printf( '<label for="recoveryflow-step-%1$d-channel">%2$s</label>', (int) $index, esc_html__( 'Over', 'kdc-wacr-recoveryflow' ) );
			echo '</th><td>';
			$this->render_select(
				sprintf( 'step[%d][channel]', $index ),
				sprintf( 'recoveryflow-step-%d-channel', $index ),
				array(
					Workflow_Definition::CHANNEL_WHATSAPP => Step_Describer::channel( Workflow_Definition::CHANNEL_WHATSAPP ),
					Workflow_Definition::CHANNEL_EMAIL    => Step_Describer::channel( Workflow_Definition::CHANNEL_EMAIL ),
				),
				Workflow_Definition::channel_for( $step )
			);
			echo '</td></tr>';

			echo '<tr><th scope="row">';
			printf( '<label for="recoveryflow-step-%1$d-template">%2$s</label>', (int) $index, esc_html__( 'Template', 'kdc-wacr-recoveryflow' ) );
			echo '</th><td>';
			printf(
				'<input type="text" id="recoveryflow-step-%1$d-template" name="step[%1$d][template]" value="%2$s" class="regular-text" aria-describedby="recoveryflow-step-%1$d-template-help">',
				(int) $index,
				esc_attr( (string) ( $with['template'] ?? '' ) )
			);
			printf(
				'<p class="description" id="recoveryflow-step-%1$d-template-help">%2$s</p>',
				(int) $index,
				esc_html__( 'The name of an approved template in your WA.cr workspace. A template that is not approved is refused when the message is sent, not when you save.', 'kdc-wacr-recoveryflow' )
			);
			echo '</td></tr>';
		}//end if

		if ( 'wacr.start_flow' === $chosen ) {
			echo '<tr><th scope="row">';
			printf( '<label for="recoveryflow-step-%1$d-hook">%2$s</label>', (int) $index, esc_html__( 'Auto Flow hook', 'kdc-wacr-recoveryflow' ) );
			echo '</th><td>';
			printf(
				'<input type="text" id="recoveryflow-step-%1$d-hook" name="step[%1$d][hook]" value="%2$s" class="regular-text" aria-describedby="recoveryflow-step-%1$d-hook-help">',
				(int) $index,
				esc_attr( (string) ( $with['hook'] ?? 'primary' ) )
			);
			printf(
				'<p class="description" id="recoveryflow-step-%1$d-hook-help">%2$s</p>',
				(int) $index,
				esc_html__( 'Which configured hook to push to. Leave this as primary unless you have set up more than one.', 'kdc-wacr-recoveryflow' )
			);
			echo '</td></tr>';
		}
	}

	/**
	 * Save, and the way back.
	 *
	 * @return void
	 */
	private function render_actions(): void {
		echo '<p class="submit">';
		printf(
			'<button type="submit" name="save_workflow" value="1" class="button button-primary">%s</button> ',
			esc_html__( 'Save workflow', 'kdc-wacr-recoveryflow' )
		);
		printf(
			'<a href="%1$s" class="button button-secondary">%2$s</a>',
			esc_url( Screen::url( Screen::WORKFLOWS ) ),
			esc_html__( 'Back to workflows', 'kdc-wacr-recoveryflow' )
		);
		echo '</p>';
	}

	/**
	 * A labelled select.
	 *
	 * @param string               $name    Field name.
	 * @param string               $id      Element id.
	 * @param array<string,string> $options Value => label.
	 * @param string               $chosen  Selected value.
	 * @param string               $hidden  An accessible name, when no visible label points here.
	 * @return void
	 */
	private function render_select( string $name, string $id, array $options, string $chosen, string $hidden = '' ): void {
		printf(
			'<select name="%1$s" id="%2$s"%3$s>',
			esc_attr( $name ),
			esc_attr( $id ),
			'' === $hidden ? '' : sprintf( ' aria-label="%s"', esc_attr( $hidden ) )
		);

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( (string) $value, $chosen, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';
	}
}
