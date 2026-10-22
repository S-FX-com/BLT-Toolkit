/**
 * BLT Toolkit: Roles screen enhancements (SPEC 9.3, RO-26).
 *
 * Everything on the Roles screen works without this file: the matrix is a
 * plain form, the toolbar is a GET form and every review is its own page.
 * With it:
 *
 * - the save bar counts unsaved changes in a polite live region;
 * - Discard puts every control back as it was drawn, without a reload;
 * - leaving the page (a link, a tab, a reload) with unsaved changes asks
 *   first (`beforeunload`), which also covers RS-2's view switch;
 * - the quick filter and "Granted only" hide rows as you type or toggle,
 *   and say how many rows are shown, in the status line (a polite live
 *   region) and the card's "Shown" badge; hidden rows stay in the form
 *   untouched, so they are saved exactly as they were drawn (RO-1);
 * - nothing reloads the page while a control changes (WCAG 3.2.2): Show
 *   deprecated, Readable names and Columns, and rows the server left out,
 *   take effect with Apply (or Enter);
 * - messages with a number are worded so one form fits every count
 *   ("Unsaved changes: 3."), since the browser cannot pick a language's
 *   plural form;
 * - "Test as…" can search for other holders of the role through the
 *   toolbar search's REST route (SW-20) with its `role` argument, so the
 *   server looks only among the role's holders, each linked to their own
 *   nonced switch URL with `redirect_to` set to the dashboard (X-3).
 *
 * Settings and strings come from window.bltToolkit.roles. Every value from
 * the server is put on the page with textContent or as an attribute value,
 * never as HTML.
 */
( function () {
	'use strict';

	var config = ( window.bltToolkit && window.bltToolkit.roles ) || {};
	var strings = config.strings || {};

	/**
	 * Fill a printf-style template: %s, %1$s, %2$s.
	 *
	 * @param {string} template Template.
	 * @param {Array}  values   Values, in order.
	 * @return {string} Text.
	 */
	function format( template, values ) {
		var index = 0;

		return String( template || '' ).replace( /%(?:(\d)\$)?s/g, function ( match, position ) {
			var value = position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ];

			return undefined === value ? '' : String( value );
		} );
	}

	/**
	 * A control's value as drawn or now: checkboxes by checked state,
	 * selects and other fields by value.
	 *
	 * @param {HTMLElement} field Form control.
	 * @return {string} Value.
	 */
	function valueOf( field ) {
		if ( 'checkbox' === field.type || 'radio' === field.type ) {
			return field.checked ? '1' : '0';
		}

		return String( field.value );
	}

	/**
	 * The editable controls of a matrix form: checkboxes and selects that
	 * are not disabled.
	 *
	 * @param {HTMLFormElement} form Form.
	 * @return {HTMLElement[]} Controls.
	 */
	function controls( form ) {
		var out = [];

		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( field.disabled ) {
				return;
			}

			if ( 'checkbox' === field.type || 'SELECT' === field.tagName ) {
				out.push( field );
			}
		} );

		return out;
	}

	/**
	 * Set up one matrix form with its save bar.
	 *
	 * @param {HTMLFormElement} form Form.
	 */
	function setUpMatrix( form ) {
		var wrap = form.closest( '[data-blt-matrix-wrap]' ) || document;
		var count = wrap.querySelector( '[data-blt-count]' );
		var discard = wrap.querySelector( '[data-blt-discard]' );
		var fields = controls( form );
		var initial = fields.map( valueOf );
		var submitting = false;
		var dirty = 0;

		/**
		 * Count the controls that differ from how they were drawn.
		 */
		function update() {
			dirty = 0;

			fields.forEach( function ( field, index ) {
				if ( valueOf( field ) !== initial[ index ] ) {
					dirty++;
				}
			} );

			if ( count ) {
				count.textContent = 0 === dirty ? strings.noChanges || '' : format( strings.changes, [ dirty ] );
			}

			if ( wrap.classList ) {
				wrap.classList.toggle( 'is-dirty', dirty > 0 );
			}
		}

		form.addEventListener( 'change', update );

		form.addEventListener( 'submit', function () {
			submitting = true;
		} );

		if ( discard ) {
			discard.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				fields.forEach( function ( field, index ) {
					if ( 'checkbox' === field.type ) {
						field.checked = '1' === initial[ index ];
					} else {
						field.value = initial[ index ];
					}
				} );

				update();

				if ( count && strings.discarded ) {
					count.textContent = strings.discarded;
				}
			} );
		}

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( submitting || 0 === dirty ) {
				return undefined;
			}

			event.preventDefault();
			event.returnValue = '';

			return '';
		} );

		update();
	}

	/**
	 * Instant quick filter and "Granted only" for a matrix (RO-26). The
	 * toolbar still submits as a GET form on Enter or Apply, and only then:
	 * no control reloads the page as its value changes (WCAG 3.2.2).
	 *
	 * @param {HTMLFormElement} toolbar The toolbar form.
	 */
	function setUpToolbar( toolbar ) {
		var card = toolbar.closest( '.blt-toolkit-matrix-card' );
		var filter = toolbar.querySelector( '[data-blt-filter]' );
		var granted = toolbar.querySelector( 'input[name="granted"][type="checkbox"]' );

		if ( ! card ) {
			return;
		}

		var rows = Array.prototype.slice.call( card.querySelectorAll( '[data-blt-cap]' ) );
		var sections = Array.prototype.slice.call( card.querySelectorAll( '[data-blt-section]' ) );
		var status = card.querySelector( '[data-blt-filter-status]' );
		var badge = card.querySelector( '[data-blt-shown]' );
		var total = rows.length;

		// What the server already filtered by: rows it left out are not on
		// the page, so only a narrower filter can apply here.
		var serverTerm = filter ? filter.defaultValue.trim().toLowerCase() : '';
		var serverGranted = granted ? granted.defaultChecked : false;

		/**
		 * Whether a row is granted now: its toggle is on, or (user mode) the
		 * user holds the capability.
		 *
		 * @param {HTMLElement} row Row.
		 * @return {boolean} Granted.
		 */
		function isGranted( row ) {
			var box = row.querySelector( 'input[type="checkbox"]' );

			if ( box ) {
				return box.checked;
			}

			return '1' === row.getAttribute( 'data-granted' );
		}

		/**
		 * Show the rows that match.
		 */
		function apply() {
			var term = filter ? filter.value.trim().toLowerCase() : '';
			var onlyGranted = granted ? granted.checked : false;
			var shown = 0;

			rows.forEach( function ( row ) {
				var text = row.getAttribute( 'data-search' ) || '';
				var visible = ( '' === term || -1 !== text.indexOf( term ) ) && ( ! onlyGranted || isGranted( row ) );

				row.hidden = ! visible;

				if ( visible ) {
					shown++;
				}
			} );

			sections.forEach( function ( section ) {
				section.hidden = ! section.querySelector( '[data-blt-cap]:not([hidden])' );
			} );

			if ( badge ) {
				badge.textContent = format( strings.shownBadge, [ shown ] );
			}

			if ( status ) {
				if ( '' !== serverTerm && -1 === term.indexOf( serverTerm ) ) {
					// Rows the server left out come back only with Apply.
					status.textContent = strings.pressEnter || '';
				} else if ( serverGranted && ! onlyGranted ) {
					status.textContent = strings.pressApply || '';
				} else {
					status.textContent = shown === total ? '' : format( strings.shown, [ shown, total ] );
				}
			}
		}

		if ( filter ) {
			filter.addEventListener( 'input', apply );
		}

		if ( granted ) {
			granted.addEventListener( 'change', apply );
		}
	}

	/**
	 * "Test as…" search (X-3 with SW-20): find holders of the role the
	 * select does not list.
	 *
	 * @param {HTMLElement} box The search block.
	 */
	function setUpTestAs( box ) {
		var input = box.querySelector( 'input[type="search"]' );
		var list = box.querySelector( '[data-blt-test-as-results]' );
		var status = box.querySelector( '[data-blt-test-as-status]' );
		var role = box.getAttribute( 'data-role' ) || '';
		var timer = 0;
		var controller = null;

		if ( ! input || ! list || ! config.switchSearch || 'function' !== typeof window.fetch ) {
			return;
		}

		box.hidden = false;

		/**
		 * Add `redirect_to` (the dashboard) to a switch URL.
		 *
		 * @param {string} url Switch URL from the server.
		 * @return {string} URL.
		 */
		function withRedirect( url ) {
			if ( ! config.dashboard || -1 !== url.indexOf( 'redirect_to=' ) ) {
				return url;
			}

			return url + ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + 'redirect_to=' + encodeURIComponent( config.dashboard );
		}

		/**
		 * Show the results.
		 *
		 * @param {Array} users Users from the server.
		 */
		function render( users ) {
			list.textContent = '';

			users.forEach( function ( user ) {
				var item = document.createElement( 'li' );
				var link = document.createElement( 'a' );

				link.href = withRedirect( String( user.url ) );
				link.textContent = format( '%1$s (%2$s)', [ user.name, user.login ] );
				item.appendChild( link );
				list.appendChild( item );
			} );

			if ( status ) {
				status.textContent = 0 === users.length ? strings.noUsers || '' : format( strings.foundUsers, [ users.length ] );
			}
		}

		/**
		 * Ask the server.
		 */
		function search() {
			var term = input.value.trim();

			if ( term.length < 2 ) {
				list.textContent = '';

				if ( status ) {
					status.textContent = '';
				}

				return;
			}

			if ( controller ) {
				controller.abort();
			}

			controller = 'function' === typeof window.AbortController ? new window.AbortController() : null;

			if ( status ) {
				status.textContent = strings.searching || '';
			}

			window.fetch( config.switchSearch + ( -1 === config.switchSearch.indexOf( '?' ) ? '?' : '&' ) + 'search=' + encodeURIComponent( term ) + '&role=' + encodeURIComponent( role ), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce || '' },
				signal: controller ? controller.signal : undefined,
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( String( response.status ) );
					}

					return response.json();
				} )
				.then( function ( users ) {
					// The server returns only this role's holders (`role`).
					var holders = ( Array.isArray( users ) ? users : [] ).filter( function ( user ) {
						return user && /^https?:\/\//i.test( String( user.url ) );
					} );

					render( holders );
				} )
				.catch( function ( error ) {
					if ( error && 'AbortError' === error.name ) {
						return;
					}

					if ( status ) {
						status.textContent = strings.failed || '';
					}
				} );
		}

		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( search, 250 );
		} );

		// The search also stops Enter from submitting anything.
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				window.clearTimeout( timer );
				search();
			}
		} );
	}

	/**
	 * Wire everything on the page.
	 */
	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-blt-matrix]' ), setUpMatrix );
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-blt-toolbar]' ), setUpToolbar );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-blt-test-as]' ), setUpTestAs );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
