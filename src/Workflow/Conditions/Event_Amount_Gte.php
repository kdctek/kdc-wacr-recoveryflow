<?php
/**
 * The condition that keeps small baskets out of a sequence.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Conditions;

use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Recovery\Rule_Set;

defined( 'ABSPATH' ) || exit;

/**
 * Is what they left behind worth at least this much?
 *
 * The threshold is written into the step as "event.amount_gte:250". It is read
 * as a number and compared, never interpreted: the argument is restricted by
 * the definition validator to characters that cannot be anything but a literal,
 * and this class casts it to a float and stops there.
 *
 * With no argument it falls back to the site's minimum order value, so a
 * merchant who has already set that number once does not have to repeat it in
 * every workflow.
 *
 * Until the editor learned to show a number box, the threshold could only be
 * written by code, and saving the workflow in the editor dropped it -- the
 * step then quietly checked the minimum order value instead. That is what
 * Condition_Argument_Interface is for.
 */
final class Event_Amount_Gte implements Condition_Interface, Condition_Argument_Interface {

	/**
	 * What a readable threshold looks like: a plain decimal, no sign, no exponent.
	 */
	private const AMOUNT_PATTERN = '/^[0-9]{1,12}(?:\.[0-9]{1,4})?$/';

	/**
	 * The name a workflow refers to this by.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'event.amount_gte';
	}

	/**
	 * One sentence for the workflow editor.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'The abandoned total is at least a given amount', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * The label for the number box.
	 *
	 * @return string
	 */
	public function get_argument_label(): string {
		return __( 'Amount', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * What the number means.
	 *
	 * @return string
	 */
	public function get_argument_help(): string {
		return __( 'In the shop currency, with up to four decimal places. Leave it empty to use the shop\'s "Ignore baskets worth less than" setting.', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * The number box's bounds.
	 *
	 * @return array{min:string,max:string,step:string}
	 */
	public function get_argument_bounds(): array {
		return array(
			'min'  => '0',
			'max'  => '',
			'step' => '0.01',
		);
	}

	/**
	 * The argument as it should be stored.
	 *
	 * @param string $raw What was typed.
	 * @return string|null
	 */
	public function normalize_argument( string $raw ): ?string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		return 1 === preg_match( self::AMOUNT_PATTERN, $raw ) ? $raw : null;
	}

	/**
	 * Answer the question.
	 *
	 * @param Recovery_Journey    $journey  The journey being run.
	 * @param array<string,mixed> $context  Run context.
	 * @param string              $argument The threshold, as typed in the step.
	 * @return bool
	 */
	public function evaluate( Recovery_Journey $journey, array $context, string $argument = '' ): bool {
		$event = $context['event'] ?? null;

		if ( ! $event instanceof Recovery_Event ) {
			return false;
		}

		$rules = $context['rules'] ?? null;

		if ( '' === $argument ) {
			return $rules instanceof Rule_Set && $event->amount_value() >= $rules->min_amount();
		}

		if ( null === $this->normalize_argument( $argument ) ) {
			// A threshold nobody can read is not a reason to message somebody.
			return false;
		}

		return $event->amount_value() >= (float) $argument;
	}
}
