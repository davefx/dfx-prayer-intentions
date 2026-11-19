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

		const link = event.target.closest( '[data-dfxpray-copy-text]' );
		if ( link ) {
			const done = function () {
				link.textContent = strings.copied;
				window.setTimeout( function () {
					link.textContent = strings.copyLink || strings.copy;
				}, 2000 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( link.dataset.dfxprayCopyText ).then( done );
			} else {
				const field = document.createElement( 'textarea' );
				field.value = link.dataset.dfxprayCopyText;
				document.body.appendChild( field );
				field.select();
				document.execCommand( 'copy' );
				field.remove();
				done();
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

	/*
	 * The intention form: only the fields that apply to its kind, the
	 * beneficiary only when it is someone else, "How we call them" when the
	 * name has more than one word, and no review date when it ends on a day.
	 */
	function syncIntention( form ) {
		const field = function ( name ) {
			return form.querySelector( '[name="intention[' + name + ']"]' );
		};
		const show = function ( node, visible ) {
			node.hidden = ! visible;
			node.querySelectorAll( 'input, select, textarea' ).forEach( function ( control ) {
				control.disabled = ! visible;
			} );
		};
		const words = function ( text ) {
			return String( text || '' ).trim().split( /\s+/ ).filter( Boolean );
		};

		const kind = field( 'type' ) ? field( 'type' ).value : '';
		form.querySelectorAll( '[data-dfxpray-kinds]' ).forEach( function ( node ) {
			show( node, node.dataset.dfxprayKinds.split( ' ' ).indexOf( kind ) !== -1 );
		} );
		const dateLabel = form.querySelector( '[data-dfxpray-date-label]' );
		if ( dateLabel ) {
			dateLabel.textContent = 'deceased' === kind ? dateLabel.dataset.death : dateLabel.dataset.date;
		}

		const forThemselves = field( 'beneficiary_is_requester' );
		form.querySelectorAll( '[data-dfxpray-beneficiary]' ).forEach( function ( node ) {
			show( node, ! ( forThemselves && forThemselves.checked ) );
		} );

		form.querySelectorAll( '[data-dfxpray-called]' ).forEach( function ( node ) {
			const person = node.dataset.dfxprayCalled;
			const name = field( person + '_name' );
			const called = field( person + '_first_name' );
			if ( 'beneficiary' === person && forThemselves && forThemselves.checked ) {
				return;
			}
			show( node, words( name.value ).length > 1 || '' !== called.value.trim() );
			called.placeholder = words( name.value )[ 0 ] || '';
		} );

		const review = field( 'next_review' );
		const ends = field( 'ends_on' );
		if ( review && ends ) {
			review.disabled = '' !== ends.value;
		}
	}

	document.querySelectorAll( 'form.dfxpray-steps' ).forEach( function ( form ) {
		form.addEventListener( 'input', function () {
			syncIntention( form );
		} );
		form.addEventListener( 'change', function () {
			syncIntention( form );
		} );
		syncIntention( form );
	} );

	document.addEventListener( 'submit', function ( event ) {
		if ( event.target.classList.contains( 'dfxpray-confirm' ) && ! window.confirm( strings.confirmDelete ) ) {
			event.preventDefault();
		}
	} );
} )();
