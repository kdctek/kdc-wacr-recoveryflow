<?php
/**
 * The condition that stops following up once the customer answers.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Conditions;

use WAcr\RecoveryFlow\Recovery\Journey_State;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Has the customer replied or followed the link?
 *
 * A reply turns a broadcast into a conversation, and the next message in a
 * conversation should come from a person, not from a scheduler. Sending the
 * second reminder to somebody who wrote back "yes, ordering now" is the fastest
 * way to make a shop look automated in the worst sense.
 */
final class Journey_Not_Engaged implements Condition_Interface {

	/**
	 * The name a workflow refers to this by.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'journey.not_engaged';
	}

	/**
	 * One sentence for the workflow editor.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'The customer has not replied or followed the link', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * Answer the question.
	 *
	 * @param Recovery_Journey    $journey  The journey being run.
	 * @param array<string,mixed> $context  Run context.
	 * @param string              $argument Unused.
	 * @return bool
	 */
	public function evaluate( Recovery_Journey $journey, array $context, string $argument = '' ): bool {
		return ! $journey->is_engaged() && Journey_State::ENGAGED !== $journey->status;
	}
}
