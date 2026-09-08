# Testing

RecoveryFlow by WA.cr moves money-adjacent state (a merchant's messaging wallet, a customer's cart) in the background, under overlapping schedulers, against a remote API with no idempotency key. The test strategy is built around that: every duplicate-trigger scenario in the idempotency matrix, every row of the threat model and every failure mode of the WA.cr connection has a test that exercises it.

## Suites

| Suite | Directory | Runner | Needs WordPress | Speed |
| --- | --- | --- | --- | --- |
| Unit | `tests/unit/` | PHPUnit 9.6 + Yoast polyfills + Brain\Monkey | No | Sub-second |
| Integration | `tests/integration/` | WordPress core test suite inside wp-env, with WooCommerce | Yes | Minutes |
| Security | `tests/security/` | Runs inside the integration bootstrap | Yes | Minutes |
| Failure | `tests/failure/` | Runs inside the integration bootstrap, with a fake WA.cr transport | Yes | Minutes |
| Accessibility | `tests/a11y/` | pa11y-ci (WCAG2AAA standard, axe and htmlcs runners) against a logged-in wp-env session | Yes | Minutes |

Static checks run alongside: `composer lint` (`php -l` plus PHPCS with WordPress, WordPress-Extra, WordPress.Security and PHPCompatibilityWP for PHP 8.0 and above) and `composer analyse` (PHPStan level 5 with the WordPress extension).

## Performance

`tests/perf/seed.php` is a WP-CLI harness for finding out what a large site costs. It is excluded from the distributed plugin: a command that writes a hundred thousand rows of invented customers is a benchmarking tool, not a feature, and there is no version of it that belongs on a merchant's server.

```bash
wp --require=tests/perf/seed.php recoveryflow-perf seed --events=100000
wp --require=tests/perf/seed.php recoveryflow-perf explain
wp --require=tests/perf/seed.php recoveryflow-perf time
wp --require=tests/perf/seed.php recoveryflow-perf clear --yes
```

`clear` removes what it wrote, including customers whose identity rows the retention pass has since anonymised away -- the id range of each run is written to an option for exactly that reason. Without it, a `clear` that looked for its own identities reported success while leaving five thousand orphaned customers behind, which is how it behaved the first time it was run for real.

Everything it writes is obviously fake and obviously ours: numbers come from Ofcom's reserved drama range, which can never be allocated to a real person; emails are on `example.test`; and every row is tagged so `clear` finds them again rather than guessing.

`explain` captures each statement on its way to MySQL through the `query` filter and EXPLAINs it, inside a transaction that is rolled back. It does not contain a copy of any query. That is the point: a benchmark that EXPLAINs a hand-copied approximation reports the plan of a statement the plugin never runs, and reports it confidently. The first version of this command did exactly that, and described the queue screen as a covering index read when the statement it had covered was a different one.

### Measured, 2026-09-08

wp-env, MySQL 8, one container, cold. Two runs: 100,000 events / 20,000 journeys, then 357,000 events / 130,000 journeys.

| Pass | 100k events | 357k events |
| --- | --- | --- |
| evaluate | 3.9 s (closed 33,195 stale events) | 2.9 s (closed 101,844) |
| dispatch | 33 ms (claimed 50, more waiting) | 153 ms (claimed 24) |
| poll | 1 ms | 1 ms |
| expire | 2 ms | 816 ms |
| retention | 763 ms (51,098 rows) | 4.2 s (150,054 rows) |

Every background query reads an index; none is a full table scan.

**What the pass changed.** Two statements were rewritten as a SELECT that reads the index built for it followed by an UPDATE addressed by primary key, because as single `UPDATE … ORDER BY … LIMIT` statements MySQL was free to choose a different index and a sort, and did:

- **Closing stale events** used `retention (status, updated_at)`, which answers only the first of its three conditions, and examined ~74,000 rows every pass to close 500. It now reads `evaluate (status, journey_id, last_activity_at)` as a covering index. Measured on the same data: **no difference at 100k (10.8 ms against 10.5 ms) and 3.2× faster at 357k (54.5 ms against 17.3 ms)** — the cost is in rows examined, and that only begins to bite once the table is large enough for the wrong index to matter. It also removes an `UPDATE … LIMIT` with no `ORDER BY`, which MySQL treats as unsafe for statement-based replication.
- **Claiming a batch** sorted the matching rows in memory to take fifty, because the status is an `IN` of several values and no index can deliver those already ordered by the due column. The UPDATE is now by primary key.

Splitting a claim in two is only safe because the UPDATE re-checks the lease: two runs may select the same rows, and the second then claims none of them, so the caller reads back only what carries its own token. That re-check is asserted, and removing it turns the suite red.

**Known and left alone.** The claim's SELECT still sorts (`Using filesort`) for the reason above; measured, it costs 33 ms at 100k journeys and 153 ms at 357k, which does not justify a second index on a hot table. The queue screen's total is an unfiltered `COUNT(*)`, which InnoDB can only answer by scanning an index — 129,059 entries at 357k journeys. Both are recorded here so the next person measures rather than rediscovers them.

## How to run each

```bash
composer install                # once
composer lint                   # coding standards and PHP syntax
composer analyse                # static analysis
composer test:unit              # unit suite, no WordPress needed

npx @wordpress/env start        # WordPress + WooCommerce in Docker; plugin mapped from the repo root
composer test:integration       # integration, security and failure suites inside wp-env
npm run a11y                    # pa11y-ci over every admin screen and the public pages
npx @wordpress/env stop
```

CI runs lint, analyse and unit on PHP 8.0 and 8.3, and integration plus accessibility on one matrix cell through wp-env.

## Fixture approach

- **Cart and order fixtures** are plain PHP arrays in `tests/fixtures/` describing WooCommerce carts (lines, coupons, totals) and orders (status sequences). Integration tests build real WooCommerce carts and orders from them; unit tests feed them straight into the event normaliser.
- **WA.cr responses** are JSON fixtures for `/v1/me`, `/v1/channels`, `/v1/templates`, `/v1/messages`, `/v1/contacts` and `/v1/conversations/{digits}/messages`, including the error envelope for each error code and a `429` with `Retry-After`.
- **Fake transport.** `WAcr\Client` takes a `Transport`; tests inject a fake that returns scripted responses, records every request (method, URL, headers, body) and can simulate a timeout after the request was written, a pre-send network failure, or a slow response that exceeds the time budget. No test ever reaches the network.
- **Clock.** `Core\Clock` is injectable so tests move time forward to make journeys due, tokens expire and quiet hours start, without sleeping.
- **Phone table.** A CSV of raw inputs, country codes and expected E.164 results (or `invalid`) drives the normaliser test.
- **Eligibility table.** A matrix of customer, consent, mode and rule inputs against the expected reason code drives the eligibility test.

## What each suite covers

### Unit

Event normalisation (WooCommerce cart fixture to `Recovery_Event`); the eligibility rules and reason table; the identity-resolver matrix (user, phone, email fallback, external id, create, and the conflict cases); `Journey_State` allowed and forbidden transitions; `Token_Service` (alphabet and length, hash-only storage, expiry, per-journey revocation); the phone normaliser table; `Template_Renderer` (escaping, unknown variables, WhatsApp parameter rules); the workflow `Engine` (sequencing, stop reasons, idempotent advance); `WAcr\Client` with the fake transport (envelope decoding, error categories, 429 pause, timeout means unknown, no automatic retry on `POST`, exact HMAC signature bytes on a hook push); the Redactor; `Mask`; the `Rate_Limiter`.

### Integration

Schema install and upgrade; repositories; REST controllers through `WP_REST_Request`; the WooCommerce adapter end to end (guest cart becomes an event, checkout capture identifies the guest, the journey is created; a paid order marks it `RECOVERED`; a failed payment leaves it resumable; a cancelled order after a resume marks it `CANCELLED`; the recovery link rebuilds the cart and 302s to checkout); the processor tick end to end with the fake transport; the privacy exporter and eraser; uninstall; settings deeplink routing.

### Security matrix

Mirrors the threat model in [`security.md`](security.md#threat-model):

- REST matrix: every route × anonymous, subscriber, shop manager, administrator × with and without a nonce.
- Capability install, filter and `map_meta_cap` fallback.
- CSRF on every row action, bulk action and settings save.
- SQL injection through `orderby`, `order`, `status`, `source` and `search`.
- Stored XSS through a customer name and a product title in the list, the detail timeline and the DTO.
- Token regex (42 and 44 characters rejected), uniform 404 for unknown, expired and revoked tokens, 429 after the limit, fixed redirect target, response headers, preview-UA click suppression.
- Opt-out: GET changes nothing, POST suppresses and cancels.
- Webhook: wrong secret, oversized body, wrong content type, duplicate receipt, disallowed event, unknown phone (200 with `matched: 0`).
- Redactor patterns; an erased customer stays suppressed; reveal without the capability is 403 and with it writes an audit row; the HTTP client never logs the key and always verifies TLS.

### Failure simulations

Each of these runs the real stages against the fake transport and asserts the ledger afterwards:

| Simulation | Expected |
| --- | --- |
| WA.cr unavailable (connection refused) | Attempt backs off; no journey lost; System Status shows the error |
| Timeout after the request was written | Attempt `unknown`; reconciled to `sent` when the conversation shows the message, to `failed` with a fresh attempt when it does not |
| 429 with `Retry-After` | Budget paused; batch stopped; sends resume after the pause with new attempts |
| Duplicate hooks and duplicate events | One event row, one journey, one attempt |
| Order completed after the journey was scheduled | No send; journey `PENDING_PAYMENT` then `RECOVERED` |
| Customer anonymised mid-journey | Journey cancelled; tokens revoked; suppression intact |
| Expired token | Generic page; no restore |
| Cron overlap | Second runner finds the lock and exits; one send |
| Claim lease expires under a slow runner | The slow runner's writes lose; no double send |
| Salts rotated | Stored key reported as undecryptable; sends stop with a configuration error; no crash |

## Accessibility runs

`npm run a11y` starts from a logged-in wp-env session and runs pa11y-ci with the WCAG2AAA standard and both the axe and htmlcs runners over: Overview, Journeys, a journey detail, Workflows, Integrations, each Settings tab (General, Recovery, WA.cr, Privacy, Advanced), System Status, and the public opt-out confirmation and invalid-link pages. Any AA failure fails the build; AAA findings are reported and reviewed. A keyboard-only manual pass per screen is recorded in [`accessibility.md`](accessibility.md).

## Verification walkthrough

The end-to-end check before a release, on wp-env with a staging WA.cr workspace:

1. Start wp-env, run every suite, and confirm they are green.
2. In Settings › WA.cr, paste a staging test key with `messages:send`, `templates:read`, `channels:read`, `messages:read` and `contacts:read`. Test connection shows the workspace and scopes; choose a sender and an approved template; map variables. The deeplink `…&tab=wacr&section=connection&field=api_key` lands focused on the key field. Tab through every tab with the keyboard only.
3. As a guest on the storefront: add a product, open checkout, enter a phone, tick consent, leave. Lower the inactivity threshold to one minute and run the tick from System Status. The journey goes `SCHEDULED` then `MESSAGE_SENT`; the handset receives the template; the link restores the cart and 302s to checkout; completing the order flips the journey to `RECOVERED` and no second touch fires after 24 hours (move `next_action_at` forward to check).
4. Hand-off path: configure a staging Auto Flow with a webhook-received trigger and signature, switch the default workflow to the hand-off variant, and confirm the flow receives `recovery.journey_eligible` with `hook_*` variables and sends its own template.
5. Negative paths: revoke the key (authentication error on System Status with a deeplink to the key field); block the network (attempt `unknown`, then reconciled); 31 hits on a recovery URL (429); an expired token (generic page); opt-out GET (no change) then POST (journeys `OPTED_OUT`); the privacy eraser anonymises and the suppression persists.
6. Plugin Check and PHPCS clean. Deactivate WooCommerce: the plugin loads, Integrations shows WooCommerce as not installed, the tick runs on WP-Cron.
7. Plan gate: with no developer API key, or one below Scale, Workflows shows only the hand-off workflow and the "included with WA.cr Scale and above" card; a Scale-plan staging key unlocks the editor and direct sends within one page load.
