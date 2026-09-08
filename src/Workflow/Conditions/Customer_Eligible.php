<?php
/**
 * The condition that re-asks whether this person may be messaged.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Conditions;

use WAcr\RecoveryFlow\Customer\Customer;
use WAcr\RecoveryFlow\Recovery\Eligibility_Evaluator;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Recovery\Rule_Set;

defined( 'ABSPATH' ) || exit;

/**
 * May this person still be messaged?
 *
 * Asked again inside the workflow, not only when the journey was created,
 * because consent can be withdrawn in the day between scheduling a reminder and
 * sending it. A check performed once at the start would message somebody who
 * had opted out in the meantime, which is both the wrong thing to do and, in
 * most of the places this plugin runs, unlawful.
 */
final class Customer_Eligible implements Condition_Interface {

	/**
	 * The eligibility rules.
	 *
	 * @var Eligibility_Evaluator
	 */
	private Eligibility_Evaluator $eligibility;

	/**
	 * Constructor.
	 *
	 * @param Eligibility_Evaluator $eligibility The eligibility rules.
	 */
	public function __construct( Eligibility_Evaluator $eligibility ) {
		$this->eligibility = $eligibility;
	}

	/**
	 * The name a workflow refers to this by.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'customer.eligible';
	}

	/**
	 * One sentence for the workflow editor.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'The customer may still be messaged', 'kdc-wacr-recoveryflow' );
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
		$customer = $context['customer'] ?? null;
		$rules    = $context['rules'] ?? null;

		if ( ! $rules instanceof Rule_Set ) {
			return false;
		}

		return $this->eligibility->for_send( $customer instanceof Customer ? $customer : null, $rules )->allowed;
	}
}
