# RecoveryFlow by WA.cr

Turn lost conversions into conversations that convert. RecoveryFlow detects abandoned journeys in WordPress (carts, checkouts, forms, tickets, bookings) and recovers them through WA.cr on WhatsApp.

**RecoveryFlow detects → WA.cr communicates → customer converts.**

This README is for developers. Merchant-facing copy is in [`readme.txt`](readme.txt); the design is in [`docs/`](docs/).

## What it is

RecoveryFlow is a conversion-recovery engine for WordPress, not a WooCommerce plugin. Source integrations (WooCommerce first) detect stalled journeys and push them into the core as Recovery Events. The core resolves who the person is, decides whether they may be contacted, runs a Recovery Journey under a Workflow, dispatches WhatsApp touches through WA.cr, and stops the moment the original conversion completes.

Everything works with a WA.cr Auto Flow on any workspace that has Auto Flows. WordPress-authored multi-touch workflows with direct sends use the WA.cr developer API and are included with WA.cr Scale and above.

## Architecture in five lines

1. **Integrations push, the core pulls.** An adapter upserts one event row per open journey from server-side hooks; nothing is processed inline. Evaluation, sending and polling run in a background tick (Action Scheduler if present, else WP-Cron) under row locks and time budgets.
2. **Identified is not eligible.** Identity resolution is deterministic (user → phone → email fallback → external ID → create). Eligibility is a separate decision with a recorded reason, and explicit consent is the default.
3. **Journeys are a state machine with optimistic transitions.** Every change is one conditional `UPDATE … WHERE id = … AND status = …`, so a conversion landing mid-batch always wins.
4. **Sends are idempotent by ledger.** Every send reserves an attempt row under a UNIQUE key before the HTTP call; unknown outcomes are reconciled from the conversation, never blindly re-sent. One recovery token per attempt, stored only as a hash.
5. **Two dispatch actions, one client.** `wacr.start_flow` hands the journey to a WA.cr Auto Flow; `wacr.send_template` sends plugin-timed templates over the WA.cr developer API. Both go through one `WAcr\Client` with a local rate budget.

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | 8.0 or later |
| WordPress | 6.5 or later |
| WooCommerce (only for the WooCommerce integration) | 8.0 or later |
| Action Scheduler | Optional. Used when present; WP-Cron otherwise |
| WA.cr workspace | Auto Flows for hand-off; Scale and above for direct sends and polling |

Single-site and multisite-safe (not network-aware). No Composer at runtime and no JavaScript build step.

## Local development

```bash
composer install              # dev dependencies only: WPCS, PHPCompatibilityWP, PHPUnit, PHPStan
npx @wordpress/env start      # WordPress + WooCommerce, plugin mapped from the repo root as kdc-wacr-recoveryflow
composer lint                 # php -l + PHPCS (WordPress, WordPress-Extra, WordPress.Security, PHPCompatibilityWP 8.0-)
composer analyse              # PHPStan level 5 with the WordPress extension
composer test:unit            # PHPUnit + Brain\Monkey, no WordPress, sub-second
composer test:integration     # WordPress core test suite inside wp-env, with WooCommerce
npm run a11y                  # pa11y-ci (WCAG2AAA standard, axe + htmlcs) over every admin screen
```

To work against a staging WA.cr workspace, choose **Staging** in Settings › WA.cr; the client then talks to `api.wacart.dev` instead of `api.wa.cr`. Define `KDC_WACR_RECOVERYFLOW_UNLOCK_ALL` in `wp-config.php` (wp-env only) to unlock plan-gated features for tests.

See [`docs/testing.md`](docs/testing.md) for the suites and fixtures.

## Repository layout

```text
kdc-wacr-recoveryflow/                (repo root == plugin root == WordPress.org slug)
├── kdc-wacr-recoveryflow.php         header, constants, requirements gate, boot on plugins_loaded
├── uninstall.php                     honours delete_data_on_uninstall
├── readme.txt · README.md · CHANGELOG.md · LICENSE
├── composer.json · phpcs.xml.dist · phpunit.xml.dist · phpstan.neon.dist · .distignore · .gitignore · .editorconfig
├── .wp-env.json                      WordPress + WooCommerce for integration tests and manual verification
├── .github/workflows/ci.yml          phpcs + phpstan + unit; integration + a11y via wp-env
├── src/
│   ├── Core/        Plugin (container), Autoloader, Activator, Deactivator, Upgrader, Hooks, Clock, Feature_Gate
│   ├── Recovery/    Recovery_Event, Event_Draft, Event_Ingest, Recovery_Journey, Journey_State, repositories,
│   │                Attempt, Eligibility_Evaluator, Rule_Set, Recovery_Url, Recovery_Controller, Conversion_Tracker
│   ├── Customer/    Customer, Customer_Repository, Identity_Resolver, Phone_Normalizer, Consent_Store, Mask
│   ├── Workflow/    Workflow, repositories (+ immutable versions), Workflow_Definition, Engine, Send_Gate,
│   │                Step_Registry, Steps/, Conditions/, Actions/{Send_Template,Start_Flow}, Variable_Context,
│   │                Template_Renderer, Message_Composer
│   ├── Integration/ Recovery_Source_Interface, Pollable_Source_Interface, Source_Registry, Abstract_Source,
│   │                WooCommerce/{Source, Cart_Tracker, Checkout_Capture, Order_Observer, Cart_Restorer,
│   │                Consent_Field, Hpos}, Custom/ (documented example)
│   ├── WAcr/        Client, Credentials, Transport, Result, Error, Rate_Budget, Cache, Flow_Hook, Dto/
│   ├── Jobs/        Scheduler_Interface, Action_Scheduler_Driver, Wp_Cron_Driver, Scheduler_Factory,
│   │                Stage_Runner, Tick, Lock, Time_Budget, Stages/{Evaluate, Dispatch, Poll, Expire, Retention}
│   ├── REST/        Abstract_Controller, Journeys, Status, Integrations, Settings, Templates, Webhook, Args, Dto/
│   ├── Admin/       Menu, Assets, Notices, Pages/{Overview, Journeys, Journey_Detail, Workflows, Integrations,
│   │                System_Status}, Settings/{Page, Router, Tab, Section, Card, Field_Renderer, Schema}
│   ├── Database/    Schema (dbDelta + versioned migrations), Table_Names, Repository, Lock_Repository, Receipt_Repository
│   ├── Security/    Capabilities, Nonce, Rate_Limiter, Crypto, Hash_Key, Token_Service, Webhook_Verifier, Redactor, Audit
│   ├── Privacy/     Exporter, Eraser, Anonymizer, Retention_Policy
│   └── Support/     Logger, Options, Money, Str, Json, Uuid, User_Agent
├── assets/          css/admin.css, js/admin.js, js/settings.js (vanilla, no build), images/
├── languages/       kdc-wacr-recoveryflow.pot
├── templates/       recovery-invalid.php, opt-out-confirm.php, opt-out-done.php
├── tests/           bootstrap.php, unit/, integration/, security/, failure/, a11y/, fixtures/
└── docs/            architecture, integrations, developer-api, security, privacy, wa-cr-integration, testing, accessibility, internationalization
```

## Conventions

- Namespace `WAcr\RecoveryFlow`, PSR-4 under `src/`, WordPress-style `Snake_Case` class names, no Composer autoloader at runtime.
- Hooks `recoveryflow_*`, REST namespace `kdc/v1`, routes under `wacr/recoveryflow/`, options, capabilities and tables prefixed `recoveryflow_`, constants `KDC_WACR_RECOVERYFLOW_*`, text domain `kdc-wacr-recoveryflow`.
- WordPress Coding Standards 3.x plus PHPCompatibilityWP; core admin UI only; WCAG 2.2 AA minimum, AAA where achievable; en-IN spelling in copy; the brand is written **WA.cr**.
- All datetimes are UTC and bound as strings; every processor query hits an index and carries a `LIMIT`; every state change is a conditional `UPDATE`.
- [`CHANGELOG.md`](CHANGELOG.md) follows Keep a Changelog; each entry opens with a bolded plain-language statement of what changed for the person affected.

## Documentation

| Document | What it covers |
| --- | --- |
| [`docs/architecture.md`](docs/architecture.md) | Domain model, journey state machine, identity resolution, background processing, idempotency matrix, schema overview |
| [`docs/integrations.md`](docs/integrations.md) | The Recovery Source interface, the seven questions every adapter answers, WooCommerce, planned adapters |
| [`docs/developer-api.md`](docs/developer-api.md) | Hooks and filters, REST routes, a minimal custom source, workflow JSON, variable allow-list |
| [`docs/security.md`](docs/security.md) | Capabilities, tokens, opt-out, webhook receiver, secrets, redaction, threat model |
| [`docs/privacy.md`](docs/privacy.md) | Data inventory, consent model, opt-out, exporter, eraser, retention, uninstall, what is sent to WA.cr |
| [`docs/wa-cr-integration.md`](docs/wa-cr-integration.md) | Connection and scopes, dispatch actions, template mapping, Auto Flow hand-off, polling, rate limits, errors |
| [`docs/testing.md`](docs/testing.md) | Test suites, how to run them, fixtures, failure simulations, security matrix, accessibility runs |
| [`docs/accessibility.md`](docs/accessibility.md) | The WCAG 2.2 checklist, settings deeplinks, how pa11y-ci is run |
| [`docs/internationalization.md`](docs/internationalization.md) | The translation rules every string follows, the three checks that enforce them, why nothing is translated before `init` |

## Status

Version 0.1.0 is an initial development release. The roadmap is built in vertical slices: scaffold; domain model and WA.cr client; WooCommerce integration and background processing; admin, REST, privacy and documentation; then a form-based workflow editor, further integrations (Gravity Forms first) and WP-CLI. Where a document describes something not yet built, it says so.

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
