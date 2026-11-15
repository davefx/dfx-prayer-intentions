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
		edition: 'daily',
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
	/* Dates, icons and lines                                              */
	/* ------------------------------------------------------------------ */

	const LOCALE = document.documentElement.lang || undefined;

	// "2026-11-13 08:30:00" or "2026-11-13", read as a local date.
	function toDate( value ) {
		const m = /^(\d{4})-(\d{2})-(\d{2})/.exec( value || '' );
		return m ? new Date( Number( m[ 1 ] ), Number( m[ 2 ] ) - 1, Number( m[ 3 ] ) ) : null;
	}

	function formatDate( value, options ) {
		const date = toDate( value );
		return date ? new Intl.DateTimeFormat( LOCALE, options ).format( date ) : '';
	}

	// The calendar leaf of a card: big day, short month.
	function leaf( value ) {
		const date = toDate( value );
		if ( ! date ) {
			return null;
		}
		return h(
			'span',
			{ class: 'dfx-leaf', 'aria-label': formatDate( value, { day: 'numeric', month: 'long', year: 'numeric' } ) },
			h( 'span', { class: 'dfx-leaf-day' }, formatDate( value, { day: 'numeric' } ) ),
			h( 'span', { class: 'dfx-leaf-month' }, formatDate( value, { month: 'short' } ).replace( '.', '' ) )
		);
	}

	const ICONS = {
		today: 'M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4M12 8a4 4 0 1 0 0 8a4 4 0 1 0 0-8',
		intentions: 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
		moderation: 'M4 13l2-8h12l2 8M4 13v6h16v-6M4 13h5l1 2h4l1-2h5',
		add: 'M12 5v14M5 12h14',
		ask: 'M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12zM9.6 9.4a2.4 2.4 0 1 1 3.3 2.2c-.5.3-.9.7-.9 1.4M12 15.8h.01',
	};

	function icon( name ) {
		const svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		const path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		path.setAttribute( 'd', ICONS[ name ] );
		svg.append( path );
		return svg;
	}

	// "❗ Carmen, madre de Luis: quimioterapia" -> person in bold, then the summary.
	function lineParts( line ) {
		const text = String( line || '' ).replace( /^❗\s*/, '' );
		const cut = text.indexOf( ': ' );
		return -1 === cut ? [ h( 'strong', {}, text ) ] : [ h( 'strong', {}, text.slice( 0, cut ) ), h( 'span', { class: 'dfx-sep' }, ': ' ), h( 'span', { class: 'dfx-summary' }, text.slice( cut + 2 ) ) ];
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
		return h(
			'header',
			{ class: 'dfx-header' },
			h( 'img', { class: 'dfx-logo', src: C.icon, alt: '' } ),
			h( 'div', { class: 'dfx-title' }, h( 'h1', {}, S.appName ), groups || ( current ? h( 'span', { class: 'dfx-group' }, current.name ) : null ) )
		);
	}

	function nav() {
		const tabs = { today: S.today, intentions: S.intentions, ask: S.toAskTab, moderation: S.moderation, add: S.add };
		return h(
			'nav',
			{ class: 'dfx-nav' },
			Object.entries( tabs ).map( ( [ key, label ] ) => h( 'button', { type: 'button', class: key === state.tab ? 'is-active' : null, 'aria-current': key === state.tab ? 'page' : null, onclick: () => go( key ) }, icon( key ), h( 'span', {}, label ) ) )
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

	/*
	 * History: every screen is an entry, so Android's back button moves
	 * through the app. The first entry is a guard: going back to it keeps
	 * the leader on "Today" instead of leaving for the page before the app
	 * (often the login page).
	 */
	function remember( entry ) {
		history.pushState( Object.assign( { dfx: true }, entry ), '' );
	}

	function go( tab, fromHistory ) {
		state.tab = tab;
		if ( ! fromHistory ) {
			remember( { tab } );
		}
		render();
	}

	window.addEventListener( 'popstate', ( event ) => {
		const entry = event.state;
		if ( ! entry || ! entry.dfx || entry.guard ) {
			remember( { tab: 'today' } );
			return go( 'today', true );
		}
		if ( entry.id ) {
			state.tab = entry.tab;
			return detail( entry.id, false, true );
		}
		return go( entry.tab, true );
	} );

	async function render() {
		if ( ! state.groups.length ) {
			return frame( h( 'p', {}, S.noGroups ) );
		}
		switch ( state.tab ) {
			case 'intentions':
				return intentions();
			case 'ask':
				return toAsk();
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
		const result = await api( 'index', { group_id: state.group, edition: state.edition }, 'index:' + state.edition + ':' + state.group );
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
		const edition = ( key, label ) =>
			h(
				'button',
				{
					type: 'button',
					class: 'dfx-segment' + ( key === state.edition ? ' is-active' : '' ),
					'aria-pressed': key === state.edition ? 'true' : 'false',
					onclick: () => {
						state.edition = key;
						today();
					},
				},
				label
			);
		frame(
			result.offline ? h( 'p', { class: 'dfx-notice' }, S.offline ) : null,
			h( 'div', { class: 'dfx-segments', role: 'group' }, edition( 'daily', S.daily ), edition( 'monthly', S.monthly ) ),
			h(
				'div',
				{ class: 'dfx-actions' },
				navigator.share ? h( 'button', { type: 'button', class: 'dfx-button is-primary', onclick: () => navigator.share( { text } ).catch( () => {} ) }, S.share ) : null,
				copy
			),
			h( 'article', { class: 'dfx-card dfx-paper' }, h( 'pre', { class: 'dfx-message', id: 'dfx-message' }, text ) )
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
			items.map( ( item ) =>
				h(
					'li',
					{},
					h(
						'button',
						{ type: 'button', class: 'dfx-item' + ( 'high' === item.priority ? ' is-urgent' : '' ), onclick: () => detail( item.id ) },
						leaf( item.created_at ),
						h(
							'span',
							{ class: 'dfx-item-body' },
							h( 'span', { class: 'dfx-item-line' }, lineParts( item.line ) ),
							item.requester ? h( 'span', { class: 'dfx-requester' }, S.requestedBy + ' ' + item.requester ) : null,
							h(
								'span',
								{ class: 'dfx-item-meta' },
								h( 'span', { class: 'dfx-chip is-' + item.status }, C.statuses[ item.status ] || '' ),
								item.to_ask ? h( 'span', { class: 'dfx-chip is-ask' }, S.toAskMark ) : null,
								item.next_review ? h( 'small', { class: 'dfx-review' }, S.nextReview + ': ' + formatDate( item.next_review, { day: 'numeric', month: 'short' } ) ) : null
							)
						)
					)
				)
			)
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

	async function toAsk() {
		const t = ticket();
		loading();
		const result = await api( 'list', { group_id: state.group, status: 'ask' }, 'list:' + state.group + ':ask' );
		if ( stale( t ) ) {
			return;
		}
		frame(
			result.offline ? h( 'p', { class: 'dfx-notice' }, S.offline ) : null,
			h( 'h2', {}, S.toAskTitle ),
			h( 'p', { class: 'dfx-muted' }, S.toAskHelp ),
			! result.ok ? h( 'p', { class: 'dfx-notice is-error' }, result.message ) : null,
			result.ok && ! result.data.items.length ? h( 'p', { class: 'dfx-muted' }, S.nothing ) : null,
			result.ok
				? ( result.data.groups || [] ).map( ( group ) =>
						h(
							'section',
							{ class: 'dfx-ask-group' },
							h( 'h3', { class: 'dfx-ask-who' }, group.requester ),
							group.ask ? h( 'a', { class: 'dfx-button dfx-ask', href: group.ask, target: '_blank', rel: 'noopener' }, group.ids.length > 1 ? S.askAllWhatsApp : S.askWhatsApp ) : h( 'p', { class: 'dfx-muted' }, S.noPhone ),
							itemList( result.data.items.filter( ( item ) => group.ids.includes( item.id ) ) )
						)
				  )
				: null
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

	async function detail( id, keepNotice, fromHistory ) {
		if ( ! keepNotice && ! fromHistory ) {
			remember( { tab: state.tab, id } );
		}
		const notice = keepNotice ? state.notice : null;
		const t = ticket();
		loading();
		state.notice = notice;
		const result = await api( 'get', { intention_id: id } );
		if ( stale( t ) ) {
			return;
		}
		if ( ! result.ok ) {
			return frame( h( 'p', { class: 'dfx-notice is-error' }, result.message ), h( 'button', { type: 'button', class: 'dfx-button', onclick: () => history.back() }, S.back ) );
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

		if ( open || 'pending' === it.status ) {
			blocks.unshift( editForm( it ) );
		}

		if ( 'closed' === it.status ) {
			blocks.push( h( 'button', { type: 'button', class: 'dfx-button is-primary', onclick: () => act( id, 'reopen', {} ) }, S.reopen ) );
		}

		const updates = result.data.updates;

		frame(
			h( 'button', { type: 'button', class: 'dfx-back', onclick: () => history.back() }, '← ' + S.back ),
			h(
				'section',
				{ class: 'dfx-hero' + ( 'high' === it.priority ? ' is-urgent' : '' ) },
				h( 'p', { class: 'dfx-created' }, S.addedOn + ' ' + formatDate( it.created_at, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } ) ),
				h( 'h2', { class: 'dfx-line', id: 'dfx-line' }, lineParts( result.data.line ) ),
				h( 'p', { class: 'dfx-requester', id: 'dfx-requester' }, S.requestedBy + ' ' + it.requester_name + ( it.requester_contact ? ' · ' + it.requester_contact : '' ) ),
				result.data.ask ? h( 'p', {}, h( 'a', { class: 'dfx-button dfx-ask', href: result.data.ask, target: '_blank', rel: 'noopener' }, S.askWhatsApp ) ) : null,
				h(
					'p',
					{ class: 'dfx-item-meta' },
					h( 'span', { class: 'dfx-chip is-' + it.status, id: 'dfx-status' }, C.statuses[ it.status ] ),
					it.to_ask ? h( 'span', { class: 'dfx-chip is-ask' }, S.toAskMark ) : null,
					h( 'span', { class: 'dfx-chip' }, C.types[ it.type ] ),
					it.next_review ? h( 'small', { class: 'dfx-review' }, S.nextReview + ': ' + formatDate( it.next_review, { day: 'numeric', month: 'long' } ) ) : null
				)
			),
			h( 'details', { class: 'dfx-card' }, h( 'summary', {}, S.fullText ), h( 'p', { class: 'dfx-body' }, it.body ), h( 'small', { class: 'dfx-muted' }, S.neverPublished ) ),
			blocks,
			updates.length ? h( 'h2', {}, S.updates ) : null,
			updates.length ? h( 'ul', { class: 'dfx-timeline' }, updates.map( ( u ) => h( 'li', {}, h( 'small', {}, formatDate( u.created_at, { day: 'numeric', month: 'long' } ) + ( u.author ? ' · ' + u.author : '' ) ), h( 'p', {}, u.body ) ) ) ) : null
		);
	}

	/**
	 * Every field of an intention, as in the panel. Checkboxes send the
	 * has_* markers so that unticking one saves it as off.
	 */
	function editForm( it ) {
		const levels = Object.keys( C.levels );
		const allowed = Object.fromEntries( levels.slice( Math.max( 0, levels.indexOf( it.discretion ) ) ).map( ( key ) => [ key, C.levels[ key ] ] ) );
		const text = ( name, label, value, extra ) => field( label, h( 'input', Object.assign( { name, value: value || '' }, extra || {} ) ) );
		const box = ( name, label, value ) => check( label, h( 'input', { type: 'checkbox', name, value: '1', checked: !! value } ) );

		return h(
			'details',
			{ class: 'dfx-edit' },
			h( 'summary', {}, S.edit ),
			form(
				( data ) => {
					const intention = {};
					[ 'requester_name', 'requester_first_name', 'requester_display_name', 'requester_contact', 'beneficiary_name', 'beneficiary_first_name', 'beneficiary_display_name', 'relationship', 'type', 'reference_date', 'reference_time', 'body', 'summary', 'discretion', 'priority', 'next_review', 'asked_on', 'scope', 'topic', 'requester_phone' ].forEach( ( key ) => {
						if ( data.has( key ) ) {
							intention[ key ] = data.get( key );
						}
					} );
					[ 'requester_is_member', 'beneficiary_is_requester', 'beneficiary_is_member', 'anniversary', 'to_ask' ].forEach( ( key ) => {
						if ( data.get( key ) ) {
							intention[ key ] = '1';
						}
					} );
					return act( it.id, 'save_intention', {
						group_id: it.group_id,
						has_requester_is_member: 1,
						has_beneficiary_is_requester: 1,
						has_beneficiary_is_member: 1,
						has_anniversary: 1,
						has_to_ask: 1,
						intention,
					} );
				},
				text( 'requester_name', S.requester, it.requester_name, { required: true } ),
				field( S.nameToShowRequester, h( 'input', { name: 'requester_first_name', value: it.requester_first_name || '' } ), S.calledHelp ),
				text( 'requester_contact', S.contact, it.requester_contact ),
				text( 'requester_phone', S.phone, it.requester_phone, { type: 'tel' } ),
				box( 'requester_is_member', S.requesterIsMember, it.requester_is_member ),
				box( 'beneficiary_is_requester', S.forThemselves, it.beneficiary_is_requester ),
				text( 'beneficiary_name', S.beneficiary, it.beneficiary_name ),
				text( 'beneficiary_first_name', S.nameToShowBeneficiary, it.beneficiary_first_name ),
				text( 'relationship', S.relationship, it.relationship, { maxlength: 100 } ),
				box( 'beneficiary_is_member', S.beneficiaryIsMember, it.beneficiary_is_member ),
				field( S.kind, select( 'type', C.types, it.type ) ),
				text( 'reference_date', S.referenceDate, it.reference_date, { type: 'date' } ),
				text( 'reference_time', S.time, it.reference_time, { type: 'time' } ),
				box( 'anniversary', S.anniversary, it.anniversary ),
				field( S.fullText, h( 'textarea', { name: 'body', rows: 4, required: true }, it.body ), S.neverPublished ),
				text( 'summary', S.summary, it.summary, { maxlength: 100, required: 'pending' !== it.status } ),
				field( S.discretion, select( 'discretion', allowed, it.discretion ) ),
				field( S.priority, select( 'priority', { normal: S.normal, high: S.highPriority }, it.priority ) ),
				field( S.message, select( 'scope', C.scopes, it.scope ) ),
				field( S.topic, select( 'topic', C.topics, it.topic ) ),
				'pending' !== it.status ? text( 'next_review', S.nextReview, it.next_review, { type: 'date' } ) : null,
				box( 'to_ask', S.toAskMark, it.to_ask ),
				text( 'requester_display_name', S.visibleRequester, it.requester_display_name ),
				field( S.visibleBeneficiary, h( 'input', { name: 'beneficiary_display_name', value: it.beneficiary_display_name || '' } ), S.descriptionHelp ),
				text( 'asked_on', S.askedOn, String( it.created_at || '' ).slice( 0, 10 ), { type: 'date', max: C.today } ),
				h( 'button', { class: 'dfx-button is-primary' }, S.saveChanges )
			)
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
				field( S.phone, h( 'input', { name: 'requester_phone', type: 'tel' } ) ),
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
				field( S.message, select( 'scope', C.scopes, 'daily' ) ),
				field( S.topic, select( 'topic', C.topics, '' ) ),
				field( S.askedOn, h( 'input', { type: 'date', name: 'asked_on', max: C.today } ), S.askedOnHint ),
				h( 'button', { class: 'dfx-button is-primary' }, S.addIntention )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Start                                                               */
	/* ------------------------------------------------------------------ */

	async function start() {
		history.replaceState( { dfx: true, guard: true }, '' );
		remember( { tab: state.tab } );
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
