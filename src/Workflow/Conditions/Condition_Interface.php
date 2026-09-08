<?php
/**
 * What every workflow condition must provide.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Conditions;

use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * A yes-or-no question a workflow may ask before going on.
 *
 * Conditions are registered in code and looked up by name, never called by
 * name: a definition contains the string "journey.not_completed" and the engine
 * finds the object the site registered under it. A name that nobody registered
 * stops the journey. That indirection is the whole reason a workflow definition
 * cannot become code execution.
 *
 * An implementation must answer from live state rather than from anything it
 * cached, must be cheap enough to run inside a batch of fifty journeys, and
 * must never throw: the engine treats an exception as a reason to stop the
 * journey, which is a poor outcome for a question that could simply be answered
 * "no".
 */
interface Condition_Interface {

	/**
	 * The name a workflow refers to this by, e.g. 'journey.not_completed'.
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
	 * Answer the question for one journey.
	 *
	 * @param Recovery_Journey    $journey  The journey being run.
	 * @param array<string,mixed> $context  Run context: event, customer, rules, source, workflow,
	 *                                      step_index, claim_token.
	 * @param string              $argument The literal after the colon in "if", or ''.
	 * @return bool True to carry on to the next step.
	 */
	public function evaluate( Recovery_Journey $journey, array $context, string $argument = '' ): bool;
}
