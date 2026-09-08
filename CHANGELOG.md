# Changelog

All notable changes to RecoveryFlow by WA.cr will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Each entry opens with a plain-language statement of what changed for the person
affected, followed by the detail.

## [Unreleased]

### Added

- **The plugin activates cleanly on any WordPress 6.5 site running PHP 8.0, with or without WooCommerce.** Main plugin file with header, `KDC_WACR_RECOVERYFLOW_*` constants, a requirements gate that explains what is missing instead of failing, and boot on `plugins_loaded`.
- **Classes load without Composer at runtime.** PSR-4 autoloader for the `WAcr\RecoveryFlow` namespace and a small service container.
- **Activation creates the plugin's ten database tables and can upgrade them in later versions.** Activator, Upgrader and a versioned dbDelta schema; lock rows are seeded at install so background stages can take row locks from the first run.
- **Access is controlled by dedicated capabilities rather than `manage_options` alone.** Six `recoveryflow_*` capabilities installed on activation, re-applied whenever the capability version changes, and re-granted whenever any other plugin activates -- so installing RecoveryFlow before WooCommerce does not leave shop managers with no access.
- **A per-site random hash key is minted on activation** so phone and email hashes are keyed to the site and cannot be looked up across installations.
- **Uninstall cleans up after itself.** Credentials, the hash key, capabilities and scheduled actions are always removed; tables and options are removed only when "Delete all data on uninstall" is enabled.
- **The plugin is translation-ready.** Text domain `kdc-wacr-recoveryflow` and a `.pot` template in `languages/`.
- **Contributors get the full toolchain on `composer install`.** Composer scripts for linting (WordPress Coding Standards with PHPCompatibilityWP), static analysis (PHPStan), unit and integration test suites (PHPUnit), pa11y-ci for accessibility, a `.wp-env.json` for a local WordPress with WooCommerce, and a CI workflow that runs them.
- **The documentation set exists from day one.** `readme.txt` and the `docs/` folder: architecture, integrations, developer API, security, privacy, WA.cr integration, testing and accessibility.
- **A recovery journey can only make moves that make sense.** The state machine is declared in one place, including the awaiting-payment state that stops messaging the moment an order is placed and hands the journey back exactly once if the payment then fails.
- **A phone number typed at checkout is turned into a real international number, or refused.** The normaliser uses the billing country as its hint, handles trunk prefixes and international prefixes, and refuses rather than guesses when it cannot tell -- a national number promoted to the wrong country would deliver a customer's cart reminder to a stranger.
- **Recovery links are unguessable and stored only as hashes.** 32 random bytes, checked against a pattern before any database lookup, so a scanner costs a regular expression rather than a query.
- **Talking to WA.cr goes through one client.** Connection test, sender and template lookups with caching, template sends, conversation reads, contact lookup, and the Auto Flow hand-off, with the API key encrypted at rest and never rendered back.
- **A failed send is classified accurately enough to retry safely.** A connection that never opened is retried; a request that timed out after it was written is recorded as unknown and reconciled before anything is sent again, because the messaging API has no idempotency key and a blind retry would message the customer twice and bill the merchant twice.

### Security

- **Nothing personal reaches a log.** A redactor drops known fields and masks phone numbers, email addresses, credentials and recovery links inside free text, including error strings that arrive from elsewhere.

[Unreleased]: https://github.com/kdctek/kdc-wacr-recoveryflow/commits/main
