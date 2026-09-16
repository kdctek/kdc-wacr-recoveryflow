# Changelog

All notable changes to RecoveryFlow by WA.cr will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Each entry opens with a plain-language statement of what changed for the person
affected, followed by the detail.

## [Unreleased]

## [0.1.4] - 2026-09-16

### Security

- **The early WooCommerce contact capture now verifies a RecoveryFlow nonce before accepting submitted contact details or consent.** The add-to-cart capture fields are part of WooCommerce's existing form, so an invalid or missing RecoveryFlow nonce is ignored by RecoveryFlow and does not interfere with the customer's normal add-to-cart action.

### Changed

- **The plugin version is now 0.1.4.** This release addresses the WordPress.org review finding concerning nonce verification in the WooCommerce early-capture path.

## [0.1.3] - 2026-09-13

### Changed

- **The listing has real icon artwork, including a vector.** `icon.svg` is what WordPress.org shows wherever it can, with the two PNGs as fallbacks. The placeholder icon is replaced by the plugin's own mark, and the directory reads `icon-128x128`, `icon-256x256` and `icon.svg` and nothing else -- a 512x512 master alone means a listing with no icon at all, and nothing anywhere says so. `dist:check` now asserts the icons and banners exist at the exact dimensions the directory expects, and that an `icon.svg`, if present, is a real square scalable one: it outranks both PNGs, so a broken SVG is worse than none. This directory is excluded from the zip, so no other gate can see any of it.

- **The plugin now points at its own product site.** `Plugin URI` declared `wa.cr/recoveryflow`, a page that was never built, so the one link WordPress shows on the plugins screen was a 404 -- which is also how WordPress.org's review found it. It points at recoveryflow.wa.cr, the product site, instead.

- **The three pages a shopper can be sent to now load their CSS as a stylesheet instead of carrying it inline.** The opt-out confirmation, the opt-out receipt and the page every dead recovery link renders are standalone documents -- no theme, no `wp_head()` -- so each one carried its own `<style>` block. WordPress.org's review flagged all three, and the guideline is right: the rules are now one enqueued file, registered and enqueued through `wp_enqueue_style()`, shared across the two pages of an opt-out and cached by the browser between them. Nothing about the pages looks different.

- **The opt-out form now carries a nonce as well as the token in its URL.** The token was, and remains, the real credential: 256 bits of randomness, sent to one person, for one message. The nonce is minted when the confirmation page renders and checked when the button is pressed. Crucially it cannot lock anybody out of unsubscribing -- the recipient has no WordPress session to carry a long-lived nonce, so a stale one re-renders the confirmation page with a fresh nonce rather than refusing. An unsubscribe that can answer "no" is not an unsubscribe.

### Added

- **The listing carries a Trademarks section, and it covers every mark the plugin names rather than only Meta's.** The plugin talks about WordPress, WooCommerce and Gravity Forms on nearly every screen and throughout the listing, and acknowledged none of them; the one attribution it did carry named WhatsApp and Meta. Each is now attributed to its owner -- WhatsApp, Facebook and Meta to Meta Platforms, Inc., WordPress to the WordPress Foundation, WooCommerce to Automattic Inc. and Gravity Forms to Rocketgenius Inc. -- with a statement that naming them implies no affiliation, endorsement or sponsorship. The clause is open-ended, so an integration added later is covered before anybody remembers to edit this.

- **The listing now says who makes this, at the top.** RecoveryFlow and WA.cr are both KDC products and "WA.cr" is our registered trade name in India, but the listing only said so in passing, a long way down, while the non-affiliation notice sat further down still. WordPress.org's review asked whether we were the rightful owner of the name; a reader of the listing could not have told either. Both statements are now one short note directly under the opening.

### Fixed

- **The plugin's own description contradicted its listing about needing a WA.cr account.** The header said an active WA.cr account was required, full stop; the readme said -- correctly -- that detection and email recovery need no account at all. Somebody reading the plugins screen would have concluded the plugin was useless to them without signing up for a service they may not need. The header now says which half needs what.

### Removed

- **`load_plugin_textdomain()` is gone.** WordPress has loaded a plugin's translations by itself since 4.6, and since 6.7 does it only at the moment a string is first asked for. Calling it explicitly only moved that work earlier into every request and risked running it before `init`. The `.pot` translators work from is unchanged.

## [0.1.2] - 2026-09-09

### Fixed

- **Detecting an abandoned basket no longer depends on your WA.cr plan, for any integration.** Any recovery source other than WooCommerce -- the Gravity Forms adapter that ships in this plugin, and any integration another plugin registers -- was switched off unless the site's WA.cr workspace was on the Scale plan. Not merely hidden: its hooks were never attached, so nothing from it was recorded at all. That was wrong on its own terms. These integrations watch tables that are already on the site and ask WA.cr for nothing, so a plan cannot be what decides whether they run, and WordPress.org's guidelines say so plainly: a plugin may require a paid service for what genuinely needs one, and may not withhold what its own code already does. Sending still needs WA.cr, which is the honest place for that line and where it stays.
- **The workflow screen let you build fewer kinds of workflow than the plugin would accept.** It went read-only whenever the workspace had no developer API -- no "Add workflow" button, and a notice saying workflows were read-only -- while the save path underneath happily accepted anything that did not send a template from WordPress. So a shop could not start the email-only workflow it was entitled to build, and was told the screen was closed when one kind of step was. The button is always there now, and the notice names the step that is actually refused.

## [0.1.1] - 2026-09-09

Eight fixes on top of the first release, and none of them changes what the plugin is for.
Three were found by running it against real Gravity Forms and a real WA.cr hand-off for the first time; two by running WordPress.org's own checker before submitting rather than after being rejected; and the rest by reading what the screens actually say against what the platform actually does.

### Added

- **RecoveryFlow now notices when WA.cr accepts a reminder and does not send it.** The Auto Flow hand-off answers "200 OK" to three situations in which it ran nothing at all: the workspace cannot run Auto Flows, the flow is paused or still a draft, or its trigger was never published. The plugin read the 200 and nothing else, so it marked every one of those reminders as sent and moved the recovery on. A shop on a plan that cannot run flows, or one whose flow was simply paused, would have shown a fortnight of reminders delivered and sent none of them. Those are now held and tried again, the reason WA.cr gave is kept, and the status screen says which of the three it was and what to do about it. Nothing is lost: the reminders wait, and the first one that gets through clears the warning.
- **A Gravity Forms form can now record consent, so recoveries from it can actually be sent.** Gravity Forms' own Consent field is read wherever a watched form carries one. A form with no consent question still records nothing.
- **The Integrations screen now says what an integration needs before it can message anyone.** Each card carries a line about consent on a site that requires it.

### Fixed

- **The plugin said the Auto Flow hand-off worked on any WA.cr plan. It does not.** Handing a journey to a flow needs a plan that can actually run one, which starts at WA.cr Growth.
- **Somebody who filled in a form is no longer written down when the form kept nothing to reach them by.**
- **The plugin told WordPress never to update it.** The `Update URI` header was removed.
- **One file could be requested directly in a browser.** The guard is now the first thing in the file.
- **A recovery now says which integration it came from by name.**

## [0.1.0] - 2026-09-09

First release. RecoveryFlow detects abandoned journeys across WordPress commerce, works out whether the person may lawfully be contacted, and recovers them -- over WhatsApp through WA.cr, which needs an active WA.cr account because this plugin holds no WhatsApp ability of its own, or by email from WordPress itself, which needs no WA.cr account at all.

### Added

- Recovery email sent by WordPress itself, through the site's own mail configuration.
- A contact detail can be asked for before the checkout.
- The checkout's phone number can be made compulsory.
- Recovery journeys, workflows, recovery links, opt-outs, privacy tools, REST API, WP-CLI and supported integrations.
- WooCommerce and Gravity Forms recovery integrations.
- Accessible, translation-ready WordPress admin screens and documentation.

### Security

- Personal data is removed or masked from logs.

[Unreleased]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.4...HEAD
[0.1.4]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/kdctek/kdc-wacr-recoveryflow/releases/tag/v0.1.0
