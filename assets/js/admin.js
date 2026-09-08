/**
 * RecoveryFlow admin enhancements.
 *
 * Everything here is an enhancement of markup the server has already sent. With
 * this file blocked, missing or broken the settings screen still renders every
 * setting, still saves, and still scrolls to a deeplinked field -- the anchor in
 * the URL does that on its own. What is added is the part a page load cannot do:
 * moving keyboard focus to the control somebody was sent to, announcing that it
 * happened, hiding settings that do not currently apply, and copying a link.
 *
 * The script carries no user-facing text of its own. Every sentence it can
 * announce is passed in from PHP, already translated, so the plugin's whole
 * translatable surface stays in the .pot the PHP generator produces. That is a
 * deliberate constraint, not an oversight: one string here would require a
 * JavaScript i18n build and a second translation pipeline for the rest of the
 * plugin's life.
 */
( function () {
	'use strict';

	var config = window.recoveryFlowAdmin || {};
	var strings = config.strings || {};

	/**
	 * Say something to a screen reader without moving anything on screen.
	 *
	 * @param {string} message What to announce.
	 */
	function announce( message ) {
		if ( message && window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message );
		}
	}

	/**
	 * Put the cursor in the control a deeplink named.
	 *
	 * The browser has already scrolled to it via the URL fragment. What it has
	 * not done is move focus, so a keyboard user who followed "go to this
	 * setting" would land at the top of the document and have to tab back down
	 * through the whole form to reach the thing they asked for.
	 *
	 * preventScroll keeps the browser from jumping a second time, which
	 * otherwise reads as the page twitching.
	 */
	function focusDeeplinkedField() {
		if ( ! config.focusField ) {
			return;
		}

		var id = ( config.fieldPrefix || '' ) + config.focusField.replace( /_/g, '-' );
		var field = document.getElementById( id );

		if ( ! field ) {
			return;
		}

		// A field inside a collapsed panel has to be opened before it can be
		// focused, or focus lands on something with no dimensions.
		var panel = field.closest( 'details' );

		if ( panel ) {
			panel.open = true;
		}

		try {
			field.focus( { preventScroll: true } );
		} catch ( e ) {
			field.focus();
		}

		announce( strings.focused );
	}

	/**
	 * Hide settings that do not currently apply.
	 *
	 * The condition is written on the row as data-requires, either as a field
	 * name ("shown while that box is ticked") or as field:value ("shown while
	 * that control holds this value").
	 *
	 * Rows are hidden with the hidden attribute rather than a class, so they
	 * leave the accessibility tree as well as the layout. A row that is merely
	 * invisible is still read out, and still receives focus on the way past.
	 */
	function bindConditionalRows() {
		var rows = document.querySelectorAll( '[data-requires]' );

		if ( ! rows.length ) {
			return;
		}

		var watched = [];

		function controlsNamed( name ) {
			return document.querySelectorAll(
				'[name$="[' + name + ']"]'
			);
		}

		function currentValue( name ) {
			var controls = controlsNamed( name );
			var value = null;

			Array.prototype.forEach.call( controls, function ( control ) {
				if ( control.type === 'checkbox' ) {
					value = control.checked ? '1' : '';
				} else if ( control.type === 'radio' ) {
					if ( control.checked ) {
						value = control.value;
					}
				} else {
					value = control.value;
				}
			} );

			return value;
		}

		function apply() {
			Array.prototype.forEach.call( rows, function ( row ) {
				var parts = row.getAttribute( 'data-requires' ).split( ':' );
				var name = parts[ 0 ];
				var wanted = parts.length > 1 ? parts.slice( 1 ).join( ':' ) : null;
				var value = currentValue( name );

				if ( value === null ) {
					return;
				}

				row.hidden = wanted === null ? value === '' : value !== wanted;
			} );
		}

		Array.prototype.forEach.call( rows, function ( row ) {
			var name = row.getAttribute( 'data-requires' ).split( ':' )[ 0 ];

			if ( watched.indexOf( name ) !== -1 ) {
				return;
			}

			watched.push( name );

			Array.prototype.forEach.call( controlsNamed( name ), function ( control ) {
				control.addEventListener( 'change', apply );
			} );
		} );

		apply();
	}

	/**
	 * Ask before ticking a box whose consequence is not reversible.
	 *
	 * Only on the way on. Unticking a destructive option needs no confirming --
	 * error prevention should never stand between somebody and safety.
	 */
	function bindConfirmations() {
		var boxes = document.querySelectorAll( 'input[type="checkbox"][data-confirm]' );

		Array.prototype.forEach.call( boxes, function ( box ) {
			box.addEventListener( 'click', function ( event ) {
				if ( ! box.checked ) {
					return;
				}

				if ( ! window.confirm( box.getAttribute( 'data-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	/**
	 * Turn a section's permalink into a copy-to-clipboard button.
	 *
	 * It stays a real link underneath, so middle-click, right-click and
	 * open-in-new-tab keep working, and a browser without clipboard access --
	 * or a page not served over HTTPS, where the API is unavailable -- falls
	 * back to simply following it.
	 */
	function bindCopyLinks() {
		var links = document.querySelectorAll( '[data-copy-link]' );

		Array.prototype.forEach.call( links, function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) {
					return;
				}

				event.preventDefault();

				navigator.clipboard.writeText( link.href ).then(
					function () {
						announce( strings.linkCopied );
					},
					function () {
						announce( strings.linkFailed );
					}
				);
			} );
		} );
	}

	/**
	 * Remember which expandable panels somebody had open.
	 *
	 * Per browser, in localStorage. It is a convenience about the shape of a
	 * screen rather than anything about the site, so it belongs in the browser
	 * that is displaying it; storage being unavailable -- a private window, a
	 * browser set to block it -- simply means the panels start closed.
	 */
	function bindPanelMemory() {
		var panels = document.querySelectorAll( 'details[data-remember]' );
		var key = 'recoveryflow-panels';
		var open = {};

		try {
			open = JSON.parse( window.localStorage.getItem( key ) || '{}' ) || {};
		} catch ( e ) {
			open = {};
		}

		Array.prototype.forEach.call( panels, function ( panel ) {
			var id = panel.getAttribute( 'data-remember' );

			if ( open[ id ] ) {
				panel.open = true;
			}

			panel.addEventListener( 'toggle', function () {
				open[ id ] = panel.open;

				try {
					window.localStorage.setItem( key, JSON.stringify( open ) );
				} catch ( e ) {
					// Nothing to do: the panel still works, it just will not be
					// remembered.
				}
			} );
		} );
	}

	function init() {
		bindConditionalRows();
		bindConfirmations();
		bindCopyLinks();
		bindPanelMemory();
		focusDeeplinkedField();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
