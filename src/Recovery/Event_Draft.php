<?php
/**
 * What an adapter reports.
 *
 * @package WAcr\RecoveryFlow
 */

namespace WAcr\RecoveryFlow\Recovery;

use WAcr\RecoveryFlow\Customer\Identity_Hints;

defined( 'ABSPATH' ) || exit;

/**
 * An adapter's description of an unfinished conversion, before the core has
 * decided what to do about it.
 *
 * A draft is not an event. Adapters run inside somebody's page request, where
 * the only acceptable cost is one indexed write, so they describe what they see
 * and hand it over; deciding whether it is worth recovering, resolving who the
 * visitor is and creating a journey all happen later, in the background.
 *
 * Nothing here may carry personal data except through identity, which is the
 * one field the privacy tooling knows to look at. Putting a phone number in
 * metadata to save a lookup would put it in the event row, where the exporter,
 * the eraser and the redactor would never find it.
 */
final class Event_Draft {

	/**
	 * Which adapter is reporting.
	 *
	 * @var string
	 */
	public string $source_id;

	/**
	 * What kind of thing this is: cart, checkout, form, booking, ticket.
	 *
	 * @var string
	 */
	public string $source_type;

	/**
	 * The adapter's stable key for this open journey.
	 *
	 * One key per thing-in-progress, reused as it changes: the same shopping
	 * session's cart must upsert onto one row rather than accumulating a row
	 * per keystroke.
	 *
	 * @var string
	 */
	public string $dedupe_key;

	/**
	 * The source's session identifier, kept so a later order can be attributed.
	 *
	 * @var string
	 */
	public string $session_key = '';

	/**
	 * The source's own id for the thing, if it has one yet.
	 *
	 * @var string
	 */
	public string $external_id = '';

	/**
	 * ISO 4217 currency code.
	 *
	 * @var string
	 */
	public string $currency = '';

	/**
	 * Value in major units as a decimal string.
	 *
	 * @var string
	 */
	public string $amount = '0';

	/**
	 * How many things are in it.
	 *
	 * @var int
	 */
	public int $item_count = 0;

	/**
	 * Contents: names, references and quantities. Never personal data.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $items = array();

	/**
	 * Source-specific detail. Never personal data.
	 *
	 * @var array<string,mixed>
	 */
	public array $metadata = array();

	/**
	 * Who the adapter thinks is shopping.
	 *
	 * @var Identity_Hints
	 */
	public Identity_Hints $identity;

	/**
	 * When the visitor last touched it, UTC. Empty means "now".
	 *
	 * @var string
	 */
	public string $last_activity_at = '';

	/**
	 * Constructor.
	 *
	 * @param string $source_id   Adapter id.
	 * @param string $source_type Event type.
	 * @param string $dedupe_key  Stable key for this open journey.
	 */
	public function __construct( string $source_id, string $source_type, string $dedupe_key ) {
		$this->source_id   = $source_id;
		$this->source_type = $source_type;
		$this->dedupe_key  = substr( $dedupe_key, 0, 191 );
		$this->identity    = new Identity_Hints();
	}

	/**
	 * Set the monetary value.
	 *
	 * @param string $amount   Decimal string in major units.
	 * @param string $currency ISO 4217 code.
	 * @return Event_Draft
	 */
	public function with_value( string $amount, string $currency ): self {
		$this->amount   = $amount;
		$this->currency = strtoupper( substr( $currency, 0, 3 ) );

		return $this;
	}

	/**
	 * Set the contents.
	 *
	 * @param array<int,array<string,mixed>> $items Item snapshots.
	 * @return Event_Draft
	 */
	public function with_items( array $items ): self {
		$this->items      = array_values( $items );
		$this->item_count = 0;

		foreach ( $this->items as $item ) {
			$this->item_count += isset( $item['qty'] ) ? max( 0, (int) $item['qty'] ) : 1;
		}

		return $this;
	}

	/**
	 * Attach who the adapter thinks is shopping.
	 *
	 * @param Identity_Hints $identity Hints.
	 * @return Event_Draft
	 */
	public function with_identity( Identity_Hints $identity ): self {
		$this->identity = $identity;

		return $this;
	}

	/**
	 * Whether this draft describes anything at all.
	 *
	 * An emptied cart is reported so the open event can be closed, but it is
	 * never worth starting a journey for.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return 0 === $this->item_count && 0.0 === (float) $this->amount;
	}

	/**
	 * A value that changes whenever the contents or the contact details change.
	 *
	 * Adapters compare this against the last one they stored so that a visitor
	 * refreshing the cart page twenty times produces no writes at all.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					$this->dedupe_key,
					$this->amount,
					$this->currency,
					$this->item_count,
					$this->items,
					$this->identity->fingerprint(),
				)
			)
		);
	}
}
