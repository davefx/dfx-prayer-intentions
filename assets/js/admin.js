/**
 * Panel helpers: Copy buttons, extra key-date rows and confirmations.
 */
( function () {
	'use strict';

	const strings = window.dfxprayAdmin || { copied: 'Copied!', copy: 'Copy', confirmDelete: 'Continue?' };

	function copyText( textarea ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( textarea.value );
		}

		textarea.focus();
		textarea.select();
		document.execCommand( 'copy' );
		return Promise.resolve();
	}

	document.addEventListener( 'click', function ( event ) {
		const copy = event.target.closest( '[data-dfxpray-copy]' );
		if ( copy ) {
			const textarea = document.getElementById( copy.dataset.dfxprayCopy );
			if ( textarea ) {
				copyText( textarea ).then( function () {
					copy.textContent = strings.copied;
					copy.dataset.copied = '1';
					window.setTimeout( function () {
						copy.textContent = strings.copy;
						delete copy.dataset.copied;
					}, 2000 );
				} );
			}
			return;
		}

		const add = event.target.closest( '[data-dfxpray-add-row]' );
		if ( add ) {
			const rows = add.parentElement.querySelector( '[data-dfxpray-rows]' );
			const last = rows && rows.querySelector( '[data-dfxpray-row]:last-child' );
			if ( last ) {
				const clone = last.cloneNode( true );
				clone.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.value = '';
				} );
				rows.appendChild( clone );
			}
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		if ( event.target.classList.contains( 'dfxpray-confirm' ) && ! window.confirm( strings.confirmDelete ) ) {
			event.preventDefault();
		}
	} );
} )();
