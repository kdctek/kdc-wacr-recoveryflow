# Changelog

All notable changes to RecoveryFlow by WA.cr will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Each entry opens with a plain-language statement of what changed for the person
affected, followed by the detail.

## [Unreleased]

### Added

- **A workflow can now skip shoppers who keep ignoring recoveries.** The new check, "the customer has ignored fewer than a set number of earlier recoveries" (`customer.ignored_fewer_than`, 2 unless you set another number from 1 to 20), stops a journey for somebody who let that many earlier recoveries end without opening the link, replying or buying. A recovery counts only if it sent at least one message. Opt-outs, failures and unreachable shoppers never count, and neither does a journey the check itself stopped before sending, so the rule cannot talk somebody into silence. Only the last 180 days count; the `recoveryflow_ignored_lookback_days` filter changes that. Placed in front of a last touch that carries a discount, it stops that discount going to the people least likely to use it. It saves billed WhatsApp conversations and discount margin; it does not recover more baskets. It uses only the shop's own recovery history, so it works on every plan with no Google or WA.cr dependency. It is not added to the default workflows, so nobody's messages change until they add it. Email link scanners record clicks, so an email-only recovery is less likely to count as ignored: every mistake it can make is towards sending one more message.

### Fixed

- **A shopper who comes back after the retention period is recovered like anyone else, instead of never being messaged again.** The daily retention pass anonymises a customer once their last recovery is older than `retention_days` (90 by default). It used to keep their identity hashes on the anonymised record, exactly as an erasure request does. So the next basket left with the same phone number, email address or WordPress account resolved back to that record, and eligibility refuses an anonymised record outright. Every shopper whose last recovery finished more than `retention_days` ago was silently unreachable. At the default setting the first shoppers would have reached this around 7 December 2026; a shop that shortened the period may already have. The retention pass now deletes the record's identity rows and drops its WordPress account link before stamping it, so a return visit starts a new customer. Opt-outs still hold, because the consent ledger keeps its own copy of each hash: a returning shopper who opted out is refused as `suppressed`. An erasure the person asked for (Tools › Erase Personal Data, or erasing by phone) is unchanged and keeps them unmessageable. Records anonymised by earlier versions cannot be told apart by route, so they keep the old behaviour. A retention-anonymised record also stops being reachable from the person's email or phone, so a later export or erasure request finds nothing.

- **A STOP reported by a WA.cr flow for a number RecoveryFlow does not hold is now recorded, so the first basket that number leaves is not messaged.** The webhook receiver used to drop an `opt_out` when no customer matched. That number might belong to a record the retention pass has just let go of, and WA.cr does not refuse a send to somebody who opted out, so the plugin's consent ledger is the only thing that can. The suppression is recorded against the number's hash on every channel. The response is the same `200` with `matched: 0` either way, so the endpoint still answers nothing about who is a customer. A reply from an unknown number still records nothing.

- **A condition's number can now be set in the workflow editor, and saving a workflow no longer drops it.** The editor offered conditions by name alone. A threshold such as "the basket is worth at least 250" could not be typed, and re-saving a workflow that had one quietly turned it into the shop's minimum basket value. The picker also offered that check as "the basket is worth at least", ending mid-sentence. A check that takes a number now gets a labelled number box with its range and what an empty box means. After choosing such a check, Save shows its box, the same way changing a step's type redraws the step. A number the check cannot read refuses the save with a sentence naming the step, and the number is shown back as typed. Third-party conditions opt in through the new `Condition_Argument_Interface`; one that does not implement it works exactly as before.

- **The developer documentation no longer lists conditions that do not exist or shows a workflow the validator would refuse.** `docs/developer-api.md` listed `event.item_count_gte` and `journey.attempts_lt` as built in; neither was ever registered. Its example workflow ended a step with `stop:engaged`, which is not a state a journey can stop in, so the documented example could not be saved. It also cited a `Workflow_Definition::schema()` method that does not exist. The smoke suite now checks the documented built-ins against the registry in both directions, and validates every example workflow in that document.

## [0.2.0] - 2026-10-06

### Added

- **A new Settings › Analytics tab, and a "Reports to" card on the Integrations screen, put both GA4 features in one place.** "Tag recovery links" is on by default. "Report recoveries to Google Analytics 4" is off until a Measurement ID and a Measurement Protocol API secret are saved. The Measurement ID is checked when saved: a Universal Analytics `UA-`, a Google tag `GT-` or a stream number is refused with a message, because Google's endpoint would accept each one and count it nowhere. The API secret is stored encrypted like the WA.cr key, never printed back and never exposed over REST, and it is removed on uninstall. An EU endpoint can be chosen. "Send a test event" checks the event with Google's validation endpoint, then sends one `recoveryflow_test` event with debug mode on. Google does not let a plugin check a Measurement ID and secret, so the result sends the merchant to GA4's DebugView instead of claiming the credentials work. The card and the tab state where reporting stands in the same sentence, from the same check the reporter uses. `readme.txt` now discloses Google Analytics as an optional external service with what is sent, when and where.

- **Journeys that sent a message can now be reported to the merchant's own GA4 property, so Google Ads can retarget the baskets WhatsApp did not win and stop paying for the ones it did.** Four events go over the Measurement Protocol: `recoveryflow_messaged`, `recoveryflow_recovered` (with the recovered `value` and `currency`), `recoveryflow_expired` (with `basket_value`, not `value`, so a key event cannot count lost money as revenue) and `recoveryflow_opted_out`. A journey that never sent a message is never reported. In the default explicit-consent mode that also means every reported shopper ticked the recovery box. The params are a journey reference, the workflow slug, the source and money; nothing personal is sent. Consent is sent only as a refusal: when the site's consent tool refused marketing, both ad fields go as `DENIED`; otherwise the field is left out and GA4 applies what the site's own tag recorded for that browser. Events are queued, never sent inside a shopper's request, and a new `report` stage sends them every five minutes. Each milestone is queued at most once, because GA4 does not de-duplicate. A row is marked as sending before its request goes out, and a request that may have arrived, such as a timeout, is never retried. Anything older than 71 hours is dropped as stale rather than letting GA4 re-stamp it onto the wrong day. Expiry is reported from the Expire stage's tidy pass, because expiry is one bulk update that fires no hook. A journey that expired unmessaged has its client id forgotten there. Queue rows are pruned after 30 days. The log redactor now masks GA client ids and an `api_secret` in any URL. The `recoveryflow_ga4_event` filter changes or stops an event.

- **Recovered visits now show up in Google Analytics as RecoveryFlow traffic, not as "direct".** Every tap on a recovery link redirects to the shop with `utm_source=recoveryflow`, `utm_medium` set to the channel (`whatsapp` or `email`), `utm_campaign` set to the workflow's slug and `utm_content` set to the step (`step-1`, `step-2` ...). The tags go on the redirect because a WhatsApp template's button is fixed by Meta's approval and has nowhere per-message to put them. Before this, the endpoint's `Referrer-Policy: no-referrer`, which keeps the token away from the shop's scripts, made every recovered visit look direct. Tags the shop's own address already carries are never overwritten. This is on by default because it sends nothing anywhere. In GA4, email sessions land in the Email channel group and WhatsApp sessions in "Unassigned"; `docs/integrations.md` explains the one-rule custom channel group that gives them their own row. The new `recoveryflow_restore_utm_params` filter changes or removes tags.

- **RecoveryFlow can now remember which Google Analytics visitor a basket belongs to, once a merchant turns on GA4 reporting.** At checkout and in Gravity Forms, the shopper's own `_ga` cookie is read on the server and stored with the basket. Nothing is read until the merchant has switched GA4 reporting on and entered a Measurement ID and API secret. Where the site runs the WP Consent API, nothing is read unless statistics consent is granted. When the visitor's consent banner refused analytics, Google's tag never set the cookie, so there is nothing to read. The recovery tick-box is deliberately not used for this: it is consent to WhatsApp reminders, not to analytics. A marketing refusal from the WP Consent API is stored beside the id, so a later report can tell Google not to use it for ads. The id is a personal identifier and is handled as one. It has its own column, appears in Tools > Export Personal Data, and is removed by erasure and by the retention anonymiser. It is never read in background jobs, because Action Scheduler's async runner carries the cookies of whichever request triggered it, usually an admin's. Database schema 3 adds the column and the table the reports will queue in.

## [0.1.5] - 2026-09-20

### Changed

- **Published RecoveryFlow by WA.cr to the WordPress.org Plugin Repository.**

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

[0.1.5]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.4...v0.1.5
[Unreleased]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.5...v0.2.0
[0.1.4]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/kdctek/kdc-wacr-recoveryflow/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/kdctek/kdc-wacr-recoveryflow/releases/tag/v0.1.0
