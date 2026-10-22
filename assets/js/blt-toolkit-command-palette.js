/**
 * BLT Toolkit: command palette commands (SPEC 7.3, SW-26).
 *
 * Loaded only on screens where WordPress enqueued `wp-commands` (6.3+), by
 * BLT_Toolkit_Switch_Ui::enqueue_command_palette(), which also decides what
 * the current person may do and prints it in window.bltToolkit.commands:
 *
 * - switchBack: { url, label, avatar } while switched: "Switch back to {Name}";
 * - switchOff:  { url, label } when they may switch off;
 * - switchTo:   { path, minChars, label, prefix } when the toolbar search is
 *   on: a command loader that asks GET blt-toolkit/v1/switch/users, which
 *   returns only users they may switch to, each with a nonced URL made on
 *   the server.
 *
 * Plain JavaScript on the wp.* globals (no build step, no JSX). Labels are
 * translated on the server; the English words stay in searchLabel so the
 * commands are found in either language.
 */
( function () {
	'use strict';

	var settings = window.bltToolkit && window.bltToolkit.commands;
	var wp = window.wp;

	if ( ! settings || ! wp || ! wp.commands || ! wp.data || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;
	var dispatch = wp.data.dispatch;
	var store = wp.commands.store;
	var Dashicon = wp.components && wp.components.Dashicon;

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
	 * The command's icon: the user's avatar when there is one.
	 *
	 * @param {string} avatar Avatar URL, or ''.
	 * @return {*} Element.
	 */
	function icon( avatar ) {
		if ( isWebUrl( avatar ) ) {
			return el( 'img', { src: avatar, alt: '', width: 24, height: 24 } );
		}

		return Dashicon ? el( Dashicon, { icon: 'admin-users' } ) : null;
	}

	/**
	 * A command callback that goes to a URL.
	 *
	 * @param {string} url Switch URL.
	 * @return {Function} Callback.
	 */
	function go( url ) {
		return function ( args ) {
			if ( args && 'function' === typeof args.close ) {
				args.close();
			}

			if ( isWebUrl( url ) ) {
				window.location.assign( url );
			}
		};
	}

	if ( settings.switchBack && isWebUrl( settings.switchBack.url ) ) {
		dispatch( store ).registerCommand( {
			name: 'blt-toolkit/switch-back',
			label: settings.switchBack.label,
			searchLabel: settings.switchBack.label + ' switch back',
			icon: icon( settings.switchBack.avatar ),
			callback: go( settings.switchBack.url ),
		} );
	}

	if ( settings.switchOff && isWebUrl( settings.switchOff.url ) ) {
		dispatch( store ).registerCommand( {
			name: 'blt-toolkit/switch-off',
			label: settings.switchOff.label,
			searchLabel: settings.switchOff.label + ' switch off',
			icon: icon( '' ),
			callback: go( settings.switchOff.url ),
		} );
	}

	if ( ! settings.switchTo || ! wp.apiFetch || ! wp.url ) {
		return;
	}

	var switchTo = settings.switchTo;
	var minChars = Math.max( 1, parseInt( switchTo.minChars, 10 ) || 2 );

	/**
	 * Fill the "Switch to %s" template.
	 *
	 * @param {string} name Display name.
	 * @return {string} Label.
	 */
	function label( name ) {
		return String( switchTo.label || '%s' ).replace( /%(?:1\$)?s/, String( name ) );
	}

	/**
	 * Strip "Switch to" (translated or English) from what was typed, so
	 * "switch to jane" searches for "jane".
	 *
	 * @param {string} search Palette input.
	 * @return {string} Search text.
	 */
	function clean( search ) {
		var text = String( search || '' );
		var prefix = String( switchTo.prefix || '' ).toLowerCase();
		var lower = text.toLowerCase();

		if ( prefix && 0 === lower.indexOf( prefix ) ) {
			text = text.slice( prefix.length );
		} else if ( 0 === lower.indexOf( 'switch to' ) ) {
			text = text.slice( 9 );
		}

		return text.replace( /^\s+|\s+$/g, '' );
	}

	/**
	 * The value after it has stopped changing for a moment.
	 *
	 * @param {string} value Value.
	 * @return {string} Debounced value.
	 */
	function useDebounced( value ) {
		var state = useState( value );

		useEffect(
			function () {
				var timer = window.setTimeout( function () {
					state[ 1 ]( value );
				}, 250 );

				return function () {
					window.clearTimeout( timer );
				};
			},
			[ value ]
		);

		return state[ 0 ];
	}

	/**
	 * Command loader hook: users matching the palette's search.
	 *
	 * @param {Object} options Loader options; options.search is the input.
	 * @return {Object} { commands, isLoading }.
	 */
	function useSwitchToLoader( options ) {
		var search = useDebounced( clean( options && options.search ) );
		var state = useState( { users: [], isLoading: false } );
		var result = state[ 0 ];
		var setResult = state[ 1 ];

		useEffect(
			function () {
				var cancelled = false;

				if ( search.length < minChars ) {
					setResult( { users: [], isLoading: false } );

					return undefined;
				}

				setResult( { users: [], isLoading: true } );

				wp.apiFetch( { path: wp.url.addQueryArgs( switchTo.path, { search: search } ) } )
					.then( function ( users ) {
						if ( ! cancelled ) {
							setResult( { users: Array.isArray( users ) ? users : [], isLoading: false } );
						}
					} )
					.catch( function () {
						if ( ! cancelled ) {
							setResult( { users: [], isLoading: false } );
						}
					} );

				return function () {
					cancelled = true;
				};
			},
			[ search ]
		);

		var commands = useMemo(
			function () {
				return result.users
					.filter( function ( user ) {
						return user && isWebUrl( user.url );
					} )
					.map( function ( user ) {
						var name = String( user.name || user.login || '' );

						return {
							name: 'blt-toolkit/switch-to-' + parseInt( user.id, 10 ),
							label: label( name ),
							searchLabel: label( name ) + ' ' + String( user.login || '' ) + ' switch to ' + name,
							icon: icon( user.avatar ),
							callback: go( user.url ),
						};
					} );
			},
			[ result.users ]
		);

		return { commands: commands, isLoading: result.isLoading };
	}

	dispatch( store ).registerCommandLoader( {
		name: 'blt-toolkit/switch-to-user',
		hook: useSwitchToLoader,
	} );
} )();
