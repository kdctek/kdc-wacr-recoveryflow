<?php
/**
 * What each background pass is called, in words.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a background pass is given a human name.
 *
 * Two screens now print these -- the status screen's table of what each pass
 * last did, and the result of running them by hand -- and they sit one above
 * the other on the same page. Wording kept in each screen separately drifts,
 * and the reader of a page naming the same pass two different ways has to work
 * out whether they are the same thing.
 *
 * The stored value stays a machine key. This is the label, made at the moment
 * of rendering, which is why it is a method and not a constant: a translated
 * string built at class-load time is built before the locale is known.
 */
final class Stage_Label {

	/**
	 * One pass, in words.
	 *
	 * An unknown key is returned as it came in rather than replaced with
	 * "Unknown": a stage another plugin registered has a key that means
	 * something to whoever registered it, and hiding it helps nobody.
	 *
	 * @param string $stage Stage key.
	 * @return string
	 */
	public static function for_stage( string $stage ): string {
		switch ( $stage ) {
			case Scheduler_Interface::EVALUATE:
				return __( 'Finding abandoned baskets', 'kdc-wacr-recoveryflow' );

			case Scheduler_Interface::DISPATCH:
				return __( 'Sending reminders', 'kdc-wacr-recoveryflow' );

			case Scheduler_Interface::POLL:
				return __( 'Checking for replies', 'kdc-wacr-recoveryflow' );

			case Scheduler_Interface::EXPIRE:
				return __( 'Closing recoveries that ran out of time', 'kdc-wacr-recoveryflow' );

			case Scheduler_Interface::RETENTION:
				return __( 'Clearing out old data', 'kdc-wacr-recoveryflow' );

			default:
				return $stage;
		}
	}
}
