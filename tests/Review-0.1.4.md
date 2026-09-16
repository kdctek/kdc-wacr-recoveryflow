# WordPress.org Review — 0.1.4

This release addresses the review finding in the WooCommerce early-capture path.

The add-to-cart capture fields are rendered inside WooCommerce's existing form and now carry a dedicated RecoveryFlow nonce. `capture_add_to_cart()` verifies that nonce before RecoveryFlow accepts the submitted contact details or consent. A missing or invalid RecoveryFlow nonce is ignored by RecoveryFlow and does not block WooCommerce from completing its normal add-to-cart operation.

The basket-page capture already used WordPress nonce verification and remains unchanged.

The plugin version, `Stable tag`, changelog and upgrade notice have been updated to 0.1.4.
