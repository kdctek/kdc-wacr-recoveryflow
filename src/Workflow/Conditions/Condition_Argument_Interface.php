<?php
/**
 * What a condition that takes a number must also provide.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Conditions;

defined( 'ABSPATH' ) || exit;

/**
 * A condition whose question has a number in it.
 *
 * "The basket is worth at least 250" and "the customer has ignored fewer than
 * two earlier recoveries" are one condition each with a number after the colon
 * in "if". The engine has always passed that number through; what was missing
 * was any way to type it. The editor offered conditions by name alone, so a
 * number could not be set there, and re-saving a workflow that had one quietly
 * dropped it -- changing what the step checked while the screen showed nothing
 * wrong.
 *
 * Implementing this is how a condition tells the editor it takes a number, and
 * how the editor knows what to say about it. It is a separate interface, not
 * three more methods on Condition_Interface, so that every condition another
 * plugin has already registered keeps working unchanged: a condition that does
 * not implement it simply gets no number box, which is what it had before.
 *
 * The editor only ever offers a number box, never free text, and the
 * definition validator still refuses anything that is not a plain literal.
 * normalize_argument() sits between the two so a merchant meets a sentence
 * about their step rather than a pattern they cannot read.
 */
interface Condition_Argument_Interface {

	/**
	 * The label for the box the number is typed into, e.g. "Amount".
	 *
	 * @return string
	 */
	public function get_argument_label(): string;

	/**
	 * One sentence under the box: what the number means and what an empty box does.
	 *
	 * Also used to explain a refusal, so it should state the accepted range.
	 *
	 * @return string
	 */
	public function get_argument_help(): string;

	/**
	 * The number box's bounds, as attribute values.
	 *
	 * Use '' for a bound the box should not have.
	 *
	 * @return array{min:string,max:string,step:string}
	 */
	public function get_argument_bounds(): array;

	/**
	 * The argument as it should be stored.
	 *
	 * @param string $raw What was typed, trimmed.
	 * @return string|null The value to store; '' for "use the default"; null when it cannot be read.
	 */
	public function normalize_argument( string $raw ): ?string;
}
