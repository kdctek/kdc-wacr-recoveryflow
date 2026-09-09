<?php
/**
 * One workflow's editor.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin\Pages;

use WAcr\RecoveryFlow\Admin\Nonce_Field;
use WAcr\RecoveryFlow\Admin\Screen;
use WAcr\RecoveryFlow\Admin\Step_Describer;
use WAcr\RecoveryFlow\Admin\Workflow_Form;
use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\WAcr\Template_Catalog;
use WAcr\RecoveryFlow\Workflow\Email_Composer;
use WAcr\RecoveryFlow\Workflow\Step_Registry;
use WAcr\RecoveryFlow\Workflow\Variable_Context;
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
	 * The approved templates a step may choose from.
	 *
	 * @var Template_Catalog
	 */
	private Template_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Repository $workflows The workflow store.
	 * @param Step_Registry       $registry  The step registry.
	 * @param Template_Catalog    $catalog   The approved templates.
	 */
	public function __construct( Workflow_Repository $workflows, Step_Registry $registry, Template_Catalog $catalog ) {
		$this->workflows = $workflows;
		$this->registry  = $registry;
		$this->catalog   = $catalog;
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

		Nonce_Field::render( Workflow_Form::ACTION );
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
				esc_html__( 'Only handing the recovery to a WA.cr Auto Flow can be saved on this workspace\'s plan. It needs no API key, but it does need a WA.cr plan that can run a flow, which is Growth and above.', 'kdc-wacr-recoveryflow' )
			);
		}

		echo '</td></tr>';

		/*
		 * The channel is stated, not chosen. It used to be a second dropdown
		 * beside this one, offering WhatsApp or email for a WA.cr template --
		 * which cannot arrive as email, and never did: whatever was picked, a
		 * WhatsApp message went out and was billed as one. An action is a way
		 * of reaching somebody, so the channel is a property of it, and saying
		 * so here is the honest replacement for a choice that was never real.
		 */
		$handler = '' === $chosen ? null : $this->registry->action( $chosen );

		if ( null !== $handler ) {
			echo '<tr><th scope="row">';
			echo esc_html__( 'Over', 'kdc-wacr-recoveryflow' );
			echo '</th><td>';
			printf( '<p>%s</p>', esc_html( Step_Describer::channel( $handler->get_channel() ) ) );

			if ( ! $handler->is_available() ) {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'This site cannot send on that channel yet, so steps using it will wait rather than send. The Channels tab says what is missing.', 'kdc-wacr-recoveryflow' )
				);
			}

			echo '</td></tr>';
		}

		if ( 'wacr.send_template' === $chosen ) {
			$this->render_template_fields( $index, (string) ( $with['template'] ?? '' ), $with );
		}

		if ( 'wacr.send_email' === $chosen ) {
			$this->render_email_fields( $index, $with );
		}

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
	 * The subject and body of a recovery email.
	 *
	 * There is no picker here and nothing to approve, which is the whole
	 * difference from a WhatsApp template: the merchant writes the words. What
	 * they are NOT asked to write is the postal address or the unsubscribe --
	 * those are appended to every message by the composer, because a footer
	 * somebody can edit is a footer somebody can delete, and both are what make
	 * the message lawful to send.
	 *
	 * @param int                 $index Zero-based step position.
	 * @param array<string,mixed> $with  The step's stored arguments.
	 * @return void
	 */
	private function render_email_fields( int $index, array $with ): void {
		echo '<tr><th scope="row">';
		printf( '<label for="recoveryflow-step-%1$d-subject">%2$s</label>', (int) $index, esc_html__( 'Subject', 'kdc-wacr-recoveryflow' ) );
		echo '</th><td>';
		printf(
			'<input type="text" id="recoveryflow-step-%1$d-subject" name="step[%1$d][subject]" value="%2$s" class="large-text" maxlength="%3$d" aria-describedby="recoveryflow-step-%1$d-subject-help">',
			(int) $index,
			esc_attr( (string) ( $with['subject'] ?? '' ) ),
			(int) Email_Composer::MAX_SUBJECT_LENGTH
		);
		printf(
			'<p class="description" id="recoveryflow-step-%1$d-subject-help">%2$s</p>',
			(int) $index,
			esc_html__( 'What the customer sees before they open it. Placeholders work here too.', 'kdc-wacr-recoveryflow' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">';
		printf( '<label for="recoveryflow-step-%1$d-body">%2$s</label>', (int) $index, esc_html__( 'Message', 'kdc-wacr-recoveryflow' ) );
		echo '</th><td>';
		printf(
			'<textarea id="recoveryflow-step-%1$d-body" name="step[%1$d][body]" rows="10" class="large-text code" aria-describedby="recoveryflow-step-%1$d-body-help">%2$s</textarea>',
			(int) $index,
			esc_textarea( (string) ( $with['body'] ?? '' ) )
		);
		printf(
			'<p class="description" id="recoveryflow-step-%1$d-body-help">%2$s</p>',
			(int) $index,
			esc_html__( 'Plain text. Your postal address and an unsubscribe link are added to the foot of every message automatically -- do not type them here, and they cannot be removed.', 'kdc-wacr-recoveryflow' )
		);

		printf( '<p class="description">%s</p>', esc_html__( 'Placeholders you can use:', 'kdc-wacr-recoveryflow' ) );
		echo '<ul class="recoveryflow-placeholder-list">';

		foreach ( Variable_Context::keys() as $key ) {
			printf( '<li><code>%s</code></li>', esc_html( '{{ ' . $key . ' }}' ) );
		}

		echo '</ul>';
		echo '</td></tr>';
	}

	/**
	 * Which template to send, and what goes into it.
	 *
	 * The picker is a list when WA.cr can be asked and a text box when it
	 * cannot. Falling back rather than blocking matters: a workspace whose
	 * credential lacks templates:read, or whose network is down for a minute,
	 * still has a merchant in front of the screen who knows the name of their
	 * own template. What the fallback must not do is pretend -- so it says why
	 * there is no list.
	 *
	 * @param int                 $index    Zero-based step position.
	 * @param string              $chosen   The template name currently stored.
	 * @param array<string,mixed> $with     The step's arguments.
	 * @return void
	 */
	private function render_template_fields( int $index, string $chosen, array $with ): void {
		$catalog = $this->catalog->all();

		echo '<tr><th scope="row">';
		printf( '<label for="recoveryflow-step-%1$d-template">%2$s</label>', (int) $index, esc_html__( 'Template', 'kdc-wacr-recoveryflow' ) );
		echo '</th><td>';

		if ( ! $catalog['ok'] ) {
			printf(
				'<input type="text" id="recoveryflow-step-%1$d-template" name="step[%1$d][template]" value="%2$s" class="regular-text" aria-describedby="recoveryflow-step-%1$d-template-help">',
				(int) $index,
				esc_attr( $chosen )
			);
			printf(
				'<p class="description" id="recoveryflow-step-%1$d-template-help">%2$s %3$s</p>',
				(int) $index,
				esc_html( $catalog['reason'] ),
				esc_html__( 'Type the name of an approved template instead. It is checked when the message is sent.', 'kdc-wacr-recoveryflow' )
			);
			echo '</td></tr>';

			return;
		}

		// Connected, asked, and the answer was none. Distinct from "could not
		// ask", and it must not present as an empty list with no explanation:
		// a merchant staring at a picker with nothing in it cannot tell whether
		// the plugin is broken or their workspace is empty.
		if ( array() === $catalog['templates'] ) {
			printf(
				'<input type="text" id="recoveryflow-step-%1$d-template" name="step[%1$d][template]" value="%2$s" class="regular-text" aria-describedby="recoveryflow-step-%1$d-template-help">',
				(int) $index,
				esc_attr( $chosen )
			);
			printf(
				'<p class="description" id="recoveryflow-step-%1$d-template-help">%2$s</p>',
				(int) $index,
				esc_html__( 'Your WA.cr workspace has no approved templates yet, so there is nothing to choose from. Approve one in the WA.cr console, or type its name here if you know it.', 'kdc-wacr-recoveryflow' )
			);
			echo '</td></tr>';

			return;
		}

		$options = array( '' => __( 'Choose a template', 'kdc-wacr-recoveryflow' ) );

		foreach ( $catalog['templates'] as $template ) {
			$name = (string) $template['name'];

			$options[ $name ] = empty( $template['usable'] )
				? sprintf(
					/* translators: %s: a message template name. */
					__( '%s -- cannot be used', 'kdc-wacr-recoveryflow' ),
					$name
				)
				: $name;
		}

		// A template that was chosen before and has since been deleted, or
		// un-approved, would otherwise vanish from the select and be silently
		// replaced by whatever sits at the top of the list on the next save.
		if ( '' !== $chosen && ! isset( $options[ $chosen ] ) ) {
			$options[ $chosen ] = sprintf(
				/* translators: %s: a message template name. */
				__( '%s -- no longer in your workspace', 'kdc-wacr-recoveryflow' ),
				$chosen
			);
		}

		$this->render_select(
			sprintf( 'step[%d][template]', $index ),
			sprintf( 'recoveryflow-step-%d-template', $index ),
			$options,
			$chosen
		);

		printf(
			'<p class="description" id="recoveryflow-step-%1$d-template-help">%2$s</p>',
			(int) $index,
			esc_html__( 'Only templates WA.cr has approved are listed. One that RecoveryFlow cannot fill is listed too, and says so, rather than being hidden from somebody looking for it.', 'kdc-wacr-recoveryflow' )
		);

		$template = '' === $chosen ? null : $this->catalog->find( $chosen );

		if ( null !== $template && empty( $template['usable'] ) ) {
			printf(
				'<p class="recoveryflow-template-refused">%s</p>',
				esc_html( Template_Catalog::refusal( $template ) )
			);
		}

		echo '</td></tr>';

		if ( null !== $template && ! empty( $template['usable'] ) ) {
			$this->render_variable_fields( $index, $template, $with );
		}
	}

	/**
	 * What fills each blank in the chosen template.
	 *
	 * WA.cr resolves the blanks itself and hands back, for each one, the
	 * template's own wording either side of it. That wording is shown, because
	 * "body_2" tells a merchant nothing and "Your basket of {} is waiting"
	 * tells them exactly what they are choosing a value for. Nobody should have
	 * to keep the template open in another tab to fill it in.
	 *
	 * Every value is picked from the allow-list, never typed. The renderer will
	 * only substitute allow-listed variables in any case, so a free text box
	 * would be a box in which most of what somebody typed silently disappeared.
	 *
	 * @param int                 $index    Zero-based step position.
	 * @param array<string,mixed> $template The chosen template.
	 * @param array<string,mixed> $with     The step's arguments.
	 * @return void
	 */
	private function render_variable_fields( int $index, array $template, array $with ): void {
		$slots = isset( $template['slots'] ) && is_array( $template['slots'] ) ? $template['slots'] : array();

		if ( array() === $slots ) {
			echo '<tr><th scope="row">' . esc_html__( 'What goes in it', 'kdc-wacr-recoveryflow' ) . '</th><td>';
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'This template has no blanks to fill, so it sends as written.', 'kdc-wacr-recoveryflow' )
			);
			echo '</td></tr>';

			return;
		}

		$stored  = isset( $with['variables'] ) && is_array( $with['variables'] ) ? $with['variables'] : array();
		$choices = array( '' => __( 'Leave empty', 'kdc-wacr-recoveryflow' ) );

		foreach ( Variable_Context::KEYS as $key ) {
			$choices[ '{{' . $key . '}}' ] = self::variable_label( (string) $key );
		}

		echo '<tr><th scope="row">' . esc_html__( 'What goes in it', 'kdc-wacr-recoveryflow' ) . '</th><td>';
		echo '<table class="widefat striped recoveryflow-variables"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Where in the message', 'kdc-wacr-recoveryflow' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'What to put there', 'kdc-wacr-recoveryflow' ) );
		echo '</tr></thead><tbody>';

		foreach ( $slots as $slot ) {
			$id      = (string) $slot['id'];
			$current = isset( $stored[ $id ] ) ? (string) $stored[ $id ] : '';
			$field   = sprintf( 'recoveryflow-step-%d-var-%s', $index, str_replace( '_', '-', $id ) );
			$options = $choices;

			// Anything hand-written -- a fixed word, or two variables in one
			// slot -- is kept and offered as it stands. Dropping it because the
			// picker cannot express it would quietly rewrite a message somebody
			// composed on purpose.
			if ( '' !== $current && ! isset( $options[ $current ] ) ) {
				$options[ $current ] = sprintf(
					/* translators: %s: whatever the merchant typed into this slot, shown verbatim. */
					__( '%s (kept as written)', 'kdc-wacr-recoveryflow' ),
					$current
				);
			}

			echo '<tr><th scope="row">';
			printf( '<label for="%1$s">%2$s</label>', esc_attr( $field ), esc_html( self::slot_wording( $slot ) ) );
			echo '</th><td>';
			$this->render_select(
				sprintf( 'step[%d][variables][%s]', $index, $id ),
				$field,
				$options,
				$current
			);
			echo '</td></tr>';
		}//end foreach

		echo '</tbody></table></td></tr>';
	}

	/**
	 * How one blank reads, using the template's own words.
	 *
	 * @param array<string,mixed> $slot One resolved slot.
	 * @return string
	 */
	private static function slot_wording( array $slot ): string {
		$before = trim( (string) ( $slot['before'] ?? '' ) );
		$after  = trim( (string) ( $slot['after'] ?? '' ) );

		if ( '' === $before && '' === $after ) {
			$sample = trim( (string) ( $slot['sample'] ?? '' ) );

			if ( '' !== $sample ) {
				return sprintf(
					/* translators: %s: the example value WhatsApp holds for this blank. */
					__( 'the blank shown as "%s"', 'kdc-wacr-recoveryflow' ),
					$sample
				);
			}

			return (string) $slot['id'];
		}

		return sprintf(
			/* translators: 1: the template wording before the blank, 2: the wording after it. */
			__( '%1$s ____ %2$s', 'kdc-wacr-recoveryflow' ),
			$before,
			$after
		);
	}

	/**
	 * How one allow-listed variable reads in the picker.
	 *
	 * @param string $key A Variable_Context key.
	 * @return string
	 */
	private static function variable_label( string $key ): string {
		$labels = array(
			'customer.first_name'      => __( 'The customer\'s first name', 'kdc-wacr-recoveryflow' ),
			'customer.last_name'       => __( 'The customer\'s last name', 'kdc-wacr-recoveryflow' ),
			'recovery.total'           => __( 'What the basket comes to, as a number', 'kdc-wacr-recoveryflow' ),
			'recovery.total_formatted' => __( 'What the basket comes to, with the currency', 'kdc-wacr-recoveryflow' ),
			'recovery.currency'        => __( 'The currency code', 'kdc-wacr-recoveryflow' ),
			'recovery.item_count'      => __( 'How many items are in the basket', 'kdc-wacr-recoveryflow' ),
			'recovery.items_summary'   => __( 'A short list of what is in the basket', 'kdc-wacr-recoveryflow' ),
			'recovery.first_item_name' => __( 'The name of the first item', 'kdc-wacr-recoveryflow' ),
			'recovery.recovery_url'    => __( 'The link that restores the basket', 'kdc-wacr-recoveryflow' ),
			'recovery.token'           => __( 'The basket link\'s code, for a button URL', 'kdc-wacr-recoveryflow' ),
			'recovery.opt_out_url'     => __( 'The link to stop receiving these', 'kdc-wacr-recoveryflow' ),
			'site.name'                => __( 'The shop\'s name', 'kdc-wacr-recoveryflow' ),
			'site.url'                 => __( 'The shop\'s address', 'kdc-wacr-recoveryflow' ),
			'source.name'              => __( 'Where the basket was left, such as WooCommerce', 'kdc-wacr-recoveryflow' ),
		);

		return $labels[ $key ] ?? $key;
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
