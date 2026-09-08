<?php
/**
 * Test doubles that reach into protected behaviour.
 *
 * Kept out of the runner so that file stays a list of assertions.
 *
 * @package WAcr\RecoveryFlow
 */

use WAcr\RecoveryFlow\WAcr\Transport;

/**
 * Exposes the transport's protected failure classification for testing.
 *
 * The distinction between "never left" and "might have arrived" is the whole
 * of the retry design, so it is worth asserting directly rather than only
 * through the errors it produces.
 */
class Transport_Probe extends Transport {

	/**
	 * Classify a transport failure.
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message.
	 * @return bool
	 */
	public function probe( string $code, string $message ): bool {
		return $this->may_have_been_sent( $code, $message );
	}
}
