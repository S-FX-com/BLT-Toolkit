/**
 * BLT Toolkit: background job progress panel (SPEC 6.3, JOB-1, JOB-4).
 *
 * BLT_Toolkit_Jobs::render_ui() prints the panel complete, so it works
 * without this file: the progress as it stood when the page loaded, and
 * Cancel, Retry failed and Continue now as forms posting to admin-post.php.
 *
 * This script keeps the panel current. It reads the job ID from the markup
 * (data-blt-toolkit-job), so a reload or a later visit picks the job up
 * again, and then:
 *
 * - polls GET blt-toolkit/v1/jobs/{id} while the job runs in the background;
 * - when the server says the job has stalled (background processing is not
 *   running on this host), drives it from the page with
 *   POST blt-toolkit/v1/jobs/{id}/tick, one tick after another, and shows the
 *   "keep this tab open" notice until the job ends or the background takes
 *   over again;
 * - sends Cancel and Retry failed through REST instead of reloading.
 *
 * Settings and translated strings come from window.bltToolkit.jobs, printed
 * by BLT_Toolkit_Jobs::enqueue_ui(). Every text the server sends is put on
 * the page with textContent, never as HTML.
 */
( function () {
	'use strict';

	var config = window.bltToolkit && window.bltToolkit.jobs;

	if ( ! config || ! config.root || 'function' !== typeof window.fetch ) {
		return;
	}

	var strings = config.strings || {};
	var interval = Math.max( 1000, parseInt( config.interval, 10 ) || 2000 );
	var maxBackoff = 30000;

	/**
	 * Fill a printf-style template: %s, or numbered %1$s, %2$s.
	 *
	 * @param {string} template Template.
	 * @param {Array}  values   Values.
	 * @return {string} Text.
	 */
	function format( template, values ) {
		var next = 0;

		return String( template || '' ).replace( /%(?:(\d+)\$)?s/g, function ( match, position ) {
			var value = position ? values[ parseInt( position, 10 ) - 1 ] : values[ next++ ];

			return undefined === value ? '' : String( value );
		} );
	}

	/**
	 * Seconds as "12 s", "4 min 10 s" or "1 h 5 min", as the server writes them.
	 *
	 * @param {number} seconds Seconds.
	 * @return {string} Text.
	 */
	function duration( seconds ) {
		seconds = Math.max( 0, Math.floor( seconds ) );

		if ( seconds < 60 ) {
			return format( strings.seconds, [ seconds ] );
		}

		if ( seconds < 3600 ) {
			return format( strings.minutes, [ Math.floor( seconds / 60 ), seconds % 60 ] );
		}

		return format( strings.hours, [ Math.floor( seconds / 3600 ), Math.floor( ( seconds % 3600 ) / 60 ) ] );
	}

	/**
	 * Set an element's text and show it, or hide it when the text is empty.
	 *
	 * @param {Element|null} element Element.
	 * @param {string}       text    Text.
	 * @param {boolean}      [keep]  Keep it visible even when empty.
	 */
	function setText( element, text, keep ) {
		if ( ! element ) {
			return;
		}

		element.textContent = text || '';
		element.hidden = ! keep && ! text;
	}

	/**
	 * One panel on the page.
	 *
	 * @param {Element} root The element carrying data-blt-toolkit-job.
	 */
	function Panel( root ) {
		var self = this;

		this.root = root;
		this.id = root.getAttribute( 'data-blt-toolkit-job' );
		this.view = null;
		this.receivedAt = 0;
		this.driving = false;
		this.stopped = false;
		this.inFlight = false;
		this.failures = 0;
		this.timer = null;
		this.fields = {};
		this.forms = {};

		[ 'summary', 'badge', 'actions', 'count', 'failed', 'elapsed', 'estimate', 'current', 'stalled', 'stalled-text', 'message', 'issues' ].forEach( function ( name ) {
			self.fields[ name ] = root.querySelector( '[data-field="' + name + '"]' );
		} );

		this.bar = root.querySelector( '.blt-progress' );
		this.fill = root.querySelector( '.blt-progress-fill' );

		Array.prototype.forEach.call( root.querySelectorAll( 'form[data-job-action]' ), function ( form ) {
			var verb = form.getAttribute( 'data-job-action' );

			self.forms[ verb ] = form;

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				self.act( verb, form );
			} );
		} );

		// The page drives a stalled job by itself, so the no-JavaScript
		// "Continue now" button is not needed.
		if ( this.forms.tick ) {
			this.forms.tick.hidden = true;
		}

		this.updateActions();

		window.setInterval( function () {
			self.updateClock();
		}, 1000 );

		if ( -1 === [ 'done', 'cancelled', 'failed' ].indexOf( root.getAttribute( 'data-status' ) ) ) {
			this.step();
		}
	}

	/**
	 * Call the REST route for this job.
	 *
	 * @param {string} method GET or POST.
	 * @param {string} path   '' for the job, or tick, cancel, retry.
	 * @return {Promise} Resolves to { ok, status, body }; rejects when offline.
	 */
	Panel.prototype.request = function ( method, path ) {
		var url = config.root + this.id + ( path ? '/' + path : '' );

		return window.fetch( url, {
			method: method,
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				'X-WP-Nonce': config.nonce,
				Accept: 'application/json',
			},
		} ).then( function ( response ) {
			return response.json().then(
				function ( body ) {
					return { ok: response.ok, status: response.status, body: body || {} };
				},
				function () {
					return { ok: false, status: response.status, body: {} };
				}
			);
		} );
	};

	/**
	 * Run the next poll or tick, unless one is already on its way.
	 */
	Panel.prototype.step = function () {
		var self = this;

		if ( this.stopped || this.inFlight ) {
			return;
		}

		this.inFlight = true;

		this.request( this.driving ? 'POST' : 'GET', this.driving ? 'tick' : '' ).then(
			function ( result ) {
				self.inFlight = false;
				self.handle( result );
			},
			function () {
				self.inFlight = false;
				self.offline();
			}
		);
	};

	/**
	 * Wait, then step.
	 *
	 * @param {number} delay Milliseconds.
	 */
	Panel.prototype.schedule = function ( delay ) {
		var self = this;

		window.clearTimeout( this.timer );

		this.timer = window.setTimeout( function () {
			self.step();
		}, delay );
	};

	/**
	 * Handle a poll or tick response.
	 *
	 * @param {Object} result Result of request().
	 */
	Panel.prototype.handle = function ( result ) {
		if ( ! result.ok ) {
			this.problem( result );
			return;
		}

		this.failures = 0;
		setText( this.fields.message, '' );
		this.render( result.body );
		this.next();
	};

	/**
	 * Decide what comes after a response: stop, poll again, or drive the job.
	 */
	Panel.prototype.next = function () {
		var view = this.view;

		if ( ! view || view.terminal ) {
			this.stop();
			return;
		}

		if ( view.stalled && ! this.driving ) {
			// JOB-4: nothing is running the job in the background.
			this.driving = true;
		} else if ( this.driving && false === view.ran && 'busy' === view.note && ! view.stalled ) {
			// A background tick holds the job: the background is back.
			this.driving = false;
		}

		this.showForeground();

		if ( this.driving ) {
			this.schedule( false === view.ran ? interval : 0 );
		} else {
			this.schedule( interval );
		}
	};

	/**
	 * Stop polling and driving.
	 */
	Panel.prototype.stop = function () {
		this.stopped = true;
		this.driving = false;
		window.clearTimeout( this.timer );
		this.showForeground();
	};

	/**
	 * The request did not reach the server: say so, and try again later.
	 */
	Panel.prototype.offline = function () {
		this.failures++;

		if ( this.failures > 1 ) {
			setText( this.fields.message, strings.lost );
		}

		this.schedule( Math.min( maxBackoff, interval * Math.pow( 2, this.failures ) ) );
	};

	/**
	 * The server answered with an error.
	 *
	 * @param {Object} result Result of request().
	 */
	Panel.prototype.problem = function ( result ) {
		var code = result.body && result.body.code ? result.body.code : '';

		if ( 401 === result.status || 'rest_cookie_invalid_nonce' === code ) {
			setText( this.fields.message, strings.expired );
			this.stop();
			return;
		}

		if ( 404 === result.status ) {
			setText( this.fields.message, strings.gone );
			this.disableForms();
			this.stop();
			return;
		}

		if ( 403 === result.status ) {
			setText( this.fields.message, result.body && result.body.message ? result.body.message : strings.forbidden );
			this.disableForms();
			this.stop();
			return;
		}

		this.offline();
	};

	/**
	 * Send Cancel, Retry failed or Continue now through REST.
	 *
	 * @param {string}  verb cancel, retry or tick.
	 * @param {Element} form The form.
	 */
	Panel.prototype.act = function ( verb, form ) {
		var self = this;
		var button = form.querySelector( 'button' );

		if ( 'cancel' === verb && ! window.confirm( strings.confirmCancel ) ) {
			return;
		}

		if ( button ) {
			button.disabled = true;
		}

		this.request( 'POST', verb ).then(
			function ( result ) {
				if ( button ) {
					button.disabled = false;
				}

				if ( ! result.ok ) {
					if ( 409 === result.status && result.body && result.body.message ) {
						setText( self.fields.message, result.body.message );
						return;
					}

					self.problem( result );
					return;
				}

				setText( self.fields.message, '' );
				self.render( result.body );

				if ( ! result.body.terminal ) {
					// A retry starts a new pass; a cancel may still be finishing.
					self.stopped = false;
					self.driving = false;
					self.schedule( 0 );
				} else {
					self.stop();
				}
			},
			function () {
				if ( button ) {
					button.disabled = false;
				}

				setText( self.fields.message, strings.lost );
			}
		);
	};

	/**
	 * Put a job view on the page.
	 *
	 * @param {Object} view BLT_Toolkit_Jobs::view() as JSON.
	 */
	Panel.prototype.render = function ( view ) {
		var percent = null === view.percent || undefined === view.percent ? null : Math.max( 0, Math.min( 100, view.percent ) );

		this.view = view;
		this.receivedAt = Date.now();
		this.root.setAttribute( 'data-status', view.status );

		setText( this.fields.summary, view.summary, true );

		if ( this.fields.badge ) {
			this.fields.badge.className = 'blt-badge blt-badge-' + view.badge;
			this.fields.badge.textContent = view.status_label;
		}

		if ( this.fill ) {
			this.fill.style.width = ( null === percent ? 0 : percent ) + '%';
		}

		if ( this.bar ) {
			if ( null === percent ) {
				this.bar.removeAttribute( 'aria-valuenow' );
			} else {
				this.bar.setAttribute( 'aria-valuenow', String( percent ) );
			}
		}

		setText( this.fields.count, view.count_text, true );
		setText( this.fields.failed, view.failed_text, true );
		setText( this.fields.current, view.current_text );

		this.renderIssues( view.issues || [] );
		this.updateClock();

		if ( this.forms.cancel ) {
			this.forms.cancel.hidden = ! view.can_cancel || !! view.cancel_requested;
		}

		if ( this.forms.retry ) {
			this.forms.retry.hidden = ! view.can_retry;
		}

		this.updateActions();
	};

	/**
	 * Hide the toolbar when none of its buttons shows.
	 */
	Panel.prototype.updateActions = function () {
		var forms = this.forms;

		if ( ! this.fields.actions ) {
			return;
		}

		this.fields.actions.hidden = ! Object.keys( forms ).some( function ( verb ) {
			return ! forms[ verb ].hidden;
		} );
	};

	/**
	 * Rebuild the list of failed items and warnings.
	 *
	 * @param {Array} issues Each { kind, item, message }.
	 */
	Panel.prototype.renderIssues = function ( issues ) {
		var list = this.fields.issues;

		if ( ! list ) {
			return;
		}

		while ( list.firstChild ) {
			list.removeChild( list.firstChild );
		}

		issues.forEach( function ( issue ) {
			var item = document.createElement( 'li' );
			var text = issue.item ? format( strings.issueItem, [ issue.item, issue.message ] ) : issue.message;

			// Whole translated patterns, so translators control word order
			// and punctuation.
			item.textContent = format( 'failed' === issue.kind ? strings.failedIssue : strings.warningIssue, [ text ] );
			list.appendChild( item );
		} );

		list.hidden = 0 === issues.length;
	};

	/**
	 * Elapsed time, counted on between responses while the job runs, and
	 * the estimate.
	 */
	Panel.prototype.updateClock = function () {
		var view = this.view;
		var elapsed;
		var estimate;

		if ( ! view ) {
			return;
		}

		elapsed = view.elapsed || 0;

		if ( 'running' === view.status ) {
			elapsed += ( Date.now() - this.receivedAt ) / 1000;
		}

		if ( 'running' !== view.status && 'queued' !== view.status ) {
			estimate = strings.none;
		} else if ( null === view.estimate || undefined === view.estimate ) {
			estimate = strings.calculating;
		} else {
			estimate = format( strings.about, [ duration( view.estimate ) ] );
		}

		setText( this.fields.elapsed, duration( elapsed ), true );
		setText( this.fields.estimate, estimate, true );
	};

	/**
	 * Show the "keep this tab open" notice while the page drives the job.
	 */
	Panel.prototype.showForeground = function () {
		if ( this.fields.stalled ) {
			this.fields.stalled.hidden = ! this.driving;
		}

		if ( this.driving ) {
			setText( this.fields[ 'stalled-text' ], strings.foreground, true );
		}
	};

	/**
	 * Hide the buttons of a job that can no longer be used.
	 */
	Panel.prototype.disableForms = function () {
		var forms = this.forms;

		Object.keys( forms ).forEach( function ( verb ) {
			forms[ verb ].hidden = true;
		} );

		this.updateActions();
	};

	/**
	 * Start a panel for every job on the page.
	 */
	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-blt-toolkit-job]' ), function ( root ) {
			if ( ! root.bltToolkitJob ) {
				root.bltToolkitJob = new Panel( root );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
