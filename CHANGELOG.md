# Changelog

All notable changes to RecoveryFlow by WA.cr will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Each entry opens with a plain-language statement of what changed for the person
affected, followed by the detail.

## [Unreleased]

### Added

- **An abandoned WooCommerce cart now becomes a recovery journey on its own.** The adapter watches the cart, picks up a shopper's phone number and email as they fill in checkout on both the classic and the block checkout, records whether they agreed to be messaged, and writes one row per shopping session rather than one per change. Everything it does is wrapped so that a failure in recovery tracking can never break somebody's checkout.
- **A shopper who logs in halfway through keeps one cart, not two.** WooCommerce gives a guest a new session identity when they sign in, and without following that the same basket would be tracked twice and the customer would receive two reminders for it.
- **Recovery runs in the background, in batches, and survives being run twice at once.** Five stages -- find, send, check for replies, expire, tidy up -- on Action Scheduler where WooCommerce provides it and on WP-Cron where it does not. Neither scheduler promises a job never overlaps itself, so every stage takes a database lock first and every write states the state it expects to find.
- **A message is reserved before it is sent, so a retry cannot send it twice.** The messaging API has no way to recognise a repeated request, which means an ordinary retry would deliver the same reminder again and bill the merchant for it again. A request that failed before it opened a connection is retried; one that timed out after the request was written is recorded as unknown and settled by reading the conversation back, never by sending again.
- **Merchants describe recovery as a sequence of steps.** Wait, check a condition, send -- validated against a strict schema with no code execution anywhere in it, and pinned per journey, so editing a workflow never changes what a customer already part-way through it will receive. Two sequences ship: one that sends from WordPress, and one that hands the journey to a WA.cr Auto Flow.
- **Each step chooses its own channel.** An action step carries `whatsapp` or `email`, so "WhatsApp after an hour, email the next day" is something the merchant builds rather than a fallback order the plugin decides for them.
- **Recovery links restore a cart without destroying the one the shopper already has.** Items are merged, anything out of stock or no longer purchasable is skipped, quantity limits are respected, and following somebody else's link is refused outright. Links are unguessable, stored only as a hash, and expire.
- **Opting out takes a deliberate action rather than a click.** WhatsApp fetches every link in a message to build its preview, so an opt-out that worked on a plain visit would unsubscribe every recipient the moment the message was delivered. The link shows a confirmation page and nothing changes until the shopper confirms it. Clicks from preview fetchers are not counted either.
- **Messaging stops the moment an order is placed, and stays stopped.** Placing an order pauses the journey rather than completing it; only payment completes it. A failed payment hands the journey back exactly once, because somebody whose card was declined twice does not want a third reminder. Every order hook claims a receipt first, so a gateway that calls back twice moves the journey once.
- **The plugin activates cleanly on any WordPress 6.5 site running PHP 8.0, with or without WooCommerce.** Main plugin file with header, `KDC_WACR_RECOVERYFLOW_*` constants, a requirements gate that explains what is missing instead of failing, and boot on `plugins_loaded`. The gate hands back machine-readable rows and builds its sentences only when the notice is rendered -- asking for a translation on `plugins_loaded` is too early for the text domain, and WordPress 6.7 and above warns about the early load and returns the untranslated string anyway.
- **Classes load without Composer at runtime.** PSR-4 autoloader for the `WAcr\RecoveryFlow` namespace and a small service container.
- **Activation creates the plugin's ten database tables and can upgrade them in later versions.** Activator, Upgrader and a versioned dbDelta schema; lock rows are seeded at install so background stages can take row locks from the first run.
- **Access is controlled by dedicated capabilities rather than `manage_options` alone.** Six `recoveryflow_*` capabilities installed on activation, re-applied whenever the capability version changes, and re-granted whenever any other plugin activates -- so installing RecoveryFlow before WooCommerce does not leave shop managers with no access.
- **A per-site random hash key is minted on activation** so phone and email hashes are keyed to the site and cannot be looked up across installations.
- **Uninstall cleans up after itself.** Credentials, the hash key, capabilities and scheduled actions are always removed; tables and options are removed only when "Delete all data on uninstall" is enabled.
- **The plugin is translation-ready.** Text domain `kdc-wacr-recoveryflow` and a `.pot` template in `languages/`.
- **Every string a person can read is translatable, and it stays that way as the plugin grows.** Short status labels carry a `_x()` context, because "New", "Replied" and "Failed" are one word each and cannot be translated correctly by a language that inflects for gender or number without knowing they describe a recovery journey. The shortened customer name is a format string rather than a concatenation, so a translator controls name order and whether an initial takes a full stop. The WA.cr hostname was lifted out of the environment labels into a placeholder, so no one is invited to translate a domain name. `docs/internationalization.md` states the rules; `composer i18n:audit` and `composer i18n:check` enforce them in CI alongside PHPCS.
- **Contributors get the full toolchain on `composer install`.** Composer scripts for linting (WordPress Coding Standards with PHPCompatibilityWP), static analysis (PHPStan), unit and integration test suites (PHPUnit), pa11y-ci for accessibility, a `.wp-env.json` for a local WordPress with WooCommerce, and a CI workflow that runs them.
- **The documentation set exists from day one.** `readme.txt` and the `docs/` folder: architecture, integrations, developer API, security, privacy, WA.cr integration, testing and accessibility.
- **`readme.txt` states plainly that an active WA.cr account is required.** The requirement now appears in the short description, in a dedicated section at the top of the description, in the External services disclosure, as the first installation step and as the first two FAQ entries, together with what does and does not work without an account. The short description was trimmed to the 150-character limit the plugin directory enforces, and `docs/` links point at the repository because `docs/` is excluded from the distributed build.
- **A recovery journey can only make moves that make sense.** The state machine is declared in one place, including the awaiting-payment state that stops messaging the moment an order is placed and hands the journey back exactly once if the payment then fails.
- **A phone number typed at checkout is turned into a real international number, or refused.** The normaliser uses the billing country as its hint, handles trunk prefixes and international prefixes, and refuses rather than guesses when it cannot tell -- a national number promoted to the wrong country would deliver a customer's cart reminder to a stranger.
- **Recovery links are unguessable and stored only as hashes.** 32 random bytes, checked against a pattern before any database lookup, so a scanner costs a regular expression rather than a query.
- **Talking to WA.cr goes through one client.** Connection test, sender and template lookups with caching, template sends, conversation reads, contact lookup, and the Auto Flow hand-off, with the API key encrypted at rest and never rendered back.
- **A failed send is classified accurately enough to retry safely.** A connection that never opened is retried; a request that timed out after it was written is recorded as unknown and reconciled before anything is sent again, because the messaging API has no idempotency key and a blind retry would message the customer twice and bill the merchant twice.

### Security

- **Nothing personal reaches a log.** A redactor drops known fields and masks phone numbers, email addresses, credentials and recovery links inside free text, including error strings that arrive from elsewhere.

[Unreleased]: https://github.com/kdctek/kdc-wacr-recoveryflow/commits/main
