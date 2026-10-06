<?php
/**
 * The condition that stops chasing somebody who never answers.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow\Conditions;

use WAcr\RecoveryFlow\Core\Clock;
use WAcr\RecoveryFlow\Core\Hooks;
use WAcr\RecoveryFlow\Recovery\Journey_Repository;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Has this customer ignored fewer than N earlier recoveries?
 *
 * Somebody who has let two recoveries run their course without opening the
 * link, replying or buying is unlikely to answer a third, and on WhatsApp
 * every one of those messages is billed. This lets a merchant stop paying for
 * them -- and, placed in front of a last touch that carries a discount, stop
 * giving that discount to the people least likely to use it.
 *
 * It saves messages; it does not recover more baskets. No rule built on a
 * shop's own history can show that a discount was unnecessary for somebody,
 * because that needs a group who were not sent it. The honest description is
 * "fewer wasted sends", and the label says nothing grander.
 *
 * Written as "customer.ignored_fewer_than:2". With no number it means 2. Only
 * recoveries started within the look-back window count -- 180 days unless the
 * recoveryflow_ignored_lookback_days filter says otherwise -- because a
 * customer who keeps coming back keeps one record for as long as they do, and
 * two ignored reminders from years ago say little about today.
 *
 * What counts as ignored is Journey_Repository::count_ignored_since()'s to
 * decide, and every mistake it can make is towards sending one more message.
 * A recovery this condition itself stopped before any message went out is
 * never counted, so a customer cannot be talked into silence by the rule.
 */
final class Customer_Ignored_Fewer_Than implements Condition_Interface, Condition_Argument_Interface {

	/**
	 * The name a workflow refers to this by.
	 */
	public const ID = 'customer.ignored_fewer_than';

	/**
	 * What a step with no number means.
	 */
	public const DEFAULT_LIMIT = 2;

	/**
	 * The largest number accepted.
	 */
	public const MAX_LIMIT = 20;

	/**
	 * How far back to look, in days, unless filtered.
	 */
	public const DEFAULT_LOOKBACK_DAYS = 180;

	/**
	 * Journey storage.
	 *
	 * @var Journey_Repository
	 */
	private Journey_Repository $journeys;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Journey_Repository $journeys Journey storage.
	 * @param Clock              $clock    Clock.
	 */
	public function __construct( Journey_Repository $journeys, Clock $clock ) {
		$this->journeys = $journeys;
		$this->clock    = $clock;
	}

	/**
	 * The name a workflow refers to this by.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * One sentence for the workflow editor.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'The customer has ignored fewer than a set number of earlier recoveries', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * The label for the number box.
	 *
	 * @return string
	 */
	public function get_argument_label(): string {
		return __( 'Earlier recoveries ignored', 'kdc-wacr-recoveryflow' );
	}

	/**
	 * What the number means.
	 *
	 * @return string
	 */
	public function get_argument_help(): string {
		return sprintf(
			/* translators: 1: the largest number accepted, 2: the default number, 3: how many days back are counted. */
			__( 'A whole number from 1 to %1$d. Leave it empty for %2$d. A recovery counts as ignored when a message went out and nobody opened the link, replied or bought before it ended. Only recoveries from the last %3$d days count.', 'kdc-wacr-recoveryflow' ),
			self::MAX_LIMIT,
			self::DEFAULT_LIMIT,
			$this->lookback_days()
		);
	}

	/**
	 * The number box's bounds.
	 *
	 * @return array{min:string,max:string,step:string}
	 */
	public function get_argument_bounds(): array {
		return array(
			'min'  => '1',
			'max'  => (string) self::MAX_LIMIT,
			'step' => '1',
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

		if ( 1 !== preg_match( '/^[0-9]{1,3}$/', $raw ) ) {
			return null;
		}

		$limit = (int) $raw;

		return $limit >= 1 && $limit <= self::MAX_LIMIT ? (string) $limit : null;
	}

	/**
	 * Answer the question.
	 *
	 * @param Recovery_Journey    $journey  The journey being run.
	 * @param array<string,mixed> $context  Run context.
	 * @param string              $argument The limit, as stored in the step.
	 * @return bool True to carry on.
	 */
	public function evaluate( Recovery_Journey $journey, array $context, string $argument = '' ): bool {
		$limit = $this->normalize_argument( $argument );

		if ( null === $limit ) {
			// A limit nobody can read is not a reason to message somebody.
			return false;
		}

		$limit = '' === $limit ? self::DEFAULT_LIMIT : (int) $limit;

		if ( $journey->customer_id <= 0 ) {
			// No customer, no history. Whether they may be messaged at all is
			// customer.eligible's question.
			return true;
		}

		$since = $this->clock->offset( -$this->lookback_days() * DAY_IN_SECONDS );

		return $this->journeys->count_ignored_since( $journey->customer_id, $since, $journey->id ) < $limit;
	}

	/**
	 * How many days back recoveries count.
	 *
	 * @return int At least one.
	 */
	public function lookback_days(): int {
		/**
		 * Filters how many days back an ignored recovery still counts.
		 *
		 * @param int $days Days. Default 180.
		 */
		$days = (int) apply_filters( Hooks::FILTER_IGNORED_LOOKBACK, self::DEFAULT_LOOKBACK_DAYS );

		return max( 1, $days );
	}
}
