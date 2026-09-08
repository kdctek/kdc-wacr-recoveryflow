<?php
/**
 * One execution of one workflow action.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * The row that makes a send safe to retry.
 *
 * The WA.cr messaging API has no idempotency key of its own, so the plugin
 * supplies the guarantee itself: the row is inserted under a UNIQUE key before
 * the HTTP call, never after. If the same step is dispatched twice -- two cron
 * runs, a lease that expired mid-flight, an administrator pressing retry -- the
 * second insert loses to the index and the second send never happens. Without
 * this the customer gets the same reminder twice and the merchant is billed
 * twice for it.
 *
 * The three-way status matters as much as the key. A request that failed before
 * it was written is not_sent and may be repeated; a request that timed out
 * after it was written is unknown, and unknown is never retried blindly -- the
 * conversation is re-read first to find out what actually happened.
 *
 * Each attempt also owns exactly one recovery token, which is why a retry mints
 * a new one rather than reusing the old: a link that has already reached a
 * customer keeps working, and a link that was never delivered is worthless.
 */
final class Attempt {

	public const PENDING   = 'pending';
	public const SENDING   = 'sending';
	public const SENT      = 'sent';
	public const DELIVERED = 'delivered';
	public const READ      = 'read';
	public const FAILED    = 'failed';
	public const UNKNOWN   = 'unknown';
	public const SKIPPED   = 'skipped';

	/**
	 * Row id.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * The journey this belongs to.
	 *
	 * @var int
	 */
	public int $journey_id = 0;

	/**
	 * Which workflow step produced it.
	 *
	 * @var int
	 */
	public int $step_index = 0;

	/**
	 * Which try this is for that step.
	 *
	 * @var int
	 */
	public int $attempt_no = 1;

	/**
	 * The uniqueness key: journey uid, step and attempt number.
	 *
	 * @var string
	 */
	public string $idempotency_key = '';

	/**
	 * Which workflow action ran, e.g. 'wacr.send_template'.
	 *
	 * @var string
	 */
	public string $action_type = '';

	/**
	 * Delivery channel.
	 *
	 * @var string
	 */
	public string $channel = 'whatsapp';

	/**
	 * The approved template used.
	 *
	 * @var string|null
	 */
	public ?string $template_name = null;

	/**
	 * Template language.
	 *
	 * @var string|null
	 */
	public ?string $language_code = null;

	/**
	 * Hash of the recovery token. The token itself is never stored.
	 *
	 * @var string|null
	 */
	public ?string $token_hash = null;

	/**
	 * When the link stops working, UTC.
	 *
	 * @var string|null
	 */
	public ?string $token_expires_at = null;

	/**
	 * When the link was withdrawn, UTC.
	 *
	 * @var string|null
	 */
	public ?string $token_revoked_at = null;

	/**
	 * WA.cr's id for the message.
	 *
	 * @var string|null
	 */
	public ?string $wacr_message_id = null;

	/**
	 * The upstream provider's id for the message.
	 *
	 * @var string|null
	 */
	public ?string $provider_message_id = null;

	/**
	 * Current status. One of the class constants.
	 *
	 * @var string
	 */
	public string $status = self::PENDING;

	/**
	 * Machine-readable failure code.
	 *
	 * @var string|null
	 */
	public ?string $error_code = null;

	/**
	 * Administrator-facing failure message. Never contains customer data.
	 *
	 * @var string|null
	 */
	public ?string $error_message = null;

	/**
	 * When this action was due, UTC.
	 *
	 * @var string
	 */
	public string $scheduled_at = '';

	/**
	 * When the request was started, UTC.
	 *
	 * @var string|null
	 */
	public ?string $sending_started_at = null;

	/**
	 * When the message was accepted, UTC.
	 *
	 * @var string|null
	 */
	public ?string $sent_at = null;

	/**
	 * When an unknown outcome should be resolved, UTC.
	 *
	 * @var string|null
	 */
	public ?string $reconcile_after = null;

	/**
	 * When delivery status was last checked, UTC.
	 *
	 * @var string|null
	 */
	public ?string $status_checked_at = null;

	/**
	 * When the row was created, UTC.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * Build one from a database row.
	 *
	 * @param array<string,mixed> $row Row as returned by the repository.
	 * @return Attempt
	 */
	public static function from_row( array $row ): self {
		$attempt = new self();

		$attempt->id                  = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$attempt->journey_id          = isset( $row['journey_id'] ) ? (int) $row['journey_id'] : 0;
		$attempt->step_index          = isset( $row['step_index'] ) ? (int) $row['step_index'] : 0;
		$attempt->attempt_no          = isset( $row['attempt_no'] ) ? (int) $row['attempt_no'] : 1;
		$attempt->idempotency_key     = (string) ( $row['idempotency_key'] ?? '' );
		$attempt->action_type         = (string) ( $row['action_type'] ?? '' );
		$attempt->channel             = (string) ( $row['channel'] ?? 'whatsapp' );
		$attempt->template_name       = isset( $row['template_name'] ) ? (string) $row['template_name'] : null;
		$attempt->language_code       = isset( $row['language_code'] ) ? (string) $row['language_code'] : null;
		$attempt->token_hash          = isset( $row['token_hash'] ) ? (string) $row['token_hash'] : null;
		$attempt->token_expires_at    = isset( $row['token_expires_at'] ) ? (string) $row['token_expires_at'] : null;
		$attempt->token_revoked_at    = isset( $row['token_revoked_at'] ) ? (string) $row['token_revoked_at'] : null;
		$attempt->wacr_message_id     = isset( $row['wacr_message_id'] ) ? (string) $row['wacr_message_id'] : null;
		$attempt->provider_message_id = isset( $row['provider_message_id'] ) ? (string) $row['provider_message_id'] : null;
		$attempt->status              = (string) ( $row['status'] ?? self::PENDING );
		$attempt->error_code          = isset( $row['error_code'] ) ? (string) $row['error_code'] : null;
		$attempt->error_message       = isset( $row['error_message'] ) ? (string) $row['error_message'] : null;
		$attempt->scheduled_at        = (string) ( $row['scheduled_at'] ?? '' );
		$attempt->sending_started_at  = isset( $row['sending_started_at'] ) ? (string) $row['sending_started_at'] : null;
		$attempt->sent_at             = isset( $row['sent_at'] ) ? (string) $row['sent_at'] : null;
		$attempt->reconcile_after     = isset( $row['reconcile_after'] ) ? (string) $row['reconcile_after'] : null;
		$attempt->status_checked_at   = isset( $row['status_checked_at'] ) ? (string) $row['status_checked_at'] : null;
		$attempt->created_at          = (string) ( $row['created_at'] ?? '' );

		return $attempt;
	}

	/**
	 * The uniqueness key for a given step and try.
	 *
	 * @param string $journey_uid Journey identifier.
	 * @param int    $step_index  Workflow step.
	 * @param int    $attempt_no  Which try.
	 * @return string
	 */
	public static function key( string $journey_uid, int $step_index, int $attempt_no ): string {
		return $journey_uid . ':' . $step_index . ':' . $attempt_no;
	}

	/**
	 * Whether the message definitely reached WA.cr.
	 *
	 * @return bool
	 */
	public function was_sent(): bool {
		return in_array( $this->status, array( self::SENT, self::DELIVERED, self::READ ), true );
	}

	/**
	 * Whether this attempt is still in flight or unresolved.
	 *
	 * @return bool
	 */
	public function needs_resolution(): bool {
		return in_array( $this->status, array( self::SENDING, self::UNKNOWN ), true );
	}

	/**
	 * Whether the recovery link on this attempt can still be used.
	 *
	 * @param string $now UTC datetime to compare against.
	 * @return bool
	 */
	public function link_is_usable( string $now ): bool {
		if ( null === $this->token_hash || null !== $this->token_revoked_at ) {
			return false;
		}

		return null === $this->token_expires_at || $this->token_expires_at > $now;
	}
}
