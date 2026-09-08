<?php
/**
 * What every background stage looks like.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * One unit of background work, told how long it has.
 *
 * A stage is handed the budget rather than making its own, because the WP-Cron
 * driver runs all five in one request and they have to share. A stage that
 * ignored the budget would be the reason the fifth one never ran.
 *
 * A stage owns no locking of its own. Stage_Runner takes the lock named by
 * lock_key() before run() is called and gives it back afterwards, so a stage
 * body can be read as though it were the only thing touching those rows.
 */
interface Stage_Interface {

	/**
	 * The stage's key, one of the Scheduler_Interface stage constants.
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * The row in the locks table this stage runs under.
	 *
	 * @return string
	 */
	public function lock_key(): string;

	/**
	 * Do as much of the work as the budget allows.
	 *
	 * @param Time_Budget $budget How long there is.
	 * @return Stage_Stats What happened, including whether work is left over.
	 */
	public function run( Time_Budget $budget ): Stage_Stats;
}
