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
 */
final class Event_Amount_Gte implements Condition_Interface {

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

		if ( 1 !== preg_match( '/^[0-9]+(?:\.[0-9]{1,4})?$/', $argument ) ) {
			// A threshold nobody can read is not a reason to message somebody.
			return false;
		}

		return $event->amount_value() >= (float) $argument;
	}
}
