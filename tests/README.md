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

## `npm run a11y` — **built: 22 screens, WCAG 2.2 AA**

`pa11y-ci` over every admin screen, every settings tab and the three public
pages. The gate is AA and fails the build; AAA runs afterwards and is printed
rather than enforced. `npm run a11y:keyboard` walks the same screens with the
Tab key. Both need a running wp-env and seed the site themselves.

It still **refuses** an empty URL list rather than reporting success, which is
what it did for five slices. `tests/smoke.php` fails if a screen this plugin
registers, or a settings tab it defines, is missing from `.pa11yci.json`.

`tests/a11y/` holds the seeder, the corrected axe runner and the keyboard walk;
`docs/accessibility.md` explains why each exists.
