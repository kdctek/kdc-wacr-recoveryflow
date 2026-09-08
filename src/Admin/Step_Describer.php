<?php
/**
 * Turning a stored workflow step into a sentence a shopkeeper can read.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Admin;

use WAcr\RecoveryFlow\Workflow\Workflow_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a step is put into words.
 *
 * A workflow is stored as data and executed by looking values up, which is what
 * makes it safe -- nothing in a definition is ever called or evaluated. The cost
 * of that safety is that the stored form reads like machinery:
 * `{"type":"condition","if":"journey.not_completed","else":"stop:recovered"}`
 * is precise and tells a merchant nothing. This class is the translation, and
 * it lives on its own because both the list screen and the editor need the
 * same words for the same step. Two descriptions of one step that drift apart
 * is a way to make somebody distrust the screen entirely.
 *
 * Every branch falls back to naming the raw value rather than to saying
 * nothing. A step type added by another plugin through the registry filters
 * will not have a sentence here, and "Runs my_plugin.do_thing" is a worse
 * sentence than the ones above it but a far better screen than a blank row
 * where a step should be.
 */
final class Step_Describer {

	/**
	 * Describe one step.
	 *
	 * @param array<string,mixed> $step One step of a definition.
	 * @return string A complete sentence, unescaped.
	 */
	public static function describe( array $step ): string {
		$type = isset( $step['type'] ) ? (string) $step['type'] : '';

		switch ( $type ) {
			case Workflow_Definition::TYPE_WAIT:
				return self::describe_wait( $step );

			case Workflow_Definition::TYPE_CONDITION:
				return self::describe_condition( $step );

			case Workflow_Definition::TYPE_ACTION:
				return self::describe_action( $step );
		}

		/* translators: %s: the step type as stored in the workflow. */
		return sprintf( __( 'Runs the step type %s, which was added by another plugin.', 'kdc-wacr-recoveryflow' ), $type );
	}

	/**
	 * A short label for the kind of step, for a badge or a heading.
	 *
	 * @param array<string,mixed> $step One step of a definition.
	 * @return string
	 */
	public static function label( array $step ): string {
		$type = isset( $step['type'] ) ? (string) $step['type'] : '';

		switch ( $type ) {
			case Workflow_Definition::TYPE_WAIT:
				return _x( 'Wait', 'workflow step type', 'kdc-wacr-recoveryflow' );

			case Workflow_Definition::TYPE_CONDITION:
				return _x( 'Check', 'workflow step type', 'kdc-wacr-recoveryflow' );

			case Workflow_Definition::TYPE_ACTION:
				return _x( 'Send', 'workflow step type', 'kdc-wacr-recoveryflow' );
		}

		return _x( 'Step', 'workflow step type', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * Put a duration into words.
	 *
	 * Whole days, hours and minutes are named as such rather than reduced to
	 * one unit: "1 day" is how somebody set it and how they will look for it,
	 * and "1440 minutes" would make them do arithmetic to check their own work.
	 *
	 * @param string $duration An ISO 8601 duration.
	 * @return string
	 */
	public static function duration( string $duration ): string {
		$seconds = Workflow_Definition::duration_to_seconds( $duration );

		if ( $seconds <= 0 ) {
			return $duration;
		}

		if ( 0 === $seconds % DAY_IN_SECONDS ) {
			$days = (int) ( $seconds / DAY_IN_SECONDS );

			/* translators: %d: a number of days. */
			return sprintf( _n( '%d day', '%d days', $days, 'kdc-wacr-recoveryflow' ), $days );
		}

		if ( 0 === $seconds % HOUR_IN_SECONDS ) {
			$hours = (int) ( $seconds / HOUR_IN_SECONDS );

			/* translators: %d: a number of hours. */
			return sprintf( _n( '%d hour', '%d hours', $hours, 'kdc-wacr-recoveryflow' ), $hours );
		}

		$minutes = (int) round( $seconds / MINUTE_IN_SECONDS );

		/* translators: %d: a number of minutes. */
		return sprintf( _n( '%d minute', '%d minutes', $minutes, 'kdc-wacr-recoveryflow' ), $minutes );
	}

	/**
	 * The name of a channel, for a person.
	 *
	 * @param string $channel Channel key.
	 * @return string
	 */
	public static function channel( string $channel ): string {
		if ( Workflow_Definition::CHANNEL_EMAIL === $channel ) {
			return _x( 'email', 'message channel', 'kdc-wacr-recoveryflow' );
		}

		return _x( 'WhatsApp', 'message channel', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * Describe a wait step.
	 *
	 * @param array<string,mixed> $step The step.
	 * @return string
	 */
	private static function describe_wait( array $step ): string {
		$for = isset( $step['for'] ) ? (string) $step['for'] : '';

		/* translators: %s: a length of time, already in words, such as "1 day". */
		return sprintf( __( 'Waits %s before going on.', 'kdc-wacr-recoveryflow' ), self::duration( $for ) );
	}

	/**
	 * Describe a condition step.
	 *
	 * @param array<string,mixed> $step The step.
	 * @return string
	 */
	private static function describe_condition( array $step ): string {
		$expression = isset( $step['if'] ) ? (string) $step['if'] : '';
		$otherwise  = isset( $step['else'] ) ? (string) $step['else'] : '';

		return sprintf(
			/* translators: 1: what is checked, 2: what happens when the check fails. */
			__( 'Checks that %1$s. If not, %2$s.', 'kdc-wacr-recoveryflow' ),
			self::condition_phrase( $expression ),
			self::branch_phrase( $otherwise )
		);
	}

	/**
	 * Describe an action step.
	 *
	 * @param array<string,mixed> $step The step.
	 * @return string
	 */
	private static function describe_action( array $step ): string {
		$action  = isset( $step['do'] ) ? (string) $step['do'] : '';
		$with    = isset( $step['with'] ) && is_array( $step['with'] ) ? $step['with'] : array();
		$channel = self::channel( Workflow_Definition::channel_for( $step ) );

		if ( 'wacr.start_flow' === $action ) {
			return __( 'Hands the recovery to a WA.cr Auto Flow, which decides what to send from there.', 'kdc-wacr-recoveryflow' );
		}

		if ( 'wacr.send_template' === $action ) {
			$template = isset( $with['template'] ) ? (string) $with['template'] : '';

			if ( '' === $template ) {
				/* translators: %s: a channel name, such as WhatsApp. */
				return sprintf( __( 'Sends a message over %s. No template has been chosen yet.', 'kdc-wacr-recoveryflow' ), $channel );
			}

			return sprintf(
				/* translators: 1: a message template name, 2: a channel name such as WhatsApp. */
				__( 'Sends the %1$s template over %2$s.', 'kdc-wacr-recoveryflow' ),
				$template,
				$channel
			);
		}

		return sprintf(
			/* translators: 1: an action name as stored, 2: a channel name. */
			__( 'Runs %1$s over %2$s.', 'kdc-wacr-recoveryflow' ),
			$action,
			$channel
		);
	}

	/**
	 * Put a condition expression into words.
	 *
	 * @param string $expression The stored expression, possibly with an argument.
	 * @return string
	 */
	private static function condition_phrase( string $expression ): string {
		$name     = Workflow_Definition::condition_name( $expression );
		$argument = Workflow_Definition::condition_argument( $expression );

		switch ( $name ) {
			case 'journey.not_completed':
				return __( 'the order has still not been placed', 'kdc-wacr-recoveryflow' );

			case 'journey.not_engaged':
				return __( 'the customer has not replied or opened their basket again', 'kdc-wacr-recoveryflow' );

			case 'customer.eligible':
				return __( 'the customer may still be messaged', 'kdc-wacr-recoveryflow' );

			case 'event.amount_gte':
				return sprintf(
					/* translators: %s: an amount of money, as typed by the merchant. */
					__( 'the basket is worth at least %s', 'kdc-wacr-recoveryflow' ),
					$argument
				);
		}

		return '' === $argument ? $name : $name . ':' . $argument;
	}

	/**
	 * Put an "else" branch into words.
	 *
	 * @param string $branch The stored branch.
	 * @return string
	 */
	private static function branch_phrase( string $branch ): string {
		$state = Workflow_Definition::stop_state( $branch );

		switch ( $state ) {
			case 'recovered':
				return __( 'the recovery stops and is recorded as recovered', 'kdc-wacr-recoveryflow' );

			case 'cancelled':
				return __( 'the recovery stops and is recorded as cancelled', 'kdc-wacr-recoveryflow' );

			case 'opted_out':
				return __( 'the recovery stops, because the customer asked not to be messaged', 'kdc-wacr-recoveryflow' );

			case 'expired':
				return __( 'the recovery stops and is recorded as expired', 'kdc-wacr-recoveryflow' );

			case 'invalid':
				return __( 'the recovery stops, because there is no way to reach this customer', 'kdc-wacr-recoveryflow' );

			case 'failed':
				return __( 'the recovery stops and is recorded as failed', 'kdc-wacr-recoveryflow' );
		}

		if ( '' !== $state ) {
			/* translators: %s: a journey state name. */
			return sprintf( __( 'the recovery stops as %s', 'kdc-wacr-recoveryflow' ), $state );
		}

		return __( 'the recovery stops', 'kdc-wacr-recoveryflow' );
	}
}
