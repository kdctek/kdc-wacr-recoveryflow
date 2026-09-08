# Developer API

Everything a developer needs to extend RecoveryFlow by WA.cr: the hooks it fires and the filters it applies, its REST routes, how to register a custom Recovery Source, the workflow definition format and the variables a workflow may use.

Namespace `WAcr\RecoveryFlow`. Hook prefix `recoveryflow_`. REST namespace `kdc/v1`, routes under `wacr/recoveryflow/`. Text domain `kdc-wacr-recoveryflow`. PHP 8.0 and WordPress 6.5 or later.

Argument types below refer to the value objects in [`architecture.md`](architecture.md#domain-model): `Recovery_Event`, `Recovery_Journey`, `Attempt`, `Customer`. Objects passed to actions are read-only snapshots; changing them has no effect on the database.

## Actions

Actions are fired after the database write that they describe has succeeded. They never fire for a compare-and-set that lost its race.

| Hook | Arguments | Fires when |
| --- | --- | --- |
| `recoveryflow_event_created` | `Recovery_Event $event` | A new event row is inserted. Not fired for upsert updates to an existing open row |
| `recoveryflow_journey_created` | `Recovery_Journey $journey, Recovery_Event $event` | The Evaluate stage inserts a journey, in any initial state |
| `recoveryflow_journey_transition` | `Recovery_Journey $journey, string $from, string $to, array $patch` | Every successful state change. `$patch` is the set of columns written alongside the status |
| `recoveryflow_journey_scheduled` | `Recovery_Journey $journey, string $next_action_at` | A journey enters `SCHEDULED`, including a resume after a failed payment. `$next_action_at` is UTC |
| `recoveryflow_journey_message_sent` | `Recovery_Journey $journey, Attempt $attempt` | WA.cr accepted a send, or a hand-off push succeeded |
| `recoveryflow_journey_engaged` | `Recovery_Journey $journey, string $via` | The customer replied (`reply`), tapped a link from a real browser (`click`), or a webhook reported a reply (`webhook`) |
| `recoveryflow_journey_recovered` | `Recovery_Journey $journey, string $attribution, string $recovered_amount` | The source reported completion. `$attribution` is `exact`, `session` or `customer` |
| `recoveryflow_journey_expired` | `Recovery_Journey $journey` | The Expire stage closed the journey |
| `recoveryflow_journey_cancelled` | `Recovery_Journey $journey, string $reason` | Rules, a merchant action or a second payment failure ended the journey |
| `recoveryflow_journey_opted_out` | `Recovery_Journey $journey, string $source` | An opt-out ended the journey. `$source` is a consent source: `link`, `keyword`, `wacr`, `admin` or `webhook` |
| `recoveryflow_before_message` | `Recovery_Journey $journey, Attempt $attempt, array $context` | Immediately before the HTTP call to WA.cr, after the attempt row is reserved. `$context` is the rendered variable context (see the allow-list below). This is an observation point; it cannot change the message. To change what is sent, register your own action through `recoveryflow_register_workflow_actions` |
| `recoveryflow_after_message` | `Recovery_Journey $journey, Attempt $attempt, \WAcr\RecoveryFlow\WAcr\Result $result` | Immediately after the HTTP call, whatever the outcome. `$result->ok`, `$result->status` and `$result->error` (category, code, message, retryable, send_state) are available; the API key and message body are not. `$result->status` is the HTTP status where there was one and `0` where the request never completed, so judge failures by `$result->error->category`, never by the status |

Example: post to your own analytics when a journey is recovered.

```php
add_action( 'recoveryflow_journey_recovered', function ( $journey, string $attribution, string $amount ) {
    if ( 'customer' === $attribution ) {
        return; // only count exact and session attributions
    }
    my_analytics_track( 'recovery', [ 'journey' => $journey->journey_uid, 'amount' => $amount ] );
}, 10, 3 );
```

## Filters

Registries are passed by object; return the registry from your callback.

| Filter | Arguments | Purpose |
| --- | --- | --- |
| `recoveryflow_register_sources` | `Source_Registry $registry` | Add a Recovery Source. Applied on `init` at priority 5 |
| `recoveryflow_register_workflow_steps` | `Step_Registry $registry` | Add a step type beyond `wait`, `condition` and `action` |
| `recoveryflow_register_workflow_conditions` | `Step_Registry $registry` | Add a condition usable in `"if"`. Built-ins: `journey.not_completed`, `journey.not_engaged`, `customer.eligible`, `event.amount_gte`, `event.item_count_gte`, `journey.attempts_lt` |
| `recoveryflow_register_workflow_actions` | `Step_Registry $registry` | Add an action usable in `"do"`. Built-ins: `wacr.send_template`, `wacr.start_flow`. A generic `webhook.post` is planned |
| `recoveryflow_normalize_phone` | `?string $e164, string $raw, ?string $country_iso2` | Override or correct the result of phone normalisation. Return `null` to mark the number invalid |
| `recoveryflow_calling_codes` | `array $codes` | Map of ISO 3166-1 alpha-2 country code to calling code, used by the normaliser. Add or correct entries |
| `recoveryflow_capability_map` | `array $map` | Capability name to list of roles, applied on activation and whenever the capability version changes. See [`security.md`](security.md#capabilities) |
| `recoveryflow_feature_enabled` | `bool $enabled, string $feature` | Override the plan gate for `workflow_editor`, `direct_send`, `engagement_polling` or `extra_sources`. WA.cr still enforces its own plan rules server-side |
| `recoveryflow_optout_keywords` | `string[] $keywords` | Whole-message keywords that count as an opt-out when read from the conversation. Default `STOP`, `UNSUBSCRIBE`, `CANCEL`, `END`, `QUIT` |
| `recoveryflow_wacr_allowed_hosts` | `string[] $hosts` | Hostnames the client and the Auto Flow hook URL may target. Default `api.wa.cr` and `api.wacart.dev`. A custom host is only honoured for white-label keys and must be HTTPS |
| `recoveryflow_wc_restore_cart_item_data` | `string[] $allowed_keys, array $line, Recovery_Journey $journey` | Keys of WooCommerce `cart_item_data` that the restorer may copy back from the snapshot. Default none, because that array is where other plugins keep arbitrary data |

Example: allow a gift-message key to survive a restore.

```php
add_filter( 'recoveryflow_wc_restore_cart_item_data', function ( array $keys ) {
    $keys[] = 'gift_message';
    return $keys;
} );
```

## REST routes

Base: `/wp-json/kdc/v1/wacr/recoveryflow`. Every route except the webhook receiver requires cookie authentication with a REST nonce (or an application password) **and** the capability in the table. Responses are DTOs; personal data is masked unless `reveal=1` is passed by a user with `recoveryflow_reveal_pii`, and every reveal writes an audit receipt.

| Method | Path | Capability | Notes |
| --- | --- | --- | --- |
| `GET` | `/journeys` | `recoveryflow_view_journeys` | `page`, `per_page` (max 100), `status` (enum), `source` (registered sources), `orderby` (enum), `order` (`asc`/`desc`), `search` (max 64 characters: a phone is hashed and matched exactly, an email is matched exactly, a name uses a LIKE) |
| `GET` | `/journeys/{uid}` | `recoveryflow_view_journeys` | `reveal=1` requires `recoveryflow_reveal_pii`, otherwise 403 |
| `POST` | `/journeys/{uid}/cancel` | `recoveryflow_manage_journeys` | Any non-terminal journey |
| `POST` | `/journeys/{uid}/retry` | `recoveryflow_manage_journeys` | Only from `FAILED` or `EXPIRED`; mints a new attempt and token |
| `POST` | `/journeys/{uid}/revoke-links` | `recoveryflow_manage_journeys` | Revokes every token on the journey |
| `POST` | `/journeys/{uid}/opt-out` | `recoveryflow_manage_journeys` | Appends a `suppressed` consent row with source `admin` |
| `GET` | `/integrations` | `recoveryflow_view_status` | Registered sources with availability and enabled state |
| `GET` | `/status` | `recoveryflow_view_status` | Connected, mode, workspace name, scopes present and missing, sender ok, cron ok, HTTPS, versions, last tick and poll. Never the key |
| `POST` | `/settings/test-connection` | `recoveryflow_manage_settings` | Optional `api_key` (`^(wacr\|waht)_(live\|test)_[A-Za-z0-9_-]{16,128}$`) to test before saving; never echoed back |
| `GET` | `/templates` | `recoveryflow_manage_workflows` | Approved templates with their variables; optional `waba_id` |
| `POST` | `/webhooks/wacr` | Public, own verification | See [`security.md`](security.md#webhook-receiver) |

Errors follow WordPress conventions: `WP_Error` with `rest_forbidden` (403), `rest_invalid_param` (400) or a `recoveryflow_*` code.

## A minimal custom source

This example recovers unpaid bookings from a fictional booking plugin that fires `mybookings_booking_created` and `mybookings_booking_paid`. Around forty lines is all a source needs. The `Event_Draft` field names mirror `Recovery_Event`; the identity hints mirror `Identity_Hints`. The final shape of the draft builder is fixed together with the Gravity Forms adapter in slice 3, so treat this as the intended shape rather than a frozen signature.

```php
<?php
namespace My_Plugin\RecoveryFlow;

use WAcr\RecoveryFlow\Integration\Abstract_Source;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

final class Bookings_Source extends Abstract_Source {
    public function get_id(): string          { return 'mybookings'; }
    public function get_name(): string        { return __( 'My Bookings', 'my-plugin' ); }
    public function get_description(): string { return __( 'Recovers unpaid bookings.', 'my-plugin' ); }
    public function is_available(): bool      { return function_exists( 'mybookings_get_booking' ); }
    public function get_event_types(): array  { return [ 'booking' ]; }
    public function get_default_rules(): array { return [ 'inactivity_minutes' => 60, 'max_age_days' => 3 ]; }

    public function register(): void {
        add_action( 'mybookings_booking_created', [ $this, 'on_created' ] );
        add_action( 'mybookings_booking_paid',    [ $this, 'on_paid' ] );
    }

    public function on_created( object $booking ): void {
        $draft = new Event_Draft( [
            'source_type' => 'booking',
            'dedupe_key'  => 'booking:' . $booking->id,
            'external_id' => (string) $booking->id,
            'currency'    => $booking->currency,
            'amount'      => (string) $booking->total,
            'items'       => [ [ 'sku' => $booking->slot, 'name' => $booking->service_name, 'qty' => 1,
                                 'unit_amount' => (string) $booking->total, 'line_amount' => (string) $booking->total ] ],
            'identity'    => [ 'phone' => $booking->phone, 'email' => $booking->email,
                               'first_name' => $booking->first_name, 'country' => $booking->country ],
        ] );
        $this->ingest( $draft ); // upsert; never throws into the request
    }

    public function on_paid( object $booking ): void {
        $this->report_completed( (string) $booking->id, (string) $booking->total ); // journey → RECOVERED
    }

    public function is_conversion_complete( Recovery_Event $event ): bool {
        $booking = mybookings_get_booking( (int) $event->external_id );
        return ! $booking || 'paid' === $booking->status; // unknown ⇒ treat as complete: fail closed
    }

    public function restore( Recovery_Journey $journey, Recovery_Event $event ): string|\WP_Error {
        return mybookings_get_payment_url( (int) $event->external_id ) ?: new \WP_Error( 'gone', 'Booking no longer exists.' );
    }
}
```

Register it:

```php
add_filter( 'recoveryflow_register_sources', function ( $registry ) {
    $registry->add( new \My_Plugin\RecoveryFlow\Bookings_Source() );
    return $registry;
} );
```

## Workflow definition

Workflows are JSON documents stored with an immutable snapshot per version. `Workflow_Definition::schema()` validates them on save. A journey pins the version it started under, so editing a workflow never changes a running journey.

```json
{
  "name": "Cart recovery (2 touches)",
  "version": 1,
  "trigger": { "event": "journey.eligible", "source": "*" },
  "steps": [
    { "type": "condition", "if": "journey.not_completed", "else": "stop:recovered" },
    { "type": "condition", "if": "customer.eligible",     "else": "stop:cancelled" },
    { "type": "action",    "do": "wacr.send_template", "with": {
        "template": "cart_reminder_1", "language": "en",
        "variables": {
          "body_1": "{{customer.first_name}}",
          "body_2": "{{recovery.total_formatted}}",
          "button_0_url_1": "{{recovery.token}}"
        } } },
    { "type": "wait",      "for": "P1D" },
    { "type": "condition", "if": "journey.not_completed", "else": "stop:recovered" },
    { "type": "condition", "if": "journey.not_engaged",   "else": "stop:engaged" },
    { "type": "action",    "do": "wacr.send_template", "with": {
        "template": "cart_reminder_2", "language": "en",
        "variables": { "body_1": "{{customer.first_name}}", "button_0_url_1": "{{recovery.token}}" } } }
  ]
}
```

The Auto Flow hand-off workflow has the same shape with a single step:

```json
{
  "name": "Hand off to WA.cr Auto Flow",
  "version": 1,
  "trigger": { "event": "journey.eligible", "source": "*" },
  "steps": [
    { "type": "action", "do": "wacr.start_flow", "with": { "hook": "primary" } }
  ]
}
```

| Key | Meaning |
| --- | --- |
| `trigger.event` | Only `journey.eligible` today |
| `trigger.source` | A `source_id` or `*` |
| `steps[].type` | `condition`, `action` or `wait`, plus anything registered through `recoveryflow_register_workflow_steps` |
| `condition.if` / `condition.else` | A registered condition name; `else` is `stop:recovered`, `stop:cancelled`, `stop:engaged` or omitted to continue |
| `action.do` / `action.with` | A registered action and its parameters |
| `wait.for` | An ISO 8601 duration such as `PT30M` or `P1D`; quiet hours may shift the resulting time |

Execution rules: the Engine runs from `current_step` until a `wait` (which sets `next_action_at` and returns the journey to `SCHEDULED`), a `stop:*`, or the end. Each step executes at most once per `(journey, step_index)`: actions create attempt rows under a UNIQUE key; waits and conditions advance `current_step` in the same optimistic update. Guards on every wait and send: quiet hours, the per-customer frequency cap, one open journey per phone, three journeys per phone per 30 days, and the maximum touches per journey.

Two default workflows are seeded on activation: the direct-send variant and the hand-off variant. The form-based editor for building your own without writing JSON is planned for slice 2; until then workflows are edited as JSON by a user with `recoveryflow_manage_workflows`.

## Variable allow-list

Templates may reference only these variables. Unknown variables render empty and log a warning; there is no PHP evaluation of any kind.

| Variable | Value |
| --- | --- |
| `customer.first_name` | First name, or empty |
| `customer.last_name` | Last name; sent to WA.cr only when enabled in Settings |
| `recovery.total` | Total as a plain decimal, for example `1499.00` |
| `recovery.total_formatted` | Total formatted in the source's currency, for example `₹1,499.00` |
| `recovery.currency` | ISO 4217 code |
| `recovery.item_count` | Number of lines |
| `recovery.items_summary` | Short, length-capped list of item names |
| `recovery.first_item_name` | Name of the first line |
| `recovery.recovery_url` | The full recovery link for this attempt |
| `recovery.token` | The bare token, for templates whose button URL already contains `/recovery/` |
| `recovery.opt_out_url` | The opt-out confirmation page for this attempt |
| `site.name`, `site.url` | From WordPress settings |
| `source.name` | The source's display name |

`Template_Renderer::render( $string, $context, $mode )` applies one of three modes: `whatsapp_param` (newlines, tabs and runs of four or more spaces are stripped and the value is length-capped, as WhatsApp requires for template parameters), `url` (raw URL-encoded) and `text`.
