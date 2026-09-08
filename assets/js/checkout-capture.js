/**
 * Ask WooCommerce to re-read the classic checkout once the shopper has given
 * us something worth reading.
 *
 * WooCommerce only ever hands the classic checkout form to the server during
 * its update_order_review AJAX call, and checkout.js asks for that call from a
 * fixed list of selectors: address fields, and anything inside a
 * .update_totals_on_change container. WooCommerce declares billing_phone and
 * billing_email as plain form-row-wide, so they are in neither list. Typing a
 * phone number therefore fires nothing at all, and the only certain read of the
 * form happens at Place Order -- by which time a shopper who abandoned is gone,
 * along with the number they had already typed.
 *
 * So this file exists to close one gap and no more: when a shopper finishes
 * with the phone or email field, tell WooCommerce to do the round trip it
 * already knows how to do. It is deliberately not a capture mechanism of its
 * own -- it opens no endpoint, sends no data anywhere, and carries no nonce,
 * because it never talks to the server itself. It triggers WooCommerce's own
 * event and WooCommerce's own request, on WooCommerce's own nonce, and the
 * plugin reads the result from the hook it was already listening on.
 *
 * If this script never loads, the checkout behaves exactly as it did before it
 * existed. Consent given through the tick-box is still captured without it,
 * because that field carries the update_totals_on_change class and core fires
 * the same round trip on its own.
 *
 * @package WAcr\RecoveryFlow
 */

( function ( $ ) {
	'use strict';

	// checkout.js is a jQuery plugin and update_checkout is a jQuery event, so
	// without jQuery there is nothing here to hook into and nothing to fix.
	if ( ! $ ) {
		return;
	}

	/**
	 * The fields worth a round trip: the two that identify a person and that
	 * WooCommerce does not already watch. Address fields are left alone --
	 * core watches those itself, and duplicating it would double the requests.
	 */
	var FIELDS = '#billing_phone, #billing_email, #shipping_phone';

	/**
	 * The last value we told the server about, per field id.
	 *
	 * Deliberately NOT seeded from the form on load. A value the browser
	 * autofilled has never reached the server, so the first time the shopper
	 * leaves that field it is news, and treating it as already-known would lose
	 * exactly the guest whose browser knows their number.
	 */
	var sent = {};

	/**
	 * Tell WooCommerce to re-read the form, if this field has something new.
	 *
	 * @param {HTMLElement} field The field the shopper just left.
	 */
	function maybeUpdate( field ) {
		if ( ! field || ! field.id ) {
			return;
		}

		var value = $.trim( field.value || '' );

		// An empty field tells us nothing we do not already assume, and a
		// shopper tabbing through an untouched checkout must not generate a
		// request per field.
		if ( '' === value ) {
			return;
		}

		// Re-blurring an unchanged field is the common case -- clicking away
		// and back again -- and it is not worth a round trip.
		if ( sent[ field.id ] === value ) {
			return;
		}

		sent[ field.id ] = value;

		$( document.body ).trigger( 'update_checkout' );
	}

	$( function () {
		var $form = $( 'form.checkout' );

		// The block checkout has no such form. Nothing here applies to it, and
		// it does not need this: the Store API writes its address-location
		// fields to the customer as the shopper types.
		if ( ! $form.length ) {
			return;
		}

		// Delegated, because WooCommerce replaces parts of the checkout after
		// every update and a directly bound handler would not survive it.
		//
		// 'change' fires for an autofilled or pasted value that never receives
		// a blur; 'blur' catches the shopper who types and then clicks away
		// without committing. Both funnel through the same guard, so the pair
		// costs at most one request per genuinely new value.
		$form.on( 'blur change', FIELDS, function () {
			maybeUpdate( this );
		} );
	} );
} )( window.jQuery );
