<?php
/**
 * Turning a posted form into a workflow definition, and back again.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Workflow\Message_Composer;
use WAcr\RecoveryFlow\Workflow\Workflow;
use WAcr\RecoveryFlow\Workflow\Step_Registry;
use WAcr\RecoveryFlow\Workflow\Workflow_Definition;
use WAcr\RecoveryFlow\Workflow\Workflow_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * The editor's server half.
 *
 * Everything the editor can do is a form submission: adding a step, removing
 * one, moving one, and saving. There is no JavaScript in the loop and no
 * endpoint of its own -- the screen posts to admin-post.php, this class reads
 * the post, and the browser is sent back to the editor. Add and move do not
 * touch the database at all; they hand the rebuilt definition back to the
 * screen to draw again. That costs a page load per step, and buys an editor
 * that behaves identically with scripts blocked, in a browser that never ran
 * them, and in the screenshot somebody pastes into a support thread.
 *
 * The definition is rebuilt from the post on every submission rather than
 * patched into the stored one. A patch would need to know which fields the
 * form contained, and a form that omits a field it used to send -- an older
 * tab left open, a field made conditional -- would silently keep the stored
 * value while the merchant looked at a screen that did not show it. Rebuilding
 * means what you see is what is saved, and a field nobody posted is a field
 * with no value rather than an old one.
 *
 * Nothing here trusts the posted structure. Step types, conditions, actions,
 * channels and stop states are each checked against a registry or a constant
 * before they are written, and the whole definition then goes through
 * Workflow_Definition::validate() before it reaches the repository, which
 * validates again. A definition is data an administrator typed, and the safety
 * argument for executing it by lookup only holds if nothing else can get in.
 */
final class Workflow_Form {

	/**
	 * The admin-post action name.
	 */
	public const ACTION = 'recoveryflow_save_workflow';

	/**
	 * How long a save result waits to be shown, in seconds.
	 */
	private const RESULT_TTL = 60;

	/**
	 * Where a per-user save result is kept.
	 */
	private const RESULT_KEY = 'recoveryflow_workflow_result_';

	/**
	 * The workflow store.
	 *
	 * @var Workflow_Repository
	 */
	private Workflow_Repository $workflows;

	/**
	 * The actions a step may name.
	 *
	 * Held so a step's channel can be taken from the action that will run it
	 * rather than from the form. An action IS a way of reaching somebody, so
	 * the two cannot be chosen separately without being able to contradict each
	 * other -- and a contradiction would be stored, shown back as valid, and
	 * refused only at three in the morning when the step ran.
	 *
	 * @var Step_Registry
	 */
	private Step_Registry $steps;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Repository $workflows The workflow store.
	 * @param Step_Registry       $steps     The actions a step may name.
	 */
	public function __construct( Workflow_Repository $workflows, Step_Registry $steps ) {
		$this->workflows = $workflows;
		$this->steps     = $steps;
	}

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Read the post, do what it asked, and go back to the editor.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_WORKFLOWS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to edit recovery workflows on this site.', 'kdc-wacr-recoveryflow' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately above.
		$posted = (array) wp_unslash( $_POST );

		$id      = isset( $posted['workflow_id'] ) ? (int) $posted['workflow_id'] : 0;
		$command = self::command( $posted );

		if ( 'save' !== $command['name'] ) {
			$this->remember_draft( self::rearrange( self::read( $posted, $this->steps ), $command ) );

			wp_safe_redirect( Screen::workflow_url( $id ) );

			exit;
		}

		$outcome = $this->store( $id, self::read( $posted, $this->steps ), $posted );

		self::remember(
			array(
				'ok'      => $outcome['ok'],
				'message' => $outcome['message'],
			)
		);

		if ( ! $outcome['ok'] ) {
			$this->remember_draft( self::read( $posted, $this->steps ) );
		}

		wp_safe_redirect( Screen::workflow_url( $outcome['id'] ) );

		exit;
	}

	/**
	 * The result of the last save by this user, if there is one.
	 *
	 * @return array{ok:bool,message:string}|null
	 */
	public static function result(): ?array {
		$key    = self::RESULT_KEY . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) || ! isset( $result['message'] ) ) {
			return null;
		}

		delete_transient( $key );

		return array(
			'ok'      => ! empty( $result['ok'] ),
			'message' => (string) $result['message'],
		);
	}

	/**
	 * The definition this user is part-way through editing, if any.
	 *
	 * Held per user and briefly, because it is a half-finished edit rather than
	 * a stored workflow: two administrators editing at once must not see each
	 * other's unsaved steps, and one abandoned edit must not be waiting for
	 * whoever opens the screen next week.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function draft(): ?array {
		$draft = get_transient( self::draft_key() );

		if ( ! is_array( $draft ) ) {
			return null;
		}

		delete_transient( self::draft_key() );

		return $draft;
	}

	/**
	 * Build a definition from a posted form.
	 *
	 * @param array<string,mixed> $posted   The unslashed post.
	 * @param Step_Registry       $registry The actions a step may name.
	 * @return array<string,mixed>
	 */
	public static function read( array $posted, Step_Registry $registry ): array {
		$steps = array();
		$rows  = isset( $posted['step'] ) && is_array( $posted['step'] ) ? $posted['step'] : array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$step = self::read_step( $row, $registry );

			if ( array() !== $step ) {
				$steps[] = $step;
			}
		}

		return array(
			'name'    => sanitize_text_field( (string) ( $posted['workflow_name'] ?? '' ) ),
			'version' => 1,
			'trigger' => array(
				'event'  => Workflow_Definition::TRIGGER_EVENT,
				'source' => Workflow_Definition::ANY_SOURCE,
			),
			'steps'   => $steps,
		);
	}

	/**
	 * Read one posted step row.
	 *
	 * @param array<string,mixed> $row      The row.
	 * @param Step_Registry       $registry The actions a step may name.
	 * @return array<string,mixed> Empty when the type is not one we know.
	 */
	private static function read_step( array $row, Step_Registry $registry ): array {
		$type = isset( $row['type'] ) ? (string) $row['type'] : '';

		if ( Workflow_Definition::TYPE_CONDITION === $type ) {
			$step = array(
				'type' => Workflow_Definition::TYPE_CONDITION,
				'if'   => sanitize_text_field( (string) ( $row['if'] ?? '' ) ),
			);

			$else = sanitize_text_field( (string) ( $row['else'] ?? '' ) );

			if ( '' !== $else ) {
				$step['else'] = $else;
			}

			return $step;
		}

		if ( Workflow_Definition::TYPE_WAIT === $type ) {
			return array(
				'type' => Workflow_Definition::TYPE_WAIT,
				'for'  => self::duration(
					(int) ( $row['wait_amount'] ?? 0 ),
					(string) ( $row['wait_unit'] ?? 'hours' )
				),
			);
		}

		if ( Workflow_Definition::TYPE_ACTION === $type ) {
			$action  = sanitize_text_field( (string) ( $row['do'] ?? '' ) );
			$handler = $registry->action( $action );

			/*
			 * Taken from the action, never from the form. The channel is not a
			 * choice beside the action, it is a property of it: a WA.cr
			 * template goes over WhatsApp because that is what one is. Reading
			 * a posted value here would let a hand-edited form store a step
			 * whose two halves disagree -- accepted, shown back as valid, and
			 * refused only when it ran. An action nothing has registered keeps
			 * the default; the validator refuses it by name a moment later.
			 */
			$step = array(
				'type'    => Workflow_Definition::TYPE_ACTION,
				'do'      => $action,
				'channel' => null === $handler
					? Workflow_Definition::CHANNEL_WHATSAPP
					: $handler->get_channel(),
			);

			$with = self::read_action_arguments( $action, $row );

			if ( array() !== $with ) {
				$step['with'] = $with;
			}

			return $step;
		}//end if

		return array();
	}

	/**
	 * The arguments an action step carries.
	 *
	 * Only the keys the named action understands are read. A form that posted
	 * a template for a hand-off step -- which happens the moment somebody
	 * changes a step's action and submits without the fields being redrawn --
	 * must not write a template onto a step that has no use for one, because
	 * the validator would then refuse a definition the merchant cannot see
	 * anything wrong with.
	 *
	 * @param string              $action The action name.
	 * @param array<string,mixed> $row    The posted row.
	 * @return array<string,mixed>
	 */
	private static function read_action_arguments( string $action, array $row ): array {
		if ( 'wacr.send_email' === $action ) {
			/*
			 * Only the subject is length-capped here, because it is the one a
			 * mail header carries; the body's cap belongs to the composer,
			 * which is also what strips the trailing whitespace and collapses
			 * the blank lines a placeholder leaves behind when its value is
			 * empty. Neither is sanitised into HTML safety: the message goes
			 * out as text/plain and is escaped at the point of output when the
			 * step is shown back on the screen.
			 */
			$with = array(
				'subject' => sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
				'body'    => trim( (string) wp_unslash( $row['body'] ?? '' ) ),
			);

			return array_filter( $with, static fn ( string $value ): bool => '' !== $value );
		}

		if ( 'wacr.send_template' === $action ) {
			$with = array(
				'template' => sanitize_text_field( (string) ( $row['template'] ?? '' ) ),
			);

			$language = sanitize_text_field( (string) ( $row['language'] ?? '' ) );

			if ( '' !== $language ) {
				$with['language'] = $language;
			}

			$variables = self::read_variables( $row );

			if ( array() !== $variables ) {
				$with['variables'] = $variables;
			}

			return $with;
		}

		if ( 'wacr.start_flow' === $action ) {
			$hook = sanitize_key( (string) ( $row['hook'] ?? '' ) );

			return array( 'hook' => '' === $hook ? 'primary' : $hook );
		}

		return array();
	}

	/**
	 * What the merchant chose to put in each of the template's blanks.
	 *
	 * A slot is written only when it has a value. An empty one is left out
	 * rather than stored as an empty string, because the composer refuses a
	 * variables block with a gap in its numbering -- WhatsApp fills these in
	 * order, so a gap sends the wrong value to a customer -- and an empty
	 * string is a gap wearing a value's clothes.
	 *
	 * Slot names are checked against what the composer can fill, not merely
	 * sanitised. The names arrive from a form, and a form is not a boundary.
	 *
	 * @param array<string,mixed> $row The posted step row.
	 * @return array<string,string>
	 */
	private static function read_variables( array $row ): array {
		$posted = isset( $row['variables'] ) && is_array( $row['variables'] ) ? $row['variables'] : array();
		$slots  = array();

		foreach ( $posted as $slot => $value ) {
			$slot = sanitize_text_field( (string) $slot );

			if ( ! Message_Composer::supports_slot( $slot ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$slots[ $slot ] = $value;
		}

		return $slots;
	}

	/**
	 * Assemble an ISO 8601 duration from a number and a unit.
	 *
	 * @param int    $amount How many.
	 * @param string $unit   minutes, hours or days.
	 * @return string
	 */
	public static function duration( int $amount, string $unit ): string {
		$amount = max( 0, min( 999999, $amount ) );

		switch ( $unit ) {
			case 'minutes':
				return 'PT' . $amount . 'M';

			case 'days':
				return 'P' . $amount . 'D';
		}

		return 'PT' . $amount . 'H';
	}

	/**
	 * Split an ISO 8601 duration back into a number and a unit, for the form.
	 *
	 * The unit chosen is the largest one that divides exactly, so a wait saved
	 * as one day comes back as "1 day" rather than as "24 hours". Somebody who
	 * typed 1 day and reopens the screen should find what they typed.
	 *
	 * @param string $duration An ISO 8601 duration.
	 * @return array{amount:int,unit:string}
	 */
	public static function split_duration( string $duration ): array {
		$seconds = Workflow_Definition::duration_to_seconds( $duration );

		if ( $seconds > 0 && 0 === $seconds % DAY_IN_SECONDS ) {
			return array(
				'amount' => (int) ( $seconds / DAY_IN_SECONDS ),
				'unit'   => 'days',
			);
		}

		if ( $seconds > 0 && 0 === $seconds % HOUR_IN_SECONDS ) {
			return array(
				'amount' => (int) ( $seconds / HOUR_IN_SECONDS ),
				'unit'   => 'hours',
			);
		}

		return array(
			'amount' => (int) round( $seconds / MINUTE_IN_SECONDS ),
			'unit'   => 'minutes',
		);
	}

	/**
	 * Which button was pressed, and on which step.
	 *
	 * @param array<string,mixed> $posted The post.
	 * @return array{name:string,index:int}
	 */
	public static function command( array $posted ): array {
		$raw = '';

		foreach ( array( 'add_step', 'remove_step', 'move_up', 'move_down' ) as $name ) {
			if ( isset( $posted[ $name ] ) ) {
				$raw = $name . ':' . ( is_array( $posted[ $name ] ) ? '' : (string) $posted[ $name ] );

				break;
			}
		}

		if ( '' === $raw ) {
			return array(
				'name'  => 'save',
				'index' => -1,
			);
		}

		$parts = explode( ':', $raw, 2 );

		return array(
			'name'  => $parts[0],
			'index' => isset( $parts[1] ) && is_numeric( $parts[1] ) ? (int) $parts[1] : -1,
		);
	}

	/**
	 * Apply an add, remove or move to a definition.
	 *
	 * @param array<string,mixed>          $definition The rebuilt definition.
	 * @param array{name:string,index:int} $command    What was pressed.
	 * @return array<string,mixed>
	 */
	public static function rearrange( array $definition, array $command ): array {
		$steps = isset( $definition['steps'] ) && is_array( $definition['steps'] ) ? array_values( $definition['steps'] ) : array();
		$index = $command['index'];
		$last  = count( $steps ) - 1;

		switch ( $command['name'] ) {
			case 'add_step':
				$steps[] = array(
					'type' => Workflow_Definition::TYPE_WAIT,
					'for'  => 'PT1H',
				);

				break;

			case 'remove_step':
				if ( $index >= 0 && $index <= $last ) {
					array_splice( $steps, $index, 1 );
				}

				break;

			case 'move_up':
				if ( $index > 0 && $index <= $last ) {
					$carried             = $steps[ $index - 1 ];
					$steps[ $index - 1 ] = $steps[ $index ];
					$steps[ $index ]     = $carried;
				}

				break;

			case 'move_down':
				if ( $index >= 0 && $index < $last ) {
					$carried             = $steps[ $index + 1 ];
					$steps[ $index + 1 ] = $steps[ $index ];
					$steps[ $index ]     = $carried;
				}

				break;
		}//end switch

		$definition['steps'] = $steps;

		return $definition;
	}

	/**
	 * Decide whether a definition may be stored, store it, and say what happened.
	 *
	 * Deliberately free of redirects and transients: those are handle()'s, and
	 * a decision tangled up with them is a decision no test can reach. The
	 * previous shape of this method had exactly that problem -- the refusal
	 * message could be swapped for a worse one and every assertion stayed
	 * green, because the assertions could only see the message-building
	 * function, never the branch that chose to use it.
	 *
	 * @param int                 $id         Workflow id, 0 for a new one.
	 * @param array<string,mixed> $definition The rebuilt definition.
	 * @param array<string,mixed> $posted     The unslashed post, for the two switches.
	 * @return array{ok:bool,message:string,id:int} Where to go back to, and what to say.
	 */
	public function store( int $id, array $definition, array $posted ): array {
		$existing = 0 === $id ? null : $this->workflows->find( $id );

		if ( ! self::may_write( $definition ) ) {
			return array(
				'ok'      => false,
				'message' => self::refusal(),
				'id'      => $id,
			);
		}

		$valid = Workflow_Definition::validate( $definition );

		if ( true !== $valid ) {
			return array(
				'ok'      => false,
				'message' => $valid->get_error_message(),
				'id'      => $id,
			);
		}

		$saved = $this->workflows->save(
			array(
				'id'         => $id,
				'name'       => (string) $definition['name'],
				'slug'       => null === $existing ? sanitize_title( (string) $definition['name'] ) : $existing->slug,
				'source_id'  => null === $existing ? '' : $existing->source_id,
				'status'     => empty( $posted['workflow_active'] ) ? Workflow::STATUS_DRAFT : Workflow::STATUS_ACTIVE,
				'is_default' => ! empty( $posted['workflow_default'] ),
				'definition' => $definition,
			)
		);

		if ( 0 === $saved ) {
			return array(
				'ok'      => false,
				'message' => __( 'The workflow could not be saved. Somebody else may have changed it while you were editing; reopen it and try again.', 'kdc-wacr-recoveryflow' ),
				'id'      => $id,
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Workflow saved. Recoveries already running keep the version they started on.', 'kdc-wacr-recoveryflow' ),
			'id'      => $saved,
		);
	}

	/**
	 * Why a workflow that sends from WordPress cannot be saved here.
	 *
	 * Feature_Gate::unavailable_reason() alone is a true sentence about the
	 * stored credential and an answer to a question nobody asked. Somebody who
	 * pressed Save on a workflow with a send step in it and read "No WA.cr API
	 * key is connected yet" has been told a fact, not what they did, what was
	 * refused, or what to do instead -- which is the same failure as a screen
	 * that says "A key is saved" above "No key is connected": each sentence
	 * true of its own value, neither an answer.
	 *
	 * So the refusal names the step that caused it, then gives the reason, then
	 * gives the way forward that needs no developer API.
	 *
	 * @return string
	 */
	public static function refusal(): string {
		$reason = Feature_Gate::unavailable_reason();

		return sprintf(
			/* translators: %s: a sentence explaining why the WA.cr developer API is unavailable. */
			__( 'This workflow was not saved, because one of its steps sends a message from WordPress and this site cannot do that yet. %s Until then, a step can hand the recovery to a WA.cr Auto Flow instead, which needs no API key but does need a WA.cr plan that can run a flow, which is Growth and above.', 'kdc-wacr-recoveryflow' ),
			$reason
		);
	}

	/**
	 * Whether this workspace may store this definition.
	 *
	 * A plan below Scale cannot use the developer API, so a workflow that sends
	 * from WordPress cannot run there and is refused rather than stored into a
	 * state that would fail silently at dispatch time. A workflow that only
	 * hands off to a WA.cr Auto Flow needs no API key and is always allowed --
	 * that is the whole Lite path, and refusing it would leave those merchants
	 * with a screen they can open and nothing they can do in it.
	 *
	 * The second half of that question is Workflow_Definition's to answer, and
	 * the list screen asks it too: a list that offers an edit this method then
	 * refuses would be worse than either answer on its own.
	 *
	 * @param array<string,mixed> $definition The definition.
	 * @return bool
	 */
	public static function may_write( array $definition ): bool {
		if ( Feature_Gate::is_enabled( Feature_Gate::WORKFLOW_EDITOR ) ) {
			return true;
		}

		return ! Workflow_Definition::needs_developer_api( $definition );
	}

	/**
	 * Keep a half-finished definition for the redirect.
	 *
	 * @param array<string,mixed> $definition The definition.
	 * @return void
	 */
	private function remember_draft( array $definition ): void {
		set_transient( self::draft_key(), $definition, self::RESULT_TTL );
	}

	/**
	 * Where this user's unsaved edit lives.
	 *
	 * @return string
	 */
	private static function draft_key(): string {
		return 'recoveryflow_workflow_draft_' . get_current_user_id();
	}

	/**
	 * Keep the outcome of a save for the redirect.
	 *
	 * @param array{ok:bool,message:string} $result What happened.
	 * @return void
	 */
	private static function remember( array $result ): void {
		set_transient( self::RESULT_KEY . get_current_user_id(), $result, self::RESULT_TTL );
	}
}
