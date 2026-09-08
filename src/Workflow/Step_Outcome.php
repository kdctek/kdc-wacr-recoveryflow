<?php
/**
 * What running a journey produced.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Workflow;

defined( 'ABSPATH' ) || exit;

/**
 * The engine's answer to "what happened to this journey?".
 *
 * LOST_RACE is the one that matters. A journey can change underneath a
 * background run -- the customer completes the order while the batch is
 * deciding to message them -- and the correct response is to abandon the work
 * silently rather than to retry it. Making that a first-class outcome rather
 * than a false return value means the dispatcher can count it, report it, and
 * never confuse it with a failure worth alerting anybody about.
 */
final class Step_Outcome {

	public const WAITING   = 'waiting';
	public const SENT      = 'sent';
	public const STOPPED   = 'stopped';
	public const SKIPPED   = 'skipped';
	public const FAILED    = 'failed';
	public const LOST_RACE = 'lost_race';

	/**
	 * One of the class constants.
	 *
	 * @var string
	 */
	public string $status;

	/**
	 * Short machine-readable detail.
	 *
	 * @var string
	 */
	public string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $status One of the class constants.
	 * @param string $reason Machine-readable detail.
	 */
	public function __construct( string $status, string $reason = '' ) {
		$this->status = $status;
		$this->reason = $reason;
	}

	/**
	 * Build an outcome.
	 *
	 * @param string $status One of the class constants.
	 * @param string $reason Machine-readable detail.
	 * @return Step_Outcome
	 */
	public static function of( string $status, string $reason = '' ): self {
		return new self( $status, $reason );
	}

	/**
	 * Whether the journey is finished with.
	 *
	 * @return bool
	 */
	public function is_final(): bool {
		return in_array( $this->status, array( self::STOPPED, self::FAILED ), true );
	}

	/**
	 * Whether the dispatcher should stop working the whole batch.
	 *
	 * Set when the failure is about the connection rather than this journey --
	 * a rate limit or a rejected key will fail every remaining journey in the
	 * same way, and burning the batch to find that out helps nobody.
	 *
	 * @var bool
	 */
	public bool $stop_batch = false;

	/**
	 * Mark this outcome as one that should end the batch.
	 *
	 * @return Step_Outcome
	 */
	public function and_stop_batch(): self {
		$this->stop_batch = true;

		return $this;
	}
}
