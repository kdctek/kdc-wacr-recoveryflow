# Tests

Three harnesses, for three different jobs. **One of them is built.**

## `php tests/smoke.php` — no dependencies, runs anywhere

Boots the real classes against a small WordPress stand-in (`tests/wp-stubs.php`)
and checks the things that are easy to get subtly wrong and that clicking around
wp-admin would never catch: autoloading, the encryption round trip and its
failure mode, keyed hashing, the schema's dbDelta dialect and its indexes, the
recovery-token shape, capability grants and the feature gate.

It needs nothing but PHP, so it runs before `composer install` and on any host.

**Sanity-check the harness before trusting a green run.** Add a deliberately
failing assertion and confirm it is reported; a runner that silently passes
everything looks exactly like a healthy one.

## `composer test:*` — PHPUnit — **NOT BUILT. All four suites are empty.**

Every directory below holds a `.gitkeep` and nothing else, so `phpunit
--testsuite unit` reports "No tests executed!" and exits 0. **A passing
`composer test:unit` is not a signal of anything.** CI no longer runs it: a job
named "Unit tests" ticked green for five slices over a suite with no tests in
it, which is worse than having no job, because a green tick is read as an
answer.

`tests/smoke.php` asserts that the two stay in step in **both** directions — the
first PHPUnit test you write fails the smoke suite until the CI job is restored,
and restoring the job over empty suites fails it too. So the table below is a
plan, and the plan cannot silently become a claim.

| Suite | What it should cover | Needs | State |
| --- | --- | --- | --- |
| `test:unit` | Pure classes with Brain Monkey | `composer install` | Empty |
| `test:integration` | Repositories, REST routes, the WooCommerce adapter | WordPress test suite (`npm run env:start`) | Empty |
| `test:security` | Authorisation, CSRF, injection, token guessing, replay | WordPress test suite | Empty |
| `test:failure` | API unavailable, timeouts, rate limits, duplicate events | WordPress test suite | Empty |

## `npm run a11y` — **NOT BUILT. `.pa11yci.json` lists no URLs.**

`pa11y-ci` against every admin screen at WCAG 2.2 AAA. Every screen has now
shipped and not one is listed, so the command was checking nothing while
passing. It now runs through `bin/a11y.sh`, which **refuses** on an empty URL
list rather than reporting success. Add the URLs — and the scripted login
action described in `docs/accessibility.md` — to `.pa11yci.json` to build it.
