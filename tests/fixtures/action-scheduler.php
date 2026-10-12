<?php
/**
 * Just enough of Action Scheduler's facade for the driver's unit test.
 *
 * Lives in tests/fixtures rather than in the test because a file may hold one
 * class, in one namespace, and the real ActionScheduler class is global.
 *
 * @package WAcr\RecoveryFlow
 */

if ( ! class_exists( 'ActionScheduler' ) ) {
	/**
	 * Just enough of Action Scheduler's facade to cancel by id.
	 */
	final class ActionScheduler {

		/**
		 * Every id cancelled, in order, including refused repeats.
		 *
		 * @var array<int,int>
		 */
		public static array $cancelled = array();

		/**
		 * The store.
		 *
		 * @return object
		 */
		public static function store() {
			return new class() {

				/**
				 * Cancel one action; a second cancel of the same row throws, as the real store does.
				 *
				 * @param int $id Action id.
				 * @return void
				 * @throws \InvalidArgumentException When the action is already cancelled.
				 */
				public function cancel_action( $id ) {
					$already                       = in_array( $id, \ActionScheduler::$cancelled, true );
					\ActionScheduler::$cancelled[] = $id;
					if ( $already ) {
						throw new \InvalidArgumentException( 'Already cancelled.' );
					}
				}
			};
		}
	}
}
