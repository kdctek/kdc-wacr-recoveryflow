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
| `recoveryflow_register_workflow_actions` | `Step_Registry $registry` | Add an action usable in `"do"`. Built-ins: `wacr.send_template`, `wacr.send_email`, `wacr.start_flow`. A generic `webhook.post` is planned |
| `recoveryflow_normalize_phone` | `?string $e164, string $raw, ?string $country_iso2` | Override or correct the result of phone normalisation. Return `null` to mark the number invalid |
| `recoveryflow_calling_codes` | `array $codes` | Map of ISO 3166-1 alpha-2 country code to calling code, used by the normaliser. Add or correct entries |
| `recoveryflow_capability_map` | `array $map` | Capability name to list of roles, applied on activation and whenever the capability version changes. See [`security.md`](security.md#capabilities) |
| `recoveryflow_feature_enabled` | `bool $enabled, string $feature` | Override the plan gate for `workflow_editor`, `direct_send`, `engagement_polling` or `extra_sources`. WA.cr still enforces its own plan rules server-side |
| `recoveryflow_optout_keywords` | `string[] $keywords` | Whole-message keywords that count as an opt-out when read from the conversation. Default `STOP`, `UNSUBSCRIBE`, `CANCEL`, `END`, `QUIT` |
| `recoveryflow_wacr_allowed_hosts` | `string[] $hosts` | Hostnames the client and the Auto Flow hook URL may target. Default `api.wa.cr` and `api.wacart.dev`. A custom host is only honoured for white-label keys and must be HTTPS |
| `recoveryflow_pre_ingest_event` | `Event_Draft|null $draft` | Every event any adapter reports, immediately before it is written. Return `null` to drop it |
| `recoveryflow_gf_paid_statuses` | `string[] $statuses` | Gravity Forms `payment_status` values that count as a completed sale. Default `Paid`, `Active`, `Approved`, `Authorized` |
| `recoveryflow_gf_field_overrides` | `array $overrides, array $form` | Pins which Gravity Forms field holds a contact detail, keyed by field type (`email`, `phone`, `name`, `address`). Default: the first field of each type |
| `recoveryflow_gf_first_look_days` | `int $days` | How far back the first Gravity Forms backfill reads. Default 30 |
| `recoveryflow_wc_restore_cart_item_data` | `string[] $allowed_keys, array $line, Recovery_Journey $journey` | Keys of WooCommerce `cart_item_data` that the restorer may copy back from the snapshot. Default none, because that array is where other plugins keep arbitrary data |

Example: allow a gift-message key to survive a restore.

```php
add_filter( 'recoveryflow_wc_restore_cart_item_data', function ( array $keys ) {
    $keys[] = 'gift_message';
    return $keys;
} );
```

## REST routes

Base: `/wp-json/kdc/v1/wacr/recoveryflow`. Every route requires cookie authentication with a REST nonce (or an application password) **and** the capability in the table. Responses are shaped objects rather than database rows; personal data is masked unless `reveal=1` is passed by a user holding `recoveryflow_reveal_pii`, and every reveal writes an audit receipt.

A logged-out request gets `401`, a logged-in request without the capability gets `403`, and the message names the capability that was missing.

| Method | Path | Capability | Notes |
| --- | --- | --- | --- |
| `GET` | `/journeys` | `recoveryflow_view_journeys` | `page`, `per_page` (max 200), `status` (enum), `source`, `orderby` (`id`, `created_at`, `updated_at`, `next_action_at`, `status`), `order` (`asc`/`desc`), `search`, `reveal`. Totals are in the `X-WP-Total` and `X-WP-TotalPages` headers |
| `GET` | `/journeys/{uid}` | `recoveryflow_view_journeys` | Includes the attempt history. `reveal=1` needs `recoveryflow_reveal_pii`; without it the response is masked rather than refused |
| `POST` | `/journeys/{uid}` | `recoveryflow_manage_journeys` | `action=cancel`, `retry`, `revoke_links` or `opt_out`. `409` if the journey has already finished, if a background pass moved it between your read and your write, or if a retry has nothing left to try |
| `GET` | `/status` | `recoveryflow_view_status` | Every health check with a severity, a sentence and a deeplink, plus what each background pass last did. Never the key |
| `POST` | `/connection/test` | `recoveryflow_manage_settings` | Asks WA.cr whether the saved credential works. Tests what is stored; it does not accept a key to try |
| `GET` | `/settings` | `recoveryflow_manage_settings` | The settings, plus what is currently blocking the email channel. The API key is not included in any form |
| `POST` | `/ui-state` | `recoveryflow_view_status` | Remembers whether one expandable panel was left open, per user |
| `GET` | `/integrations` | `recoveryflow_manage_settings` | Every registered integration with the registry's own verdict (`active`, `switched_off`, `not_included`, `unavailable`), the sentence that explains it, and a deeplink to its switch. Read-only |
| `GET` | `/templates` | `recoveryflow_manage_workflows` | The approved WhatsApp templates, each with its fillable slots and whether RecoveryFlow can send it. `refresh=1` bypasses the cache |

**`search` matches the journey's own reference only** — not a phone number, an email address or a name. That is deliberate rather than unfinished: identities are stored as keyed hashes, so searching a phone number would mean either scanning a plaintext column or hashing the search term, and hashing it would turn the search box into an oracle that confirms whether a given number belongs to a customer of this shop, for anyone who can reach the endpoint.

**`/integrations` and `/templates` are read-only, and neither works its answer out for itself.** The verdict on an integration comes from `Source_Registry::status()` and its sentence from `Source_Registry::status_message()` -- the same two the Integrations screen prints -- because a screen that derived its verdict from neighbouring facts has been wrong here four times, most memorably calling an integration active when its hooks had never been attached. `/templates` reads the same `Template_Catalog` the workflow editor's picker reads, including its judgement on which templates this plugin can actually send; a template needing an image header or a carousel is listed and marked rather than hidden, because a merchant who cannot find the template they approved last week concludes the connection is broken. Switching an integration off is a settings write and goes through the settings tree, for the same reason `/settings` is read-only: a second way to write a setting is a second set of rules about what a valid setting is, and the one nobody exercised is the one that disagrees.

**A refusal from `/templates` is not an empty list.** No API key, a plan below Scale, an unreachable network and a workspace with genuinely no approved templates are four situations needing four different responses, and all four look identical as `[]`. The response always carries `ok`, and when it is false the reason in WA.cr's own words.

**None of the four writes sends anything.** `retry` puts a failed recovery back in the queue and the dispatch pass sends it, after the send gate has asked about consent, opt-outs and quiet hours -- so a customer who opted out between the failure and the retry is still not messaged. There is deliberately no "send now": an endpoint that fires a message costs money and reaches a real person, and is a much larger thing to get right than one that can only queue.

**Only a `failed` recovery can be retried, and the state machine is what enforces it.** `failed` is the one terminal state meaning "the machinery could not" rather than "do not message this person"; `recovered`, `expired`, `cancelled`, `opted_out` and `invalid` each carry a decision about the customer and stay closed for good. The only transition out of `failed` is to `scheduled` -- never straight to `message_sent`, which would skip the send gate -- and only a person may make it, because a fault that failed a thousand recoveries must not retry all thousand by itself.

**A retry on a step that has used its attempts is refused, not queued.** The dispatch action gives up at three attempts per step, so re-queueing an exhausted step produces a recovery that fails again the moment a pass reaches it; answering `200` to that would be a lie with a delay on it. The refusal names the count and the cap.

**`revoke_links` stops the links without stopping the recovery** -- for a link that has been forwarded, posted publicly or caught in a shared inbox. A later step may send a new one, which is the whole difference between it and `cancel`. Revoking when there is nothing to revoke reports zero rather than success.

**`opt_out` is the same act as the unsubscribe link**, through the same implementation, for the customer who telephones the shop instead of clicking. It suppresses every identity the customer has -- not merely the phone number -- and stops every open recovery of theirs, not merely this one. Only the recorded source differs.

**A missing journey and an erased one return the same `404`**, for the same reason: two different answers would let anyone with the view capability confirm that a particular reference used to be real.

Errors follow WordPress conventions: `WP_Error` with a `recoveryflow_*` code and an HTTP status.

## The webhook receiver

`POST /wp-json/kdc/v1/wacr/recoveryflow/webhooks/wacr`

A WA.cr Auto Flow can call this site through a webhook node. Generate the secret at Settings > WA.cr > Letting a flow call this site back; it is shown once, because only its fingerprint is stored. Put it in the `x-recoveryflow-secret` header. That header name is this plugin's, not WA.cr's -- the merchant types it into the flow themselves, so it has to be written down somewhere and this is where.

Send JSON:

```json
{ "event": "recovery.opt_out", "phone": "+447700900123", "id": "a-unique-id-per-event" }
```

| Field | Meaning |
| --- | --- |
| `event` | `recovery.opt_out` or `recovery.replied`. Anything else is a `400` that names what is accepted, so a mistyped event is not a silent success |
| `phone` | The customer, in any form the checkout would have understood. It is normalised through the same resolver before being looked up |
| `id` | Yours, different for every event. It is what lets this site recognise the same event arriving twice and do nothing the second time |

`recovery.opt_out` is the one worth building: a customer who replies STOP inside your flow is suppressed here immediately rather than up to a poll interval later, and every minute of that delay is a minute in which another reminder can reach somebody who asked to be left alone. It does exactly what the unsubscribe link does -- every identity the customer has, every open recovery of theirs. `recovery.replied` marks their open recoveries engaged, and deliberately does not stop them: somebody asking "how much is postage?" has not finished their order.

**The refusals are distinct because they need different fixes**, and a flow author reading their node log has nothing else to go on: `415` the body was not JSON, `413` it was over 16 KB, `401` the secret was wrong or absent, `400` the event is not one this accepts or the body is not readable JSON.

**A `401` is the same answer whether the secret was wrong, missing, or never generated at all.** Telling those apart would tell somebody probing which sites are worth returning to.

**A customer this site does not know is a `200` with `matched: 0`, not a `404`.** The flow did nothing wrong, and a `404` would turn the endpoint into a way to ask which phone numbers belong to this shop's customers.

**A shared secret is replayable in a way a signature is not**, since it is not bound to the body. Two things narrow that: the endpoint is HTTPS, and every event carrying an `id` is deduplicated through the receipt ledger, so a replay is recognised and does nothing the first one did not.

**It answers POST only.** WhatsApp's link-preview fetcher and every crawler in existence will GET any URL they find, and this one suppresses customers.

### Built differently from the plan, and why

Nothing from the plan is now missing, but one thing was built differently from how the plan described it, and the difference is worth stating plainly rather than leaving somebody to discover it.

**The plan described `/webhooks/wacr` as a receiver for delivery statuses and inbound replies, verifying an `x-wacr-signature` header. Neither half of that is available from WA.cr, so neither was built.**

- **WA.cr has no outbound webhook subscription.** There is no way to ask it to notify a URL when a message is delivered, read or replied to. That is why this plugin reconciles through the poll pass, asking `GET /v1/conversations/{e164}/messages?after=`, and the poll pass remains the only route by which a delivery status or a reply reaches this site. An event here reporting a delivery status would document a capability that does not exist.
- **The one thing in WA.cr that can call a URL does not sign the body.** An Auto Flow webhook node sends `content-type: application/json` plus whatever headers the merchant typed into the flow, and computes no HMAC. The signature the plan is remembering runs the other way: RecoveryFlow signs what it pushes *into* a flow, with `x-wacr-signature: sha256=<hex>`. A receiver here verifying a signature would verify a header nothing sends, refuse every real request, and look rigorous while doing it.

So the receiver authenticates with a shared secret, which is what the platform can actually present.

## A minimal custom source

**The example below is a real file**, `src/Integration/Custom/Example_Source.php`, shipped in the plugin and never registered. Read that rather than this: it is linted, type-checked and instantiated by the test suite against the real interfaces, so it cannot describe an API the plugin does not have. The version that used to be printed here could, and did -- it called a constructor with the wrong signature and two methods that had never existed, and said so confidently for three releases, because nothing anywhere could tell.

Copy the file, rename it, and change the four places that say `mybookings`.

```php
use WAcr\RecoveryFlow\Customer\Identity_Hints;
use WAcr\RecoveryFlow\Integration\Abstract_Source;
use WAcr\RecoveryFlow\Recovery\Event_Draft;
use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

final class My_Bookings_Source extends Abstract_Source {

    public function get_id(): string          { return 'mybookings'; }
    public function get_name(): string        { return __( 'My Bookings', 'my-plugin' ); }
    public function get_description(): string { return __( 'Recovers bookings that were reserved and never paid for.', 'my-plugin' ); }
    public function is_available(): bool      { return function_exists( 'mybookings_get_booking' ); }
    public function get_event_types(): array  { return [ 'booking' ]; }
    public function get_default_rules(): array { return [ 'inactivity_minutes' => 60, 'max_age_days' => 3 ]; }

    public function register(): void {
        add_action( 'mybookings_booking_reserved', [ $this, 'on_reserved' ] );
        add_action( 'mybookings_booking_paid',     [ $this, 'on_paid' ] );
    }

    public function on_reserved( $booking ): void {
        $draft = new Event_Draft( $this->get_id(), 'booking', 'booking:' . (int) $booking->id );

        $draft->external_id = (string) $booking->id;
        $draft->session_key = 'booking:' . (int) $booking->id;
        $draft->with_value( (string) $booking->total, (string) $booking->currency );
        $draft->with_items( [ [ 'name' => $booking->service_name, 'ref' => $booking->slot, 'qty' => 1 ] ] );

        $hints             = new Identity_Hints();
        $hints->phone_raw  = (string) $booking->phone;
        $hints->email      = (string) $booking->email;
        $hints->first_name = (string) $booking->first_name;
        $hints->country    = (string) $booking->country;

        $draft->with_identity( $hints );

        $this->report( $draft );          // upsert; never throws into the request
    }

    public function on_paid( $booking ): void {
        $this->report_completed( 'booking:' . (int) $booking->id, 'paid' );
    }

    public function is_conversion_complete( Recovery_Event $event ): bool {
        if ( ! function_exists( 'mybookings_get_booking' ) ) {
            return true;                   // cannot tell ⇒ fail closed, send nothing
        }

        $booking = mybookings_get_booking( (int) $event->external_id );

        return ! is_object( $booking ) || 'paid' === $booking->status;
    }

    public function restore( Recovery_Journey $journey, Recovery_Event $event ) {
        $url = (string) mybookings_get_payment_url( (int) $event->external_id );

        return '' === $url ? new \WP_Error( 'gone', 'Booking no longer exists.' ) : $url;
    }
}
```

Register it on the action, taking the ingest from the registry rather than from the container:

```php
add_action( 'recoveryflow_register_sources', function ( \WAcr\RecoveryFlow\Integration\Source_Registry $registry ) {
    $registry->add( new My_Bookings_Source( $registry->ingest() ) );
} );
```

### The five things to get right

| | |
| --- | --- |
| **`is_available()` runs on every page load** | One `class_exists` or `function_exists`, nothing else. A version check, an option read or a query here is a cost every request on the site pays whether or not anybody is booking anything |
| **`dedupe_key` is one key per thing-in-progress**, reused as it changes | It is UNIQUE with your source id, so reporting the same booking twice updates one row instead of making a second. Getting this wrong is how somebody receives three reminders about one booking |
| **Never put personal data in `metadata` or `items`** | Contact details go in `Identity_Hints`, the one field the exporter, the eraser and the redactor know to look at. A phone number tucked into metadata to save a lookup is a phone number that survives an erasure request |
| **`is_conversion_complete()` answers from live state, every time** | It is asked again immediately before every send, not only when the event was detected: the person may have paid in another tab an hour later. If you cannot tell, return `true` -- the cost of a missed reminder is a reminder; the cost of a wrong one is asking somebody to pay twice |
| **`restore()` merges, and touches only the clicker's own session** | Somebody following a recovery link may have started again already, and replacing that with an older snapshot destroys the very conversion being recovered |

`Abstract_Source` gives you `report()` and `report_completed()`, plus a `build_recovery_url()` that returns the plugin's own `/recovery/{token}` endpoint, an empty `get_settings_fields()` and an empty `consent_note()`. Override those last three only if you need to.

Override **`consent_note()`** if a merchant has to do something for your source to have consent -- put a particular field on a form, tick something in another plugin. It is shown on your card on the Integrations screen, and only on a site that requires explicit consent, so write it as an instruction rather than a caveat. Leave it empty if your source collects consent itself or needs nothing. It exists because "this source identifies people it may not message" was true of a shipped integration for a whole release while every screen it appeared on stayed silent about it.

### Settings of your own

`get_settings_fields()` returns field definitions in the same shape as the plugin's settings schema. They are folded into the one settings tree, so they are rendered, validated and deeplinked by exactly the same code as the plugin's own -- you cannot ship a field that saves without being cleaned. Each is stored as `source_{your id}_{your key}`:

```php
public function get_settings_fields(): array {
    return [
        'include_free' => [
            'type'    => 'checkbox',
            'label'   => __( 'Also chase bookings that cost nothing', 'my-plugin' ),
            'help'    => __( 'A free booking cannot be paid for.', 'my-plugin' ),
            'default' => false,
        ],
    ];
}

// Reading it back:
Options::get( Source_Registry::setting_key( $this->get_id(), 'include_free' ), false );
```

Every registered source also gets a switch at `source_{your id}_enabled`, on by default.

### Sources with no hooks to push from

If the system you are integrating with fires no hooks -- it writes straight to its own tables, or lives behind an API -- implement `Pollable_Source_Interface` as well. The Evaluate pass will ask you for a bounded page on every tick:

```php
public function detect_recovery_events( int $limit, ?string $cursor ): Event_Batch {
    $rows   = my_api_unpaid_since( $cursor, $limit );   // never scan unboundedly
    $drafts = [];
    $last   = $cursor;

    foreach ( $rows as $row ) {
        $last = (string) $row->created_at;              // wherever you got to

        $draft = new Event_Draft( $this->get_id(), 'booking', 'booking:' . (int) $row->id );
        $draft->external_id = (string) $row->id;
        $draft->with_value( (string) $row->total, (string) $row->currency );

        $drafts[] = $draft;
    }

    // A full page means there is more behind it; the tick will come back.
    return new Event_Batch( $drafts, $last, count( $rows ) >= $limit );
}
```

The cursor is opaque to the core -- a row id, a timestamp, an API page token -- and is stored in the `recoveryflow_source_cursors` option, capped at 500 characters. Return `null` for it when there is nothing more to read; the stored position is dropped and the next poll starts from the beginning. Set `has_more` when you returned a full page, and the tick will come back for the rest.

Three rules the core enforces around you: the cursor is advanced only after your batch has been ingested, so a fatal mid-page cannot silently skip rows a poll would never see again; a source that throws is dropped for that run and keeps its stored position, so one broken integration neither stops the others nor loses its place; and every draft must carry your own `source_id`, because `(source_id, dedupe_key)` is UNIQUE and a draft filed under somebody else's id would upsert onto their row.

Gravity Forms is a worked example of a source that is both hooked and pollable, and [`integrations.md`](integrations.md#why-it-polls) explains why it needs to be both.

## WP-CLI

`wp recoveryflow` runs the same code the scheduler runs, not a copy of it, so what happens in a terminal is what happens at three in the morning.

| Command | Does |
| --- | --- |
| `wp recoveryflow status` | Every health check and what each background pass last did. The same checks the status screen and `/status` read, so the three cannot give different diagnoses. Exits non-zero if any check is at error severity |
| `wp recoveryflow tick [--stage=<stage>] [--until-clear]` | Runs the background passes now, in the foreground, under the real locks and the real time budget. A pass another process already holds is skipped rather than run twice |
| `wp recoveryflow journeys [--status=] [--source=] [--search=] [--page=] [--per-page=] [--format=]` | The recovery queue. `--format=count` prints the total alone |
| `wp recoveryflow sources` | The registered integrations and whether each is watching. The status column is `Source_Registry::status()`, the same answer that decides whether a source's hooks are attached |

**Contact details are masked, always, and there is no flag to unmask them.** Anybody who can run WP-CLI can already read the database, so an unmasked column would protect nothing; it would only add a route to customers' phone numbers that appears in shell history, in CI logs and over somebody's shoulder, and that no audit trail covers. The reveal that *is* audited is on the REST route and the single-journey screen, where a person holding a named capability asks for it.

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

Two default workflows are seeded on activation: the direct-send variant and the hand-off variant. A user with `recoveryflow_manage_workflows` builds their own on **Workflows &rsaquo; Edit**, a form-based editor that works with JavaScript switched off. The JSON below is the stored shape, not the way anybody has to author one.

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
