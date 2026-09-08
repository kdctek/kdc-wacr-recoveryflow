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
| `get_settings_fields()` | Integrations card | Field definitions in the same shape as the Settings schema |
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

### Sending events into the core

Sources never process anything inline. They build an `Event_Draft` and hand it to `Event_Ingest::upsert()`, which performs one `INSERT … ON DUPLICATE KEY UPDATE` on `(source_id, dedupe_key)`. Everything else, identity resolution, eligibility, scheduling, sending and polling, happens in the background stages described in [`architecture.md`](architecture.md#background-processing). Two rules the ingest enforces:

- `metadata` must not contain personal data. The Redactor strips it and logs a warning.
- Contact hints (phone, email, names, country) are passed as identity hints, not stored on the event. The event only ever carries the resolved `customer_id`.

## The seven questions every adapter answers

Every source answers these in its class docblock and in this document. For WooCommerce:

| Question | WooCommerce |
| --- | --- |
| **1. What is recoverable?** | A cart with at least one line and a non-zero total, from the first item added until an order is placed or the cart is emptied. Classic and block-based carts and checkouts are the same event (`source_type` `cart` or `checkout`). Carts belonging to users with `manage_woocommerce`, zero-amount carts, JSON pings and test-mode orders are excluded |
| **2. How is the customer identified?** | Logged-in customers: WordPress user id plus billing details from the customer object, read once per session. Guests: billing phone, email, first and last name and country captured server-side as the customer fills in the classic checkout, or from the Store API customer update on the block checkout. No JavaScript, no public endpoint. The contact snapshot lives in the WooCommerce session and identity is resolved only when it changes |
| **3. When is it abandoned?** | After 30 minutes without a cart change (adapter default; the site setting overrides). Events older than seven days expire without a journey |
| **4. How is completion detected?** | Order placed (classic, Store API or `woocommerce_new_order`) moves the journey to `PENDING_PAYMENT`. A paid status or `on-hold` (setting `recovered_statuses`) moves it to `RECOVERED`. A failed or cancelled payment resumes the journey once. Orders are matched by the journey uid and event uid stamped on the order at creation, then by session key, then by customer within 30 days |
| **5. Where do we send the customer?** | `/recovery/{token}` restores the cart into the clicker's own session, merging with what is there, prefills guest billing details, and 302s to the checkout URL (the cart URL if nothing could be restored) |
| **6. What value information exists?** | Currency, total, item count and a line-item snapshot (name, SKU, quantity, unit and line amounts, product and variation ids and attributes), plus applied coupon codes in metadata. Never addresses |
| **7. What consent constraints apply?** | In `explicit_consent` mode an unchecked checkbox naming the site and WhatsApp is added after the phone field on the classic checkout and as an additional checkout field on the block checkout, and the wording version is recorded with the consent. Opt-outs from any channel are honoured in every mode |

## WooCommerce in detail

Requires WooCommerce 8.0 or later. The plugin declares compatibility with High-Performance Order Storage and the cart and checkout blocks on `before_woocommerce_init`.

| Concern | How it works |
| --- | --- |
| Cart activity | `woocommerce_cart_updated` (fires once per mutating request after totals, including Store API requests) sets a dirty flag; `woocommerce_cart_emptied` flags an emptied cart. One write happens on `shutdown`, after WooCommerce has set its cookies: snapshot the items, amount and currency, skip if the fingerprint is unchanged, throttle to one write per 60 seconds per session unless the contact changed or the customer is on checkout, then upsert. Errors are logged, never thrown into the customer's request. An empty cart closes the event as `invalid` with reason `cart_emptied`. No per-item hooks are used |
| Guest identification | Classic checkout: `woocommerce_checkout_update_order_review` carries the posted billing fields as the customer types, so a guest is identified before they ever press Place Order. Only billing phone, email, first and last name, country and shipping country are kept, sanitised and capped at 100 characters. Block checkout: `woocommerce_store_api_cart_update_customer_from_request` and `…_checkout_update_customer_from_request`. Logged-in: once per session on `woocommerce_cart_loaded_from_session` |
| Consent field | Classic: appended to the billing fields at priority 115 (after phone) and read from the posted data. Blocks: registered with `woocommerce_register_additional_checkout_field` as a checkbox in the contact step with id `recoveryflow/whatsapp-consent`. Shown only in `explicit_consent` mode |
| Order stamping | `woocommerce_checkout_create_order` (before WooCommerce's own save, so no extra write) and the Store API equivalent stamp `_recoveryflow_session_key`, `_recoveryflow_event_uid` and, after a restore, `_recoveryflow_journey_uid` through `update_meta_data()`, which is HPOS-safe |
| Conversion | Every handler first claims a receipt `wc_order:{id}:{status}`; every write is compare-and-set on the journey status. A customer-level match always stops the person's active journeys, but only `exact` and `session` attributions count as recovered revenue unless customer-level attribution is enabled |
| Restore | For each snapshot line, load the variation or product, skip missing, non-purchasable or out-of-stock items, clamp to the maximum purchase quantity, skip lines already in the cart, and add with WooCommerce's own notices. `cart_item_data` is restored only for keys allow-listed by `recoveryflow_wc_restore_cart_item_data` (default none). Coupons are re-applied from metadata. Guest billing name, email, phone and country are prefilled (setting, default on), never for a logged-in user. The restore refuses if the logged-in user differs from the journey's user. Only the clicker's own session is touched |
| Rules | Inactivity 30 minutes, maximum age 7 days, minimum amount 0. Global settings override |

## Planned adapters

Nothing below exists yet. Each will be built on the interface above with zero changes to the core, which is the test that the abstraction holds.

| Source | Journey type | Status |
| --- | --- | --- |
| **Gravity Forms** | Partially completed or unpaid form submissions (`form`, `payment`) | Planned for slice 3. This is the adapter that proves the abstraction, so it lands together with the final custom-source example and WP-CLI |
| **Tickera** | Unpaid ticket orders (`ticket`) | Planned |
| **Event Tickets** | Unpaid ticket orders and RSVPs (`ticket`) | Planned |
| **Easy Digital Downloads** | Abandoned checkouts (`checkout`) | Planned |
| **Custom** | Anything with a hook or a table to poll | Available now through `recoveryflow_register_sources`; the documented example class ships with slice 3 |

Systems that expose no hooks at all can implement `Pollable_Source_Interface`; the Evaluate stage calls `detect_recovery_events()` with a cursor so a large backlog drains across successive runs.
