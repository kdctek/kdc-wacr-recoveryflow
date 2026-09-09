# Integrations

RecoveryFlow by WA.cr is an engine. It knows nothing about carts, forms or tickets on its own; every system it recovers journeys from is a **Recovery Source** that plugs into the core through one interface. WooCommerce is the first supported source and ships inside the plugin. Others follow through the same interface, and site owners and developers can register their own.

## The Recovery Source interface

```php
namespace WAcr\RecoveryFlow\Integration;

use WAcr\RecoveryFlow\Recovery\Recovery_Event;
use WAcr\RecoveryFlow\Recovery\Recovery_Journey;

interface Recovery_Source_Interface {
    public function get_id(): string;
    public function get_name(): string;
    public function get_description(): string;
    public function is_available(): bool;                   // dependency present and version ok
    public function register(): void;                       // attach hooks; called when available and enabled
    public function get_event_types(): array;               // e.g. [ 'cart', 'checkout' ]
    public function get_default_rules(): array;             // Rule_Set overrides (inactivity, max age, min amount…)
    public function is_conversion_complete( Recovery_Event $event ): bool;   // authoritative re-check at step time
    public function build_recovery_url( Recovery_Journey $journey, string $token ): string; // default Recovery_Url::build()
    public function restore( Recovery_Journey $journey, Recovery_Event $event ): string|\WP_Error; // returns redirect URL
    public function get_settings_fields(): array;           // rendered on the Integrations card
}

interface Pollable_Source_Interface extends Recovery_Source_Interface {
    public function detect_recovery_events( int $limit, ?string $cursor ): Event_Batch;  // for systems without hooks
}

abstract class Abstract_Source implements Recovery_Source_Interface {
    // sensible defaults for build_recovery_url() and get_settings_fields(), plus access to Event_Ingest
}
```

What each method is for:

| Method | Called when | Contract |
| --- | --- | --- |
| `get_id()` | Always | A short stable slug, for example `woocommerce`. It becomes `source_id` on events and journeys and the `source` filter value in REST |
| `get_name()`, `get_description()` | Integrations screen | Human-readable, translatable |
| `is_available()` | Every request, cheaply | Return `true` only when the third-party system is installed at a supported version. Never throw |
| `register()` | `init`, only when available and enabled | Attach the hooks that detect activity, capture identity and report completion |
| `get_event_types()` | Validation and UI | The `source_type` values this source produces |
| `get_default_rules()` | Eligibility | Per-source defaults for inactivity threshold, maximum age and minimum amount. Site settings override them |
| `is_conversion_complete()` | Before every send | The authoritative answer from the source system, not from the event row. If you cannot tell, return `true`; the engine fails closed and does not send |
| `build_recovery_url()` | When composing a message | Usually the default `/recovery/{token}` redirector. Override only if your system needs a different landing route |
| `restore()` | When a customer taps the link | Rebuild what they abandoned in their own session and return the URL to send them to. Return a `WP_Error` to show the generic invalid-link page |
| `get_settings_fields()` | Settings &rsaquo; Integrations | Field definitions in the same shape as the Settings schema. They are folded into the one settings tree, so they are rendered, validated and deeplinked by exactly the same code as the plugin's own fields; each key is stored as `source_{id}_{key}` so two adapters cannot collide |
| `detect_recovery_events()` | Evaluate stage, for pollable sources | Return a batch of drafts plus a cursor for systems that have no hooks to push from |

### Registering a source

Built-in sources are registered by the core. Third parties use the filter, which is applied when the registry is built on `init` at priority 5:

```php
add_filter( 'recoveryflow_register_sources', function ( \WAcr\RecoveryFlow\Integration\Source_Registry $registry ) {
    $registry->add( new My_Source() );
    return $registry;
} );
```

A minimal working class is in [`developer-api.md`](developer-api.md#a-minimal-custom-source).

### Switching an integration on and off

Every registered source gets a section on **Settings &rsaquo; Integrations**, with a switch and whatever fields the adapter declared. The switch is stored as `source_{id}_enabled` and defaults to on, because a merchant who installs a recovery plugin on a WooCommerce site has already said what they want it to do. Switching one off stops new journeys being recorded from it; journeys already under way finish or expire on their own, and nothing already recorded is deleted.

The Integrations screen states, per source, which of four things is true, and it asks `Source_Registry::status()` -- the same method that decides whether the source's hooks are attached:

| Status | Means |
| --- | --- |
| `unavailable` | The system this source integrates with is not installed or not active |
| `switched_off` | Present and working, switched off here |
| `not_included` | Present and switched on, but the WA.cr plan does not include sources beyond WooCommerce. **The hooks are not attached**, so nothing is recorded |
| `active` | Watching |

### Sending events into the core

Sources never process anything inline. They build an `Event_Draft` and hand it to `Event_Ingest::upsert()`, which performs one `INSERT … ON DUPLICATE KEY UPDATE` on `(source_id, dedupe_key)`. Everything else, identity resolution, eligibility, scheduling, sending and polling, happens in the background stages described in [`architecture.md`](architecture.md#background-processing). Two rules the ingest enforces:

- `metadata` must not contain personal data. The Redactor strips it and logs a warning.
- Contact hints (phone, email, names, country) are passed as identity hints, not stored on the event. The event only ever carries the resolved `customer_id`.

## The seven questions every adapter answers

Every source answers these in its class docblock and in this document. For WooCommerce:

| Question | WooCommerce |
| --- | --- |
| **1. What is recoverable?** | A cart with at least one line and a non-zero total, from the first item added until an order is placed or the cart is emptied. Classic and block-based carts and checkouts are the same event (`source_type` `cart` or `checkout`). Carts belonging to users with `manage_woocommerce`, zero-amount carts, JSON pings and test-mode orders are excluded |
| **2. How is the customer identified?** | Logged-in customers: WordPress user id plus billing details from the customer object, read once per session. Guests: billing phone, email, first and last name and country captured server-side as the customer fills in the classic checkout, or from the Store API customer update on the block checkout. Captured server-side and stored server-side, with no public endpoint; on the classic checkout a small script nudges WooCommerce's own update_order_review round trip when the phone or email field loses focus, and sends nothing itself. The contact snapshot lives in the WooCommerce session, is written in one place (`Contact_Snapshot`, where a blank never overwrites something already known), and identity is resolved only when it changes. Two optional points ask earlier, both off by default: a form on the basket page and a field beside add-to-cart -- see below |
| **3. When is it abandoned?** | After 30 minutes without a cart change (adapter default; the site setting overrides). Events older than seven days expire without a journey |
| **4. How is completion detected?** | Order placed (classic, Store API or `woocommerce_new_order`) moves the journey to `PENDING_PAYMENT`. A paid status or `on-hold` (setting `recovered_statuses`) moves it to `RECOVERED`. A failed or cancelled payment resumes the journey once. Orders are matched by the journey uid and event uid stamped on the order at creation, then by session key, then by customer within 30 days |
| **5. Where do we send the customer?** | `/recovery/{token}` restores the cart into the clicker's own session, merging with what is there, prefills guest billing details, and 302s to the checkout URL (the cart URL if nothing could be restored) |
| **6. What value information exists?** | Currency, total, item count and a line-item snapshot (name, SKU, quantity, unit and line amounts, product and variation ids and attributes), plus applied coupon codes in metadata. Never addresses |
| **7. What consent constraints apply?** | In `explicit_consent` mode an unchecked checkbox naming the site and WhatsApp is added after the phone field on the classic checkout and as an additional checkout field on the block checkout, and the wording version is recorded with the consent. The same box, the same wording and the same recorded version appear at the two optional earlier points. Opt-outs from any channel are honoured in every mode |

### Asking before the checkout

The checkout is where contact details are normally learned, and that is the
problem: most shoppers who abandon never reach it, so the plugin knows nothing
about the majority of the baskets it records. Two optional points ask earlier.
**Both are off by default**, because each one puts a field in front of somebody
trying to get through a page -- a trade a merchant makes, not a plugin.

They are not the same shape, and the difference is the interesting part.

| | Basket page (`capture_at_cart`) | Add to basket (`capture_at_add_to_cart`) |
| --- | --- | --- |
| Rendered by | `woocommerce_after_cart_table`, plus `woocommerce_after_cart` so a **block** basket is covered too | `woocommerce_after_add_to_cart_button`, inside WooCommerce's own form |
| How it reaches the server | Its own POST to `admin-post.php` | WooCommerce's own add-to-cart request |
| Defences | Nonce, per-address rate limit (10 per 5 minutes), redirect back so a refresh cannot re-post, and a re-check that the setting is still on | WooCommerce's own; nothing of ours is opened |
| JavaScript | None | None |

The basket form is **the only place in this plugin that accepts a post from a
member of the public**, which is why it carries all four. A nonce on a page
anybody can load says where a post came from and nothing about how often, so the
rate limit is not belt-and-braces: without it this is a free way to write to a
shop's session store, once per request, forever. It writes nothing but the
shopper's own contact snapshot and their own consent answer, into their own
session -- no journey, no other customer -- and it answers identically whether or
not the details were already known, so it cannot be asked whether an address is
enrolled.

Neither point records a consent refusal when nothing was filled in. An ignored
field is not an answer, and treating it as one would suppress a shopper who
never replied.

### Making the phone number compulsory

`checkout_phone_required` turns WooCommerce's optional billing phone into a
required one, on the classic and block checkouts alike. **It filters
`woocommerce_checkout_phone_field` and never writes it.** That option is
WooCommerce's own setting, shared with the block checkout's own screen; writing
it would mean a RecoveryFlow switch silently editing a WooCommerce screen, and
-- the part that actually matters -- the requirement would survive this plugin
being deactivated, leaving a shop with a rule nobody chose and no control that
explains it. Filtering the read leaves the merchant's stored value untouched.

Note that requiring it also un-hides a phone field set to `hidden`, because
there is no way to require something nobody is shown. The setting says so.

## WooCommerce in detail

Requires WooCommerce 8.0 or later. The plugin declares compatibility with High-Performance Order Storage and the cart and checkout blocks on `before_woocommerce_init`.

| Concern | How it works |
| --- | --- |
| Cart activity | `woocommerce_cart_updated` sets a dirty flag. It fires many times in a request -- once per coupon change, once per quantity change, and on plain cart-page views where nothing changed at all -- so the flag only means "a request touched the cart"; the snapshot fingerprint, not the flag, is what stops pointless writes. It does fire for Store API and Blocks requests, and it is the only cart hook that still fires once the cart is empty. `woocommerce_cart_emptied` flags an emptied cart. One write happens on `shutdown`, after WooCommerce has set its cookies: snapshot the items, amount and currency, skip if the fingerprint is unchanged, throttle to one write per 60 seconds per session unless the contact changed or the customer is on checkout, then upsert. Errors are logged, never thrown into the customer's request. An empty cart closes the event as `invalid` with reason `cart_emptied`. No per-item hooks are used |
| Guest identification | Classic checkout: `woocommerce_checkout_update_order_review` carries the posted billing fields as the customer types, so a guest is identified before they ever press Place Order. Only billing phone, email, first and last name, country and shipping country are kept, sanitised and capped at 100 characters. Block checkout: `woocommerce_store_api_cart_update_customer_from_request` and `…_checkout_update_customer_from_request`. Logged-in: once per session on `woocommerce_cart_loaded_from_session` |
| Consent field | Classic: appended to the billing fields at priority 115 (after phone) and read from the posted data. Blocks: registered with `woocommerce_register_additional_checkout_field` as a checkbox with id `recoveryflow/whatsapp-consent` at the **address** location, not the contact location. WooCommerce only persists a contact-location field when an order is created, and an abandoned checkout has no order -- a consent box there would be visible to the shopper and unreadable to the plugin, which is worse than not asking. An address-location field is written to the customer and the session as the shopper types, which is what makes consent usable for recovery at all. The cost is that WooCommerce renders address fields in both the shipping and billing forms; the location is filterable through `recoveryflow_wc_blocks_consent_location`. Shown only in `explicit_consent` mode |
| Order stamping | `woocommerce_checkout_create_order` (before WooCommerce's own save, so no extra write) and the Store API equivalent stamp `_recoveryflow_session_key`, `_recoveryflow_event_uid` and, after a restore, `_recoveryflow_journey_uid` through `update_meta_data()`, which is HPOS-safe |
| Conversion | Every handler first claims a receipt `wc_order:{id}:{status}`; every write is compare-and-set on the journey status. A customer-level match always stops the person's active journeys, but only `exact` and `session` attributions count as recovered revenue unless customer-level attribution is enabled |
| Restore | For each snapshot line, load the variation or product, skip missing, non-purchasable or out-of-stock items, clamp to the maximum purchase quantity, skip lines already in the cart, and add with WooCommerce's own notices. `cart_item_data` is restored only for keys allow-listed by `recoveryflow_wc_restore_cart_item_data` (default none). Coupons are re-applied from metadata. Guest billing name, email, phone and country are prefilled (setting, default on), never for a logged-in user. The restore refuses if the logged-in user differs from the journey's user. Only the clicker's own session is touched |
| Rules | Inactivity 30 minutes, maximum age 7 days, minimum amount 0. Global settings override |

## Gravity Forms

Requires Gravity Forms 2.4 or later, and a WA.cr plan that includes integrations beyond WooCommerce. It recovers two things, deliberately unalike, because a second adapter that recovered another kind of basket would prove nothing about the abstraction.

| Question | Gravity Forms |
| --- | --- |
| **1. What is recoverable?** | A **save-and-continue draft** (`source_type` `form`), from the moment it is saved until it is resumed and submitted or Gravity Forms purges it; and an **entry whose payment never arrived** (`source_type` `payment`) -- `payment_status` present and not one of `Paid`, `Active`, `Approved`, `Authorized`. Entries marked spam or trash are excluded, as are forms with neither a phone nor an email field |
| **2. How is the customer identified?** | By field **type**, read off the form's own definition: the first `email`, `phone`, `name` and `address` field. Gravity Forms has no fixed key for any of them and the merchant may rename every label, so the type -- which Gravity Forms owns -- is the only stable thing to read. A logged-in submitter's `created_by` is used when there is one. Pin a specific field with `recoveryflow_gf_field_overrides`. **A phone field has two storage shapes** -- see below |
| **3. When is it abandoned?** | A draft the moment it is saved: unlike a quiet basket, the person has said out loud that they are coming back later. An unpaid entry after the site's inactivity threshold. Maximum age defaults to **7 days**, because Gravity Forms purges drafts after 30 by default and the resume token dies with the row |
| **4. How is completion detected?** | A draft, by the submission that consumes its resume token (`gform_post_submission`, reading `gform_resume_token` from the request -- which is how Gravity Forms finds the draft to delete). An entry, by `gform_post_payment_completed` or any `gform_post_payment_action` reporting a paid status, each claiming a receipt first so a redelivered gateway callback cannot be counted twice. Both re-checked from live state before every send |
| **5. Where do we send the customer?** | Nothing is restored. Gravity Forms holds the half-finished form itself and hands it back on its own resume link, which is far better than this plugin rebuilding somebody's answers from a snapshot. A draft goes to its form page with `gf_token` on it; an entry goes back to the page it was submitted from |
| **6. What value information exists?** | `payment_amount` and `currency` where the form takes money, and the form's title as the single line item. A draft usually has no amount at all, which is why the minimum-amount rule defaults to nothing here |
| **7. What consent constraints apply?** | The same as everywhere else, with one difference that matters: **Gravity Forms has no checkout and RecoveryFlow adds no consent field to it.** In `explicit_consent` mode a form must carry the merchant's own consent question. Until it does, this source identifies people it is not allowed to message -- which is the correct failure, and is stated on the Integrations screen rather than left to be discovered |

### The two shapes of a phone number

Up to Gravity Forms 2.9 a phone field stored a plain string. Gravity Forms 3.0
added an international phone, and a field set to that format does not store a
number at all -- it stores a JSON document:

```json
{"country":"gb","national":"07700 900123","formatted":"+44 7700 900123","e164":"+447700900123"}
```

RecoveryFlow reads both. It takes `e164` where there is one, because Gravity
Forms has already validated it against E.164 on the way in, and otherwise falls
back through `formatted` and `national` in the order Gravity Forms itself uses.
The document's `country` is used as the hint for normalising -- but only when
the form has no address field to answer that, so a form asking both behaves as
it always did.

**What this looked like before it was handled** is worth recording, because the
failure was silent and the shape is easy to meet again in another integration.
The whole document was handed to the phone normaliser, which refused it -- the
right refusal, since guessing a number out of that text is how somebody else's
reminder reaches a stranger. But a refused number is indistinguishable from a
blank one, so every entry from an international phone field looked like an entry
from somebody who had not given a number. The form still counts as messageable,
because the field is there, so journeys were created for people no message could
ever reach.

The **shape** is what is recognised, never the Gravity Forms version. The format
is chosen per field, a form can be edited to change it, and one site can hold
entries recorded under both -- so a version test would be wrong for the entries
on one side of the change.

### Its own settings

On **Settings &rsaquo; Integrations &rsaquo; Gravity Forms**:

| Setting | Default | Means |
| --- | --- | --- |
| Remind people who saved a form to finish later | On | Watch save-and-continue drafts |
| Remind people whose payment never went through | On | Watch unpaid entries, live and by backfill |
| Only these forms | Empty | Form numbers separated by commas. Empty means every form |

### Why it polls

It is the first source to implement `Pollable_Source_Interface`, and the reason is not a demonstration. Hooks only ever tell you about the future: a merchant installing RecoveryFlow onto a site with four hundred unpaid entries would get nothing from any of them, and the same hole opens every time the integration is switched off and on again. So the Evaluate pass also reads entries created since a stored cursor -- `date_created`, ascending, active only -- and applies the identical rule the submission hook applies, from the same class. A backfill that applied a looser rule would chase exactly the people the live path had decided to leave alone.

The first poll of a site looks back 30 days (`recoveryflow_gf_first_look_days`). Reading the whole history would pay to load every row so that the maximum-age rule could refuse nearly all of it.

### Filters

| Filter | Purpose |
| --- | --- |
| `recoveryflow_gf_paid_statuses` | The `payment_status` values that count as a completed sale. Stated as a list rather than "anything that is not Failed", because a negative rule would call every status a future add-on invents money received |
| `recoveryflow_gf_field_overrides` | Pins which field holds a contact detail, keyed by Gravity Forms field type. For a form with a work number and a mobile |
| `recoveryflow_gf_first_look_days` | How far back the first poll of a site reads |

### Deliberately not done

A **refund does not restart a recovery**. `gform_post_payment_refunded` is not handled: a journey that reached `RECOVERED` is finished, and reopening it would message somebody who has just been given their money back.

## Planned adapters

Nothing below exists yet. Each will be built on the interface above with zero changes to the core, which is the test Gravity Forms has already passed: nothing outside `src/Integration/` mentions Gravity Forms, and the test suite asserts it.

| Source | Journey type | Status |
| --- | --- | --- |
| **Tickera** | Unpaid ticket orders (`ticket`) | Planned |
| **Event Tickets** | Unpaid ticket orders and RSVPs (`ticket`) | Planned |
| **Easy Digital Downloads** | Abandoned checkouts (`checkout`) | Planned |
| **Custom** | Anything with a hook or a table to poll | Available now through `recoveryflow_register_sources`. A worked example is in [`developer-api.md`](developer-api.md#a-minimal-custom-source) |

## Sources with nothing to push from

Most plugins fire hooks, and a source built on them costs nothing until something happens. Some do not -- an external booking system, a plugin that writes straight to its own tables -- and the only way to find an abandoned journey is to go and look for one. Those implement `Pollable_Source_Interface`, and the Evaluate stage asks them for a bounded page on every tick:

```php
public function detect_recovery_events( int $limit, ?string $cursor ): Event_Batch;
```

| Rule | Why |
| --- | --- |
| Return at most `$limit` drafts, and never scan unboundedly | The stage shares one time budget with the four that follow it. A poll that reads a whole table takes its time out of the evaluation that turns those very drafts into journeys |
| The cursor is opaque to the core | A row id, a timestamp or an API page token -- only the source needs to understand which. It is stored in the `recoveryflow_source_cursors` option, with autoload off, and capped at 500 characters |
| Return `null` as the cursor when there is no more to read | The stored position is dropped and the next poll starts from the beginning |
| Set `has_more` when a full page was returned | The stage records a backlog and the tick comes back for the rest, which is how a hundred thousand unread rows drain across successive runs |
| Every draft must carry your own `source_id` | `(source_id, dedupe_key)` is UNIQUE, so a draft filed under another source's id would upsert onto its row. Drafts that do are dropped |

The cursor is advanced only after the batch has been ingested. A source that throws is dropped for that run and keeps its stored position, so one broken integration neither stops the others nor loses its place.
