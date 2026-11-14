/**
 * DFX Prayer Intentions: the leaders' app.
 *
 * Plain script, no build step. Every piece of data comes from the plugin's
 * private API (admin-ajax, op=…). The message of the day and the lists are
 * kept on the device to be read without connection (summaries only, never
 * the full text of a request); changes made without connection wait in a
 * queue and are sent when it comes back.
 */
( function () {
	'use strict';

	const C = window.DFXPRAY_APP;
	const S = C.strings;
	const root = document.getElementById( 'dfxpray-app' );

	const store = {
		get( key, fallback ) {
			try {
				const value = window.localStorage.getItem( 'dfxpray:' + key );
				return null === value ? fallback : JSON.parse( value );
			} catch ( e ) {
				return fallback;
			}
		},
		set( key, value ) {
			try {
				window.localStorage.setItem( 'dfxpray:' + key, JSON.stringify( value ) );
			} catch ( e ) {}
		},
	};

	const state = {
		groups: [],
		group: store.get( 'group', 0 ),
		tab: 'today',
		filter: 'current',
		search: '',
		notice: null,
	};

	/* ------------------------------------------------------------------ */
	/* DOM helpers: text is always set as text, never as HTML.             */
	/* ------------------------------------------------------------------ */

	function h( tag, attrs, ...children ) {
		const el = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( key.startsWith( 'on' ) ) {
				el.addEventListener( key.slice( 2 ), value );
			} else if ( 'checked' === key || 'value' === key ) {
				el[ key ] = value;
			} else {
				el.setAttribute( key, true === value ? '' : value );
			}
		} );
		children.flat( Infinity ).forEach( ( child ) => {
			if ( null === child || undefined === child || false === child ) {
				return;
			}
			el.append( child instanceof Node ? child : document.createTextNode( String( child ) ) );
		} );
		return el;
	}

	function field( label, control, help ) {
		return h( 'label', { class: 'dfx-field' }, h( 'span', { class: 'dfx-label' }, label ), control, help ? h( 'small', {}, help ) : null );
	}

	function check( label, control ) {
		return h( 'label', { class: 'dfx-check' }, control, ' ', label );
	}

	function select( name, options, value ) {
		return h( 'select', { name }, Object.entries( options ).map( ( [ key, text ] ) => h( 'option', { value: key, selected: key === value ? 'selected' : null }, text ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* API                                                                 */
	/* ------------------------------------------------------------------ */

	function params( body, data, prefix ) {
		Object.entries( data ).forEach( ( [ key, value ] ) => {
			const name = prefix ? prefix + '[' + key + ']' : key;
			if ( value && 'object' === typeof value ) {
				params( body, value, name );
			} else if ( undefined !== value && null !== value ) {
				body.append( name, String( value ) );
			}
		} );
	}

	async function api( op, data, cacheKey ) {
		const body = new URLSearchParams();
		params( body, Object.assign( { action: C.action, _nonce: C.nonce, op }, data || {} ) );

		try {
			const response = await fetch( C.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } );
			const json = await response.json();
			if ( cacheKey && json.ok ) {
				store.set( 'cache:' + cacheKey, json.data );
			}
			return json;
		} catch ( e ) {
			const cached = cacheKey ? store.get( 'cache:' + cacheKey, null ) : null;
			return cached ? { ok: true, data: cached, offline: true } : { ok: false, code: 'offline', message: S.offline };
		}
	}

	async function write( op, data ) {
		if ( ! navigator.onLine ) {
			const queue = store.get( 'queue', [] );
			queue.push( { op, data } );
			store.set( 'queue', queue );
			return { ok: true, queued: true, message: S.queued };
		}
		const result = await api( op, data );
		if ( ! result.ok && 'offline' === result.code ) {
			const queue = store.get( 'queue', [] );
			queue.push( { op, data } );
			store.set( 'queue', queue );
			return { ok: true, queued: true, message: S.queued };
		}
		return result;
	}

	async function flushQueue() {
		const queue = store.get( 'queue', [] );
		const left = [];
		for ( const item of queue ) {
			const result = await api( item.op, item.data );
			if ( ! result.ok && 'offline' === result.code ) {
				left.push( item );
			}
		}
		store.set( 'queue', left );
		if ( queue.length !== left.length ) {
			render();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Shell                                                               */
	/* ------------------------------------------------------------------ */

	function notify( result ) {
		state.notice = result ? { ok: result.ok, text: result.message || ( result.ok ? S.saved : S.error ) } : null;
	}

	function header() {
		const groups = state.groups.length > 1 ? select( 'group', Object.fromEntries( state.groups.map( ( g ) => [ String( g.id ), g.name ] ) ), String( state.group ) ) : null;
		if ( groups ) {
			groups.setAttribute( 'aria-label', S.group );
			groups.addEventListener( 'change', () => {
				state.group = Number( groups.value );
				store.set( 'group', state.group );
				go( state.tab );
			} );
		}
		const current = state.groups.find( ( g ) => g.id === state.group );
		return h( 'header', { class: 'dfx-header' }, h( 'h1', {}, S.appName ), groups || ( current ? h( 'span', { class: 'dfx-group' }, current.name ) : null ) );
	}

	function nav() {
		const tabs = { today: S.today, intentions: S.intentions, moderation: S.moderation, add: S.add };
		return h(
			'nav',
			{ class: 'dfx-nav' },
			Object.entries( tabs ).map( ( [ key, label ] ) => h( 'button', { type: 'button', class: key === state.tab ? 'is-active' : null, 'aria-current': key === state.tab ? 'page' : null, onclick: () => go( key ) }, label ) )
		);
	}

	function frame( ...content ) {
		const queue = store.get( 'queue', [] );
		root.replaceChildren(
			header(),
			h(
				'main',
				{ class: 'dfx-main' },
				state.notice ? h( 'p', { class: 'dfx-notice ' + ( state.notice.ok ? 'is-ok' : 'is-error' ), role: 'status' }, state.notice.text ) : null,
				queue.length ? h( 'p', { class: 'dfx-notice' }, S.pendingSend + ' ' + queue.length ) : null,
				content
			),
			nav()
		);
		state.notice = null;
	}

	// Each view takes a ticket; a view that finishes loading after another one
	// was opened does not draw over it.
	let latest = 0;

	function ticket() {
		latest += 1;
		return latest;
	}

	function stale( t ) {
		return t !== latest;
	}

	function loading() {
		frame( h( 'p', { class: 'dfx-muted' }, S.loading ) );
	}

	function go( tab ) {
		state.tab = tab;
		render();
	}

	async function render() {
		if ( ! state.groups.length ) {
			return frame( h( 'p', {}, S.noGroups ) );
		}
		switch ( state.tab ) {
			case 'intentions':
				return intentions();
			case 'moderation':
				return moderation();
			case 'add':
				return addForm();
			default:
				return today();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Today: the message to share                                         */
	/* ------------------------------------------------------------------ */

	async function today() {
		const t = ticket();
		loading();
		const result = await api( 'index', { group_id: state.group }, 'index:' + state.group );
		if ( stale( t ) ) {
			return;
		}
		if ( ! result.ok ) {
			return frame( h( 'p', { class: 'dfx-notice is-error' }, result.message ) );
		}
		const text = result.data.text;
		const copy = h( 'button', { type: 'button', class: 'dfx-button' }, S.copy );
		copy.addEventListener( 'click', async () => {
			await navigator.clipboard.writeText( text );
			copy.textContent = S.copied;
		} );
		frame(
			result.offline ? h( 'p', { class: 'dfx-notice' }, S.offline ) : null,
			h(
				'div',
				{ class: 'dfx-actions' },
				navigator.share ? h( 'button', { type: 'button', class: 'dfx-button is-primary', onclick: () => navigator.share( { text } ).catch( () => {} ) }, S.share ) : null,
				copy
			),
			h( 'pre', { class: 'dfx-message', id: 'dfx-message' }, text )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Lists                                                               */
	/* ------------------------------------------------------------------ */

	function itemList( items ) {
		if ( ! items.length ) {
			return h( 'p', { class: 'dfx-muted' }, S.nothing );
		}
		return h(
			'ul',
			{ class: 'dfx-list' },
			items.map( ( item ) => h( 'li', {}, h( 'button', { type: 'button', class: 'dfx-item', onclick: () => detail( item.id ) }, item.line, h( 'small', {}, C.statuses[ item.status ] || '' ) ) ) )
		);
	}

	async function intentions() {
		const t = ticket();
		loading();
		const result = await api( 'list', { group_id: state.group, status: state.filter, q: state.search }, state.search ? null : 'list:' + state.group + ':' + state.filter );
		if ( stale( t ) ) {
			return;
		}
		const search = h( 'input', { type: 'search', value: state.search, placeholder: S.search, 'aria-label': S.search } );
		search.addEventListener( 'change', () => {
			state.search = search.value;
			intentions();
		} );
		const filter = select( 'status', { current: S.current, review: S.toReview, closed: C.statuses.closed }, state.filter );
		filter.setAttribute( 'aria-label', S.intentions );
		filter.addEventListener( 'change', () => {
			state.filter = filter.value;
			intentions();
		} );
		frame(
			h( 'div', { class: 'dfx-toolbar' }, search, filter ),
			result.offline ? h( 'p', { class: 'dfx-notice' }, S.offline ) : null,
			result.ok ? itemList( result.data.items ) : h( 'p', { class: 'dfx-notice is-error' }, result.message )
		);
	}

	async function moderation() {
		const t = ticket();
		loading();
		const [ pending, review ] = await Promise.all( [
			api( 'list', { group_id: state.group, status: 'pending' }, 'list:' + state.group + ':pending' ),
			api( 'list', { group_id: state.group, status: 'review' }, 'list:' + state.group + ':review' ),
		] );
		if ( stale( t ) ) {
			return;
		}
		frame(
			h( 'h2', {}, S.toModerate ),
			pending.ok ? itemList( pending.data.items ) : h( 'p', { class: 'dfx-notice is-error' }, pending.message ),
			h( 'h2', {}, S.toReview ),
			review.ok ? itemList( review.data.items ) : h( 'p', { class: 'dfx-notice is-error' }, review.message )
		);
	}

	/* ------------------------------------------------------------------ */
	/* One intention                                                       */
	/* ------------------------------------------------------------------ */

	function form( onsubmit, ...content ) {
		return h(
			'form',
			{
				class: 'dfx-form',
				onsubmit: async ( event ) => {
					event.preventDefault();
					await onsubmit( new FormData( event.target ) );
				},
			},
			content
		);
	}

	async function act( id, op, data ) {
		const result = await write( op, Object.assign( { intention_id: id }, data ) );
		notify( result );
		return detail( id, true );
	}

	async function detail( id, keepNotice ) {
		const notice = keepNotice ? state.notice : null;
		const t = ticket();
		loading();
		state.notice = notice;
		const result = await api( 'get', { intention_id: id } );
		if ( stale( t ) ) {
			return;
		}
		if ( ! result.ok ) {
			return frame( h( 'p', { class: 'dfx-notice is-error' }, result.message ), h( 'button', { type: 'button', class: 'dfx-button', onclick: () => go( state.tab ) }, S.back ) );
		}
		const it = result.data.intention;
		const open = 'active' === it.status || 'review' === it.status;
		const blocks = [];

		if ( 'pending' === it.status ) {
			let confirming = false;
			const discard = h( 'button', { type: 'button', class: 'dfx-button is-danger' }, S.discard );
			discard.addEventListener( 'click', async () => {
				if ( ! confirming ) {
					confirming = true;
					discard.textContent = S.discardConfirm;
					return;
				}
				notify( await write( 'discard', { intention_id: id, reason: 'spam' } ) );
				go( 'moderation' );
			} );
			blocks.push(
				form(
					async ( data ) => {
						notify( await write( 'approve', { intention_id: id, summary: data.get( 'summary' ), priority: data.get( 'priority' ) ? 'high' : 'normal', discretion: it.discretion } ) );
						go( 'moderation' );
					},
					field( S.summary, h( 'input', { name: 'summary', required: true, maxlength: 100 } ) ),
					check( S.highPriority, h( 'input', { type: 'checkbox', name: 'priority', value: '1' } ) ),
					h( 'div', { class: 'dfx-actions' }, h( 'button', { class: 'dfx-button is-primary' }, S.approve ), discard )
				)
			);
		}

		if ( open ) {
			blocks.push(
				form(
					( data ) => act( id, 'add_update', { body: data.get( 'body' ), is_news: data.get( 'is_news' ) ? 1 : '' } ),
					field( S.newUpdate, h( 'textarea', { name: 'body', rows: 3, required: true } ) ),
					check( S.asNews, h( 'input', { type: 'checkbox', name: 'is_news', value: '1', checked: true } ) ),
					h( 'button', { class: 'dfx-button is-primary' }, S.addUpdate )
				),
				form(
					( data ) => act( id, 'save_intention', { group_id: it.group_id, intention: { summary: data.get( 'summary' ) } } ),
					field( S.summary, h( 'input', { name: 'summary', value: it.summary, maxlength: 100, required: true } ) ),
					h( 'button', { class: 'dfx-button' }, S.save )
				),
				h(
					'div',
					{ class: 'dfx-actions' },
					'review' === it.status ? h( 'button', { type: 'button', class: 'dfx-button is-primary', onclick: () => act( id, 'keep_active', {} ) }, S.stillCurrent ) : null,
					h( 'button', { type: 'button', class: 'dfx-button', onclick: () => act( id, 'set_priority', { priority: 'high' === it.priority ? 'normal' : 'high' } ) }, 'high' === it.priority ? S.markNormal : S.markHigh )
				),
				form(
					( data ) => act( id, 'postpone', { date: data.get( 'date' ) } ),
					field( S.postponeTo, h( 'input', { type: 'date', name: 'date', required: true } ) ),
					h( 'button', { class: 'dfx-button' }, S.postpone )
				),
				form(
					( data ) => act( id, 'close', { reason: data.get( 'reason' ), thanksgiving: data.get( 'thanksgiving' ) ? 1 : '', thanksgiving_summary: data.get( 'thanksgiving_summary' ) || '' } ),
					field( S.closeAs, select( 'reason', C.reasons, 'answered' ) ),
					check( S.addThanksgiving, h( 'input', { type: 'checkbox', name: 'thanksgiving', value: '1' } ) ),
					field( S.thanksSummary, h( 'input', { name: 'thanksgiving_summary', maxlength: 100 } ) ),
					h( 'button', { class: 'dfx-button' }, S.close )
				)
			);
		}

		if ( 'closed' === it.status ) {
			blocks.push( h( 'button', { type: 'button', class: 'dfx-button is-primary', onclick: () => act( id, 'reopen', {} ) }, S.reopen ) );
		}

		const updates = result.data.updates;

		frame(
			h( 'button', { type: 'button', class: 'dfx-back', onclick: () => go( state.tab ) }, '← ' + S.back ),
			h( 'h2', { class: 'dfx-line', id: 'dfx-line' }, result.data.line ),
			h( 'p', { class: 'dfx-muted' }, [ C.statuses[ it.status ], C.types[ it.type ], it.next_review ? S.nextReview + ': ' + it.next_review : null ].filter( Boolean ).join( ' · ' ) ),
			h( 'details', {}, h( 'summary', {}, S.fullText ), h( 'p', { class: 'dfx-body' }, it.body ), h( 'small', {}, S.neverPublished ) ),
			blocks,
			updates.length ? h( 'h2', {}, S.updates ) : null,
			updates.length ? h( 'ul', { class: 'dfx-timeline' }, updates.map( ( u ) => h( 'li', {}, h( 'small', {}, u.created_at.slice( 0, 16 ) + ( u.author ? ' · ' + u.author : '' ) ), h( 'p', {}, u.body ) ) ) ) : null
		);
	}

	/* ------------------------------------------------------------------ */
	/* Add                                                                 */
	/* ------------------------------------------------------------------ */

	function addForm() {
		ticket();
		frame(
			h( 'h2', {}, S.addIntention ),
			form(
				async ( data ) => {
					const intention = {};
					data.forEach( ( value, key ) => {
						intention[ key ] = value;
					} );
					intention.priority = data.get( 'priority' ) ? 'high' : 'normal';
					const result = await write( 'save_intention', { intention_id: 0, group_id: state.group, intention } );
					notify( result );
					if ( result.ok && result.id ) {
						state.tab = 'intentions';
						return detail( result.id, true );
					}
					return render();
				},
				field( S.requester, h( 'input', { name: 'requester_name', required: true } ) ),
				check( S.member, h( 'input', { type: 'checkbox', name: 'requester_is_member', value: '1', checked: true } ) ),
				check( S.forThemselves, h( 'input', { type: 'checkbox', name: 'beneficiary_is_requester', value: '1' } ) ),
				field( S.beneficiary, h( 'input', { name: 'beneficiary_name' } ) ),
				field( S.relationship, h( 'input', { name: 'relationship', maxlength: 100 } ) ),
				check( S.member, h( 'input', { type: 'checkbox', name: 'beneficiary_is_member', value: '1' } ) ),
				field( S.kind, select( 'type', C.types, 'ongoing' ) ),
				field( S.referenceDate, h( 'input', { type: 'date', name: 'reference_date' } ) ),
				field( S.summary, h( 'input', { name: 'summary', maxlength: 100, required: true } ) ),
				field( S.fullText, h( 'textarea', { name: 'body', rows: 4, required: true } ), S.neverPublished ),
				check( S.highPriority, h( 'input', { type: 'checkbox', name: 'priority', value: '1' } ) ),
				h( 'button', { class: 'dfx-button is-primary' }, S.addIntention )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Start                                                               */
	/* ------------------------------------------------------------------ */

	async function start() {
		const result = await api( 'bootstrap', {}, 'bootstrap' );
		state.groups = result.ok ? result.data.groups : [];
		if ( ! state.groups.some( ( g ) => g.id === state.group ) && state.groups.length ) {
			state.group = state.groups[ 0 ].id;
		}
		render();
		flushQueue();
	}

	window.addEventListener( 'online', flushQueue );

	if ( 'serviceWorker' in navigator ) {
		navigator.serviceWorker.register( C.worker, { scope: C.appUrl } ).catch( () => {} );
	}

	start();
}() );
