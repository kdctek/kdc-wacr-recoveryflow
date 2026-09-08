# Tests

Two harnesses, for two different jobs.

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

## `composer test:*` — PHPUnit

| Suite | What it covers | Needs |
| --- | --- | --- |
| `test:unit` | Pure classes with Brain Monkey | `composer install` |
| `test:integration` | Repositories, REST routes, the WooCommerce adapter | WordPress test suite (`npm run env:start`) |
| `test:security` | Authorisation, CSRF, injection, token guessing, replay | WordPress test suite |
| `test:failure` | API unavailable, timeouts, rate limits, duplicate events | WordPress test suite |

## `npm run a11y`

`pa11y-ci` against every admin screen at WCAG 2.2 AAA. Add the URLs to
`.pa11yci.json` as screens land.
