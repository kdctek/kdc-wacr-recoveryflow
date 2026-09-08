<?php
/**
 * The condition that stops a journey when the sale already happened.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Conditions;

use WAcr\RecoveryFlow\Integration\Recovery_Source_Interface;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;
use WAcr\RecoveryFlow\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Has the customer bought the thing yet?
 *
 * The single most damaging thing this plugin could do is ask somebody to
 * finish an order they have already paid for, so the question is put to the
 * source rather than answered from the event row: between the reminder being
 * scheduled and it being sent, the customer may have checked out in another
 * tab, on another device, or over the phone, and only the source knows.
 *
 * Every uncertain answer is read as "completed". A missing event, a source that
 * is no longer installed, a source that threw -- none of those are grounds for
 * messaging somebody, and the cost of stopping a journey that could have been
 * recovered is far below the cost of chasing a paying customer for money they
 * have already handed over.
 */
final class Journey_Not_Completed implements Condition_Interface {

	/**
	 * Logger.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Logger.
	 */
	public function __construct( ?Logger $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * The name a workflow refers to this by.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'journey.not_completed';
	}

	/**
	 * One sentence for the workflow editor.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'The customer has not completed the order yet', 'kdc-wacr-recoveryflow' );
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
		$event = $context['event'] ?? null;

		if ( ! $event instanceof Recovery_Event || ! $event->is_open() ) {
			return false;
		}

		$source = $context['source'] ?? null;

		if ( ! $source instanceof Recovery_Source_Interface ) {
			return false;
		}

		try {
			return ! $source->is_conversion_complete( $event );
		} catch ( \Throwable $error ) {
			// Only the class of the failure is recorded. A source's exception
			// message can carry an order number or an address, and this line
			// ends up in a table an administrator can export.
			if ( $this->logger instanceof Logger ) {
				$this->logger->error(
					'workflow',
					'Source could not confirm whether the order completed: ' . get_class( $error ),
					array( 'source' => $journey->source_id ),
					$journey->id
				);
			}

			return false;
		}
	}
}
