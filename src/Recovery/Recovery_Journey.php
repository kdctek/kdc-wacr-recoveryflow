<?php
/**
 * One attempt to recover one lost conversion.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * The state machine's row.
 *
 * An event says what was abandoned; a journey is what the plugin does about it.
 * They are separate because most events never become journeys -- an anonymous
 * cart cannot be messaged, and turning a hundred thousand of them into a
 * hundred thousand unmessageable journey rows would cost a store its database
 * for nothing.
 *
 * Two clocks run on this row and they are deliberately separate columns.
 * next_action_at is when the workflow should do something; poll_at is when the
 * conversation should be re-read to see whether the customer replied. Sharing
 * one column would mean a reply check could postpone a send, or a send could
 * lose a reply.
 *
 * claim_token and claimed_until are the lease. A background run marks a batch
 * as its own before working it, so two runs -- and WordPress will give you two
 * runs -- cannot both dispatch the same journey.
 */
final class Recovery_Journey {

	/**
	 * Row id.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Public identifier. Random, so it leaks no volume information.
	 *
	 * @var string
	 */
	public string $journey_uid = '';

	/**
	 * The event this recovers.
	 *
	 * @var int
	 */
	public int $event_id = 0;

	/**
	 * Who is being recovered.
	 *
	 * @var int
	 */
	public int $customer_id = 0;

	/**
	 * Which adapter detected the event.
	 *
	 * @var string
	 */
	public string $source_id = '';

	/**
	 * The workflow being followed.
	 *
	 * @var int
	 */
	public int $workflow_id = 0;

	/**
	 * The exact version of that workflow, pinned at creation.
	 *
	 * A merchant editing a workflow must not change what a running journey will
	 * do next: a customer should never receive step two of a sequence they were
	 * never enrolled in.
	 *
	 * @var int
	 */
	public int $workflow_version = 1;

	/**
	 * Current state. One of the Journey_State constants.
	 *
	 * @var string
	 */
	public string $status = Journey_State::NEW;

	/**
	 * Why it is in that state.
	 *
	 * @var string|null
	 */
	public ?string $status_reason = null;

	/**
	 * How far through the workflow it has got.
	 *
	 * @var int
	 */
	public int $current_step = 0;

	/**
	 * When the workflow should next run, UTC.
	 *
	 * @var string|null
	 */
	public ?string $next_action_at = null;

	/**
	 * When the conversation should next be re-read, UTC.
	 *
	 * @var string|null
	 */
	public ?string $poll_at = null;

	/**
	 * How many times it has been re-read, which sets the next interval.
	 *
	 * @var int
	 */
	public int $poll_count = 0;

	/**
	 * When this stops being worth recovering, UTC.
	 *
	 * @var string
	 */
	public string $expires_at = '';

	/**
	 * The lease held by a background run, if any.
	 *
	 * @var string|null
	 */
	public ?string $claim_token = null;

	/**
	 * When that lease runs out, UTC.
	 *
	 * @var string|null
	 */
	public ?string $claimed_until = null;

	/**
	 * How many messages have been attempted.
	 *
	 * @var int
	 */
	public int $attempts_count = 0;

	/**
	 * How many times a failed payment has handed this journey back. Capped at one.
	 *
	 * @var int
	 */
	public int $resume_count = 0;

	/**
	 * When the first message went out, UTC.
	 *
	 * @var string|null
	 */
	public ?string $first_sent_at = null;

	/**
	 * How many times a recovery link has been followed.
	 *
	 * @var int
	 */
	public int $clicks_count = 0;

	/**
	 * When it was last followed, UTC.
	 *
	 * @var string|null
	 */
	public ?string $last_click_at = null;

	/**
	 * When the customer engaged, UTC.
	 *
	 * @var string|null
	 */
	public ?string $engaged_at = null;

	/**
	 * How they engaged: 'reply' or 'click'.
	 *
	 * @var string|null
	 */
	public ?string $engaged_via = null;

	/**
	 * When the conversion completed, UTC.
	 *
	 * @var string|null
	 */
	public ?string $recovered_at = null;

	/**
	 * The source's id for the completed thing, e.g. an order number.
	 *
	 * @var string|null
	 */
	public ?string $recovered_external_id = null;

	/**
	 * What it was worth, as a decimal string.
	 *
	 * @var string|null
	 */
	public ?string $recovered_amount = null;

	/**
	 * How confidently the conversion was tied to this journey: exact, session or customer.
	 *
	 * @var string|null
	 */
	public ?string $attribution = null;

	/**
	 * The last thing that went wrong.
	 *
	 * @var string|null
	 */
	public ?string $last_error_code = null;

	/**
	 * When it went wrong, UTC.
	 *
	 * @var string|null
	 */
	public ?string $last_error_at = null;

	/**
	 * When the journey was created, UTC.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * When it last changed, UTC.
	 *
	 * @var string
	 */
	public string $updated_at = '';

	/**
	 * How a conversion was tied back to a journey.
	 */
	public const ATTRIBUTION_EXACT    = 'exact';
	public const ATTRIBUTION_SESSION  = 'session';
	public const ATTRIBUTION_CUSTOMER = 'customer';

	/**
	 * Build one from a database row.
	 *
	 * @param array<string,mixed> $row Row as returned by the repository.
	 * @return Recovery_Journey
	 */
	public static function from_row( array $row ): self {
		$journey = new self();

		$journey->id                    = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$journey->journey_uid           = (string) ( $row['journey_uid'] ?? '' );
		$journey->event_id              = isset( $row['event_id'] ) ? (int) $row['event_id'] : 0;
		$journey->customer_id           = isset( $row['customer_id'] ) ? (int) $row['customer_id'] : 0;
		$journey->source_id             = (string) ( $row['source_id'] ?? '' );
		$journey->workflow_id           = isset( $row['workflow_id'] ) ? (int) $row['workflow_id'] : 0;
		$journey->workflow_version      = isset( $row['workflow_version'] ) ? (int) $row['workflow_version'] : 1;
		$journey->status                = (string) ( $row['status'] ?? Journey_State::NEW );
		$journey->status_reason         = isset( $row['status_reason'] ) ? (string) $row['status_reason'] : null;
		$journey->current_step          = isset( $row['current_step'] ) ? (int) $row['current_step'] : 0;
		$journey->next_action_at        = isset( $row['next_action_at'] ) ? (string) $row['next_action_at'] : null;
		$journey->poll_at               = isset( $row['poll_at'] ) ? (string) $row['poll_at'] : null;
		$journey->poll_count            = isset( $row['poll_count'] ) ? (int) $row['poll_count'] : 0;
		$journey->expires_at            = (string) ( $row['expires_at'] ?? '' );
		$journey->claim_token           = isset( $row['claim_token'] ) ? (string) $row['claim_token'] : null;
		$journey->claimed_until         = isset( $row['claimed_until'] ) ? (string) $row['claimed_until'] : null;
		$journey->attempts_count        = isset( $row['attempts_count'] ) ? (int) $row['attempts_count'] : 0;
		$journey->resume_count          = isset( $row['resume_count'] ) ? (int) $row['resume_count'] : 0;
		$journey->first_sent_at         = isset( $row['first_sent_at'] ) ? (string) $row['first_sent_at'] : null;
		$journey->clicks_count          = isset( $row['clicks_count'] ) ? (int) $row['clicks_count'] : 0;
		$journey->last_click_at         = isset( $row['last_click_at'] ) ? (string) $row['last_click_at'] : null;
		$journey->engaged_at            = isset( $row['engaged_at'] ) ? (string) $row['engaged_at'] : null;
		$journey->engaged_via           = isset( $row['engaged_via'] ) ? (string) $row['engaged_via'] : null;
		$journey->recovered_at          = isset( $row['recovered_at'] ) ? (string) $row['recovered_at'] : null;
		$journey->recovered_external_id = isset( $row['recovered_external_id'] ) ? (string) $row['recovered_external_id'] : null;
		$journey->recovered_amount      = isset( $row['recovered_amount'] ) ? (string) $row['recovered_amount'] : null;
		$journey->attribution           = isset( $row['attribution'] ) ? (string) $row['attribution'] : null;
		$journey->last_error_code       = isset( $row['last_error_code'] ) ? (string) $row['last_error_code'] : null;
		$journey->last_error_at         = isset( $row['last_error_at'] ) ? (string) $row['last_error_at'] : null;
		$journey->created_at            = (string) ( $row['created_at'] ?? '' );
		$journey->updated_at            = (string) ( $row['updated_at'] ?? '' );

		return $journey;
	}

	/**
	 * Whether this journey is still being worked.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return Journey_State::is_active( $this->status );
	}

	/**
	 * Whether this journey is finished with, whatever the outcome.
	 *
	 * @return bool
	 */
	public function is_terminal(): bool {
		return Journey_State::is_terminal( $this->status );
	}

	/**
	 * Whether a recovery message may still be sent for it.
	 *
	 * @return bool
	 */
	public function may_send(): bool {
		return Journey_State::may_send( $this->status );
	}

	/**
	 * Whether the customer has done anything in response.
	 *
	 * @return bool
	 */
	public function is_engaged(): bool {
		return null !== $this->engaged_at;
	}
}
