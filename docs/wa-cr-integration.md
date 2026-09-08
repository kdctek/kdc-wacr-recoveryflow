# WA.cr integration

RecoveryFlow by WA.cr talks to exactly one external service: WA.cr. This document covers how the connection is made, what each feature needs, the two ways a journey reaches WhatsApp, how engagement comes back, how the plugin stays inside WA.cr's limits, what each error means, and the plan gate. Everything is implemented in `WAcr\RecoveryFlow\WAcr\Client` and its collaborators.

## Connection

**Environments.** Production talks to `https://api.wa.cr`; the staging environment (a Settings option) talks to `https://api.wacart.dev`. A custom host is accepted only for white-label keys, must be HTTPS, and must pass `recoveryflow_wacr_allowed_hosts`.

**API key.** Paste a WA.cr developer API key (`wacr_live_…`, `wacr_test_…`, or a white-label `waht_…` key) in Settings › WA.cr › Connection. The key is stored encrypted, shown masked afterwards, and never returned by any REST route. The workspace is derived from the key by WA.cr; the plugin never sends or stores a workspace identifier.

**Test connection.** `GET /v1/me` needs no scope. Its response gives the plugin the key's granted scopes and the workspace status, which are cached for one hour and shown on the Connection card and System Status. The same call is how the plugin learns whether the workspace has developer API access (see the plan gate below).

**Sender.** `GET /v1/channels?status=connected` lists the WhatsApp senders that can actually send; pick one on the Sender card. Only a `connected` channel can send.

### Scopes needed per feature

| Feature | WA.cr call | Scope |
| --- | --- | --- |
| Test connection, plan detection, workspace status | `GET /v1/me` | none |
| Sender picker | `GET /v1/channels?status=connected` | `channels:read` |
| Template picker and variable mapping | `GET /v1/templates?status=APPROVED` | `templates:read` |
| Direct sends (`wacr.send_template`) | `POST /v1/messages` | `messages:send` |
| Engagement polling, delivery status, reconciling unknown sends, STOP keywords | `GET /v1/conversations/{digits}/messages` | `messages:read` |
| WA.cr opt-out check before a direct send | `GET /v1/contacts?q=…` | `contacts:read` |
| Sync contacts to WA.cr; sync opt-out to WA.cr | `POST /v1/contacts`, `PATCH` | `contacts:write` |
| Auto Flow hand-off (`wacr.start_flow`) | `POST {api}/automations/hooks/{token}` | none; gated by the workspace's Auto Flows entitlement, not by `/v1` |

A missing scope never breaks the plugin. The feature that needs it is disabled with an explanation and a deeplink, and System Status lists "missing scopes" with the same link.

## The two dispatch actions

Both are workflow actions and both go through the same client. A workflow uses one or the other; the default workflows seeded on activation come in both variants.

| | `wacr.start_flow` (hand-off) | `wacr.send_template` (direct send) |
| --- | --- | --- |
| Who decides timing and content | WA.cr's Auto Flow: delays, branches, stop-on-reply, quiet hours, 24-hour frequency cap | The WordPress workflow: waits, conditions, template per step |
| What the plugin sends | One signed `recovery.journey_eligible` event to the flow's hook URL | One `POST /v1/messages` per touch with an approved template and mapped variables |
| WA.cr requirement | Auto Flows on the workspace. No API key needed | Developer API access: WA.cr Scale and above |
| Engagement back in WordPress | Optional: an Auto Flow webhook step to `/wp-json/kdc/v1/wacr/recoveryflow/webhooks/wacr` | Automatic polling of the conversation (`messages:read`), plus the webhook if configured |
| Best for | Merchants who want the conversation designed and run in WA.cr | Merchants who want timing and content controlled inside WordPress |

## Template variable mapping

WhatsApp business-initiated messages to a contact outside an open 24-hour window must use an approved template. `GET /v1/templates?status=APPROVED` returns, for each template, the variables it expects (`body_1`, `header_1`, `button_0_url_1`, …). In a workflow step you map each of those to a RecoveryFlow variable:

```json
{ "type": "action", "do": "wacr.send_template", "with": {
    "template": "cart_reminder_1", "language": "en",
    "variables": {
      "body_1": "{{customer.first_name}}",
      "body_2": "{{recovery.total_formatted}}",
      "button_0_url_1": "{{recovery.token}}"
    } } }
```

The allow-list of RecoveryFlow variables is in [`developer-api.md`](developer-api.md#variable-allow-list). Values rendered into template parameters are cleaned as WhatsApp requires (no newlines or tabs, no runs of four or more spaces, length-capped). For a URL button whose template URL ends in `/recovery/`, map the bare `{{recovery.token}}`; for one that takes a full URL, map `{{recovery.recovery_url}}`.

The picker that fills this in from `/templates` without editing JSON is planned for slice 2.

## Auto Flow hand-off recipe

This is the path that works on any workspace with Auto Flows.

1. In WA.cr (app.wa.cr) create an Auto Flow whose trigger is **Webhook received**. Copy its hook URL and set a signing secret. Point its identity mapping at the `phone` field of the payload.
2. In WordPress, go to Settings › WA.cr › Auto Flow hand-off, paste the hook URL and the signing secret. The URL must be HTTPS on `api.wa.cr` or `api.wacart.dev`.
3. In Workflows, make the "Hand off to WA.cr Auto Flow" workflow the default for your source.
4. Optionally, add a second Auto Flow (or a second trigger) for `recovery.journey_recovered` and `recovery.journey_expired`, and paste its URL as the secondary hook, so a running flow can stop the moment the customer buys.
5. Optionally, add a **Webhook** step to the flow that posts `reply` and `opt_out` events back to `https://example.com/wp-json/kdc/v1/wacr/recoveryflow/webhooks/wacr` with the header `X-RecoveryFlow-Secret` set to the secret minted in Settings › WA.cr. This is how a hand-off journey becomes `ENGAGED` or `OPTED_OUT` in WordPress without the `messages:read` scope.

A "Send test event" button for the hook is planned for slice 2.

### Signature

Every hook push carries `x-wacr-signature`: an HMAC-SHA256 of the raw request body using the signing secret. Configure the same secret on the Auto Flow trigger so WA.cr rejects anything not sent by your site. Payloads are well under WA.cr's 64 KB hook limit; the items summary is length-capped for that reason.

### The `recovery.journey_eligible` payload

Flat scalars only, so WA.cr exposes each top-level key to the flow as a `{{var.hook_<key>}}` variable.

| Field | Type | Meaning | Flow variable |
| --- | --- | --- | --- |
| `event` | string | Always `recovery.journey_eligible` | `{{var.hook_event}}` |
| `journey_uid` | string | Opaque journey reference | `{{var.hook_journey_uid}}` |
| `source` | string | Source id, for example `woocommerce` | `{{var.hook_source}}` |
| `source_type` | string | `cart`, `checkout`, `form`, `ticket`, `booking` or `payment` | `{{var.hook_source_type}}` |
| `phone` | string | Customer phone, E.164. Map this as the identity field | `{{var.hook_phone}}` |
| `first_name` | string, optional | Present when known and enabled | `{{var.hook_first_name}}` |
| `currency` | string | ISO 4217 | `{{var.hook_currency}}` |
| `total` | string | Decimal, major units | `{{var.hook_total}}` |
| `item_count` | integer | Number of lines | `{{var.hook_item_count}}` |
| `items_summary` | string | Short list of item names | `{{var.hook_items_summary}}` |
| `recovery_url` | string | The recovery link for this touch | `{{var.hook_recovery_url}}` |
| `opt_out_url` | string | The opt-out page for this touch | `{{var.hook_opt_out_url}}` |
| `site_name`, `site_url` | string | From WordPress | `{{var.hook_site_name}}`, `{{var.hook_site_url}}` |
| `abandoned_at` | string | ISO 8601 UTC | `{{var.hook_abandoned_at}}` |

The optional `recovery.journey_recovered` and `recovery.journey_expired` events carry `event`, `journey_uid`, `phone`, `source` and the timestamp, so the flow can match the conversation and stop.

### Suggested flow shape

```text
Webhook received (recovery.journey_eligible, identity = phone)
  → Delay 30 minutes (or rely on RecoveryFlow's inactivity threshold and delay less)
  → Send template "cart_reminder_1"
      body_1 = {{var.hook_first_name}}, body_2 = {{var.hook_total}}, button URL = {{var.hook_recovery_url}}
  → Wait for reply (stop on reply; hand the conversation to a person or a bot)
  → Delay 24 hours
  → Send template "cart_reminder_2"
  → Stop
```

WA.cr applies its own quiet hours and 24-hour frequency cap to every step, so the flow does not need to model them.

## Engagement polling

With `messages:read`, the Poll stage reads `GET /v1/conversations/{digits}/messages?after=<ISO>&limit=200` for journeys in `MESSAGE_SENT` or `ENGAGED`, from one minute before the first send. This is the only way to learn that a customer replied or what happened to a sent message; WA.cr does not push outbound events.

- An inbound message after the send marks the journey `ENGAGED` (`engaged_via = 'reply'`).
- A whole-message opt-out keyword marks it `OPTED_OUT`.
- An outbound message that matches an attempt updates the attempt to `delivered`, `read` or `failed`.
- An attempt in `unknown` (the request timed out after it was written) is reconciled by time window and template: an outbound message with our template after `sent_at` means it was sent; none within 15 minutes means it was not, and a fresh attempt is minted.

Polling backs off through 5 minutes, 15 minutes, 1 hour and then every 6 hours, and stops 72 hours after the first send. Without `messages:read`, journeys still work; engagement then arrives only through the webhook receiver or a recovery-link click.

## Rate limits and how the plugin stays under them

WA.cr allows 120 requests per minute per key and answers `429` with `Retry-After` beyond that. The plugin never gets close:

- **Local budget.** `Rate_Budget` keeps a fixed-window counter of 60 requests per minute in an atomically updated option. Every stage takes from it before each call and yields when it is exhausted; the backlog drains on the next run.
- **Pause on 429.** A `429` pauses the budget until `Retry-After` and stops the current batch. The failed attempt's token is revoked; a new attempt is minted after the pause.
- **Caching.** `/v1/me` one hour; senders 15 minutes; templates cached; the WA.cr opt-out flag six hours.
- **Batches.** Dispatch claims at most 50 journeys per run and Poll reads one conversation per journey with a 200-message page.
- **No automatic retry of sends.** `POST /v1/messages` has no idempotency key; a retry would double-send and double-bill. The plugin sends once per attempt row and reconciles rather than retries.

## Error categories

Every failure is classified into one category, stored on the attempt and the journey (`last_error_code`), and shown with a deeplink to the setting that fixes it where one exists.

| Category | Typical causes | What the plugin does |
| --- | --- | --- |
| `CONFIGURATION_ERROR` | No key, undecryptable key, no sender, no template mapped | Nothing is sent; System Status names the missing piece with a deeplink |
| `AUTHENTICATION_ERROR` | 401; `plan_upgrade_required` (the workspace lacks developer API access) | Direct sends pause; the plan gate re-evaluates; a notice links to Settings › WA.cr |
| `AUTHORIZATION_ERROR` | `insufficient_scope` | The feature needing the scope is disabled with an explanation; nothing else stops |
| `API_ERROR` | 5xx; `send_failed`, `invalid_body`, `unknown_sender` | 5xx and pre-send network errors back off (5 min doubling to 6 h, at most three tries); a 422-class body error marks the journey `FAILED` with an admin notice |
| `RATE_LIMIT_ERROR` | 429 | Budget paused until `Retry-After`; batch stops |
| `INTEGRATION_ERROR` | A source could not answer or restore | Logged; the journey fails closed (no send) |
| `VALIDATION_ERROR` | Template variables missing or malformed, phone invalid | Journey `INVALID` or `FAILED` with a reason; nothing is sent |
| `RECOVERY_EXPIRED` | The journey or token expired before the step ran | Step skipped; journey `EXPIRED` |
| `RECOVERY_ALREADY_COMPLETED` | The source reports the conversion already happened | Step skipped; journey `RECOVERED` or `PENDING_PAYMENT` |

The transport distinguishes a network failure before the request was sent (`network_pre_send`: DNS, connection refused; safe to retry) from a timeout after it was written (`timeout_unknown`: outcome unknown; reconciled, never retried blind).

## What we send

The disclosure below is identical in `readme.txt`, [`privacy.md`](privacy.md#what-is-sent-to-wacr-and-when) and the "What RecoveryFlow sends to WA.cr, and when" card in Settings › WA.cr.

On an eligible send or hand-off: the phone number in E.164; the template name and language; the mapped variable values (first name, total and currency, item count, recovery link, site name); for hand-off additionally the items summary, site URL, abandonment time, opt-out link and journey reference; last name and email only when enabled; the API key in the authorisation header. On "Test connection": the key only. When listing templates and senders: no customer data. Before a direct send with `contacts:read`: the phone number, to read the opt-out flag. When polling with `messages:read`: the phone number, to read that conversation. When contact sync or opt-out sync is enabled: the phone, name and opt-out flag. When a secondary hook is configured: the journey reference and phone on recovery or expiry. Nothing when the integration is disabled, in `disabled` mode, for ineligible journeys, or at install, activation or upgrade.

## The Lite/Pro gate

One plugin; `Core\Feature_Gate` decides what it offers based on the connected workspace.

| | Any workspace with Auto Flows | WA.cr Scale and above |
| --- | --- | --- |
| Detection, identity, consent, eligibility, recovery links, attribution, Overview, Journeys, System Status | Yes | Yes |
| Dispatch | `wacr.start_flow` to an Auto Flow | Plus `wacr.send_template` from WordPress-authored multi-touch workflows |
| Engagement back in WordPress | Auto Flow webhook step → webhook receiver | Plus automatic conversation polling |
| Workflow editor | Hand-off workflow only | Full editor (form-based editor planned for slice 2) |

The source of truth is the cached `/v1/me` result: a successful response means the workspace holds developer API access and the Pro features are on; `403 plan_upgrade_required`, no key, or an undecryptable key means the hand-off path only. The gate re-evaluates on every `/v1/me` (test connection, hourly refresh, or any `plan_upgrade_required` seen by the client). Gated screens render the real screen with a core card explaining "included with WA.cr Scale and above" and a deeplink to Settings › WA.cr, never a disabled shell. The filter `recoveryflow_feature_enabled` and the constant `KDC_WACR_RECOVERYFLOW_UNLOCK_ALL` (for development and tests) override it, but WA.cr still enforces developer API access server-side, so an override cannot send what the workspace is not entitled to send.
