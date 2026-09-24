/**
 * Progressive enhancement of the request form: ask for the date only when
 * the chosen type needs it, and hide the beneficiary fields for "for me".
 */
( function () {
	'use strict';

	function setup( form ) {
		const self = form.querySelector( '[data-dfxpray-self]' );
		const other = form.querySelector( '[data-dfxpray-other]' );
		const dateBox = form.querySelector( '[data-dfxpray-date]' );
		const timeBox = form.querySelector( '[data-dfxpray-time]' );
		const dateLabel = form.querySelector( '[data-dfxpray-date-label]' );
		const types = form.querySelectorAll( 'input[name="dfxpray[type]"]' );

		function refreshSelf() {
			if ( self && other ) {
				other.hidden = self.checked;
			}
		}

		function refreshType() {
			const chosen = form.querySelector( 'input[name="dfxpray[type]"]:checked' );
			const needsDate = chosen && '1' === chosen.dataset.needsDate;
			const hasTime = chosen && '1' === chosen.dataset.hasTime;

			if ( dateBox ) {
				dateBox.hidden = ! needsDate;
			}
			if ( timeBox ) {
				timeBox.hidden = ! hasTime;
			}
			if ( dateLabel && chosen && chosen.dataset.dateLabel ) {
				dateLabel.textContent = chosen.dataset.dateLabel;
			}
		}

		if ( self ) {
			self.addEventListener( 'change', refreshSelf );
		}
		types.forEach( ( input ) => input.addEventListener( 'change', refreshType ) );

		refreshSelf();
		refreshType();
	}

	document.querySelectorAll( '.dfxpray-form form' ).forEach( setup );
} )();
