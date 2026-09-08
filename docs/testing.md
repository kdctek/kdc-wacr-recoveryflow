# Testing

RecoveryFlow by WA.cr moves money-adjacent state (a merchant's messaging wallet, a customer's cart) in the background, under overlapping schedulers, against a remote API with no idempotency key. The test strategy is built around that: every duplicate-trigger scenario in the idempotency matrix, every row of the threat model and every failure mode of the WA.cr connection has a test that exercises it.

## Suites

| Suite | Directory | Runner | Needs WordPress | Speed | State |
| --- | --- | --- | --- | --- | --- |
| Smoke | `tests/smoke.php` | Plain PHP against the fakes in `tests/wp-stubs.php` | No | Seconds | **Built. This is the suite.** |
| Unit | `tests/unit/` | PHPUnit 9.6 + Yoast polyfills + Brain\Monkey | No | Sub-second | **Built. Run by CI.** |
| Security | `tests/security/` | Same runner; no WordPress needed for what it asserts | No | Sub-second | **Built. Run by CI.** |
| Failure | `tests/failure/` | Same runner; the refusal paths | No | Sub-second | **Built. Run by CI.** |
| Accessibility | `.pa11yci.json`, `tests/a11y/` | pa11y-ci at WCAG 2.2 AA over 24 screens, then AAA as a report; a Tab-key pass over the same screens | Yes | Minutes | **Built. 24 screens, run by CI.** |

**There is no integration suite, and that is a decision rather than a gap.** An
integration suite here would boot a real WordPress with WooCommerce and assert
against it, which is exactly what `tests/smoke.php` already does, on two PHP
versions, on every push. A second one built on the WordPress core test library
would restate it at the cost of a much heavier CI setup. If that ever changes,
add the suite to `phpunit.xml.dist` **and** write a test in it in the same
commit: the smoke run fails on a declared suite with nothing in it.

Most of what is asserted about the PHP is still in the smoke suite: `php tests/smoke.php`, run by CI on PHP 8.0 and 8.3. The three PHPUnit suites hold what is worth stating as a rule rather than as a wiring check -- pure decisions with no WordPress behind them, where a failure names the rule that broke.

**Run them with `bin/phpunit.sh`, never `phpunit` directly.** PHPUnit 9 exits 0 over an empty suite and cannot be told not to; the attribute for it does not exist in this version and setting one is ignored without complaint, and the version that does have the option needs PHP 8.1 while this plugin supports 8.0. The wrapper refuses instead. `composer test:unit`, `test:security`, `test:failure` and `test:all` all go through it, and so does CI.

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
composer test:unit              # one suite, through the guard

npx @wordpress/env start        # WordPress + WooCommerce in Docker; plugin mapped from the repo root
composer test:all               # unit, security and failure. None of them need WordPress
npm run a11y                    # WCAG 2.2 AA over all 24 screens, then AAA as a report
npm run a11y:keyboard           # walk the same screens with the Tab key
npx @wordpress/env stop
```

CI runs smoke on PHP 8.0 and 8.3, the static checks and the translation checks on one cell each, and the accessibility run and keyboard pass on one cell through wp-env. There is no PHPUnit job, for the reason below.

## Fixture approach

- **Cart and order fixtures** are plain PHP arrays in `tests/fixtures/` describing WooCommerce carts (lines, coupons, totals) and orders (status sequences). Integration tests build real WooCommerce carts and orders from them; unit tests feed them straight into the event normaliser.
- **WA.cr responses** are JSON fixtures for `/v1/me`, `/v1/channels`, `/v1/templates`, `/v1/messages`, `/v1/contacts` and `/v1/conversations/{digits}/messages`, including the error envelope for each error code and a `429` with `Retry-After`.
- **Fake transport.** `WAcr\Client` takes a `Transport`; tests inject a fake that returns scripted responses, records every request (method, URL, headers, body) and can simulate a timeout after the request was written, a pre-send network failure, or a slow response that exceeds the time budget. No test ever reaches the network.
- **Clock.** `Core\Clock` is injectable so tests move time forward to make journeys due, tokens expire and quiet hours start, without sleeping.
- **Phone table.** A CSV of raw inputs, country codes and expected E.164 results (or `invalid`) drives the normaliser test.
- **Eligibility table.** A matrix of customer, consent, mode and rule inputs against the expected reason code drives the eligibility test.

## What each suite covers

> **Three of the four PHPUnit suites are now built; the fourth was removed
> rather than left declared.** `tests/unit/`, `tests/security/` and
> `tests/failure/` hold real tests and run in CI. `tests/integration/` is gone,
> because `tests/smoke.php` already is that suite.
>
> The history is worth keeping, because it is the reason the plumbing looks the
> way it does. CI once ran a job called **Unit tests** over four empty
> directories: `phpunit --testsuite unit` reports "No tests executed!" and exits
> 0, so every release since the first carried a green tick for coverage that did
> not exist. The job was removed rather than left to be misread, and
> `tests/smoke.php` asserts that CI runs PHPUnit **if and only if** a PHPUnit
> test exists.
>
> That pairing is why the job could come back the moment tests were written --
> and why it comes back behind `bin/phpunit.sh` rather than as a bare `phpunit`
> call. The wrapper is the part that cannot lie: it treats "No tests executed!"
> as a failure. A job calling `phpunit` directly would be the original defect
> restored, and the smoke run now fails if one appears.
>
> **The list below is still a to-do in the parts that name things no test
> touches yet**, and that distinction has already cost something: this section
> listed "the workflow `Engine` (sequencing, stop reasons, idempotent advance)"
> from the first release, and until the email channel shipped the Engine had no
> test of any kind. That is exactly how a workflow step could name one channel
> and send on another for four slices with every gate green -- a document said it
> was covered, and nothing can check a promise. What is written and running is
> named under each heading; everything else is intention.

### Unit

**Written and running:** the two link rules on `Attempt` (revocation closes a
basket restore and leaves an unsubscribe open; expiry closes both);
`Journey_State` asserted as properties rather than as a copy of the table (a
terminal state that records a decision about a person never reopens, `FAILED`
reopens only into `SCHEDULED`, and never straight to `MESSAGE_SENT`);
`Token_Service` (alphabet, length, hash-only storage, prefixes do not match);
`User_Agent::is_link_preview()` including the honest limit that a corporate
scanner cannot be recognised; `Contact_Snapshot`'s cleaning and country rules.

**Still intention:** event normalisation (WooCommerce cart fixture to `Recovery_Event`); the eligibility rules and reason table; the identity-resolver matrix (user, phone, email fallback, external id, create, and the conflict cases); `Journey_State` allowed and forbidden transitions; `Token_Service` (alphabet and length, hash-only storage, expiry, per-journey revocation); the phone normaliser table; `Template_Renderer` (escaping, unknown variables, WhatsApp parameter rules); the workflow `Engine` (sequencing, stop reasons, idempotent advance); `WAcr\Client` with the fake transport (envelope decoding, error categories, 429 pause, timeout means unknown, no automatic retry on `POST`, exact HMAC signature bytes on a hook push); the Redactor; `Mask`; the `Rate_Limiter`.

### Integration

**There is no integration suite.** Everything this heading used to list --
schema install and upgrade, repositories, REST controllers through
`WP_REST_Request`, the WooCommerce adapter end to end, the processor tick, the
privacy exporter and eraser, uninstall, settings deeplink routing -- is asserted
by `tests/smoke.php` against a real container, on two PHP versions, on every
push. Declaring a second suite for it would restate the same ground and would
have to be kept in step with it.

### Security matrix

**Written and running:** `Privacy\Redactor`, in both directions -- every kind of
secret it is supposed to remove does not survive, and the sentence around it
does. A redactor that erased the message would be perfectly safe and useless.

**Still intention:** the rest of this list, which mirrors the threat model in [`security.md`](security.md#threat-model):

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

**Written and running:** the email compliance gate, tested as refusals rather
than as a happy path with a negation. Each blocker is asserted ALONE on settings
that are otherwise complete -- testing them together would pass just as well
against a gate that returned one reason for everything, and a merchant clearing
that gate would be sent round in a circle. The thirty-day boundary is asserted
at exactly thirty and at twenty-nine, because an off-by-one there is either a
refusal nobody can explain or a promise the site cannot keep.

**Still intention:** the transport simulations below, each of which would run the
real stages against the fake transport and assert the ledger afterwards:

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

`npm run a11y` seeds the site, logs in, and runs pa11y-ci over 24 screens: Overview; the queue unfiltered, filtered and with a search matching nothing; one recovery; the workflows list; the editor loaded and empty; Integrations; all seven Settings tabs; a deeplinked field; System Status; the setup screen; and the five public pages -- the opt-out page, the page after the unsubscribe button has been pressed, a link that does not resolve, a product page carrying the add-to-cart capture field, and the basket page with the save-this-basket form on a basket the run filled by pressing the real add-to-cart button.

**The gate is WCAG 2.2 AA and it fails the build.** The same command then runs the screens again at AAA and prints the findings without failing on them -- almost all of them are core WordPress's own colours on core's own components. `npm run a11y:keyboard` walks every screen with the Tab key.

`docs/accessibility.md` carries the detail: the two rules that are ignored and the accessibility-tree evidence for each, what the AAA pass reports, and the recorded keyboard pass. Do not quote either number without re-reading that file -- `tests/smoke.php` fails if the ignore list and that document stop agreeing.

## Verification walkthrough

The end-to-end check before a release, on wp-env with a staging WA.cr workspace:

1. Start wp-env, run every suite, and confirm they are green.
2. In Settings › WA.cr, paste a staging test key with `messages:send`, `templates:read`, `channels:read`, `messages:read` and `contacts:read`. Test connection shows the workspace and scopes; choose a sender and an approved template; map variables. The deeplink `…&tab=wacr&section=connection&field=api_key` lands focused on the key field. Tab through every tab with the keyboard only.
3. As a guest on the storefront: add a product, open checkout, enter a phone, tick consent, leave. Lower the inactivity threshold to one minute and run the tick from System Status. The journey goes `SCHEDULED` then `MESSAGE_SENT`; the handset receives the template; the link restores the cart and 302s to checkout; completing the order flips the journey to `RECOVERED` and no second touch fires after 24 hours (move `next_action_at` forward to check).
4. Hand-off path: configure a staging Auto Flow with a webhook-received trigger and signature, switch the default workflow to the hand-off variant, and confirm the flow receives `recovery.journey_eligible` with `hook_*` variables and sends its own template.
5. Negative paths: revoke the key (authentication error on System Status with a deeplink to the key field); block the network (attempt `unknown`, then reconciled); 31 hits on a recovery URL (429); an expired token (generic page); opt-out GET (no change) then POST (journeys `OPTED_OUT`); the privacy eraser anonymises and the suppression persists.
6. Plugin Check and PHPCS clean. Deactivate WooCommerce: the plugin loads, Integrations shows WooCommerce as not installed, the tick runs on WP-Cron.
7. Plan gate: with no developer API key, or one below Scale, Workflows shows only the hand-off workflow and the "included with WA.cr Scale and above" card; a Scale-plan staging key unlocks the editor and direct sends within one page load.
