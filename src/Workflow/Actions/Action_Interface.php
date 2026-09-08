<?php
/**
 * What every workflow action must provide.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Actions;

use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Workflow\Step_Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * The one thing in a workflow that reaches the outside world.
 *
 * Like conditions, actions are registered in code and found by name, so a
 * definition can only ask for something a plugin already decided to offer.
 *
 * An action owns every database write it needs, and each one must go through
 * Journey_Repository::update_claimed() or ::transition(). Both return false
 * when somebody else changed the row first -- a customer completing their order
 * while a batch is halfway through deciding to message them -- and false means
 * stop immediately and report Step_Outcome::LOST_RACE. Writing anyway would
 * overwrite a conversion and message somebody who had already bought.
 *
 * An action that sends anything must reserve its attempt row before the request
 * leaves the site, never after.
 */
interface Action_Interface {

	/**
	 * The name a workflow refers to this by, e.g. 'wacr.send_template'.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * One sentence for the workflow editor.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Whether this action can run on this site right now.
	 *
	 * Checked by the editor so an unavailable action is explained rather than
	 * silently failing at three in the morning.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Do the thing.
	 *
	 * @param Recovery_Journey    $journey    The journey being run.
	 * @param array<string,mixed> $parameters The step's "with" block.
	 * @param array<string,mixed> $context    Run context: event, customer, rules, source, workflow,
	 *                                        step_index, claim_token, next_action_at.
	 * @return Step_Outcome
	 */
	public function run( Recovery_Journey $journey, array $parameters, array $context ): Step_Outcome;
}
