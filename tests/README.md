# Tests

Three harnesses, for three different jobs. **All three are built.**

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

## `composer test:*` — PHPUnit — run it through `bin/phpunit.sh`

Three suites, all of which run without WordPress and in well under a second.
They hold what is worth stating as a rule rather than as a wiring check: pure
decisions, where a failure names the rule that broke instead of the plumbing
that carried it.

| Suite | What it holds | State |
| --- | --- | --- |
| `test:unit` | The two link rules on `Attempt`; `Journey_State` as properties rather than a copy of its table; `Token_Service`; link-preview detection and its honest limit; `Contact_Snapshot` cleaning | Built |
| `test:security` | `Privacy\Redactor`, asserted in both directions — the secret does not survive and the sentence around it does | Built |
| `test:failure` | The email compliance gate, each blocker alone, and the thirty-day boundary at exactly thirty and twenty-nine | Built |

There is deliberately **no integration suite**. `tests/smoke.php` already boots
the real classes and runs on two PHP versions on every push, which is what one
here would be.

**Never run `phpunit` directly, and never put a bare `phpunit` call in CI.** It
exits 0 over an empty suite and PHPUnit 9 cannot be told otherwise — the
attribute does not exist in this version and setting one is ignored without
complaint; the version that has it needs PHP 8.1 and this plugin supports 8.0.
`bin/phpunit.sh` treats "No tests executed!" as a failure, and every
`composer test:*` script goes through it.

That guard exists because of what happened without it: a CI job named "Unit
tests" ticked green for five slices over four empty directories, because a green
tick is read as an answer. `tests/smoke.php` asserts the pair in **both**
directions — emptying the suites fails the build until the job goes, removing
the job fails it until the suites do, and declaring a suite in
`phpunit.xml.dist` with nothing in it fails it too.

## `npm run a11y` — **built: 24 screens, WCAG 2.2 AA**

`pa11y-ci` over every admin screen, every settings tab and the three public
pages. The gate is AA and fails the build; AAA runs afterwards and is printed
rather than enforced. `npm run a11y:keyboard` walks the same screens with the
Tab key. Both need a running wp-env and seed the site themselves.

It still **refuses** an empty URL list rather than reporting success, which is
what it did for five slices. `tests/smoke.php` fails if a screen this plugin
registers, or a settings tab it defines, is missing from `.pa11yci.json`.

`tests/a11y/` holds the seeder, the corrected axe runner and the keyboard walk;
`docs/accessibility.md` explains why each exists.
