# Privacy

RecoveryFlow by WA.cr exists to contact people who did not finish something. That only works, and is only lawful, if the plugin is precise about what it stores, why it may message someone, how they stop it, and what leaves the site. This document is the full account; `readme.txt` carries the short version merchants see on WordPress.org.

## Data inventory

| Data | Where | At rest | In REST | In logs |
| --- | --- | --- | --- | --- |
| Phone number (as typed, and E.164) | Customers table | Plaintext, plus an HMAC-SHA256 with the per-site hash key | Masked unless `reveal=1` with `recoveryflow_reveal_pii` (audited) | Never; Redactor masks E.164 patterns |
| Email address | Customers table | Plaintext, plus a keyed hash (fallback match only) | Masked unless revealed | Never |
| First and last name | Customers table | Plaintext | Masked unless revealed | Never |
| Country | Customers table | Plaintext | Shown | No |
| WordPress user id | Customers table | Plaintext | Shown | As an id only |
| Cart or booking snapshot (item names, SKUs, quantities, amounts, product references) | Events table (JSON) | Plaintext | Masked unless revealed | Never |
| Totals, currency, item count | Events and journeys tables | Plaintext | Shown | May appear |
| Consent ledger (status, source, wording version, time) | Consents table, keyed by phone hash | Plaintext; IP as a keyed hash only | Latest status shown | Never |
| Journey states, timestamps, attribution, clicks | Journeys table | Plaintext | Shown | Journey id and state only |
| Attempts (template name, language, WA.cr message ids, delivery status) | Attempts table | Plaintext | Shown | Template name and status; never the token |
| Recovery token | Attempts table | SHA-256 only; the token itself is never stored | Never | Never; Redactor masks tokens and `/recovery/` paths |
| Session key | Events table | Plaintext | Never | Never |
| Message text | Not stored anywhere | — | — | Never |
| WA.cr API key | Options | AES-256-GCM, autoload off | Never (last four characters shown masked in status) | Never |
| Webhook secret, Auto Flow signing secret | Options | Secret as SHA-256; signing secret encrypted | Never | Never |
| Per-site hash key | Options, autoload off | Plaintext (it is a key, not data) | Never | Never |
| Adapter metadata (coupon codes and the like) | Events table (JSON) | Plaintext; personal data forbidden by contract and stripped by the Redactor | Shown | May appear |

Personal data columns are stored in plaintext, as WooCommerce stores its own. Encrypting searchable columns adds nothing against an attacker who already has `wp-config.php` and the database; what is encrypted is the small set of secrets that can spend money or impersonate the site.

## Consent model

**Identified is not eligible.** Knowing a phone number is one fact; being allowed to message it is another. The two are recorded separately, shown as two badges on every journey, and the second is never inferred from the first.

`Eligibility_Evaluator` produces a decision with a recorded reason: `mode_disabled`, `no_phone`, `invalid_phone`, `no_consent`, `suppressed`, `wacr_opted_out`, `already_open`, `frequency_cap`, `excluded_user`, `below_min_amount` or `ok`. The reason is persisted on the journey and shown in the admin.

### The three modes

| Mode | What it means | How it is enabled |
| --- | --- | --- |
| `explicit_consent` (default) | A journey is eligible only if the latest consent row for the phone is `granted`. On WooCommerce that means the customer ticked an unchecked checkbox at checkout that names the site and WhatsApp. The wording version is recorded with the consent | The default on activation |
| `identified_contact` | Any identified contact with a usable phone is eligible unless suppressed. This treats the merchant's own relationship with the customer as the basis for contact and is appropriate only where the merchant has established that basis | Behind a prominent warning notice. The merchant must type an acknowledgement, which is stored as `recoveryflow_lawful_basis_ack` with their user id, the time and the wording version |
| `disabled` | Nothing is eligible. Events are still recorded (so switching the mode on later works), but no journey is scheduled and nothing is sent to WA.cr | Settings › Recovery |

Opt-outs are honoured in every mode. Suppression always wins over consent, and over the merchant's assertion.

### The consent ledger

Consent is append-only. Each row records the status (`granted`, `denied`, `withdrawn`, `suppressed`), where it came from (`checkout_classic`, `checkout_blocks`, `account`, `merchant_assertion`, `link`, `keyword`, `wacr`, `admin`), the wording version, the time, and the IP address as a keyed hash. The latest row per phone hash is the current state. Rows are keyed by phone hash rather than customer id so that a suppression outlives the customer record.

## Opt-out

Four ways in, one result:

| Channel | How |
| --- | --- |
| Opt-out link | Every message carries `recovery.opt_out_url`. GET shows a confirmation page; POST (token-bound) performs the opt-out. GET cannot change anything because link-preview fetchers request every link in a message -- WhatsApp's does, and Gmail's image proxy and Outlook's SafeLinks are more eager still |
| Keyword reply | A whole-message `STOP`, `UNSUBSCRIBE`, `CANCEL`, `END` or `QUIT` read by the Poll stage (`recoveryflow_optout_keywords`) |
| WA.cr | The customer's `optedOut` flag in WA.cr, read before every direct send when `contacts:read` is granted, and an `opt_out` event from an Auto Flow webhook step |
| Admin | The opt-out row action or REST call by a user with `recoveryflow_manage_journeys` |

The result is always the same: a `suppressed` consent row is appended for **every identity the person has** -- their phone hash and their email hash both -- every open journey for that customer moves to `OPTED_OUT`, and every recovery token on those journeys is revoked. Suppression is stored per identity, so silencing only the phone would leave the address reachable, and for somebody who gave an address and no number it would record nothing at all. That is also why the confirmation and confirmed pages name no channel: the button stops the reminders, not one way of delivering them. Writing the opt-out back to WA.cr is a separate setting because WA.cr's flag applies to the whole workspace, not only to this site.

### Email compliance

A recovery email is commercial mail: not a receipt, not a shipping notice, not a reply to something the customer wrote. So before the email channel can be used at all, `Email_Compliance` requires three things, and `Rule_Set::channel_enabled()` refuses the channel until it has them:

| Reason code | What is missing |
| --- | --- |
| `no_postal_address` | The physical postal address of the business sending the mail, printed at the foot of every recovery email |
| `no_postal_country` | The country that address is in, as ISO 3166-1 alpha-2 |
| `unsubscribe_window_too_short` | A recovery link lifetime of at least 30 days. The unsubscribe link in an email *is* the recovery link, and it has to keep working for that long after the message was sent |

This is a second gate, not the switch. The email channel still defaults to off and turning it on remains the merchant's decision; clearing these three makes it *permissible*, never enabled. The gate is what stops a setting written by WP-CLI, a migration or another plugin from starting unlawful mail on its own.

The postal address is a stored value, not a string of the software. It is never translated, never appears in the `.pot`, and is never reformatted to suit the admin's locale -- an address is laid out according to its own country, which is why that country is asked for separately rather than parsed back out of the text.

### What a recovery email actually contains, and who sends it

`Email_Composer` builds the message and `Email_Sender` hands it to `wp_mail()`. **Nothing about an email recovery goes near WA.cr** -- not the address, not the subject, not the body -- so an email step works on a workspace with no API key and on no plan at all.

The merchant writes the subject and body themselves, with the same `{{ placeholders }}` the WhatsApp templates use; there is no template to approve because nobody approves an email. What they do **not** write is the footer. `Email_Compliance::footer()` appends the postal address and the unsubscribe link to every message, and the composer **refuses to build a message at all** when it cannot produce one -- even though `Rule_Set::channel_enabled()` has already refused the channel for the same reasons. Two gates for one rule is deliberate here: the cost of sending unlawful mail and the cost of one extra check are not comparable.

The body is plain text on purpose. A recovery email is a short note with one link in it, so HTML buys the reader nothing while costing an escaping surface, a layout to maintain across every mail client, and remote images that Gmail fetches through its own proxy. It also means no stylesheet can hide the footer, which is rather the point of a footer that exists for legal reasons.

**No `From` header is set.** The site's own mail configuration decides, so a recovery email leaves by the same route and with the same identity as the shop's order emails. A site that has configured SMTP, or a sending domain with SPF and DKIM, has already answered that question, and a `From` of the plugin's choosing would quietly opt every such site out of its own deliverability work.

### A click on an email link is weaker evidence than a click on a WhatsApp one

`User_Agent::is_link_preview()` recognises the mail proxies that identify themselves -- Gmail's image proxy, Yahoo's, Bing's preview fetcher -- alongside the chat and social crawlers it already knew. **It cannot recognise corporate link scanners.** Microsoft's Safe Links and the equivalents from Proofpoint, Mimecast and Barracuda routinely fetch every URL in an incoming message behind an ordinary browser's user agent, and no substring can tell one of those from a person. Adding guessed tokens for them would look like coverage while catching nothing, so they are deliberately absent.

So a click figure on the email channel is softer than one on WhatsApp, and that is a reporting caveat rather than a hazard, because of two properties that hold regardless:

- **A click sets no state.** Only reading a WhatsApp conversation back ever moves a journey to `ENGAGED`. A scanner cannot make a customer look like they replied.
- **The opt-out refuses a `GET`.** A scanner that opens every link in a message cannot unsubscribe the person whose mail it was scanning. This mattered already for WhatsApp's preview fetcher; on email, where scanners are both more aggressive and less identifiable, it is the guarantee doing the real work.

## Exporter, eraser, anonymiser, retention, uninstall

**Exporter.** Registered on `wp_privacy_personal_data_exporters` in group `recoveryflow`. Given an email address it finds every customer reachable from that address and exports, for each: the recovery record, every contact detail held, the full consent history (channel, decision, when, from where, and the exact wording agreed to) and each unfinished order with the reminders sent about it. One customer per page, because a shared address really can find several people.

Values are exported in full rather than masked. Masking exists to stop a passer-by reading a shop screen; it has no place in a copy of somebody's own data. A phone number is exported even though the request arrived by email, because someone asking what a shop holds about them is entitled to the number too.

**Eraser.** Registered on `wp_privacy_personal_data_erasers`. It calls the anonymiser and always reports `items_retained`, with a message explaining what is kept and why — see `Anonymizer::retained_notice()`.

**Anonymiser.** One implementation, used by both the eraser and the retention clear-out, so the two cannot drift into different ideas of what "erased" means. It nulls the names and the WA.cr contact id on the customer row and the readable value on every identity row; strips item snapshots, metadata and the session key from the events; revokes every recovery link so one already sitting in a message history stops working; cancels any journey still in flight; detaches the consent rows from the person without deleting them; and stamps `anonymized_at`, which is what makes the customer permanently unmessageable.

**What it keeps, and why.** The keyed hash of each identity stays. The consent ledger is keyed by that hash, so deleting it would delete the record that this person asked not to be messaged — and the next time they typed the same number into a checkout the shop would treat them as new and message them again. Erasing an opt-out is not a privacy improvement; it is the failure the opt-out exists to prevent. The hash is keyed to the site and is not reversible into a phone number. Amounts, dates and journey outcomes stay too: they are the shop's own trading record and say nothing about a person once the person is detached from them.

**Retention.** A daily stage, in batches paged by primary key rather than by offset — an offset walk over a table being deleted from skips rows, which on a clear-out means data quietly outliving its retention period for ever.

| What | When |
| --- | --- |
| Item snapshots, metadata and session keys on finished journeys | Stripped after `retention_days` (default 90) |
| Customers whose every journey is finished | Anonymised after `retention_days` |
| Events that never identified a customer | Deleted after 7 days |
| Expired recovery tokens | Purged |
| Receipts | Deleted after 30 days |
| Logs | Deleted after `log_retention_days` (default 14) |

A customer is only anonymised when *every* journey they have is finished, so somebody who came back after six months is not forgotten in the middle of being messaged. `retention_days` is clamped to a minimum of seven: shorter throws away the evidence needed when somebody asks why they were messaged, and that question always arrives late.

**Uninstall.** `uninstall.php` always removes the API key, the signing and webhook secrets, the hash key, capabilities, scheduled actions and transients. Tables and options are dropped only when `delete_data_on_uninstall` is enabled (default off), so a merchant who removes the plugin by mistake does not lose their history — including the record of who opted out. On multisite it iterates every site.

**A shopkeeper can record an opt-out taken by telephone.** It runs the same code as the unsubscribe link: every identity the customer has is suppressed, not merely the phone number, and every open recovery of theirs is stopped along with its links. The suppression survives a privacy erasure, because the hashes are the suppression list -- erasing them would forget that this person asked not to be messaged, and message them again the next time they typed the same number into a checkout.

**A customer who only ever gave a phone number can be erased too.** WordPress's own privacy tools find somebody by email address, which is the right key for almost every plugin and the wrong one for this: RecoveryFlow exists to recover people over WhatsApp, so a large share of the customers it holds typed a phone number at the checkout and never an address, and core's eraser has nothing to search on. Settings > Privacy > Erase one customer takes the number instead.

It normalises what is typed before hashing it, through the same resolver the checkout used -- a customer reads their number off their phone as `07700 900123` while the identity was stored as `+447700900123`, and a lookup that skipped that step would report somebody as absent who is certainly there, on the one screen where that answer sends a person away believing their data has already gone. A site that filters `recoveryflow_normalize_phone` is searched through that filter too, for the same reason. A bare local number is read as belonging to the merchant's own postal country.

It ends at the same `Anonymizer` as core's eraser and the retention clear-out. There is no second implementation of what "erased" means, and this route gets no exception: the same fields are blanked, the same links revoked, the same keyed hashes kept.

Three answers are deliberately distinct, because two of them look alike and mean opposite things: a number that could not be read (try again), a number nobody has (stop looking), and a customer who had already been erased (nothing changed, and saying "done" again would be a lie about work that did not happen). The form never echoes back who was found -- an erasure box that returned a name would answer "does this phone number belong to one of your customers" for anyone who could reach the screen.

## Data minimisation rules

- Adapters never put personal data in event metadata; the Redactor enforces it.
- Item snapshots hold names, SKUs, quantities and amounts, never addresses or notes.
- IP addresses are stored only as a keyed hash, and only with a consent row.
- The contact snapshot a WooCommerce guest types at checkout lives in the WooCommerce session until identity is resolved; it is not copied onto the event row.
- Logs never contain phone numbers, emails, names, message text, tokens or links.

## What is sent to WA.cr, and when

This is the same list that appears in `readme.txt` and on the "What RecoveryFlow sends to WA.cr, and when" card in Settings › WA.cr. It is the complete list; there is no other outbound traffic.

**Where.** `https://api.wa.cr` in production or `https://api.wacart.dev` when staging is selected, and the Auto Flow hook URL copied from WA.cr, which must be HTTPS on one of those hosts.

**What.**

- The customer's phone number in E.164 format.
- The name and language code of the approved WhatsApp template.
- The mapped variable values for that template: first name, cart total and currency, item count, the recovery link and the site name.
- For Auto Flow hand-off, the same values plus a short items summary, the site URL, the abandonment time, an opt-out link and an opaque journey reference.
- Only when enabled in Settings: last name and email address (default: first name on, last name and email off).
- Only when "Sync contacts to WA.cr" is enabled: the phone number and name, saved as a contact. Only when "Sync opt-out to WA.cr" is enabled: the opt-out flag.
- The API key in the request's authorisation header.

**When.**

- Only after an API key or an Auto Flow hook URL has been saved, and only when a journey is eligible: identified, consented and not opted out.
- On "Test connection" (the key only).
- When listing approved templates and connected senders in Settings (no customer data).
- Before a direct send, when `contacts:read` is granted: the phone number, to read the opt-out flag.
- When polling for delivery status and replies, when `messages:read` is granted: the phone number, to read that one conversation.
- When a journey is recovered or expires, if a second Auto Flow hook is configured: the journey reference and phone number, so the flow can stop.

Nothing is sent when the integration is disabled, in `disabled` mode, for ineligible journeys, or at install, activation or upgrade.

WA.cr terms of service: <https://wa.cr/terms>. WA.cr privacy policy: <https://wa.cr/privacy>.
