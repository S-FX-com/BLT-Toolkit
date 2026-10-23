/**
 * BLT Toolkit: toolbar "Switch to…" typeahead (SPEC 7.3, SW-20).
 *
 * BLT_Toolkit_Switch_Ui prints a plain search form in the toolbar that works
 * without this file: it searches the Users screen, where each row offers
 * "Switch To". This script turns the field into an ARIA combobox (WAI-ARIA
 * Authoring Practices, "combobox with listbox popup"):
 *
 * - typing two or more characters asks GET blt-toolkit/v1/switch/users for
 *   the users the current person may switch to; each result carries a
 *   nonced switch URL made on the server, so nothing here decides who may be
 *   switched to;
 * - Down and Up move through the results (aria-activedescendant), Enter
 *   switches to the highlighted user, Escape closes the list (and, when it
 *   is already closed, lets the toolbar close its menu);
 * - Enter with nothing highlighted submits the form, as without JavaScript;
 * - a polite live region says how many users were found;
 * - the "Switch to…" menu closes once keyboard focus leaves it, so it never
 *   covers what focus reaches next on the page (WCAG 2.4.11).
 *
 * Settings and translated strings come from window.bltToolkit.switchSearch.
 * Every value from the server is put on the page with textContent or as an
 * attribute value, never as HTML.
 */
( function () {
	'use strict';

	var config = window.bltToolkit && window.bltToolkit.switchSearch;

	if ( ! config || ! config.root || 'function' !== typeof window.fetch ) {
		return;
	}

	var strings = config.strings || {};
	var minChars = Math.max( 1, parseInt( config.minChars, 10 ) || 2 );
	var delay = 250;

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
	 * Whether a URL is one the browser may be sent to: http or https only.
	 *
	 * @param {*} url Candidate.
	 * @return {boolean} Whether it is safe to navigate to.
	 */
	function isWebUrl( url ) {
		return 'string' === typeof url && /^https?:\/\//i.test( url );
	}

	/**
	 * Set up one search field.
	 *
	 * @param {HTMLInputElement} input   The search field.
	 * @param {HTMLElement}      listbox The results list.
	 */
	function setUp( input, listbox ) {
		var form = input.form;
		var status = form ? form.querySelector( '.blt-tk-switch-search-status' ) : null;
		var options = [];
		var active = -1;
		var timer = 0;
		var controller = null;
		var lastQuery = '';

		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', listbox.id );
		listbox.setAttribute( 'role', 'listbox' );
		listbox.setAttribute( 'aria-label', strings.label || '' );

		if ( status ) {
			status.setAttribute( 'role', 'status' );
		}

		/**
		 * Announce text in the live region.
		 *
		 * @param {string} text Text.
		 */
		function say( text ) {
			if ( status ) {
				status.textContent = text || '';
			}
		}

		/**
		 * Highlight one option, or none.
		 *
		 * @param {number} index Option index, or -1.
		 */
		function highlight( index ) {
			active = index;

			options.forEach( function ( option, i ) {
				var on = i === index;

				option.element.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				option.element.classList.toggle( 'is-active', on );
			} );

			if ( index >= 0 && options[ index ] ) {
				input.setAttribute( 'aria-activedescendant', options[ index ].element.id );
				options[ index ].element.scrollIntoView( { block: 'nearest' } );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		/**
		 * Show or hide the list.
		 *
		 * @param {boolean} open Whether to show it.
		 */
		function setOpen( open ) {
			listbox.hidden = ! open;
			input.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			if ( ! open ) {
				highlight( -1 );
			}
		}

		/**
		 * Remove every option.
		 */
		function clear() {
			options = [];
			active = -1;

			while ( listbox.firstChild ) {
				listbox.removeChild( listbox.firstChild );
			}

			setOpen( false );
		}

		/**
		 * Go to an option's switch URL.
		 *
		 * @param {number} index Option index.
		 */
		function choose( index ) {
			var option = options[ index ];

			if ( option && isWebUrl( option.url ) ) {
				window.location.assign( option.url );
			}
		}

		/**
		 * Build the list from the server's answer.
		 *
		 * @param {Array} users Users: id, name, login, roles, avatar, url.
		 */
		function render( users ) {
			clear();

			users.forEach( function ( user, index ) {
				if ( ! user || ! isWebUrl( user.url ) ) {
					return;
				}

				var item = document.createElement( 'li' );
				var text = document.createElement( 'span' );
				var name = document.createElement( 'span' );
				var meta = document.createElement( 'span' );
				var entry = { element: item, url: user.url };
				var details = [];
				var img;

				item.id = listbox.id + '-' + index;
				item.className = 'blt-tk-switch-search-option';
				item.setAttribute( 'role', 'option' );
				item.setAttribute( 'aria-selected', 'false' );

				if ( isWebUrl( user.avatar ) ) {
					img = document.createElement( 'img' );

					img.className = 'blt-tk-switch-search-avatar';
					img.src = user.avatar;
					img.alt = '';
					img.width = 24;
					img.height = 24;
					img.loading = 'lazy';
					item.appendChild( img );
				}

				text.className = 'blt-tk-switch-search-text';
				name.className = 'blt-tk-switch-search-name';
				name.textContent = String( user.name || user.login || '' );
				text.appendChild( name );

				if ( user.login ) {
					details.push( String( user.login ) );
				}

				if ( Array.isArray( user.roles ) && user.roles.length ) {
					details.push( user.roles.join( ', ' ) );
				}

				if ( details.length ) {
					meta.className = 'blt-tk-switch-search-meta';
					meta.textContent = details.join( ' · ' );
					text.appendChild( meta );
				}

				item.appendChild( text );

				// Keep focus in the field while the pointer picks an option.
				item.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
				} );

				item.addEventListener( 'click', function () {
					choose( options.indexOf( entry ) );
				} );

				options.push( entry );
				listbox.appendChild( item );
			} );

			if ( options.length ) {
				setOpen( true );
				say( format( strings.found, options.length ) );
			} else {
				setOpen( false );
				say( strings.none );
			}
		}

		/**
		 * Ask the server for matching users.
		 *
		 * @param {string} query Search text.
		 */
		function search( query ) {
			if ( controller ) {
				controller.abort();
			}

			controller = 'function' === typeof window.AbortController ? new window.AbortController() : null;

			// Plain permalinks give a root that already has ?rest_route=.
			var url = config.root + ( -1 === config.root.indexOf( '?' ) ? '?' : '&' ) + 'search=' + encodeURIComponent( query );

			say( strings.searching );

			window
				.fetch( url, {
					method: 'GET',
					credentials: 'same-origin',
					headers: {
						Accept: 'application/json',
						'X-WP-Nonce': config.nonce || '',
					},
					signal: controller ? controller.signal : undefined,
				} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( String( response.status ) );
					}

					return response.json();
				} )
				.then( function ( users ) {
					if ( query !== lastQuery ) {
						return;
					}

					render( Array.isArray( users ) ? users : [] );
				} )
				.catch( function ( error ) {
					if ( error && 'AbortError' === error.name ) {
						return;
					}

					clear();
					say( strings.failed );
				} );
		}

		input.addEventListener( 'input', function () {
			var query = input.value.replace( /^\s+|\s+$/g, '' );

			window.clearTimeout( timer );

			if ( query === lastQuery ) {
				return;
			}

			lastQuery = query;

			if ( query.length < minChars ) {
				if ( controller ) {
					controller.abort();
				}

				clear();
				say( query.length ? strings.hint : '' );

				return;
			}

			timer = window.setTimeout( function () {
				search( query );
			}, delay );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			var open = ! listbox.hidden && options.length > 0;

			switch ( event.key ) {
				case 'ArrowDown':
				case 'Down':
					if ( options.length ) {
						event.preventDefault();
						setOpen( true );
						highlight( active < options.length - 1 ? active + 1 : 0 );
					}
					break;

				case 'ArrowUp':
				case 'Up':
					if ( options.length ) {
						event.preventDefault();
						setOpen( true );
						highlight( active > 0 ? active - 1 : options.length - 1 );
					}
					break;

				case 'Enter':
					if ( open && active >= 0 ) {
						event.preventDefault();
						choose( active );
					}
					break;

				case 'Escape':
				case 'Esc':
					if ( open ) {
						// Close the list only; the toolbar menu stays open.
						event.preventDefault();
						event.stopPropagation();
						setOpen( false );
					}
					break;
			}
		} );

		input.addEventListener( 'blur', function () {
			setOpen( false );
		} );

		input.addEventListener( 'focus', function () {
			if ( options.length ) {
				setOpen( true );
			}
		} );
	}

	/**
	 * Close the toolbar menu that holds the field once keyboard focus has
	 * left it (WCAG 2.4.11, Focus Not Obscured). Core's admin-bar.js opens
	 * a menu with Enter by adding the `hover` class and removes it only on
	 * Escape or on mouse out, so after Tab moves on the open menu would
	 * cover the controls focus reaches on the page below.
	 *
	 * The menu stays open while focus moves within it, and while the pointer
	 * is over it (core's hover intent closes it when the pointer leaves).
	 *
	 * @param {HTMLElement} input The search field.
	 */
	function closeMenuOnFocusOut( input ) {
		var item = 'function' === typeof input.closest ? input.closest( 'li.menupop' ) : null;

		if ( ! item ) {
			return;
		}

		item.addEventListener( 'focusout', function ( event ) {
			var next = event.relatedTarget;
			var toggle;

			if ( next && item.contains( next ) ) {
				return;
			}

			if ( 'function' === typeof item.matches && item.matches( ':hover' ) ) {
				return;
			}

			if ( ! item.classList.contains( 'hover' ) ) {
				return;
			}

			item.classList.remove( 'hover' );

			// As core's removeClass() does for its own menus.
			toggle = item.querySelector( 'a.ab-item' );

			if ( toggle && toggle.hasAttribute( 'aria-expanded' ) ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	/**
	 * Find the field and its list, then set them up.
	 */
	function init() {
		var input = document.getElementById( config.input );
		var listbox = document.getElementById( config.results );

		if ( input && listbox ) {
			setUp( input, listbox );
			closeMenuOnFocusOut( input );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
