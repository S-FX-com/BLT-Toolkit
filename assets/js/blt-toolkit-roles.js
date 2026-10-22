/**
 * BLT Toolkit: Roles screen enhancements (SPEC 9.3, RO-26).
 *
 * Everything on the Roles screen works without this file: the matrix is a
 * plain form, the toolbar is a GET form and every review is its own page.
 * With it:
 *
 * - the save bar counts unsaved changes in a polite live region, which
 *   also speaks an RS-7 hint that a change has just shown;
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

	// ---------- Simple view (SPEC 10) ----------

	/**
	 * Levels a content row can be set to, lowest first (SPEC 10.3).
	 */
	var LEVELS = [ 'none', 'view', 'contribute', 'author', 'edit_all', 'full' ];

	/**
	 * Levels that show Can delete, and those Manage {taxonomy} applies at.
	 */
	var DELETE_LEVELS = [ 'contribute', 'author', 'edit_all' ];
	var MANAGE_LEVELS = [ 'contribute', 'author', 'edit_all', 'full' ];

	/**
	 * Distinct strings of a list.
	 *
	 * @param {Array} list List.
	 * @return {Array} Distinct, in first-seen order.
	 */
	function unique( list ) {
		var seen = {};

		return list.filter( function ( item ) {
			if ( Object.prototype.hasOwnProperty.call( seen, item ) ) {
				return false;
			}

			seen[ item ] = true;

			return true;
		} );
	}

	/**
	 * Items of one list not in another.
	 *
	 * @param {Array} list  List.
	 * @param {Array} other Items to leave out.
	 * @return {Array} Difference.
	 */
	function without( list, other ) {
		return list.filter( function ( item ) {
			return -1 === other.indexOf( item );
		} );
	}

	/**
	 * Set up Simple view: the change counter, levels and their modifiers,
	 * RS-7 hints with "Also allow", only changed controls posted (RS-5),
	 * the review dialog (RS-6), save or discard before switching views
	 * (RS-2), and the `beforeunload` guard. Every rule is checked again on
	 * the server, which also shows its own review page when it must.
	 *
	 * @param {HTMLFormElement} form The Simple form.
	 */
	function setUpSimple( form ) {
		var wrap = form.closest( '[data-blt-simple-wrap]' ) || document;
		var source = document.getElementById( 'blt-tk-simple-data' );
		var data = { controls: [], labels: {}, critical: {}, levels: {}, names: [], threshold: 10 };
		var count = wrap.querySelector( '[data-blt-count]' );
		var discard = wrap.querySelector( '[data-blt-discard]' );
		var submitButton = wrap.querySelector( '[data-blt-simple-submit]' );
		var review = wrap.querySelector( '[data-blt-review-dialog]' );
		var switcher = wrap.querySelector( '[data-blt-switch-dialog]' );
		var reviewed = form.querySelector( '[data-blt-reviewed]' );
		var confirmField = form.querySelector( '[data-blt-confirm]' );
		var thenView = form.querySelector( '[data-blt-then-view]' );
		var thenCtl = form.querySelector( '[data-blt-then-ctl]' );
		var controls = [];
		var submitting = false;
		var ready = false;
		var dirty = 0;
		var pendingHref = '';

		if ( source ) {
			try {
				data = JSON.parse( source.textContent ) || data;
			} catch ( error ) {
				return;
			}
		}

		/**
		 * The parts of one control on the page.
		 *
		 * @param {Object} info Control from the server.
		 * @return {Object|null} Control with its elements.
		 */
		function bind( info ) {
			var el = form.querySelector( '[data-blt-control="' + info.id + '"]' );

			if ( ! el ) {
				return null;
			}

			var control = { info: info, el: el };

			if ( 'level' === info.kind ) {
				control.select = el.querySelector( 'select[data-blt-level]' );
				control.help = el.querySelector( '[data-blt-level-help]' );
				control.mods = Array.prototype.slice.call( el.querySelectorAll( '[data-blt-mod]' ) );
				control.modsSet = el.querySelector( '[data-blt-mods-set]' );
				control.badges = Array.prototype.slice.call( el.querySelectorAll( '[data-blt-at-level]' ) );

				if ( ! control.select ) {
					return null;
				}

				// The script sets each level's default modifiers itself.
				if ( control.modsSet ) {
					control.modsSet.disabled = false;
				}
			} else {
				control.box = el.querySelector( 'input[type="checkbox"]' );
				control.drawn = el.querySelector( '[data-blt-drawn]' );

				if ( ! control.box ) {
					return null;
				}
			}

			return control;
		}

		/**
		 * A modifier's checkbox.
		 *
		 * @param {HTMLElement} mod Modifier block.
		 * @return {HTMLInputElement|null} Checkbox.
		 */
		function modBox( mod ) {
			return mod.querySelector( 'input[type="checkbox"]' );
		}

		/**
		 * A control's state now: a toggle's `on`, `off` or `indeterminate`;
		 * a content row's level and modifiers, completed as the server
		 * completes them (BLT_Toolkit_Simple_Mapper::normalize_mods()).
		 *
		 * @param {Object} control Control.
		 * @return {string|Object} State.
		 */
		function stateOf( control ) {
			if ( 'level' !== control.info.kind ) {
				if ( control.box.indeterminate ) {
					return 'indeterminate';
				}

				return control.box.checked ? 'on' : 'off';
			}

			var level = control.select.value;
			var mods = { can_delete: 'full' === level, manage: {} };

			control.mods.forEach( function ( mod ) {
				var box = modBox( mod );
				var key = mod.getAttribute( 'data-blt-mod' );

				if ( ! box ) {
					return;
				}

				if ( 'can_delete' === key && -1 !== DELETE_LEVELS.indexOf( level ) ) {
					mods.can_delete = box.checked;
				}

				if ( 'manage' === key ) {
					mods.manage[ mod.getAttribute( 'data-tax' ) ] = -1 !== MANAGE_LEVELS.indexOf( level ) && box.checked;
				}
			} );

			return { level: level, mods: mods };
		}

		/**
		 * The drawn state, completed the same way.
		 *
		 * @param {Object} control Control.
		 * @return {string|Object} State.
		 */
		function drawnOf( control ) {
			var state = control.info.state;

			if ( 'level' !== control.info.kind ) {
				return state;
			}

			var mods = { can_delete: 'full' === state.level || ( -1 !== DELETE_LEVELS.indexOf( state.level ) && !! state.mods.can_delete ), manage: {} };

			Object.keys( control.info.taxonomies || {} ).forEach( function ( tax ) {
				mods.manage[ tax ] = -1 !== MANAGE_LEVELS.indexOf( state.level ) && !! ( state.mods.manage && state.mods.manage[ tax ] );
			} );

			return { level: state.level, mods: mods };
		}

		/**
		 * Whether a control differs from how it was drawn.
		 *
		 * @param {Object} control Control.
		 * @return {boolean} Changed.
		 */
		function isChanged( control ) {
			var now = stateOf( control );
			var then = drawnOf( control );

			if ( 'level' !== control.info.kind ) {
				return 'indeterminate' !== now && now !== then;
			}

			if ( 'custom' === now.level ) {
				return false;
			}

			return JSON.stringify( now ) !== JSON.stringify( then );
		}

		/**
		 * What a control's state now adds and removes (RS-5), as the server
		 * works it out: a toggle's capabilities all on or all off; a content
		 * row's level set (BLT_Toolkit_Simple_Mapper::caps_for(), sent split
		 * into its base, Can delete and Manage parts) against what the role
		 * grants within the row.
		 *
		 * @param {Object} control Control.
		 * @return {{add: Array, remove: Array}} Change.
		 */
		function changeOf( control ) {
			var info = control.info;
			var now = stateOf( control );

			if ( ! isChanged( control ) ) {
				return { add: [], remove: [] };
			}

			if ( 'meta' === info.kind ) {
				return { add: [], remove: [] };
			}

			if ( 'toggle' === info.kind ) {
				return 'on' === now ? { add: without( info.universe, info.granted ), remove: [] } : { add: [], remove: info.granted.slice() };
			}

			var level = info.levels[ now.level ];
			var target = level.base.slice();

			if ( now.mods.can_delete ) {
				target = target.concat( level.del );
			}

			Object.keys( now.mods.manage ).forEach( function ( tax ) {
				if ( now.mods.manage[ tax ] && level.manage[ tax ] ) {
					target = target.concat( level.manage[ tax ] );
				}
			} );

			target = unique( target );

			return { add: without( target, info.granted ), remove: without( info.granted, target ) };
		}

		/**
		 * A state in words (BLT_Toolkit_Simple_Controls::state_text()).
		 *
		 * @param {Object}        control Control.
		 * @param {string|Object} state   State.
		 * @return {string} Text.
		 */
		function stateText( control, state ) {
			var info = control.info;

			if ( 'level' === info.kind ) {
				var extra = [];
				var text = data.levels[ state.level ] || data.levels.custom || '';

				if ( -1 !== DELETE_LEVELS.indexOf( state.level ) && state.mods.can_delete ) {
					extra.push( strings.canDelete || '' );
				}

				if ( -1 !== MANAGE_LEVELS.indexOf( state.level ) ) {
					Object.keys( state.mods.manage || {} ).forEach( function ( tax ) {
						if ( state.mods.manage[ tax ] ) {
							extra.push( format( strings.manages, [ ( info.taxonomies && info.taxonomies[ tax ] ) || tax ] ) );
						}
					} );
				}

				return extra.length ? text + ', ' + extra.join( ', ' ) : text;
			}

			if ( 'indeterminate' === state ) {
				return strings.partly || '';
			}

			if ( 'meta' === info.kind ) {
				return 'on' === state ? strings.hidden || '' : strings.shownState || '';
			}

			return 'on' === state ? strings.allowed || '' : strings.notAllowed || '';
		}

		/**
		 * Capability labels as words in a sentence ("edit others' Events").
		 *
		 * @param {Array} caps Capabilities.
		 * @return {string} Words.
		 */
		function words( caps ) {
			return caps.map( function ( cap ) {
				var label = String( data.labels[ cap ] || cap );

				return /^[A-Z][a-z']/.test( label ) && ! /^(WordPress|Site Health|Customizer)\b/.test( label ) ? label.charAt( 0 ).toLowerCase() + label.slice( 1 ) : label;
			} ).join( ', ' );
		}

		/**
		 * Whether a control counts as on for the RS-7 hints, read from the
		 * page (so locked controls count too): a toggle on or partly on, a
		 * content row at or above a level.
		 *
		 * @param {string} id  Control ID.
		 * @param {string} min Lowest level that counts.
		 * @return {boolean} On.
		 */
		function isOn( id, min ) {
			var el = form.querySelector( '[data-blt-control="' + id + '"]' );

			if ( ! el ) {
				return false;
			}

			var select = el.querySelector( 'select[data-blt-level]' );

			if ( select ) {
				return LEVELS.indexOf( select.value ) >= LEVELS.indexOf( min || 'view' ) && -1 !== LEVELS.indexOf( select.value );
			}

			var box = el.querySelector( 'input[type="checkbox"]' );

			return !! box && 'toggle' === el.getAttribute( 'data-kind' ) && ( box.checked || box.indeterminate );
		}

		/**
		 * Show the hints that apply now (RS-7, RS-17).
		 *
		 * @return {Array} Text of each hint this call showed that was
		 *                 hidden before, once each, for the live region.
		 */
		function updateHints() {
			var shown = [];

			Array.prototype.forEach.call( form.querySelectorAll( '[data-blt-hint]' ), function ( hint ) {
				var when = {};

				try {
					when = JSON.parse( hint.getAttribute( 'data-when' ) || '{}' );
				} catch ( error ) {
					when = {};
				}

				var active = Object.keys( when ).some( function ( id ) {
					return isOn( id, when[ id ] );
				} ) && ! isOn( hint.getAttribute( 'data-needs' ), 'view' );
				var text = hint.querySelector( '.blt-toolkit-simple-hint-text' );

				if ( active && hint.hidden && text && -1 === shown.indexOf( text.textContent.trim() ) ) {
					shown.push( text.textContent.trim() );
				}

				hint.hidden = ! active;
			} );

			return shown;
		}

		/**
		 * A content row's help text, modifiers and level badges for the
		 * level selected.
		 *
		 * @param {Object} control Content row.
		 */
		function updateRow( control ) {
			var level = control.select.value;
			var option = control.select.options[ control.select.selectedIndex ];

			if ( control.help && option ) {
				control.help.textContent = option.getAttribute( 'data-help' ) || '';
			}

			control.mods.forEach( function ( mod ) {
				var levels = ( mod.getAttribute( 'data-levels' ) || '' ).split( ' ' );

				mod.classList.toggle( 'is-off', -1 === levels.indexOf( level ) );
			} );

			control.badges.forEach( function ( badge ) {
				badge.classList.toggle( 'is-off', badge.getAttribute( 'data-blt-at-level' ) !== level );
			} );
		}

		/**
		 * Count the changed controls and refresh everything that follows
		 * from them.
		 */
		function update() {
			dirty = 0;

			controls.forEach( function ( control ) {
				if ( isChanged( control ) ) {
					dirty++;
				}

				if ( 'level' === control.info.kind ) {
					updateRow( control );
				}
			} );

			var shown = updateHints();

			if ( count ) {
				count.textContent = 0 === dirty ? strings.noChanges || '' : format( strings.changes, [ dirty ] );

				// A hint that a change just showed is spoken with the count
				// (WCAG 4.1.3): the save bar is the polite live region, and
				// the hidden copy leaves what it shows as it was.
				if ( ready && shown.length ) {
					var spoken = document.createElement( 'span' );

					spoken.className = 'screen-reader-text';
					spoken.textContent = ' ' + shown.join( ' ' );
					count.appendChild( spoken );
				}
			}

			if ( wrap.classList ) {
				wrap.classList.toggle( 'is-dirty', dirty > 0 );
			}
		}

		controls = data.controls.map( bind ).filter( Boolean );

		// RS-4: a toggle only partly granted is drawn indeterminate. The
		// first click turns all of it on, the next off; the hidden field
		// then says `off` instead of "leave it".
		Array.prototype.forEach.call( form.querySelectorAll( 'input[data-indeterminate]' ), function ( box ) {
			box.indeterminate = true;
		} );

		controls.forEach( function ( control ) {
			if ( 'level' === control.info.kind ) {
				control.select.addEventListener( 'change', function () {
					var defaults = control.info.levels[ control.select.value ] ? control.info.levels[ control.select.value ].defaults : null;

					// A level picked starts with its own defaults (SPEC 10.3).
					if ( defaults ) {
						control.mods.forEach( function ( mod ) {
							var box = modBox( mod );

							if ( ! box || box.disabled ) {
								return;
							}

							if ( 'can_delete' === mod.getAttribute( 'data-blt-mod' ) ) {
								box.checked = !! defaults.can_delete;
							} else {
								box.checked = !! ( defaults.manage && defaults.manage[ mod.getAttribute( 'data-tax' ) ] );
							}
						} );
					}
				} );
			} else if ( control.drawn ) {
				control.box.addEventListener( 'change', function () {
					if ( ! control.box.indeterminate ) {
						control.drawn.value = 'off';
					}
				} );
			}
		} );

		form.addEventListener( 'change', update );

		// "Also allow" (RS-7): turn the needed control on, then move focus
		// to it, since the hint goes away.
		Array.prototype.forEach.call( form.querySelectorAll( '[data-blt-also-allow]' ), function ( button ) {
			button.hidden = false;

			button.addEventListener( 'click', function () {
				var target = form.querySelector( '[data-blt-control="' + button.getAttribute( 'data-blt-also-allow' ) + '"] input[type="checkbox"]' );

				if ( ! target || target.disabled ) {
					return;
				}

				target.indeterminate = false;
				target.checked = true;
				target.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				target.focus();
			} );
		} );

		/**
		 * Post the form with only the changed controls (RS-5): the fields of
		 * every other control lose their names, so they are not sent.
		 */
		function send() {
			controls.forEach( function ( control ) {
				var changed = isChanged( control );

				Array.prototype.forEach.call( control.el.querySelectorAll( '[name]' ), function ( field ) {
					if ( ! changed && /^simple\[/.test( field.name ) ) {
						field.setAttribute( 'data-name', field.name );
						field.removeAttribute( 'name' );
					}
				} );

				if ( changed && control.drawn && ! control.box.indeterminate ) {
					control.drawn.value = 'off';
				}
			} );

			submitting = true;
			form.submit();
		}

		/**
		 * Fill and open the review dialog (RS-6), or send at once when no
		 * review is needed (or the browser has no dialogs: the server then
		 * shows its own review page).
		 */
		function reviewOrSend() {
			var lines = [];
			var adds = [];
			var removes = [];
			var custom = false;

			controls.forEach( function ( control ) {
				if ( ! isChanged( control ) ) {
					return;
				}

				var change = changeOf( control );
				var line = format( strings.line, [ control.info.label, stateText( control, drawnOf( control ) ), stateText( control, stateOf( control ) ) ] );

				if ( change.add.length ) {
					line += ' ' + format( strings.adds, [ words( change.add ) ] );
				}

				if ( change.remove.length ) {
					line += ' ' + format( strings.removes, [ words( change.remove ) ] );
				}

				lines.push( line );
				adds = adds.concat( change.add );
				removes = removes.concat( change.remove );

				if ( change.remove.length && ( 'indeterminate' === control.info.state || ( control.info.state && 'custom' === control.info.state.level ) ) ) {
					custom = true;
				}
			} );

			adds = unique( adds );
			removes = unique( removes );

			var criticalGrants = adds.filter( function ( cap ) {
				return Object.prototype.hasOwnProperty.call( data.critical, cap );
			} );
			var touchesCritical = criticalGrants.length > 0 || removes.some( function ( cap ) {
				return Object.prototype.hasOwnProperty.call( data.critical, cap );
			} );
			var large = adds.length + removes.length > data.threshold;

			if ( ! touchesCritical && ! large && ! custom ) {
				send();
				return;
			}

			if ( ! review || 'function' !== typeof review.showModal ) {
				send();
				return;
			}

			var list = review.querySelector( '[data-blt-review-lines]' );
			var raw = review.querySelector( '[data-blt-review-raw]' );
			var criticalBox = review.querySelector( '[data-blt-review-critical]' );
			var consequences = review.querySelector( '[data-blt-review-consequences]' );
			var largeBox = review.querySelector( '[data-blt-review-large]' );
			var largeText = review.querySelector( '[data-blt-review-large-text]' );
			var customBox = review.querySelector( '[data-blt-review-custom]' );
			var intro = review.querySelector( '[data-blt-review-intro]' );
			var input = review.querySelector( '[data-blt-review-confirm]' );
			var mismatch = review.querySelector( '[data-blt-review-mismatch]' );

			list.textContent = '';
			lines.forEach( function ( text ) {
				var item = document.createElement( 'li' );

				item.textContent = text;
				list.appendChild( item );
			} );

			raw.textContent = '';
			[ [ adds, strings.granted ], [ removes, strings.removed ] ].forEach( function ( group ) {
				if ( ! group[ 0 ].length ) {
					return;
				}

				var heading = document.createElement( 'p' );
				var chips = document.createElement( 'p' );

				heading.textContent = group[ 1 ] || '';
				group[ 0 ].forEach( function ( cap ) {
					var chip = document.createElement( 'code' );

					chip.className = 'blt-chip';
					chip.textContent = cap;
					chips.appendChild( chip );
					chips.appendChild( document.createTextNode( ' ' ) );
				} );
				raw.appendChild( heading );
				raw.appendChild( chips );
			} );

			consequences.textContent = '';
			criticalGrants.forEach( function ( cap ) {
				var item = document.createElement( 'li' );
				var label = document.createElement( 'strong' );

				label.textContent = ( data.labels[ cap ] || cap ) + ': ';
				item.appendChild( label );
				item.appendChild( document.createTextNode( data.critical[ cap ] ) );
				consequences.appendChild( item );
			} );

			criticalBox.hidden = 0 === criticalGrants.length;
			largeBox.hidden = ! large;
			largeText.textContent = format( strings.large, [ data.threshold ] );
			customBox.hidden = ! custom;
			intro.textContent = format( strings.touches, [ adds.length + removes.length ] );
			input.value = '';
			mismatch.hidden = true;
			review.setAttribute( 'data-needs-name', criticalGrants.length ? '1' : '' );
			review.showModal();

			// Start at the heading, so a screen reader reads what changes
			// (the dialog is described by its intro and lines) before the
			// name field or the raw names.
			var heading = review.querySelector( '[data-blt-review-heading]' );

			if ( heading ) {
				heading.focus();
			}
		}

		if ( review ) {
			review.querySelector( '[data-blt-review-cancel]' ).addEventListener( 'click', function () {
				clearThen();
				review.close();
			} );

			// Escape is Cancel: a "save and switch" it interrupts is dropped.
			review.addEventListener( 'cancel', function () {
				clearThen();
			} );

			// Enter in the name field saves, as it would in a form.
			review.querySelector( '[data-blt-review-confirm]' ).addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key && ! event.isComposing ) {
					event.preventDefault();
					review.querySelector( '[data-blt-review-save]' ).click();
				}
			} );

			review.addEventListener( 'close', function () {
				if ( ! submitting && submitButton ) {
					submitButton.focus();
				}
			} );

			review.querySelector( '[data-blt-review-save]' ).addEventListener( 'click', function () {
				var input = review.querySelector( '[data-blt-review-confirm]' );
				var mismatch = review.querySelector( '[data-blt-review-mismatch]' );

				if ( '1' === review.getAttribute( 'data-needs-name' ) ) {
					var typed = input.value.trim().toLowerCase();
					var match = data.names.some( function ( name ) {
						return '' !== typed && String( name ).trim().toLowerCase() === typed;
					} );

					if ( ! match ) {
						mismatch.hidden = false;
						input.focus();
						return;
					}

					confirmField.value = input.value;
				}

				reviewed.value = '1';
				review.close();
				send();
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( submitting ) {
				return;
			}

			if ( 0 === dirty ) {
				if ( count ) {
					count.textContent = strings.noChange || '';
				}

				return;
			}

			reviewOrSend();
		} );

		if ( discard ) {
			discard.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				controls.forEach( function ( control ) {
					if ( 'level' === control.info.kind ) {
						Array.prototype.forEach.call( control.select.options, function ( option ) {
							option.selected = option.defaultSelected;
						} );

						control.mods.forEach( function ( mod ) {
							var box = modBox( mod );

							if ( box ) {
								box.checked = box.defaultChecked;
							}
						} );
					} else {
						control.box.checked = control.box.defaultChecked;
						control.box.indeterminate = control.box.hasAttribute( 'data-indeterminate' );

						if ( control.drawn ) {
							control.drawn.value = control.drawn.defaultValue;
						}
					}
				} );

				update();

				if ( count && strings.discarded ) {
					count.textContent = strings.discarded;
				}
			} );
		}

		/**
		 * Forget a pending "save and switch".
		 */
		function clearThen() {
			thenView.value = '';

			if ( thenCtl ) {
				thenCtl.value = '';
			}
		}

		// RS-2: switching views with unsaved changes asks to save or
		// discard them first: the view tabs, and each control's "Edit in
		// Advanced" link, which opens Advanced narrowed to that control
		// (`ctl`, kept for both Discard and Save).
		Array.prototype.forEach.call( document.querySelectorAll( '.blt-toolkit-view-tabs a, a.blt-toolkit-simple-advanced' ), function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( 0 === dirty || link.getAttribute( 'aria-current' ) || ! switcher || 'function' !== typeof switcher.showModal ) {
					return;
				}

				var ctl = /[?&]ctl=([^&#]*)/.exec( link.href );

				event.preventDefault();
				pendingHref = link.href;
				thenView.value = /[?&]view=advanced(&|$)/.test( link.href ) ? 'advanced' : 'simple';

				if ( thenCtl ) {
					thenCtl.value = ctl ? decodeURIComponent( ctl[ 1 ] ) : '';
				}

				switcher.showModal();
			} );
		} );

		if ( switcher ) {
			switcher.querySelector( '[data-blt-switch-cancel]' ).addEventListener( 'click', function () {
				clearThen();
				switcher.close();
			} );

			// Escape cancels the switch too, so a later save stays in this view.
			switcher.addEventListener( 'cancel', function () {
				clearThen();
			} );

			switcher.querySelector( '[data-blt-switch-discard]' ).addEventListener( 'click', function () {
				submitting = true;
				switcher.close();
				window.location.href = pendingHref;
			} );

			switcher.querySelector( '[data-blt-switch-save]' ).addEventListener( 'click', function () {
				switcher.close();
				reviewOrSend();
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

		// From now on a hint that appears is a change to speak, not the
		// page as it was drawn.
		ready = true;
	}

	/**
	 * Wire everything on the page.
	 */
	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-blt-matrix]' ), setUpMatrix );
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-blt-toolbar]' ), setUpToolbar );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-blt-test-as]' ), setUpTestAs );
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-blt-simple]' ), setUpSimple );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
