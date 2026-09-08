<?php
/**
 * Validation of the workflow JSON shape.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

use WAcr\RecoveryFlow\Recovery\Journey_State;

defined( 'ABSPATH' ) || exit;

/**
 * The boundary between administrator-supplied JSON and the engine.
 *
 * A workflow definition is the one place in this plugin where a human types
 * something that later decides what code runs, so it is treated as hostile
 * input even though only a user with recoveryflow_manage_workflows can save
 * one: a compromised administrator session must not become arbitrary code
 * execution on the site.
 *
 * Three rules do the work.
 *
 * Everything is an allow-list. Unknown top-level keys, unknown step types and
 * unknown keys inside a step are refused rather than ignored, because a key
 * that is silently dropped today is a key somebody relies on tomorrow, and a
 * key that is silently kept is a place to smuggle something in.
 *
 * A duration is parsed here, by a regular expression, into an integer number of
 * seconds. It is never handed to strtotime(), DateInterval or anything else
 * that interprets a string, and it is capped: "P99999D" would otherwise park a
 * journey past the heat death of the store.
 *
 * Nothing named in a definition is ever called. Conditions and actions are
 * looked up by name in a registry that only code can add to; a name that is not
 * in the registry stops the journey rather than reaching call_user_func.
 */
final class Workflow_Definition {

	/**
	 * The only trigger event there is today.
	 */
	public const TRIGGER_EVENT = 'journey.eligible';

	/**
	 * The trigger source that means "any source".
	 */
	public const ANY_SOURCE = '*';

	public const TYPE_CONDITION = 'condition';
	public const TYPE_WAIT      = 'wait';
	public const TYPE_ACTION    = 'action';

	/**
	 * Bounds. A definition outside any of these is refused on save.
	 */
	public const MAX_STEPS       = 40;
	public const MAX_NAME_LENGTH = 191;
	/**
	 * Channels an action may send on.
	 *
	 * The channel is chosen per step rather than by falling back from one to
	 * another, so "WhatsApp after an hour, email after a day" is a thing the
	 * merchant builds rather than a thing the plugin decides for them. The two
	 * are not equivalent: a WhatsApp send must be an approved template and is
	 * billed, while email is free text, unbilled, and has no 24-hour window.
	 */
	public const CHANNEL_WHATSAPP = 'whatsapp';
	public const CHANNEL_EMAIL    = 'email';
	public const CHANNELS         = array( self::CHANNEL_WHATSAPP, self::CHANNEL_EMAIL );

	public const MIN_WAIT_SECONDS = 60;
	public const MAX_WAIT_SECONDS = 2592000;

	/**
	 * The error code every refusal carries.
	 */
	public const ERROR_CODE = 'recoveryflow_workflow_invalid';

	/**
	 * A condition or action name, optionally followed by a literal argument.
	 *
	 * The argument is deliberately restricted to characters that can only ever
	 * be read as data -- no spaces, quotes, brackets or backslashes -- so that a
	 * condition which interprets it cannot be handed anything but a scalar.
	 */
	private const NAME_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*(?::[A-Za-z0-9_.\-]{1,32})?$/';

	/**
	 * ISO 8601 durations, restricted to the parts that mean the same thing
	 * everywhere. Months and years are refused: "P1M" and "PT1M" differ by a
	 * factor of forty-three thousand and a merchant will eventually type the
	 * wrong one. Six digits are accepted per part so that an absurd but
	 * well-formed duration is refused with "at most 30 days" rather than with
	 * "that is not a duration", which would send the merchant hunting a typo.
	 */
	private const DURATION_PATTERN = '/^P(?!$)(?:(\d{1,6})W)?(?:(\d{1,6})D)?(?:T(?!$)(?:(\d{1,6})H)?(?:(\d{1,6})M)?(?:(\d{1,6})S)?)?$/';

	/**
	 * Check a definition, top to bottom.
	 *
	 * @param array<string,mixed> $definition Decoded JSON.
	 * @return true|\WP_Error True when it may be stored.
	 */
	public static function validate( array $definition ) {
		$unknown = array_diff( array_keys( $definition ), array( 'name', 'version', 'trigger', 'steps' ) );

		if ( array() !== $unknown ) {
			return self::refuse(
				sprintf(
					/* translators: %s: comma-separated list of key names. */
					__( 'This workflow has settings RecoveryFlow does not recognise: %s. Remove them and save again.', 'kdc-wacr-recoveryflow' ),
					implode( ', ', array_map( 'strval', $unknown ) )
				)
			);
		}

		$name = isset( $definition['name'] ) ? trim( (string) $definition['name'] ) : '';

		if ( '' === $name || strlen( $name ) > self::MAX_NAME_LENGTH ) {
			return self::refuse( __( 'Give the workflow a name of up to 191 characters.', 'kdc-wacr-recoveryflow' ) );
		}

		if ( ! isset( $definition['version'] ) || ! is_numeric( $definition['version'] ) || (int) $definition['version'] < 1 ) {
			return self::refuse( __( 'The workflow version must be a whole number of 1 or more.', 'kdc-wacr-recoveryflow' ) );
		}

		$trigger = self::check_trigger( $definition['trigger'] ?? null );

		if ( true !== $trigger ) {
			return $trigger;
		}

		return self::check_steps( $definition['steps'] ?? null );
	}

	/**
	 * A stable digest of a definition, for change detection.
	 *
	 * Keys are sorted recursively before encoding so that re-saving an
	 * unchanged workflow whose keys arrived in a different order does not read
	 * as an edit and does not burn a version number.
	 *
	 * @param array<string,mixed> $definition Decoded JSON.
	 * @return string 64 hex characters.
	 */
	public static function hash( array $definition ): string {
		$json = wp_json_encode( self::canonical( $definition ) );

		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	/**
	 * The definition with every map's keys sorted, lists left in order.
	 *
	 * @param array<int|string,mixed> $value Any part of a definition.
	 * @return array<int|string,mixed>
	 */
	public static function canonical( array $value ): array {
		$is_list = self::is_list( $value );

		if ( ! $is_list ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::canonical( $item );
			}
		}

		return $value;
	}

	/**
	 * Read an ISO 8601 duration as a whole number of seconds.
	 *
	 * Never call anything that interprets the string: this is the one value in
	 * a definition that controls time, and a parser that accepts "next tuesday"
	 * accepts a great deal else besides.
	 *
	 * @param string $duration An ISO 8601 duration such as P1D or PT30M.
	 * @return int Seconds, clamped to the permitted range; 0 when unparseable.
	 */
	public static function duration_to_seconds( string $duration ): int {
		$matches = array();

		if ( 1 !== preg_match( self::DURATION_PATTERN, trim( $duration ), $matches ) ) {
			return 0;
		}

		$seconds = ( (int) ( $matches[1] ?? 0 ) ) * WEEK_IN_SECONDS
			+ ( (int) ( $matches[2] ?? 0 ) ) * DAY_IN_SECONDS
			+ ( (int) ( $matches[3] ?? 0 ) ) * HOUR_IN_SECONDS
			+ ( (int) ( $matches[4] ?? 0 ) ) * MINUTE_IN_SECONDS
			+ (int) ( $matches[5] ?? 0 );

		if ( $seconds < 1 ) {
			return 0;
		}

		// Clamped rather than refused here, because this also runs against
		// snapshots stored before a cap changed, and a journey pinned to an old
		// version must still be runnable.
		return min( self::MAX_WAIT_SECONDS, max( self::MIN_WAIT_SECONDS, $seconds ) );
	}

	/**
	 * The registered name part of an "if" value.
	 *
	 * @param string $expression The step's if value.
	 * @return string
	 */
	public static function condition_name( string $expression ): string {
		$at = strpos( $expression, ':' );

		return false === $at ? $expression : substr( $expression, 0, $at );
	}

	/**
	 * The literal argument part of an "if" value, if there is one.
	 *
	 * @param string $expression The step's if value.
	 * @return string Empty when the condition takes no argument.
	 */
	public static function condition_argument( string $expression ): string {
		$at = strpos( $expression, ':' );

		return false === $at ? '' : substr( $expression, $at + 1 );
	}

	/**
	 * The journey state an "else" branch stops at.
	 *
	 * @param string $branch The step's else value.
	 * @return string A terminal Journey_State, or '' when the branch continues.
	 */
	public static function stop_state( string $branch ): string {
		if ( 0 !== strncmp( $branch, 'stop:', 5 ) ) {
			return '';
		}

		$state = substr( $branch, 5 );

		return Journey_State::is_terminal( $state ) ? $state : '';
	}

	/**
	 * The states an "else" branch may name.
	 *
	 * @return string[]
	 */
	public static function stop_states(): array {
		return Journey_State::terminal();
	}

	/**
	 * Check the trigger block.
	 *
	 * @param mixed $trigger The trigger value.
	 * @return true|\WP_Error
	 */
	private static function check_trigger( $trigger ) {
		if ( ! is_array( $trigger ) ) {
			return self::refuse( __( 'The workflow needs a trigger saying what starts it.', 'kdc-wacr-recoveryflow' ) );
		}

		$unknown = array_diff( array_keys( $trigger ), array( 'event', 'source' ) );

		if ( array() !== $unknown ) {
			return self::refuse( __( 'The trigger may only contain "event" and "source".', 'kdc-wacr-recoveryflow' ) );
		}

		$event = isset( $trigger['event'] ) ? (string) $trigger['event'] : '';

		if ( self::TRIGGER_EVENT !== $event ) {
			return self::refuse(
				sprintf(
					/* translators: %s: the only supported trigger event name. */
					__( 'The only trigger RecoveryFlow understands today is "%s".', 'kdc-wacr-recoveryflow' ),
					self::TRIGGER_EVENT
				)
			);
		}

		$source = isset( $trigger['source'] ) ? (string) $trigger['source'] : self::ANY_SOURCE;

		if ( self::ANY_SOURCE !== $source && 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $source ) ) {
			return self::refuse( __( 'The trigger source must be a source identifier, or * for every source.', 'kdc-wacr-recoveryflow' ) );
		}

		return true;
	}

	/**
	 * Check the step list.
	 *
	 * @param mixed $steps The steps value.
	 * @return true|\WP_Error
	 */
	private static function check_steps( $steps ) {
		if ( ! is_array( $steps ) || array() === $steps || ! self::is_list( $steps ) ) {
			return self::refuse( __( 'The workflow needs at least one step, given as a list.', 'kdc-wacr-recoveryflow' ) );
		}

		if ( count( $steps ) > self::MAX_STEPS ) {
			return self::refuse(
				sprintf(
					/* translators: %d: the maximum number of steps a workflow may have. */
					__( 'A workflow may have at most %d steps.', 'kdc-wacr-recoveryflow' ),
					self::MAX_STEPS
				)
			);
		}

		foreach ( $steps as $index => $step ) {
			if ( ! is_array( $step ) ) {
				return self::refuse( self::at( (int) $index, __( 'each step must be an object', 'kdc-wacr-recoveryflow' ) ) );
			}

			$checked = self::check_step( (int) $index, $step );

			if ( true !== $checked ) {
				return $checked;
			}
		}

		return true;
	}

	/**
	 * Check one step.
	 *
	 * @param int                 $index Zero-based position.
	 * @param array<string,mixed> $step  The step.
	 * @return true|\WP_Error
	 */
	private static function check_step( int $index, array $step ) {
		$type = isset( $step['type'] ) ? (string) $step['type'] : '';

		if ( self::TYPE_CONDITION === $type ) {
			return self::check_condition_step( $index, $step );
		}

		if ( self::TYPE_WAIT === $type ) {
			return self::check_wait_step( $index, $step );
		}

		if ( self::TYPE_ACTION === $type ) {
			return self::check_action_step( $index, $step );
		}

		return self::refuse(
			self::at(
				$index,
				__( 'the type must be condition, wait or action', 'kdc-wacr-recoveryflow' )
			)
		);
	}

	/**
	 * Check a condition step.
	 *
	 * @param int                 $index Zero-based position.
	 * @param array<string,mixed> $step  The step.
	 * @return true|\WP_Error
	 */
	private static function check_condition_step( int $index, array $step ) {
		$unknown = array_diff( array_keys( $step ), array( 'type', 'if', 'else' ) );

		if ( array() !== $unknown ) {
			return self::refuse( self::at( $index, __( 'a condition may only have "if" and "else"', 'kdc-wacr-recoveryflow' ) ) );
		}

		$expression = isset( $step['if'] ) ? (string) $step['if'] : '';

		if ( 1 !== preg_match( self::NAME_PATTERN, $expression ) ) {
			return self::refuse( self::at( $index, __( '"if" must name a registered condition, such as journey.not_completed', 'kdc-wacr-recoveryflow' ) ) );
		}

		if ( ! isset( $step['else'] ) || '' === $step['else'] ) {
			return true;
		}

		$branch = (string) $step['else'];

		if ( '' === self::stop_state( $branch ) ) {
			return self::refuse(
				self::at(
					$index,
					sprintf(
						/* translators: %s: comma-separated list of journey states. */
						__( '"else" must be stop: followed by one of %s', 'kdc-wacr-recoveryflow' ),
						implode( ', ', self::stop_states() )
					)
				)
			);
		}

		return true;
	}

	/**
	 * Check a wait step.
	 *
	 * @param int                 $index Zero-based position.
	 * @param array<string,mixed> $step  The step.
	 * @return true|\WP_Error
	 */
	private static function check_wait_step( int $index, array $step ) {
		$unknown = array_diff( array_keys( $step ), array( 'type', 'for' ) );

		if ( array() !== $unknown ) {
			return self::refuse( self::at( $index, __( 'a wait may only have "for"', 'kdc-wacr-recoveryflow' ) ) );
		}

		$duration = isset( $step['for'] ) ? (string) $step['for'] : '';
		$matches  = array();

		if ( 1 !== preg_match( self::DURATION_PATTERN, trim( $duration ), $matches ) ) {
			return self::refuse( self::at( $index, __( '"for" must be an ISO 8601 duration such as PT30M or P1D', 'kdc-wacr-recoveryflow' ) ) );
		}

		// Checked against what was asked for, not against the clamped value:
		// duration_to_seconds() clamps so that an old snapshot stays runnable,
		// and clamping here would silently turn "PT1S" into a minute on save.
		$seconds = self::raw_seconds( $matches );

		if ( $seconds < self::MIN_WAIT_SECONDS ) {
			return self::refuse( self::at( $index, __( 'a wait must be at least one minute', 'kdc-wacr-recoveryflow' ) ) );
		}

		if ( $seconds > self::MAX_WAIT_SECONDS ) {
			return self::refuse( self::at( $index, __( 'a wait may be at most 30 days', 'kdc-wacr-recoveryflow' ) ) );
		}

		return true;
	}

	/**
	 * Which channel an action step sends on.
	 *
	 * Absent means WhatsApp, so every workflow written before channels existed
	 * keeps behaving exactly as it did.
	 *
	 * @param array<string,mixed> $step The step.
	 * @return string One of the CHANNELS values.
	 */
	public static function channel_for( array $step ): string {
		$channel = isset( $step['channel'] ) ? (string) $step['channel'] : self::CHANNEL_WHATSAPP;

		return in_array( $channel, self::CHANNELS, true ) ? $channel : self::CHANNEL_WHATSAPP;
	}

	/**
	 * Check an action step.
	 *
	 * @param int                 $index Zero-based position.
	 * @param array<string,mixed> $step  The step.
	 * @return true|\WP_Error
	 */
	private static function check_action_step( int $index, array $step ) {
		$unknown = array_diff( array_keys( $step ), array( 'type', 'do', 'with', 'channel' ) );

		if ( array() !== $unknown ) {
			return self::refuse( self::at( $index, __( 'an action may only have "do", "with" and "channel"', 'kdc-wacr-recoveryflow' ) ) );
		}

		if ( isset( $step['channel'] ) && ! in_array( $step['channel'], self::CHANNELS, true ) ) {
			return self::refuse( self::at( $index, __( '"channel" must be either whatsapp or email', 'kdc-wacr-recoveryflow' ) ) );
		}

		$name = isset( $step['do'] ) ? (string) $step['do'] : '';

		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			return self::refuse( self::at( $index, __( '"do" must name a registered action, such as wacr.send_template', 'kdc-wacr-recoveryflow' ) ) );
		}

		if ( ! isset( $step['with'] ) ) {
			return true;
		}

		if ( ! is_array( $step['with'] ) ) {
			return self::refuse( self::at( $index, __( '"with" must be an object of settings', 'kdc-wacr-recoveryflow' ) ) );
		}

		return self::check_parameters( $index, $step['with'], 0 );
	}

	/**
	 * Check that an action's parameters are plain, shallow data.
	 *
	 * Depth is capped and every leaf must be a scalar, so a definition cannot
	 * carry a structure that only makes sense to something that would evaluate
	 * it. An action reads the few keys it knows and ignores the rest.
	 *
	 * @param int                     $index Zero-based step position.
	 * @param array<int|string,mixed> $block The parameters.
	 * @param int                     $depth How deep this call is.
	 * @return true|\WP_Error
	 */
	private static function check_parameters( int $index, array $block, int $depth ) {
		if ( $depth > 1 ) {
			return self::refuse( self::at( $index, __( '"with" may be nested one level at most', 'kdc-wacr-recoveryflow' ) ) );
		}

		foreach ( $block as $key => $value ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $key ) ) {
				return self::refuse( self::at( $index, __( 'every setting name in "with" must be lowercase letters, digits and underscores', 'kdc-wacr-recoveryflow' ) ) );
			}

			if ( is_array( $value ) ) {
				$checked = self::check_parameters( $index, $value, $depth + 1 );

				if ( true !== $checked ) {
					return $checked;
				}

				continue;
			}

			if ( null !== $value && ! is_scalar( $value ) ) {
				return self::refuse( self::at( $index, __( 'settings in "with" must be text, numbers or true and false', 'kdc-wacr-recoveryflow' ) ) );
			}
		}

		return true;
	}

	/**
	 * The seconds a matched duration asked for, before clamping.
	 *
	 * @param array<int,string> $matches Regular expression matches.
	 * @return int
	 */
	private static function raw_seconds( array $matches ): int {
		return ( (int) ( $matches[1] ?? 0 ) ) * WEEK_IN_SECONDS
			+ ( (int) ( $matches[2] ?? 0 ) ) * DAY_IN_SECONDS
			+ ( (int) ( $matches[3] ?? 0 ) ) * HOUR_IN_SECONDS
			+ ( (int) ( $matches[4] ?? 0 ) ) * MINUTE_IN_SECONDS
			+ (int) ( $matches[5] ?? 0 );
	}

	/**
	 * Whether an array is a plain ordered list.
	 *
	 * Written out because array_is_list() arrived in PHP 8.1 and this plugin
	 * runs on 8.0.
	 *
	 * @param array<int|string,mixed> $value Any array.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		$expected = 0;

		foreach ( $value as $key => $ignored ) {
			if ( $key !== $expected ) {
				return false;
			}

			++$expected;
		}

		return true;
	}

	/**
	 * Prefix a message with the step it concerns.
	 *
	 * @param int    $index   Zero-based position.
	 * @param string $problem What is wrong, lowercase and unpunctuated.
	 * @return string
	 */
	private static function at( int $index, string $problem ): string {
		return sprintf(
			/* translators: 1: step number as shown to the user, 2: what is wrong with it. */
			__( 'Step %1$d: %2$s.', 'kdc-wacr-recoveryflow' ),
			$index + 1,
			$problem
		);
	}

	/**
	 * Build the refusal.
	 *
	 * @param string $message What to tell the administrator.
	 * @return \WP_Error
	 */
	private static function refuse( string $message ): \WP_Error {
		return new \WP_Error( self::ERROR_CODE, $message );
	}
}
