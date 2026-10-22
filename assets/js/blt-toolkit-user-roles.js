/**
 * BLT Toolkit: additional roles on the profile and Add New User (SPEC 11, US-1).
 *
 * BLT_Toolkit_User_Roles prints an "Additional roles" section at the end of
 * core's form that works without this file. This script only moves its row
 * directly under core's Role row, where SPEC 11 places it, and hides the
 * section it came from. The row stays inside the same form, so nothing about
 * the submit changes.
 */
( function () {
	'use strict';

	/**
	 * Move the row under the Role row, if both are on the page.
	 *
	 * @return {void}
	 */
	function move() {
		var row = document.getElementById( 'blt-tk-additional-roles-row' );
		var role = document.getElementById( 'role' );
		var section = document.getElementById( 'blt-tk-additional-roles' );

		if ( ! row || ! role || ! section ) {
			return;
		}

		var roleRow = role.closest( 'tr' );

		if ( ! roleRow || ! roleRow.parentNode || roleRow.contains( row ) ) {
			return;
		}

		roleRow.parentNode.insertBefore( row, roleRow.nextSibling );
		section.hidden = true;
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', move );
	} else {
		move();
	}
}() );
