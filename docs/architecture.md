# Architecture

RecoveryFlow by WA.cr is a conversion-recovery engine for WordPress. Source integrations detect abandoned journeys (carts, checkouts, forms, tickets, bookings), the core normalises them into Recovery Events, runs a Recovery Journey state machine under a configurable Workflow, dispatches WhatsApp recovery touches through WA.cr, and stops the moment the original conversion completes. WooCommerce is the first supported integration, not the foundation.

**RecoveryFlow detects → WA.cr communicates → customer converts.** The plugin never talks to WhatsApp, never composes free text to a cold contact and never runs a conversation. It owns detection, identity, eligibility and consent, the timing of recovery touches, stop-on-conversion, attribution and the recovery link.

## System diagram

```text
 WordPress systems                      RecoveryFlow core                              WA.cr
 ┌──────────────┐  hooks   ┌──────────────────────────────────────────────┐  HTTPS  ┌───────────────┐
 │ WooCommerce  │─────────▶│ Source adapter ─▶ Event_Ingest (upsert)      │         │ /v1/messages  │
 │ Gravity Forms│          │        │                                       │────────▶│ /v1/templates │
 │ Tickera …    │          │        ▼  (tick: Action Scheduler or WP-Cron) │         │ /v1/channels  │
 │ custom       │          │ Identity_Resolver ─▶ Eligibility ─▶ Journey   │◀────────│ /v1/conversa… │
 └──────────────┘          │        │              (state machine)          │  poll   │ /automations/ │
        ▲                  │        ▼                                       │────────▶│  hooks/{token}│
        │ restore          │ Workflow Engine: Wait → Condition → Action     │  signed └───────────────┘
        │ + 302            │        │            (Step_Registry)            │                │
 ┌──────┴───────┐          │        ▼                                       │                ▼
 │ /recovery/   │◀─────────│ Attempts ledger (idempotent, holds token hash) │         WhatsApp customer
 │  {token}     │  token   │ Conversion_Tracker ◀── source "completed" hook │                │
 └──────────────┘          └──────────────────────────────────────────────┘                │
        ▲                                                                                   │
        └───────────────────────────── customer taps the recovery link ◀────────────────────┘
```

## Design decisions

1. **Push-based ingest, pull-based processing.** Adapters upsert one event row per open journey from server-side hooks (a cheap indexed write, throttled) and never process inline. Evaluation, scheduling, sending and polling happen in the background tick.
2. **One scheduler abstraction, two drivers.** Action Scheduler when its functions exist, WP-Cron otherwise. Overlapping runs are made safe by a database row lock per stage and per-row claims, not by hoping the scheduler never double-fires.
3. **Idempotency by ledger.** Every send is an `attempts` row inserted under a UNIQUE key before the HTTP call. Unknown outcomes are reconciled by reading the conversation back from WA.cr, never by re-sending. One recovery token per attempt, stored only as a hash.
4. **Two dispatch actions behind one abstraction.** `wacr.start_flow` (signed event push; WA.cr owns the conversation sequence) and `wacr.send_template` (plugin-timed send over the WA.cr developer API). Both go through one `WAcr\Client`.
5. **Consent is RecoveryFlow's; opt-out is honoured from both sides.** "Identified" and "eligible" are different facts, shown as two badges everywhere. A suppression survives erasure.
6. **No personal data leaves the site except to WA.cr on an eligible send**, with the exact fields enumerated in Settings, `readme.txt` and [`wa-cr-integration.md`](wa-cr-integration.md).

## Domain model

All classes live under `WAcr\RecoveryFlow`. Repositories return these objects; nothing else reads the tables directly.

### Recovery Event

Immutable once built. Adapters construct it through `Event_Draft`; `Event_Ingest::upsert()` writes it.

| Field | Meaning |
| --- | --- |
| `event_uid` | UUID, stable for the life of the event |
| `source_id`, `source_type` | Which adapter produced it and what kind of journey it is: `cart`, `checkout`, `form`, `ticket`, `booking` or `payment` |
| `dedupe_key` | Adapter-stable key for one open journey (WooCommerce: the session's customer id, or `user:{id}`). UNIQUE with `source_id` while the event is open |
| `customer_id`, `session_key`, `external_id` | Resolved customer (nullable), the source's session identifier, and the source's own record id where one exists |
| `currency`, `amount`, `item_count`, `items` | Value information. `amount` is a decimal string in major units; `items` is a snapshot of `{sku, name, qty, unit_amount, line_amount, ref}` |
| `metadata` | Adapter data such as applied coupons. Personal data is forbidden here by contract and enforced by the Redactor |
| `status`, `status_reason` | `open`, `completed`, `invalid` or `expired`, with a short reason such as `cart_emptied` or `max_age` |
| `last_activity_at` | UTC; the inactivity clock runs from here |

### Customer

One row per person the plugin can message, keyed on the phone.

| Field | Meaning |
| --- | --- |
| `wp_user_id` | Linked WordPress user, when logged in |
| `email`, `email_hash` | Email and its keyed hash (fallback match only) |
| `phone_e164`, `phone_raw`, `phone_status`, `phone_hash` | Normalised number, what was typed, `unknown`/`valid`/`invalid`, and the keyed hash that is the UNIQUE identity column |
| `first_name`, `last_name`, `country_iso2` | Never used as keys |
| `wacr_contact_id`, `wacr_synced_at` | Filled only when contact sync is enabled |
| `consent_status`, `opted_out_at`, `anonymized_at` | `consent_status` is a cache of the latest consent row; the consent ledger is authoritative |

Hashes are HMAC-SHA256 with a random per-site hash key minted on activation, so a stolen table cannot be joined to another site's table by phone number.

### Recovery Journey

One journey per event (`event_id` is UNIQUE), pinned to one workflow version.

| Field | Meaning |
| --- | --- |
| `journey_uid` | UUID, the public reference used in REST and hooks |
| `status`, `status_reason`, `current_step` | State machine position and, on terminal states, why |
| `next_action_at`, `poll_at`, `poll_count`, `expires_at` | The three clocks the background stages key on |
| `claim_token`, `claimed_until` | Short lease held by the runner currently processing the row |
| `attempts_count`, `resume_count`, `first_sent_at` | Touch bookkeeping; `resume_count` implements the resume-once rule |
| `clicks_count`, `last_click_at`, `engaged_at`, `engaged_via` | Engagement evidence (`reply`, `click` or `webhook`) |
| `recovered_at`, `recovered_external_id`, `recovered_amount`, `attribution` | Conversion evidence; `attribution` is `exact`, `session` or `customer` |
| `last_error_code`, `last_error_at` | Last WA.cr error category, for the journey timeline and System Status |

### Attempt

One row per action execution, and therefore one recovery token per row.

| Field | Meaning |
| --- | --- |
| `idempotency_key` | `journey_uid:step_index:attempt_no`, UNIQUE, reserved before any HTTP call |
| `action_type`, `channel`, `template_name`, `language_code` | What was sent |
| `token_hash`, `token_expires_at`, `token_revoked_at` | SHA-256 of the recovery token (UNIQUE); the token itself is never stored |
| `wacr_message_id`, `provider_message_id` | Identifiers returned by WA.cr on success |
| `status` | `pending`, `sending`, `sent`, `delivered`, `read`, `failed`, `unknown` or `skipped` |
| `scheduled_at`, `sending_started_at`, `sent_at`, `reconcile_after`, `status_checked_at` | The timeline the reconciliation logic reads |

### Workflow

A named JSON definition with a status (`draft` or active), an optional `source_id` it applies to, an `is_default` flag and a `version`. Every save bumps the version and stores an immutable snapshot; journeys pin `(workflow_id, workflow_version)`. The JSON shape is documented in [`developer-api.md`](developer-api.md#workflow-definition).

## Journey state machine

```text
NEW ─▶ IDENTIFIED ─▶ ELIGIBLE ─▶ SCHEDULED ─▶ MESSAGE_SENT ─▶ ENGAGED ─▶ RECOVERED
                                    ▲  └── next step ──┘         │
                                    └── resume once ── PENDING_PAYMENT ─▶ RECOVERED (paid / on-hold)
Any non-terminal ─▶ RECOVERED (source reports completion) | EXPIRED | CANCELLED | OPTED_OUT | INVALID | FAILED
```

### States

| State | Terminal | Meaning |
| --- | --- | --- |
| `NEW` | No | Event recorded, identity not yet resolved. Legal for eager sources; WooCommerce never uses it because journeys are only created for identified contacts |
| `IDENTIFIED` | No | A customer with a usable phone is attached; eligibility not yet decided |
| `ELIGIBLE` | No | Eligibility passed; the workflow has not yet scheduled a step |
| `SCHEDULED` | No | `next_action_at` is set; the Dispatch stage runs the next step when it is due |
| `MESSAGE_SENT` | No | At least one touch has been accepted by WA.cr; the Poll stage watches for engagement |
| `ENGAGED` | No | The customer replied, tapped a recovery link from a real browser, or a WA.cr webhook reported a reply |
| `PENDING_PAYMENT` | No | An order was placed. Messaging stops immediately and tokens are revoked, but this is not yet a conversion |
| `RECOVERED` | Yes | The source reported completion (WooCommerce: a paid status or on-hold) |
| `EXPIRED` | Yes | `expires_at` passed without a conversion |
| `CANCELLED` | Yes | Ended by rules, by a merchant action, or by a second payment failure |
| `OPTED_OUT` | Yes | The customer opted out by link, keyword, webhook, WA.cr flag or admin action |
| `INVALID` | Yes | The journey could never be messaged, for example `phone_invalid` |
| `FAILED` | Yes | A definitive send failure after the retry budget was exhausted |

For WooCommerce, the Evaluate stage creates the journey directly as `SCHEDULED` (with `next_action_at`) when eligibility passes, or as a terminal row with `status_reason` set to the eligibility reason when it does not: `INVALID` for `no_phone` and `invalid_phone`, `CANCELLED` for policy reasons such as `no_consent`, `suppressed`, `wacr_opted_out`, `already_open`, `frequency_cap`, `excluded_user` and `below_min_amount`. That way "identified but not eligible" is visible on the Journeys screen without ever being messageable.

Journeys are created only for identified contacts with a usable phone. Anonymous events are expired cheaply by one indexed `UPDATE` and never become journeys, so 100,000 anonymous carts do not become 100,000 unmessageable rows.

### PENDING_PAYMENT and the resume-once rule

`PENDING_PAYMENT` exists because "order placed" and "order paid" are different moments and the wrong thing happens if they are conflated in either direction. Treating "placed" as recovered counts revenue that may never arrive; treating "placed" as nothing keeps messaging someone who is mid-payment.

- The moment the source reports an order for the journey, the journey moves to `PENDING_PAYMENT`. `next_action_at` and `poll_at` are cleared and every recovery token for the journey is revoked, so no further touch can fire.
- When the source reports the order paid (WooCommerce: any status in `wc_get_is_paid_statuses()` plus `on-hold`, configurable as `recovered_statuses`), the journey moves to `RECOVERED` with `recovered_amount` set from the order total.
- If the payment fails or the order is cancelled while `PENDING_PAYMENT`, the journey resumes **once**: `resume_count` becomes 1, the state returns to `SCHEDULED` and `next_action_at` is set 30 minutes ahead, so the workflow can offer help with the payment. A second failure ends the journey as `CANCELLED`. There is no third chance; a customer whose payment keeps failing is not someone to keep messaging.

### How a transition is applied

Allowed transitions live in a static map in `Journey_State`. Every change goes through `Journey_Repository::transition( $id, $from, $to, $patch )`, which is exactly one conditional statement: `UPDATE … SET … WHERE id = %d AND status = %s`. If zero rows were affected, the row moved under us and the caller treats it as a lost race and re-reads. A successful transition fires `recoveryflow_journey_transition` and then the specific hook for the new state (`recoveryflow_journey_scheduled`, `_message_sent`, `_engaged`, `_recovered`, `_expired`, `_cancelled`, `_opted_out`).

## Identity resolution

`Identity_Resolver::resolve( Identity_Hints ): Customer` is deterministic and runs in this order. The first rule that matches wins.

1. **`wp_user_id`.** A logged-in user always resolves to their own customer row.
2. **`phone_hash`.** The messaging key and the only UNIQUE identity column. If the hinted phone belongs to an existing customer, that customer is the match.
3. **`email_hash`, only when no phone was hinted.** Email is a fallback match, never a merge key. This is the same rule WA.cr applies to its own contacts; two people can share a household email but not a WhatsApp number.
4. **Adapter external customer id**, for sources that carry one.
5. **Create** a new customer row.

Conflict rules:

- A logged-in user whose phone changed updates their own row.
- If the new phone already belongs to another customer, the journey attaches to **that** customer, because the phone is what gets messaged, and `identity_conflict` is logged for the merchant.
- Name is never a key.

### Phone normalisation

`Phone_Normalizer::to_e164( $raw, $country_iso2 )` is dependency-free: it strips formatting, removes a bracketed trunk `(0)`, handles `00`, `+` and trunk-`0` prefixes with per-country exceptions, and looks the country up in a calling-code table covering around sixty countries (seeded from WooCommerce's phone data when present, extendable through `recoveryflow_calling_codes`). The result passes through `recoveryflow_normalize_phone`. An unparseable number sets `phone_status = 'invalid'` and keeps `phone_raw` for later correction; the journey ends `INVALID` with reason `phone_invalid`.

## Eligibility

`Eligibility_Evaluator::evaluate()` returns a decision with one of these reasons, which is persisted on the journey as `eligibility_reason`: `mode_disabled`, `no_phone`, `invalid_phone`, `no_consent`, `suppressed`, `wacr_opted_out`, `already_open`, `frequency_cap`, `excluded_user`, `below_min_amount`, or `ok`.

Three eligibility modes exist: `explicit_consent` (default), `identified_contact` (behind a warning and a typed acknowledgement) and `disabled`. The consent model is documented in [`privacy.md`](privacy.md#consent-model).

Guards apply again at every `wait` and every send, not just at creation: quiet hours (site timezone, default 21:00 to 09:00, which shifts `next_action_at`), a per-customer frequency cap of one message per 24 hours across journeys, at most one open journey per phone, at most three journeys per phone in 30 days, and a maximum number of touches per journey (default three).

## Background processing

### Drivers

**Action Scheduler present** (it ships with WooCommerce): five recurring actions in group `recoveryflow`, registered on `init` at priority 20 and guarded by `as_has_scheduled_action()` so re-registration is a no-op.

| Action | Interval |
| --- | --- |
| `recoveryflow/evaluate` | 60 s |
| `recoveryflow/dispatch` | 60 s |
| `recoveryflow/poll` | 300 s |
| `recoveryflow/expire` | 900 s |
| `recoveryflow/retention` | daily |

A stage that ends with a backlog enqueues a unique async `recoveryflow/run` action for itself, so 100,000 due rows drain across successive runs without waiting for the next interval.

**Action Scheduler absent**: one `recoveryflow/tick` every five minutes (a custom cron interval) runs the stages in order under one time budget; a backlog schedules a single event five seconds out and calls `spawn_cron()`.

The active driver is stored in `recoveryflow_scheduler_driver`. When it changes, for example because WooCommerce was activated or deactivated, the old driver's hooks are unscheduled and the new driver's are registered. The WP-Cron driver adds a "Run now" button to System Status and a notice when `DISABLE_WP_CRON` is set without a system cron.

### Locks and time budgets

WP-Cron can double-run: its `doing_cron` lock expires after 60 seconds, and `ALTERNATE_WP_CRON` or a system cron hitting `wp-cron.php` has no lock at all. `add_option()` is not a mutex either. So every stage takes a row lock from a seeded `locks` table:

```text
Lock::acquire( key, ttl = 90 s ): owner = uuid4()
  rows = UPDATE locks SET owner = %s, expires_at = now + ttl
         WHERE lock_key = %s AND ( expires_at IS NULL OR expires_at < now )
  rows === 1 ? owner : null        -- rows are seeded at install; INSERT IGNORE + one retry if missing
Lock::release( key, owner ):  UPDATE locks SET expires_at = NULL WHERE lock_key = %s AND owner = %s

Time_Budget = min( 20 s, max_execution_time − 5, Action Scheduler time limit − 5 )
Lock TTL (90 s) is always greater than the budget, so a dead runner's lock expires before the next run needs it.
```

### Stages

Each stage is: acquire lock → start budget → loop while there is backlog and budget remains → release → schedule a continuation if backlog remains.

| Stage | What it does |
| --- | --- |
| **Evaluate** | Expires open, journey-less events older than the max age in one indexed `UPDATE … LIMIT 500`. Then selects open, journey-less, identified events past the inactivity threshold (`LIMIT 200`), runs eligibility for each, inserts the journey (`SCHEDULED` with `next_action_at`, or a terminal state with a reason), and stamps `journey_id` on the event `WHERE journey_id IS NULL` |
| **Dispatch** | Returns immediately if the rate budget is paused. Claims due `SCHEDULED` rows (see below), and for each: re-checks the event is open, consent and opt-out, then asks the Engine for the next step. A `wait` sets `next_action_at`; a `condition` advances `current_step`; an `action` runs the send protocol; the end of the workflow leaves the journey in `MESSAGE_SENT` or moves it to `EXPIRED` |
| **Poll** | Claims `MESSAGE_SENT` and `ENGAGED` rows by `poll_at` and reads the conversation from WA.cr from a minute before `first_sent_at`. An inbound message after the send means `ENGAGED` (`engaged_via = 'reply'`); a whole-message STOP keyword means `OPTED_OUT`; an outbound message matching an attempt updates it to `delivered`, `read` or `failed`; attempts in `unknown` are reconciled by time window and template. `poll_at` steps through 5 min, 15 min, 1 h, 6 h, 6 h… by `poll_count` and stops 72 hours after the first send |
| **Expire** | Moves active journeys past `expires_at` to `EXPIRED` in batches of 500, revokes their tokens and closes the open events of terminal journeys |
| **Retention** | Daily, in primary-key-ordered batches of 500 under the budget: anonymises terminal journeys past the retention period, deletes never-identified events after seven days, purges expired tokens, receipts older than 30 days and logs past their retention |

Every stage is idempotent (conditional `UPDATE`s and UNIQUE keys), retryable (claims expire), batchable (`LIMIT` and a budget) and observable (`recoveryflow_stage_stats` records last run, duration, processed, backlog and last error per stage, shown on System Status).

### Claims

Dispatch and Poll do not lock individual rows for the duration of an HTTP call. They claim them:

```sql
UPDATE journeys
   SET claim_token = %s, claimed_until = now + 90 s
 WHERE status = 'scheduled' AND next_action_at <= now
   AND ( claimed_until IS NULL OR claimed_until < now )
 ORDER BY next_action_at LIMIT 50
```

followed by a `SELECT … WHERE claim_token = %s`. Every subsequent write on the row is a compare-and-set that includes `claim_token` and `status` in its `WHERE` clause. If a conversion lands mid-batch, the conversion's transition wins the row, the runner's write affects zero rows, and the runner logs "lost race" and moves on.

### The channel a step sends on

**The action decides the channel; the step's `channel` key records it; the engine refuses the step if they disagree.**

An action *is* a way of reaching somebody, so the channel cannot be a choice beside it. A WA.cr template goes over WhatsApp because that is what a WA.cr template is, and no setting can make it arrive as email. `Action_Interface::get_channel()` is where each action says so, and three things read it: the engine, which compares it with `Workflow_Definition::channel_for( $step )` and fails the step with `channel_mismatch` rather than sending on whichever value it happened to read; `Workflow_Form`, which derives the stored key from the action instead of from the posted form; and the attempt ledger, which records what actually went out.

Eligibility is asked about that channel too. `Eligibility_Evaluator::for_send()` takes it and answers about it alone; passing nothing keeps the broader "can this person be reached at all" meaning, which is the right question when deciding whether a journey is worth *starting* but the wrong one when deciding whether *this* message may go out. The two answers differ for exactly the people who matter -- somebody who gave an email address and no phone number is reachable, and is not reachable on WhatsApp.

This is written down at length because the absence of it was a real bug that shipped: the key was stored, validated, described on screen and offered in a dropdown while nothing in the execution path read it, so a step configured as email sent WhatsApp and was billed as WhatsApp. Every static gate was green on it for four slices.

| Action | Channel | Needs the WA.cr developer API |
| --- | --- | --- |
| `wacr.send_template` | `whatsapp` | Yes -- Scale and above |
| `wacr.start_flow` | `whatsapp` | No -- a signed push to a hook |
| `wacr.send_email` | `email` | No -- `wp_mail()`, no WA.cr involvement at all |

`Workflow_Definition::DEVELOPER_API_ACTIONS` names the first column that answers yes, rather than listing the ones that answer no. An action this plugin does not ship is then assumed not to need the API, and that asymmetry is deliberate: guessing wrong that way defers at send time with a reason a merchant can read, while guessing the other way blocks them from saving the workflow at all.

### The send protocol

```text
Send_Gate      quiet hours ⇒ shift next_action_at · frequency cap ⇒ skip
Rate_Budget    checked, not debited -- the client debits it once per call
reserve        INSERT attempt (idempotency_key, status = 'sending', sending_started_at)
               already exists? sent ⇒ advance · sending/unknown ⇒ reconcile from the conversation,
               else wait until reconcile_after, else mark lost and reserve attempt_no + 1 (max 3)
mint token     store hash on the attempt row; render variables; compose
send           Client::send_template() exactly once
ok             ⇒ attempt sent + ids; journey CAS to MESSAGE_SENT, first_sent_at, poll_at = now + 5 min,
                 next_action_at = next step or NULL
429            ⇒ attempt failed; Rate_Budget::pause( Retry-After ); stop the batch
5xx / pre-send network error ⇒ back off min( 5 min · 2^n, 6 h ), at most 3 times
422 / 403      ⇒ journey FAILED + admin notice; a 403 pauses everything
timeout after the request was written ⇒ attempt unknown, reconcile_after = now + 2 min
```

## Idempotency matrix

Every one of these can and will happen on a real site. The table says which mechanism makes each safe and what the observable outcome is.

| # | Scenario | Mechanism | Outcome |
| --- | --- | --- | --- |
| 1 | Cart hooks fire repeatedly (several changes in one request, many requests a minute) | `woocommerce_cart_updated` only sets a dirty flag; one write on `shutdown`; the snapshot fingerprint skips unchanged carts; one write per 60 s per session unless the contact changed or the customer is on checkout; `INSERT … ON DUPLICATE KEY UPDATE` on `(source_id, dedupe_key)` | One open event row per session, updated in place |
| 2 | Double-submit at checkout, or the same order hook firing twice | Every order handler first claims receipt `wc_order:{id}:{status}`; a duplicate receipt returns early. Journey transitions are compare-and-set on `status` | The second submit is a no-op; the journey moves to `PENDING_PAYMENT` once |
| 3 | Two runners evaluate the same event | `journeys.event_id` is UNIQUE; a duplicate-key error is treated as "already done"; `UPDATE events SET journey_id WHERE journey_id IS NULL` | One journey per event, whichever runner won |
| 4 | Cron overlap (WP-Cron double-fires, or a system cron and a browser hit collide) | A row lock per stage with a 90-second TTL, always longer than the time budget; claims on individual rows | The second runner finds the lock held and exits |
| 5 | Retried send after an unknown outcome (timeout after the request was written) | The attempt row is `unknown` with `reconcile_after`; the Poll stage reads the conversation. An outbound message with our template after `sent_at` means `sent`; nothing after 15 minutes means `failed` and a new `attempt_no` with a new token | Never a blind re-send; at most one message per attempt row |
| 6 | Runner dies mid-send | The attempt stays `sending` and the claim expires. The next runner sees the existing row for the same idempotency key, reconciles it from the conversation, or waits until `reconcile_after`, then marks it lost and reserves `attempt_no + 1` (max 3) | The customer receives at most one message per attempt; a lost attempt is visibly `failed` |
| 7 | 429 storm from WA.cr | Local budget of 60 requests per minute (under WA.cr's 120); a 429 pauses the budget until `Retry-After`; the batch stops; the failed attempt's token is revoked | Sends resume after the pause with fresh attempts; no burst retries |
| 8 | Multiple order hooks for one order (`checkout_order_processed`, Store API processed, `new_order`, `payment_complete`, `order_status_changed`) | Receipt per `(order, status)`; `PENDING_PAYMENT → RECOVERED` is compare-and-set | One transition per distinct status; repeats are no-ops |
| 9 | Order matches by session and by customer | Attribution is decided in priority order: journey uid on the order (`exact`), event uid (`exact`), session key (`session`), customer match (`customer`). The first match is recorded | One attribution per journey; `exact` and `session` count toward recovered revenue by default, `customer` only when customer-level attribution is on |
| 10 | Unrelated later order by the same customer (no link, days later) | Customer match is limited to `attribution_window_days` (30). Inside the window it stops every active journey for the person with attribution `customer`; outside it, nothing happens | Messaging stops for a customer who bought anyway; revenue is not claimed unless the merchant chose to |
| 11 | Repeated link clicks, and preview-bot fetches | The redirector is a plain 302 every time; clicks are recorded only for non-preview user agents; `Cart_Restorer` merges and skips identical existing lines; per-token and per-IP limits of 30 per 10 minutes | The cart is rebuilt once; the customer can tap again safely; bots are never counted |
| 12 | Webhook delivered twice | Receipt on the delivery id or, failing that, the body hash | The duplicate returns `200 {"duplicate": true}` and changes nothing |
| 13 | Plugin re-activation, or WooCommerce re-activation | dbDelta is idempotent; lock rows are `INSERT IGNORE`; capabilities are versioned; scheduled actions are guarded by `as_has_scheduled_action()`; the hash key is never re-minted; default workflows are keyed by a UNIQUE slug | No duplicate schedules, tables, capabilities or workflows; existing hashes still match |
| 14 | Workflow edited while journeys are in flight | Saving bumps `version` and stores an immutable snapshot; journeys pin `(workflow_id, workflow_version)` and the dispatcher loads exactly that snapshot | Running journeys finish on the definition they started with; new journeys use the new version |
| 15 | Conversion lands while Dispatch holds the claim | The conversion's transition is compare-and-set on `status` and does not need the claim; the runner's later write includes `claim_token` and `status` in its `WHERE` and affects zero rows | The conversion wins; the runner logs "lost race" and does not send |
| 16 | Claim lease expires under a slow runner | A second runner can claim the row after `claimed_until`; the first runner's writes fail their compare-and-set; the attempt's UNIQUE idempotency key prevents a second reservation for the same step | No double send; the slow runner's work is discarded |
| 17 | WooCommerce deactivated | The source reports `is_available() === false`, so its hooks are not attached and no new events arrive; the scheduler driver switches to WP-Cron. In-flight WooCommerce journeys cannot answer `is_conversion_complete()`, and the engine fails closed: a step that cannot re-check completion does not send. Expire closes them at `expires_at` | The plugin keeps loading and ticking; Integrations shows WooCommerce as not installed; nobody is messaged about a cart the plugin can no longer see |
| 18 | Opt-out mid-journey (link, STOP keyword, webhook, WA.cr flag or admin) | A `suppressed` consent row is appended; every open journey for the `phone_hash` moves to `OPTED_OUT` and its tokens are revoked. Dispatch re-checks consent before every send and its write is compare-and-set | No further touch, even for a journey already claimed by a runner |

## Schema overview

Ten tables, each with one job, created by dbDelta and versioned by `recoveryflow_db_version`. All datetimes are UTC bound as strings, never SQL `NOW()`, because a host may set a non-UTC session timezone. Personal data columns are plaintext, like WooCommerce's own tables (encrypting searchable columns buys nothing against an attacker who has `wp-config.php`); only secrets are encrypted.

**`recoveryflow_customers`** holds one row per person, keyed by `phone_hash` (the only UNIQUE identity column). Email is indexed for the fallback match but is not unique. The row carries the normalised and raw phone, names, country, the linked WordPress user, the WA.cr contact reference when sync is on, a cached consent status and the anonymisation timestamp.

**`recoveryflow_consents`** is an append-only ledger. The latest row per `phone_hash` wins. Each row records the status (`granted`, `denied`, `withdrawn`, `suppressed`), where it came from (`checkout_classic`, `checkout_blocks`, `account`, `merchant_assertion`, `link`, `keyword`, `wacr`, `admin`), the wording version and a keyed hash of the IP. Because rows are keyed by hash rather than by customer, a suppression survives erasure of the customer row.

**`recoveryflow_events`** stores Recovery Events. `(source_id, dedupe_key)` is UNIQUE so an adapter's upsert can only ever hit the open row; when a row closes its `dedupe_key` is suffixed with `#id`, freeing the key for the next cart in the same session. The `evaluate` index `(status, journey_id, last_activity_at)` drives the Evaluate stage; `retention` `(status, updated_at)` drives clean-up. Item snapshots and adapter metadata are JSON columns.

**`recoveryflow_journeys`** stores one journey per event (`event_id` UNIQUE). Its indexes match the stages exactly: `due (status, next_action_at)`, `poll (status, poll_at)`, `expiry (status, expires_at)`, plus `claim_token`, `customer_status` for the one-open-journey rule and `source_status` for the Journeys list.

**`recoveryflow_attempts`** is the send ledger: one row per action execution with a UNIQUE `idempotency_key` and a UNIQUE `token_hash`. It records the template, the identifiers WA.cr returned, the attempt status and the timestamps that the reconciliation logic reads. Nothing about message content is stored.

**`recoveryflow_workflows`** holds the current definition of each workflow (JSON plus its hash), a UNIQUE slug, a status, an optional source it applies to, an `is_default` flag and the current version number.

**`recoveryflow_workflow_versions`** holds immutable snapshots keyed by `(workflow_id, version)`. Journeys pin a version; the dispatcher loads from here, never from the live workflow row.

**`recoveryflow_logs`** is a ring-bounded (2,000 rows) diagnostic log with a level, a category, an optional journey id, a short message and redacted JSON context. Every write passes through the Redactor first. Retention prunes it after 14 days by default.

**`recoveryflow_receipts`** is the deduplication ledger: a primary-key `receipt_key` with a `kind` and a timestamp. It records webhook delivery ids, order-status events and every personal-data reveal in the admin (the audit trail). Receipts older than 30 days are pruned.

**`recoveryflow_locks`** holds seeded rows, one per stage, acquired by a conditional `UPDATE`. It exists because neither `add_option()` nor transients are atomic.

Every processor query hits one of the stage indexes and carries a `LIMIT`. Overview counters are indexed `COUNT`s cached for five minutes. Admin lists join customers on primary key only.

## Where the roadmap stands

Everything the first release line described is built: the scaffold, the domain model, the WA.cr client, the WooCommerce integration, background processing, the workflow engine with both dispatch actions and the email sender, the admin screens, REST, privacy tooling and this documentation. So is everything that was once listed here as planned -- the form-based workflow editor, the template-variable picker, the Auto Flow recipe with its hook test button, the diagnostics export, the Gravity Forms adapter, the documented custom-source example, WP-CLI and the 100,000-row performance pass.

What is not built: the ticketing and downloads adapters listed in [`integrations.md`](integrations.md#planned-adapters), and a generic `webhook.post` workflow action. Two things are built but unverified by anything in this repository, and both need a person rather than a run: a screen-reader pass over the admin screens, and Gravity Forms against a real Gravity Forms install.
