=== RecoveryFlow by WA.cr ===
Contributors: kdctek, vachan
Tags: abandoned cart, whatsapp, conversion recovery, woocommerce, recovery
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn lost conversions into conversations. RecoveryFlow recovers abandoned journeys over WhatsApp through WA.cr, and by email from WordPress.

== Description ==

Most sites lose more conversions than they finish. A shopper fills a cart and leaves at payment. Someone starts a booking and never confirms. A ticket order sits unpaid. RecoveryFlow by WA.cr is a recovery engine for WordPress commerce, forms, ticketing and booking systems. It notices when a journey stalls, works out whether the person may lawfully be contacted, and hands the conversation to WA.cr on WhatsApp, the channel people actually answer -- or sends a recovery email from WordPress itself, which needs no WA.cr account at all.

**RecoveryFlow detects → WA.cr communicates → customer converts.**

= An active WA.cr account is required, for WhatsApp =

RecoveryFlow is one half of a pair. It is the detection, consent and orchestration half; the WhatsApp half is [WA.cr](https://wa.cr), a separate third-party WhatsApp Business messaging platform with its own sign-up, terms and pricing. **The plugin cannot send a WhatsApp message on its own.**

To get a WhatsApp recovery out of it you need all of the following on the WA.cr side:

* An active WA.cr account and workspace, in good standing.
* A connected WhatsApp Business sender in that workspace.
* Either an Auto Flow with a "webhook received" trigger, on a WA.cr plan that can run one (Growth and above), or a WA.cr API key and at least one WhatsApp template approved by Meta (Scale and above).

**In short: sending WhatsApp through RecoveryFlow needs the WA.cr Growth plan or above.** Growth and Scale differ only in which of the two paths you get -- Growth hands journeys to an Auto Flow, Scale adds workflows authored here with direct template sends. On free, trial and starter neither path can run, so no WhatsApp reminder can leave the site whatever the plugin is set to. The email channel is unaffected and needs no WA.cr account at all.

Install it without those and the WhatsApp half of the plugin is inert: journeys are still detected, recorded and held as eligible, and every WhatsApp step stalls there unsent. Nothing in RecoveryFlow substitutes for the WA.cr side of that. What does work without WA.cr is the email channel — recovery emails are sent by WordPress itself, through whatever mail configuration the site already uses, and need no WA.cr account, no API key and no plan. There is no SMS.

Creating a WA.cr account, whatever it costs on your chosen plan, and the WhatsApp conversation charges Meta bills through it are all matters between you and WA.cr. They are not part of this plugin, and this plugin is not affiliated with or endorsed by WhatsApp or Meta.

RecoveryFlow owns detection, identity, consent, timing, the recovery link and attribution. WA.cr owns the WhatsApp conversation: approved templates, replies, quiet hours and frequency limits. The plugin never talks to WhatsApp directly and never writes free text to a cold contact.

= What you get =

* **Detection happens on your server.** Integrations watch the systems you already run and record one open journey per customer, server-side. There is no tracking script and no third-party pixel: the one small script on the classic checkout sends nothing itself, it asks WooCommerce to re-read its own form when the phone or email field loses focus. Nothing accepts a request from the public unless you switch on the basket capture form, which posts only into the shopper's own session behind a nonce and a rate limit. WooCommerce is the first supported integration; forms, ticketing and booking systems follow through the same integration interface.
* **Ask before the checkout, if you want to.** Most shoppers who leave never reach the checkout, so a shop that only asks there never learns how to reach most of the people it loses. RecoveryFlow can also ask on the basket page and beside the "add to basket" button, and can make the checkout's phone number compulsory. All three are off until you turn them on, and each says plainly what it costs.

* **Consent first.** A journey is only messaged when the customer is both identified and eligible, and the two are shown as separate badges everywhere. Explicit consent at checkout is the default.
* **Recovery links that rebuild the cart.** Each message carries its own link. It merges the saved items into whatever the customer has now, never wipes anything, and lands them on checkout.
* **Stops the moment they convert.** The instant an order is placed, messaging stops. The journey is marked recovered when the order is paid, and the revenue is attributed to the touch that brought them back.
* **Opt-out honoured from both sides.** A STOP reply, the opt-out link, or an opt-out recorded in WA.cr ends every open journey for that number. The suppression survives a privacy erasure.
* **Core WordPress admin, built to be accessible.** Overview, journey list and timeline, integrations, settings with shareable deeplinks, and a system status screen, all on core admin components to WCAG 2.2 AA and AAA where achievable.

= Two paths, and your WA.cr plan decides which =

An account is required either way. The plugin pushes a signed `recovery.journey_eligible` event to your flow, and WA.cr runs the delays, follow-ups, stop-on-reply and quiet hours. No API key is required for the hand-off -- but your workspace does have to be able to run an Auto Flow, which starts at the WA.cr Growth plan. On plans below that, RecoveryFlow still records everything it finds; it just has nowhere to hand it yet.

WordPress-authored multi-touch workflows with direct template sends, where the plugin decides the timing and content of each touch and reads delivery status and replies back, use the WA.cr developer API and are **included with WA.cr Scale and above**. The plugin detects what your workspace has and shows the right screens. Nothing is a disabled shell, and the Auto Flow path is complete on its own.

= How it works =

1. A supported integration (WooCommerce today) records activity as a Recovery Event: what was in the cart, what it is worth, and when it was last touched.
2. When the customer gives a phone number, at checkout or from their account, RecoveryFlow resolves who they are and checks eligibility: a usable WhatsApp number, consent, no opt-out, no open journey and no message in the last 24 hours.
3. After the inactivity threshold (30 minutes by default) a Recovery Journey starts under a workflow.
4. The workflow either hands the journey to a WA.cr Auto Flow, or sends an approved WhatsApp template through WA.cr with mapped variables and a recovery link, then waits and checks again before any further touch.
5. The customer taps the link. RecoveryFlow rebuilds their cart and sends them to checkout.
6. When the order is placed, messaging stops. When it is paid, the journey is recovered and attributed. If nothing happens, the journey expires quietly after seven days.

All processing runs in the background, through Action Scheduler when it is available and WP-Cron otherwise, in small, locked, resumable batches. Nothing slows the storefront.

= Supported integrations =

* **WooCommerce** 8.0 or later: carts and checkouts, classic and block-based, HPOS compatible. Available now.
* **Gravity Forms** 2.4 or later: save-and-continue drafts, and entries whose payment never went through. Nothing is rebuilt from a snapshot -- Gravity Forms hands the half-finished form back on its own resume link. Available now, with the WA.cr Scale plan or above.
* **Tickera**: planned.
* **Event Tickets**: planned.
* **Easy Digital Downloads**: planned.
* **Your own system**: register a custom integration with one PHP class through the `recoveryflow_register_sources` filter. See [integrations.md](https://github.com/kdctek/kdc-wacr-recoveryflow/blob/main/docs/integrations.md) and [developer-api.md](https://github.com/kdctek/kdc-wacr-recoveryflow/blob/main/docs/developer-api.md).

RecoveryFlow is not a WooCommerce plugin. WooCommerce is a supported integration; the plugin installs, activates and runs without it.

== External services ==

RecoveryFlow by WA.cr **requires an active account with WA.cr**, a third-party WhatsApp Business messaging platform operated independently of this plugin and of WordPress. The plugin connects to the **WA.cr API** to send WhatsApp messages on your behalf and, optionally, to read the delivery status of those messages and any replies. Without an active WA.cr workspace and a connected sender the plugin still detects and records journeys locally, but cannot message anyone, so no recovery happens. Sign-up, plan pricing and per-conversation charges are WA.cr's, not this plugin's.

**Where it connects**

* `https://api.wa.cr` in production, or `https://api.wacart.dev` when you select the staging environment in Settings.
* For Auto Flow hand-off, the hook URL you copy from your WA.cr Auto Flow. It must use HTTPS and be on one of the hosts above.

**What is sent**

* The customer's phone number in E.164 format.
* The name and language code of the approved WhatsApp template being sent.
* The mapped variable values for that template: the customer's first name, the cart total and currency, the item count, the recovery link and your site name.
* For Auto Flow hand-off, the same values plus a short summary of item names, your site URL, the time the journey was abandoned, an opt-out link and an opaque journey reference, so your flow can personalise its own messages.
* Only when you switch them on in Settings › WA.cr: the customer's last name and email address (default: first name on, last name and email off).

**What is NOT sent to WA.cr.** Recovery emails do not go through WA.cr and are not sent by it. They are handed to WordPress's own `wp_mail()` and leave by whatever route the site already uses for its other mail, so no part of an email recovery — not the address, not the subject, not the message — reaches WA.cr at all.
* Only when you enable "Sync contacts to WA.cr": the customer's phone number and name, saved as a contact in your WA.cr workspace. Only when you enable "Sync opt-out to WA.cr": the customer's opt-out flag.
* Your WA.cr API key, in the request's authorisation header, so WA.cr can identify your workspace.

**When it is sent**

* Only after you have saved a WA.cr API key or an Auto Flow hook URL, and only when a journey has been judged eligible: identified, consented and not opted out.
* When you press "Test connection" in Settings. This sends the API key only, no customer data.
* When the plugin lists your approved templates and connected senders in Settings. No customer data.
* Before a direct send, if you have granted the `contacts:read` scope: the customer's phone number, to check whether they have opted out in WA.cr.
* When polling for delivery status and replies, if you have granted the `messages:read` scope: the customer's phone number, to read that one conversation.
* When a journey is recovered or expires, if you have configured a second Auto Flow hook for those events: the journey reference and the phone number, so the flow can stop.

Nothing is sent when the integration is disabled, when eligibility mode is set to Disabled, or for journeys that are not eligible. No data is sent at install, activation or upgrade.

WA.cr: [https://wa.cr](https://wa.cr). Terms of service: [https://wa.cr/terms](https://wa.cr/terms). Privacy policy: [https://wa.cr/privacy](https://wa.cr/privacy).

== Privacy ==

RecoveryFlow keeps its data in its own tables in your WordPress database. It stores:

* **Identity**: first and last name, email address, phone number as entered and in E.164 form, country, and the linked WordPress user ID when there is one. Phone and email are also stored as keyed one-way hashes for matching.
* **A cart snapshot per journey**: product names, SKUs, quantities and amounts. Never addresses.
* **A consent record**: granted, denied or withdrawn; where it came from (classic checkout, block checkout, account, opt-out link, STOP keyword, WA.cr, admin); the wording version; the time; and the IP address as a keyed hash only.
* **Journey and message history**: states, timestamps, template names, delivery status, message IDs returned by WA.cr, link clicks and attribution. Message text is never stored.
* **An opt-out record.** After a privacy erasure this is kept only as a one-way hash of the phone number so that the opt-out continues to be honoured.

**Retention.** Finished journeys are anonymised after 90 days by default; you can change the period or set it to manual. Carts that never identified a customer are deleted after 7 days. Logs are kept for 14 days and never contain phone numbers, emails, names, message text or recovery links.

**Privacy tools.** Tools › Export Personal Data includes RecoveryFlow data for the email address and the phone numbers linked to it. Tools › Erase Personal Data anonymises it: names, email, phone and cart items are removed, totals are kept for reporting, and the eraser reports that a one-way hash is retained to keep honouring the opt-out.

**Uninstall.** Deleting the plugin always removes your API key, the hash key, capabilities and scheduled tasks. Tables and settings are removed only if you enable "Delete all data on uninstall" in Settings › Privacy.

== Installation ==

1. **Create a WA.cr account** at [https://wa.cr](https://wa.cr) and connect a WhatsApp Business sender to your workspace. This is required: without it the plugin can detect abandoned journeys but cannot send anything.
2. Upload the `kdc-wacr-recoveryflow` folder to `/wp-content/plugins/`, or install it from Plugins › Add New.
3. Activate **RecoveryFlow by WA.cr**. The plugin needs PHP 8.0 and WordPress 6.5 or later. WooCommerce 8.0 or later is needed only for the WooCommerce integration.
4. Go to **RecoveryFlow › Settings › WA.cr** and either paste the hook URL and signing secret from a WA.cr Auto Flow with a "webhook received" trigger (WA.cr Growth and above), or paste a WA.cr API key, press **Test connection**, choose a sender and an approved template, and map its variables (WA.cr Scale and above).
5. Go to **RecoveryFlow › Integrations** and enable WooCommerce. In the default eligibility mode the consent checkbox appears on checkout automatically.
6. Watch **RecoveryFlow › Overview** and **Recovery Journeys** as journeys start, and **System Status** for anything that needs attention. Every warning links straight to the setting that fixes it.

== Frequently Asked Questions ==

= Do I need a WA.cr account? =

Yes, for WhatsApp — which is what the plugin is for. WA.cr is a separate third-party service with its own sign-up, terms and pricing, and this plugin holds no WhatsApp ability of its own: without an account, every WhatsApp step stalls unsent. It is not all-or-nothing, though. Recovery emails are sent by WordPress itself and need no WA.cr account at all, so a shop with no WA.cr workspace can still detect abandoned journeys and recover them by email. There is no SMS.

= Which parts still work without one? =

Detection, identity resolution, consent capture, the journey list and the privacy tools all run locally and need no account. Everything past the point of contact — dispatch, delivery status, replies, recovery links being tapped, attribution — needs the WA.cr connection. System Status says plainly when the connection is missing.

= Does it require WooCommerce? =

No. RecoveryFlow is a recovery engine; WooCommerce is its first supported integration. The plugin installs, activates and runs its background processing without WooCommerce. With WooCommerce present it uses Action Scheduler; without it, WP-Cron.

= Does it send messages without consent? =

No. Explicit consent is the default: an unchecked checkbox at checkout that names your site and WhatsApp. The plugin records what was agreed, where and when. A merchant can switch to "identified contact" mode only after reading a warning and typing an acknowledgement, which is stored with their user ID and the wording version. Opt-outs are honoured in every mode.

= Which WA.cr plan do I need? =

Every plan needs an active account, and the plan decides which of the two workflow paths you can use. Auto Flow hand-off needs a workspace that can run an Auto Flow, which starts at WA.cr Growth, and needs no API key. WordPress-authored workflows with direct template sends and reply polling use the WA.cr developer API (`/v1`), which is included with WA.cr Scale and above. If WA.cr accepts a hand-off and does not run the flow -- because the plan cannot run one, or the flow is paused -- RecoveryFlow holds the reminder and says so on its status screen rather than recording it as sent.

= Can I build my own integration? =

Yes. Register a class implementing the Recovery Source interface through the `recoveryflow_register_sources` filter. Every integration answers seven questions: what is recoverable, how the customer is identified, when it counts as abandoned, how completion is detected, where to send the customer, what value information exists, and what consent constraints apply. See [integrations.md](https://github.com/kdctek/kdc-wacr-recoveryflow/blob/main/docs/integrations.md) and [developer-api.md](https://github.com/kdctek/kdc-wacr-recoveryflow/blob/main/docs/developer-api.md).

= Does it use WP-Cron? =

It uses Action Scheduler when it is available (WooCommerce ships it) and WP-Cron otherwise. Either way the work runs in small locked batches under a time budget, so overlapping runs are safe and nothing slows the storefront. System Status shows which driver is active and warns if WP-Cron is disabled without a system cron.

= Does it slow down my checkout? =

No. Cart activity sets a flag, and one small database write happens at the end of the request, throttled to once a minute per session. Nothing calls WA.cr during a customer's request.

= What happens if a customer taps the link twice, or a bot fetches it? =

The link keeps working while it is valid. Clicks are counted only for real browsers, never for WhatsApp's link preview fetcher, and restoring the cart merges rather than duplicates. Expired, revoked and unknown links all show the same neutral page.

= Where do I get help? =

Documentation lives in the [plugin repository](https://github.com/kdctek/kdc-wacr-recoveryflow/tree/main/docs). For WA.cr accounts, templates and Auto Flows, see [help.wa.cr](https://help.wa.cr).

== Screenshots ==

1. Overview: recovery statistics, recent activity and the health of the WA.cr connection.
2. Recovery Journeys: every journey with identified and eligible badges, status and attribution.
3. Journey detail: a timeline of touches, clicks, replies and the conversion.
4. Settings › WA.cr: connection, sender, templates and the "What we send" disclosure.
5. Integrations: WooCommerce and Gravity Forms connected, with the planned ones listed below them.
6. System Status: scheduler, scopes, HTTPS and the last run of every stage.
7. The consent checkbox on the WooCommerce block checkout.

== Changelog ==

= 0.1.1 =

Eight fixes on top of the first release. Three came from running the plugin against real Gravity Forms and a real WA.cr hand-off for the first time, and nothing here changes what it is for.

* **A reminder is no longer recorded as sent when WA.cr ran nothing.** The Auto Flow hand-off answers "200 OK" in three situations where nothing was sent at all: the workspace cannot run Auto Flows, the flow is paused or still a draft, or its trigger was never published. Those reminders were marked delivered. They are now held, tried again, and the reason is shown on the status screen.
* **The plugin said the Auto Flow hand-off worked on any WA.cr plan. It does not** -- it needs a plan that can run a flow, which is WA.cr Growth and above. The listing, the setup screen, the connection screen, the dispatch setting and the refusal shown when a workflow cannot be saved all said otherwise, and a shop owner reading any of them could have chosen a plan that can never send.
* **A Gravity Forms form can record consent**, through Gravity Forms' own Consent field, so recoveries from a form can be sent on a site that only messages people who agreed -- which is the default, and where a form recovery previously could never be sent at all.
* **Somebody who filled in a form is no longer written down when the form kept nothing to reach them by.** Gravity Forms drops a phone number it cannot read as international, so a form asking for a number routinely produced an entry with no number on it.
* **Each integration now says what it needs before it can message anyone**, on the Integrations screen, in its own words.
* **A recovery says which integration it came from by name**, instead of showing a shop worker an internal id.
* **WordPress can update the plugin.** A header left in the plugin file since the first commit told WordPress never to offer an update for it, which would have left every shop on the version it first installed.
* **One file could be requested directly in a browser.** Every file in the plugin blocks that; this one did too, but too far down to be found.

= 0.1.0 =
First release.

* Detects abandoned WooCommerce carts and checkouts, on both the classic and the block checkout, and records one recovery per shopping session.
* Picks up a phone number and email address as they are typed at the checkout, and records whether the shopper agreed to be messaged. Explicit consent is the default.
* Can also ask before the checkout -- a short form below the basket, a field beside the "add to basket" button -- and can make the checkout's phone number compulsory. All three are off until you turn them on, each says what it costs, and all three work with scripts blocked and on block-based pages.
* Recovers through WA.cr: either by handing the journey to an Auto Flow, which needs no API key and a plan that can run one (Growth and above), or by sending approved WhatsApp templates on a schedule this plugin decides. The second is included with WA.cr Scale and above.
* Recovery email sent by WordPress itself, through the site's own mail configuration -- no WA.cr account, no API key and no plan. Every message carries the sender's postal address and an unsubscribe link that keeps working for thirty days, and the channel stays off until those are set.
* Recovery links that rebuild the basket without wiping what the shopper has now, and stop working the moment the order is placed.
* A form-based workflow editor that works with JavaScript switched off, a template picker that shows each blank in the template's own words, and quiet hours and frequency caps.
* Admin screens built from core WordPress components: an overview, the recovery queue, one screen per recovery, integrations, six tabs of settings with linkable addresses, and a system status screen with a diagnostic report safe to paste into a support thread.
* Acting on a recovery: stop it, try a failed one again, stop its links working, or record that a customer asked by telephone never to be messaged again -- one at a time, or on a page of the queue at once. A bulk action runs the same code and gives the same refusals, named by reference.
* Opt-out honoured from every direction -- the unsubscribe link, an opt-out recorded in WA.cr, a STOP reported by an Auto Flow, or a shopkeeper acting on a phone call -- and the suppression survives a privacy erasure.
* WordPress privacy export and erasure, plus erasure by phone number for the customers core's tools cannot find, and a scheduled clear-out of data nobody has a reason to keep.
* A REST API under `/wp-json/kdc/v1/wacr/recoveryflow/`, capability-gated, with customer contact details masked unless revealing them is both permitted and asked for, and every reveal recorded.
* WP-CLI: `wp recoveryflow status`, `tick`, `journeys` and `sources`. Contact details are always masked.
* A Gravity Forms integration for save-and-continue drafts and entries whose payment never went through, and a documented interface for building your own.
* Every screen checked against WCAG 2.2 AA on every build -- all the admin screens, every settings tab and the pages a customer sees -- plus a keyboard pass over the same screens.
* Fully translatable, with the .pot shipped.

== Upgrade Notice ==

= 0.1.1 =
Fixes reminders being recorded as sent when WA.cr ran nothing, corrects the WA.cr plan the WhatsApp hand-off actually needs (Growth and above), lets a Gravity Forms form record consent, and restores the plugin's ability to be updated at all.

= 0.1.0 =
First release.
