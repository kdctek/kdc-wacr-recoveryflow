<?php
/**
 * Turning a posted form into a workflow definition, and back again.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Core\Feature_Gate;
use WAcr\RecoveryFlow\Security\Capabilities;
use WAcr\RecoveryFlow\Workflow\Workflow;
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
	 * Constructor.
	 *
	 * @param Workflow_Repository $workflows The workflow store.
	 */
	public function __construct( Workflow_Repository $workflows ) {
		$this->workflows = $workflows;
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
			$this->remember_draft( self::rearrange( self::read( $posted ), $command ) );

			wp_safe_redirect( Screen::workflow_url( $id ) );

			exit;
		}

		$this->save( $id, self::read( $posted ), $posted );

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
	 * @param array<string,mixed> $posted The unslashed post.
	 * @return array<string,mixed>
	 */
	public static function read( array $posted ): array {
		$steps = array();
		$rows  = isset( $posted['step'] ) && is_array( $posted['step'] ) ? $posted['step'] : array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$step = self::read_step( $row );

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
	 * @param array<string,mixed> $row The row.
	 * @return array<string,mixed> Empty when the type is not one we know.
	 */
	private static function read_step( array $row ): array {
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
			$channel = (string) ( $row['channel'] ?? '' );

			$step = array(
				'type'    => Workflow_Definition::TYPE_ACTION,
				'do'      => $action,
				'channel' => in_array( $channel, Workflow_Definition::CHANNELS, true )
					? $channel
					: Workflow_Definition::CHANNEL_WHATSAPP,
			);

			$with = self::read_action_arguments( $action, $row );

			if ( array() !== $with ) {
				$step['with'] = $with;
			}

			return $step;
		}

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
		if ( 'wacr.send_template' === $action ) {
			$with = array(
				'template' => sanitize_text_field( (string) ( $row['template'] ?? '' ) ),
			);

			$language = sanitize_text_field( (string) ( $row['language'] ?? '' ) );

			if ( '' !== $language ) {
				$with['language'] = $language;
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
	 * Validate and store, then say what happened.
	 *
	 * @param int                 $id         Workflow id, 0 for a new one.
	 * @param array<string,mixed> $definition The rebuilt definition.
	 * @param array<string,mixed> $posted     The unslashed post, for the two switches.
	 * @return void
	 */
	private function save( int $id, array $definition, array $posted ): void {
		$existing = 0 === $id ? null : $this->workflows->find( $id );

		if ( ! self::may_write( $definition ) ) {
			self::remember(
				array(
					'ok'      => false,
					'message' => Feature_Gate::unavailable_reason(),
				)
			);
			$this->remember_draft( $definition );

			wp_safe_redirect( Screen::workflow_url( $id ) );

			return;
		}

		$valid = Workflow_Definition::validate( $definition );

		if ( true !== $valid ) {
			self::remember(
				array(
					'ok'      => false,
					'message' => $valid->get_error_message(),
				)
			);
			$this->remember_draft( $definition );

			wp_safe_redirect( Screen::workflow_url( $id ) );

			return;
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
			self::remember(
				array(
					'ok'      => false,
					'message' => __( 'The workflow could not be saved. Somebody else may have changed it while you were editing; reopen it and try again.', 'kdc-wacr-recoveryflow' ),
				)
			);
			$this->remember_draft( $definition );

			wp_safe_redirect( Screen::workflow_url( $id ) );

			return;
		}

		self::remember(
			array(
				'ok'      => true,
				'message' => __( 'Workflow saved. Recoveries already running keep the version they started on.', 'kdc-wacr-recoveryflow' ),
			)
		);

		wp_safe_redirect( Screen::workflow_url( $saved ) );
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
