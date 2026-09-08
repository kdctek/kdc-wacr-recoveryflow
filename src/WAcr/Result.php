<?php
/**
 * API call outcome.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\WAcr;

defined( 'ABSPATH' ) || exit;

/**
 * Either a value or an error, never both.
 *
 * Returned instead of WP_Error so that a caller cannot accidentally use a
 * failed call's payload, and so the error carries the categories the retry
 * logic needs.
 */
final class Result {

	/**
	 * Whether the call succeeded.
	 *
	 * @var bool
	 */
	public bool $ok;

	/**
	 * The decoded payload, on success.
	 *
	 * @var array<string,mixed>
	 */
	public array $value;

	/**
	 * What went wrong, on failure.
	 *
	 * @var Error|null
	 */
	public ?Error $error;

	/**
	 * HTTP status, where there was one.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Constructor.
	 *
	 * @param bool       $ok     Success.
	 * @param array      $value  Payload.
	 * @param Error|null $error  Failure.
	 * @param int        $status HTTP status.
	 */
	private function __construct( bool $ok, array $value, ?Error $error, int $status ) {
		$this->ok     = $ok;
		$this->value  = $value;
		$this->error  = $error;
		$this->status = $status;
	}

	/**
	 * A successful call.
	 *
	 * @param array $value  Decoded payload.
	 * @param int   $status HTTP status.
	 * @return Result
	 */
	public static function success( array $value, int $status = 200 ): self {
		return new self( true, $value, null, $status );
	}

	/**
	 * A failed call.
	 *
	 * @param Error $error What went wrong.
	 * @return Result
	 */
	public static function failure( Error $error ): self {
		return new self( false, array(), $error, $error->status );
	}

	/**
	 * One value from the payload.
	 *
	 * @param string $key      Top-level key.
	 * @param mixed  $fallback Returned when absent.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->value ) ? $this->value[ $key ] : $fallback;
	}

	/**
	 * The error code, or '' on success.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->error instanceof Error ? $this->error->code : '';
	}

	/**
	 * The administrator-facing message, or '' on success.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->error instanceof Error ? $this->error->message : '';
	}
}
