# Security

RecoveryFlow by WA.cr handles three things an attacker would like: the merchant's WA.cr API key (which can spend their messaging wallet), customers' phone numbers and emails, and links that open a customer's cart. This document describes the controls, why each exists, and the test that proves it. The tests listed in the last column live in `tests/security/` and `tests/failure/` and are described in [`testing.md`](testing.md).

## Capabilities

Installed on activation and re-applied on `init` whenever `recoveryflow_caps_version` changes. The map can be adjusted with `recoveryflow_capability_map`.

| Capability | Granted to | Allows |
| --- | --- | --- |
| `recoveryflow_manage_settings` | Administrator only | Settings, the API key, environment, webhook secret, test connection, disconnect, eligibility mode |
| `recoveryflow_view_journeys` | Administrator, Shop manager | The Journeys list and detail with masked personal data |
| `recoveryflow_reveal_pii` | Administrator, Shop manager | Unmasked phone, email and item snapshot. Every reveal writes an audit receipt |
| `recoveryflow_manage_journeys` | Administrator, Shop manager | Cancel, retry, revoke links, manual opt-out |
| `recoveryflow_manage_workflows` | Administrator, Shop manager | Create and edit workflows; list templates |
| `recoveryflow_view_status` | Administrator, Shop manager | Integrations and System Status |

`map_meta_cap` lets `manage_options` satisfy only `recoveryflow_manage_settings` and `recoveryflow_view_status`. That is deliberate lock-out safety: an administrator can always reach Settings and Status, but does not get journey data or workflow editing merely by being an administrator if a site has removed those capabilities. The privacy tools reuse core's `export_others_personal_data` and `erase_others_personal_data`.

## Recovery tokens

The recovery link is `https://example.com/recovery/{token}` (or `?rf={token}` without pretty permalinks). It is the only public, unauthenticated surface that touches customer data, so it is designed to reveal nothing and to be useless to anyone but the recipient.

- **Generation.** `random_bytes(32)` encoded base64url: exactly 43 characters from `[A-Za-z0-9_-]`. The rewrite rule and the query fallback enforce the alphabet and length before any database query.
- **Storage.** Only the SHA-256 hash is stored, as a UNIQUE column on the attempt row. A database dump does not yield working links.
- **One token per send.** Every attempt mints its own token. A retry mints a new attempt and a new token; tokens are never rotated in place, so a message already delivered keeps working.
- **Lifetime.** `token_expires_at` from the setting (default 7 days, maximum 30). Revoked for the whole journey when it reaches `RECOVERED`, `CANCELLED`, `OPTED_OUT` or `EXPIRED`, and the moment an order is placed (`PENDING_PAYMENT`).
- **Uniform failure.** Unknown, expired and revoked tokens all render the same generic page with the same status code. The response does not distinguish "never existed" from "used to exist".
- **Rate limiting.** 30 requests per 10 minutes per IP hash and, separately, per token.
- **Redirector behaviour.** `/recovery/{token}` is a 302 redirector: restore the cart into the visitor's own session, stash the journey uid in that session for attribution, redirect to the source's URL. It sends `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` and `nocache_headers()`. The redirect target is computed by the adapter and never taken from the request.
- **Clicks.** Recorded only for non-preview user agents. WhatsApp's link-preview fetcher and similar crawlers (`WhatsApp/`, `facebookexternalhit`, …) fetch every URL in every message; counting them would mark every journey engaged.

## Opt-out

`GET /recovery/{token}/opt-out` renders a confirmation page showing only the site name. `POST` to the same URL with `rf_confirm=1` performs the opt-out: it appends a `suppressed` consent row, moves every open journey for that phone hash to `OPTED_OUT` and revokes their tokens.

**Why POST only.** WhatsApp's preview fetcher performs a GET on every link in a message. If a GET could opt someone out, every recipient would be opted out the moment the message was delivered. The POST is deliberately nonce-free (the recipient has no WordPress session) but it is bound to the token, so only the holder of a valid link can submit it, and a preview bot never submits forms.

Opt-outs also arrive by keyword: the Poll stage treats a whole-message `STOP`, `UNSUBSCRIBE`, `CANCEL`, `END` or `QUIT` (filter `recoveryflow_optout_keywords`) as an opt-out. Before every direct send the plugin checks its own latest consent row and, when the `contacts:read` scope is granted, the customer's `optedOut` flag in WA.cr (cached six hours). Writing the flag back to WA.cr is a separate opt-in setting (`sync_optout_to_wacr`, needs `contacts:write`) because WA.cr's flag applies to the whole workspace, not just this site.

## Webhook receiver

`POST /wp-json/recoveryflow/v1/webhooks/wacr` accepts engagement events from a WA.cr Auto Flow webhook step. It is public by necessity and hardened accordingly:

1. **Dark until configured.** Returns 404 until a webhook secret has been minted in Settings.
2. **Size and type.** Bodies over 64 KB get 413; anything but JSON gets 415.
3. **Authentication.** The header `X-RecoveryFlow-Secret` (name configurable) is compared with `hash_equals( stored_hash, sha256( supplied ) )`. Wrong or missing gets 401. The secret itself is stored only as a hash and shown once at generation, with a Rotate button.
4. **Rate limit.** Per IP hash; 429 when exceeded.
5. **Replay.** A receipt is written for the delivery id (or the body hash when there is none); a duplicate returns 200 `{"duplicate": true}` and changes nothing.
6. **Allow-listed events.** Only `reply` and `opt_out`. Anything else is acknowledged and ignored.
7. **Matching.** Journeys are matched only by the phone hash. No match returns 200 `{"matched": 0}`. The receiver never creates journeys, customers or consents from webhook data.
8. **Never 5xx.** Malformed input returns 400; exceptions are caught. The sender gets nothing to retry against and nothing to learn from.

## Secrets at rest

| Secret | Storage |
| --- | --- |
| WA.cr API key | AES-256-GCM with the `rfenc1:` prefix. The key is derived from `sha256( 'recoveryflow\|' + the four WordPress salts )`, or from the `RECOVERYFLOW_ENCRYPTION_KEY` constant when defined. Option autoload is off. Never rendered; shown masked as `wacr_live_••••1234`. If the salts change and the stored key cannot be decrypted, System Status says "re-enter key" and sends stop with a configuration error rather than failing silently |
| Webhook secret | SHA-256 only. Shown once, rotated on demand |
| Hash key | 32 random bytes minted on activation, stored with autoload off, used for the HMAC of phones, emails and IPs. Never included in exports or diagnostics |
| Auto Flow signing secret | Encrypted like the API key; used only to sign outbound hook payloads |

Nothing secret appears in the System Status export, the REST status DTO, admin HTML, inline scripts or logs.

## Redaction

`Security\Redactor` runs before every log or diagnostic persist. It removes values by key (`phone*`, `email`, `first_name`, `last_name`, `name`, `to`, `text`, `body`, `components`, `token`, `secret`, `api_key`, `authorization`, `recovery_url`, `address*`, `items`) and masks by pattern: E.164 numbers, email addresses, `(wacr|waht)_(live|test)_…` keys, 43-character tokens and `/recovery/…` paths, and `Bearer …` headers. The logs table is ring-bounded at 2,000 rows and pruned by retention. Message content is never logged at all.

## Application hygiene

- Every admin action passes `check_admin_referer()`; every settings field has a Settings API sanitiser; every REST route has a permission callback and argument schema with sanitise and validate callbacks.
- Output is escaped with `esc_*` at the point of output. Customer names and product titles are attacker-controlled and are treated as such everywhere they appear, including DTOs and list tables.
- No personal data is passed to `wp_localize_script()` or inline scripts.
- Every SQL statement goes through `$wpdb->prepare()`; `ORDER BY` columns, filters and enums are allow-listed; every list query carries a `LIMIT`.
- The HTTP transport uses `wp_remote_request()` with a 15-second timeout and `sslverify` forced true (not filterable). The client never retries a `POST /v1/messages` automatically, because WA.cr has no idempotency key and a retry would double-send and double-bill.
- Hook URLs and the custom host (white-label keys only) must be HTTPS on a host allow-listed by `recoveryflow_wacr_allowed_hosts`.

## Threat model

| Asset | Threat | Control | Test |
| --- | --- | --- | --- |
| WA.cr API key | Exposure through a database dump, options export, REST, admin HTML or logs | AES-256-GCM at rest with a salt-derived key; autoload off; masked rendering; excluded from status and diagnostics; Redactor pattern for key prefixes | Status DTO and diagnostics contain no key; transport never logs the key; Redactor masks `wacr_live_…` |
| WA.cr API key | Salt rotation makes the stored key unreadable and sends fail silently | Decrypt failure surfaces as "re-enter key" in System Status; sends stop with `CONFIGURATION_ERROR`; nothing crashes | Failure suite: rotate salts, expect the reported state |
| Merchant's messaging wallet | Spam or enrolment through checkout: anyone enters any phone number | No public ingest endpoint and no frontend JavaScript; events only from server-side hooks; explicit consent by default; one message per 24 hours per phone; one open journey per phone; three journeys per phone per 30 days; minimum amount; shop managers excluded | Eligibility reason table (`frequency_cap`, `already_open`); guest capture flow in integration tests |
| Recovery link | Token guessing or enumeration | 256 bits of randomness; regex gate on length and alphabet before any query; hash-only storage; uniform 404; per-IP and per-token rate limits | 42- and 44-character tokens rejected; unknown, expired and revoked tokens return identical responses; 31st hit in 10 minutes returns 429 |
| Recovery link | Token leak through the Referer header, server logs, or link-preview bots | `Referrer-Policy: no-referrer`; `X-Robots-Tag: noindex`; no-cache headers; Redactor masks tokens and `/recovery/` paths in plugin logs; preview user agents are never counted; opt-out needs POST | Headers asserted on the redirector; preview-UA click suppression; Redactor patterns |
| Recovery link | Replay after recovery or expiry (reopening a bought cart, or a stale cart) | Tokens revoked for the whole journey on any terminal state and on `PENDING_PAYMENT`; default 7-day, maximum 30-day lifetime; restore refuses when the logged-in user differs from the journey's user | Expired token returns the generic page; tokens revoked after an order in integration tests |
| Webhook receiver | Forged or replayed webhook creates or alters journeys | Dark until a secret exists; `hash_equals` on the secret hash; delivery receipts; allow-listed events; phone-hash matching only; never creates rows; never 5xx | Wrong secret 401; duplicate receipt returns `duplicate: true`; disallowed event ignored; unknown phone returns `matched: 0` |
| Webhook receiver, redirector | Oversized bodies and wrong content types exhaust resources | 64 KB cap (413); JSON only (415) | 413 and 415 asserted |
| Admin actions | Cross-site request forgery on cancel, retry, revoke, opt-out or settings | `check_admin_referer()` on every row and bulk action; Settings API nonces; REST cookie nonce | Every row action and settings save without a nonce is rejected |
| Capabilities | Privilege escalation: a shop manager reaching settings or the key | Dedicated capabilities; `map_meta_cap` grants `manage_options` only settings and status; the capability map filter is validated against known capability names | REST matrix: every route × anonymous, subscriber, shop manager, administrator × with and without nonce |
| Admin screens, REST | Stored XSS from customer names or product titles | `esc_*` at output in list tables, detail timeline and DTOs; no personal data in inline scripts | Name and product title containing markup render inert in list, detail and DTO |
| Database | SQL injection through list filters | `$wpdb->prepare()` everywhere; `orderby`, `order`, `status` and `source` are allow-listed enums; `search` is hashed, exact-matched or escaped for LIKE; `LIMIT` always | Injection strings in `orderby`, `order`, `status`, `source` and `search` are rejected or neutralised |
| Personal data | Unauthorised reveal of unmasked phone, email or items | `reveal=1` requires `recoveryflow_reveal_pii`; every reveal writes an audit receipt; masked by default everywhere | Reveal without the capability returns 403; with it, an audit row exists |
| Personal data | Leakage through logs and diagnostics | Redactor deny-list and patterns before every persist; ring-bounded logs; 14-day retention; message content never logged; diagnostics export redacted | Redactor pattern suite; diagnostics export contains no phone, email, key or token |
| Personal data | Over-retention, or data left behind after uninstall | Retention stage (90-day anonymisation, 7-day anonymous events, 30-day receipts, 14-day logs); uninstall always removes secrets and always removes data when the option is on | Retention and uninstall integration tests |
| Opt-out | Privacy erasure re-enables messaging by deleting the suppression | Suppression lives in the consent ledger keyed by phone hash; the eraser anonymises the customer but keeps the hash; eligibility reads the latest consent row | Erased customer with a prior opt-out is still `suppressed` |
| Redirector | Open redirect to an attacker's site | The target is computed by the adapter (`wc_get_checkout_url()` for WooCommerce) and never read from the request | Redirect target fixed regardless of query parameters |
| WA.cr opt-out flag | Overstepping WA.cr's workspace-global opt-out: messaging someone WA.cr has opted out, or writing a site-level opt-out to the whole workspace | `optedOut` is read before every direct send when `contacts:read` is granted; writing it requires the explicit `sync_optout_to_wacr` setting and `contacts:write` | Send gate skips a WA.cr-opted-out contact; with the setting off, no contact update is sent |
