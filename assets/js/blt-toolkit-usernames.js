/**
 * BLT Toolkit: change a username from the profile screen (SPEC 8.4, UN-16).
 *
 * BLT_Toolkit_Username_Profile prints a "Change username" section inside
 * core's profile form that works without this file: type a new username and
 * save the profile. This script only makes it tidier:
 *
 * - the field (with its label, help text and any warning or password
 *   field) moves into core's Username row, behind a "Change" button with
 *   aria-expanded, and the now-empty section is hidden; core's "Usernames
 *   cannot be changed." note in that row is hidden, because here they can;
 * - "Cancel" empties the field and hides it again, so saving the profile
 *   keeps the username;
 * - as the person types, GET blt-toolkit/v1/usernames/check says whether the
 *   name would pass (debounced, stale answers ignored). It is advisory: the
 *   server validates again when the profile is saved and decides;
 * - a link to #blt-tk-username (the Users list "Change username" action,
 *   UN-19) opens the field and moves focus to it.
 *
 * The field stays inside the profile form throughout: there is no form of
 * its own (SPEC 19, UC-8). Settings and translated strings come from
 * window.bltToolkit.usernames. Server text is put on the page with
 * textContent, never as HTML.
 */
( function () {
	'use strict';

	var config = window.bltToolkit && window.bltToolkit.usernames;

	if ( ! config ) {
		return;
	}

	var strings = config.strings || {};
	var delay = 400;

	/**
	 * Fill a printf-style template that has one %s.
	 *
	 * @param {string} template Template.
	 * @param {*}      value    Value.
	 * @return {string} Text.
	 */
	function format( template, value ) {
		return String( template || '' ).replace( /%(?:1\$)?s/, String( value ) );
	}

	/**
	 * Set up the field once the page is parsed.
	 *
	 * @return {void}
	 */
	function init() {
		var section = document.getElementById( config.anchor || 'blt-tk-username' );
		var field = document.getElementById( 'blt-tk-username-field' );
		var input = document.getElementById( 'blt-tk-new-user-login' );
		var label = document.getElementById( 'blt-tk-new-user-login-label' );
		var status = document.getElementById( 'blt-tk-new-user-login-status' );
		var core = document.getElementById( 'user_login' );
		var row = core ? core.closest( 'tr' ) : null;
		var cell = core ? core.closest( 'td' ) : null;

		if ( ! section || ! field || ! input || ! status || ! row || ! cell ) {
			return;
		}

		// Core's note says usernames cannot be changed; here they can.
		var note = cell.querySelector( '.description' );

		if ( note ) {
			note.hidden = true;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button blt-tk-username-toggle';
		button.textContent = strings.change || 'Change';
		button.setAttribute( 'aria-expanded', 'false' );
		button.setAttribute( 'aria-controls', field.id );

		if ( strings.changeFor ) {
			button.setAttribute( 'aria-label', strings.changeFor );
		}

		core.insertAdjacentText( 'afterend', ' ' );
		core.parentNode.insertBefore( button, core.nextSibling.nextSibling );

		// The label travels with the field, so the input keeps its name.
		if ( label ) {
			var holder = document.createElement( 'p' );
			holder.className = 'blt-tk-username-label';
			holder.appendChild( label );
			field.insertBefore( holder, field.firstChild );
		}

		cell.appendChild( field );
		section.hidden = true;

		var timer = null;
		var controller = null;
		var sequence = 0;

		/**
		 * Show or hide the field.
		 *
		 * @param {boolean} open  Whether to show it.
		 * @param {boolean} focus Whether to move focus into it.
		 * @return {void}
		 */
		function setOpen( open, focus ) {
			field.hidden = ! open;
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			button.textContent = open ? ( strings.cancel || 'Cancel' ) : ( strings.change || 'Change' );

			if ( open ) {
				if ( strings.changeFor ) {
					button.removeAttribute( 'aria-label' );
				}

				if ( focus ) {
					input.focus();
				}

				return;
			}

			if ( strings.changeFor ) {
				button.setAttribute( 'aria-label', strings.changeFor );
			}

			// Hidden means "keep the username": nothing is submitted.
			input.value = '';
			clearTimeout( timer );
			cancelRequest();
			say( '', '' );
		}

		/**
		 * Write the status line.
		 *
		 * @param {string} text  Message.
		 * @param {string} state `valid`, `invalid` or ''.
		 * @return {void}
		 */
		function say( text, state ) {
			status.textContent = text;
			status.className = 'blt-tk-username-status' + ( state ? ' is-' + state : '' );

			if ( 'invalid' === state ) {
				input.setAttribute( 'aria-invalid', 'true' );
			} else {
				input.removeAttribute( 'aria-invalid' );
			}
		}

		/**
		 * Abort a check in flight.
		 *
		 * @return {void}
		 */
		function cancelRequest() {
			if ( controller ) {
				controller.abort();
				controller = null;
			}
		}

		/**
		 * Ask the server about the typed name (advisory).
		 *
		 * @param {string} name Trimmed name.
		 * @return {void}
		 */
		function check( name ) {
			if ( ! config.root || 'function' !== typeof window.fetch ) {
				return;
			}

			cancelRequest();

			var mine = ++sequence;
			var url;

			try {
				url = new URL( config.root, window.location.href );
			} catch ( e ) {
				return;
			}

			url.searchParams.set( 'user_id', String( config.userId ) );
			url.searchParams.set( 'username', name );

			controller = 'function' === typeof window.AbortController ? new window.AbortController() : null;

			say( strings.checking || '', '' );

			window.fetch( url.toString(), {
				method: 'GET',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce || '', Accept: 'application/json' },
				signal: controller ? controller.signal : undefined,
			} )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} )
				.then( function ( result ) {
					if ( mine !== sequence ) {
						return;
					}

					var body = result.body || {};

					if ( ! result.ok || 'boolean' !== typeof body.valid ) {
						say( strings.failed || '', '' );
						return;
					}

					if ( body.valid ) {
						say( format( strings.available, body.username || name ), 'valid' );
						return;
					}

					var text = String( body.message || '' );

					if ( body.suggestion && strings.tryThis ) {
						text += ' ' + format( strings.tryThis, body.suggestion );
					}

					say( text, 'invalid' );
				} )
				.catch( function ( error ) {
					if ( error && 'AbortError' === error.name ) {
						return;
					}

					if ( mine === sequence ) {
						say( strings.failed || '', '' );
					}
				} );
		}

		button.addEventListener( 'click', function () {
			setOpen( field.hidden, true );
		} );

		input.addEventListener( 'input', function () {
			var name = input.value.trim();

			clearTimeout( timer );
			cancelRequest();
			sequence++;

			if ( '' === name || name === config.current ) {
				say( '', '' );
				return;
			}

			timer = setTimeout( function () {
				check( name );
			}, delay );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! field.hidden ) {
				event.preventDefault();
				setOpen( false, false );
				button.focus();
			}
		} );

		// A name typed before (the form came back with an error) or a link to
		// the section opens the field.
		var linked = window.location.hash === '#' + ( config.anchor || 'blt-tk-username' );

		if ( '' !== input.value || linked || field.querySelector( 'input[type="checkbox"]:checked' ) ) {
			setOpen( true, linked );

			if ( linked ) {
				row.scrollIntoView( { block: 'center' } );
			}
		} else {
			field.hidden = true;
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
