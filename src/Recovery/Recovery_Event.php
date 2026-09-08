<?php
/**
 * A detected, unfinished conversion.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Something a visitor started and did not finish.
 *
 * This is deliberately not a cart. A cart is one shape a lost conversion takes;
 * a half-finished booking, an abandoned multi-step form and an unpaid ticket
 * reservation are others, and all of them are worth a message. Adapters
 * translate their own vocabulary into this one, which is why the core knows
 * nothing about products, line items or WooCommerce.
 *
 * The fields that look like a cart -- amount, item_count, items -- are here
 * because "how much was it worth" and "what was in it" are the two questions
 * every recovery message needs answered, whatever the source. Everything
 * source-specific goes in metadata, which is contractually free of personal
 * data: identity lives on the customer record, not on the event.
 */
final class Recovery_Event {

	public const OPEN      = 'open';
	public const COMPLETED = 'completed';
	public const INVALID   = 'invalid';
	public const EXPIRED   = 'expired';

	/**
	 * Row id.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Public identifier, stable for the life of the event.
	 *
	 * @var string
	 */
	public string $event_uid = '';

	/**
	 * Which adapter detected this.
	 *
	 * @var string
	 */
	public string $source_id = '';

	/**
	 * What kind of thing was abandoned: cart, checkout, form, booking, ticket.
	 *
	 * @var string
	 */
	public string $source_type = '';

	/**
	 * The adapter's stable key for this open journey.
	 *
	 * @var string
	 */
	public string $dedupe_key = '';

	/**
	 * Resolved customer, once there is one.
	 *
	 * @var int|null
	 */
	public ?int $customer_id = null;

	/**
	 * Journey created from this event, once there is one.
	 *
	 * @var int|null
	 */
	public ?int $journey_id = null;

	/**
	 * The source's session identifier, for attributing a later order.
	 *
	 * @var string|null
	 */
	public ?string $session_key = null;

	/**
	 * The source's own id for the thing, e.g. an order or entry id.
	 *
	 * @var string|null
	 */
	public ?string $external_id = null;

	/**
	 * ISO 4217 currency code.
	 *
	 * @var string
	 */
	public string $currency = '';

	/**
	 * Value in major units, as a decimal string so it never loses precision.
	 *
	 * @var string
	 */
	public string $amount = '0';

	/**
	 * How many things were in it.
	 *
	 * @var int
	 */
	public int $item_count = 0;

	/**
	 * Current status: one of the class constants.
	 *
	 * @var string
	 */
	public string $status = self::OPEN;

	/**
	 * Why the status is what it is.
	 *
	 * @var string|null
	 */
	public ?string $status_reason = null;

	/**
	 * Snapshot of what was in it. Names and references only, never personal data.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $items = array();

	/**
	 * Source-specific detail. Contractually free of personal data.
	 *
	 * @var array<string,mixed>
	 */
	public array $metadata = array();

	/**
	 * When the visitor last touched it, UTC.
	 *
	 * @var string
	 */
	public string $last_activity_at = '';

	/**
	 * When it was finished, UTC, if it was.
	 *
	 * @var string|null
	 */
	public ?string $completed_at = null;

	/**
	 * When the row was created, UTC.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * When the row last changed, UTC.
	 *
	 * @var string
	 */
	public string $updated_at = '';

	/**
	 * Build one from a database row.
	 *
	 * @param array<string,mixed> $row Row as returned by the repository.
	 * @return Recovery_Event
	 */
	public static function from_row( array $row ): self {
		$event = new self();

		$event->id               = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$event->event_uid        = (string) ( $row['event_uid'] ?? '' );
		$event->source_id        = (string) ( $row['source_id'] ?? '' );
		$event->source_type      = (string) ( $row['source_type'] ?? '' );
		$event->dedupe_key       = (string) ( $row['dedupe_key'] ?? '' );
		$event->customer_id      = isset( $row['customer_id'] ) ? (int) $row['customer_id'] : null;
		$event->journey_id       = isset( $row['journey_id'] ) ? (int) $row['journey_id'] : null;
		$event->session_key      = isset( $row['session_key'] ) ? (string) $row['session_key'] : null;
		$event->external_id      = isset( $row['external_id'] ) ? (string) $row['external_id'] : null;
		$event->currency         = (string) ( $row['currency'] ?? '' );
		$event->amount           = (string) ( $row['amount'] ?? '0' );
		$event->item_count       = isset( $row['item_count'] ) ? (int) $row['item_count'] : 0;
		$event->status           = (string) ( $row['status'] ?? self::OPEN );
		$event->status_reason    = isset( $row['status_reason'] ) ? (string) $row['status_reason'] : null;
		$event->items            = self::decode( $row['items_json'] ?? null );
		$event->metadata         = self::decode( $row['metadata_json'] ?? null );
		$event->last_activity_at = (string) ( $row['last_activity_at'] ?? '' );
		$event->completed_at     = isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null;
		$event->created_at       = (string) ( $row['created_at'] ?? '' );
		$event->updated_at       = (string) ( $row['updated_at'] ?? '' );

		return $event;
	}

	/**
	 * Whether this event is still worth working on.
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return self::OPEN === $this->status;
	}

	/**
	 * The amount as a float, for comparisons only.
	 *
	 * Never use this to display or transmit money: the decimal string is the
	 * value of record, and this is a lossy convenience for threshold checks.
	 *
	 * @return float
	 */
	public function amount_value(): float {
		return (float) $this->amount;
	}

	/**
	 * The name of the first item, for message variables.
	 *
	 * @return string
	 */
	public function first_item_name(): string {
		foreach ( $this->items as $item ) {
			if ( isset( $item['name'] ) && '' !== (string) $item['name'] ) {
				return (string) $item['name'];
			}
		}

		return '';
	}

	/**
	 * A short, plain-text summary of the contents for a message variable.
	 *
	 * @param int $max_items How many to name before summarising the rest.
	 * @return string
	 */
	public function items_summary( int $max_items = 2 ): string {
		$names = array();

		foreach ( $this->items as $item ) {
			$name = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		if ( array() === $names ) {
			return '';
		}

		$shown     = array_slice( $names, 0, max( 1, $max_items ) );
		$remaining = count( $names ) - count( $shown );

		if ( $remaining < 1 ) {
			return implode( ', ', $shown );
		}

		return sprintf(
			/* translators: 1: comma-separated list of item names, 2: how many further items there are. */
			_n( '%1$s and %2$d more', '%1$s and %2$d more', $remaining, 'kdc-wacr-recoveryflow' ),
			implode( ', ', $shown ),
			$remaining
		);
	}

	/**
	 * Decode a stored JSON column.
	 *
	 * @param mixed $json Raw column value.
	 * @return array<int|string,mixed>
	 */
	private static function decode( $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
