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
| Opt-out link | Every message carries `recovery.opt_out_url`. GET shows a confirmation page; POST (token-bound) performs the opt-out. GET cannot change anything because WhatsApp's link-preview fetcher requests every link in a message |
| Keyword reply | A whole-message `STOP`, `UNSUBSCRIBE`, `CANCEL`, `END` or `QUIT` read by the Poll stage (`recoveryflow_optout_keywords`) |
| WA.cr | The customer's `optedOut` flag in WA.cr, read before every direct send when `contacts:read` is granted, and an `opt_out` event from an Auto Flow webhook step |
| Admin | The opt-out row action or REST call by a user with `recoveryflow_manage_journeys` |

The result is always the same: a `suppressed` consent row is appended, every open journey for that phone hash moves to `OPTED_OUT`, and every recovery token on those journeys is revoked. Writing the opt-out back to WA.cr is a separate setting because WA.cr's flag applies to the whole workspace, not only to this site.

## Exporter, eraser, anonymiser, retention, uninstall

**Exporter.** Registered on `wp_privacy_personal_data_exporters` in group `recoveryflow`. Given an email address it exports the customer record, the consent history and the journeys (states, times, totals, attribution, attempts with template names and delivery status) for that email and for the phone hashes linked to it.

**Eraser.** Registered on `wp_privacy_personal_data_erasers`. It calls the anonymiser and reports `items_retained` with the message that a one-way hash is kept so the opt-out continues to be honoured. An erase-by-phone action is also available in the admin for users with `erase_others_personal_data` (with a nonce), for customers who never gave an email.

**Anonymiser.** Nulls names, email, phone (raw and E.164) and country on the customer row; drops item snapshots from the events; keeps totals, currency, item counts, states and attribution for reporting; revokes every token; and sets `anonymized_at`. The phone hash and the consent ledger remain, which is what keeps a suppression effective after erasure. A journey whose customer is anonymised while it is still active is cancelled.

**Retention.** A daily stage, in small batches:

| What | When |
| --- | --- |
| Terminal journeys and their customers' identifying data | Anonymised after `retention_days` (default 90; `0` means manual, with a warning in System Status) |
| Events that never identified a customer | Deleted after 7 days |
| Expired recovery tokens | Purged |
| Receipts | Deleted after 30 days |
| Logs | Deleted after `log_retention_days` (default 14) |

**Uninstall.** `uninstall.php` always removes the API key, the signing and webhook secrets, the hash key, capabilities, scheduled actions and transients. Tables and options are dropped only when `delete_data_on_uninstall` is enabled (default off), so a merchant who removes the plugin by mistake does not lose their history. On multisite it iterates every site.

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
