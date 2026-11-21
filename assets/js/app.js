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
		saved: false,
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
		members: 'M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20M9 11a3.5 3.5 0 1 0 0-7a3.5 3.5 0 1 0 0 7M22 20v-1.5a4 4 0 0 0-3-3.9M16 4.1a3.5 3.5 0 0 1 0 6.8',
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

	/**
	 * "Until 27 Sep" for an intention prayed for until a day, otherwise
	 * its next review.
	 *
	 * @param {Object} item  Intention.
	 * @param {string} month Month format, 'short' or 'long'.
	 */
	function reviewNote( item, month ) {
		if ( item.ends_on ) {
			return h( 'small', { class: 'dfx-review' }, S.until + ' ' + formatDate( item.ends_on, { day: 'numeric', month } ) );
		}
		return item.next_review ? h( 'small', { class: 'dfx-review' }, S.nextReview + ': ' + formatDate( item.next_review, { day: 'numeric', month } ) ) : null;
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
			h( 'div', { class: 'dfx-title' }, h( 'h1', {}, S.appName ), groups || ( current ? h( 'span', { class: 'dfx-group' }, current.name ) : null ) ),
			C.manualUrl ? h( 'a', { class: 'dfx-manual', href: C.manualUrl }, S.manual ) : null
		);
	}

	function nav() {
		const tabs = { today: S.today, intentions: S.intentions, ask: S.toAskTab, members: S.members, moderation: S.moderation, add: S.add };
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
			const saved = state.saved;
			state.saved = false;
			return entry.edit ? editScreen( entry.id, true ) : detail( entry.id, saved, true );
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
			case 'members':
				return membersTab();
			case 'add':
				return addForm();
			default:
				return today();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Installing: on phones and tablets, until the app runs installed     */
	/* ------------------------------------------------------------------ */

	const install = { prompt: null, installed: false, el: null };

	function isInstalled() {
		return install.installed || window.navigator.standalone === true || ( window.matchMedia && window.matchMedia( '(display-mode: standalone)' ).matches );
	}

	function isMobile() {
		const nav = window.navigator;
		const coarse = window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches;
		const ipad = 'MacIntel' === nav.platform && nav.maxTouchPoints > 1;
		const mobile = ( nav.userAgentData && nav.userAgentData.mobile ) || /Android|iPhone|iPad|iPod|Mobile/i.test( nav.userAgent ) || ipad;
		return Boolean( coarse && mobile );
	}

	function isIos() {
		const nav = window.navigator;
		return /iPhone|iPad|iPod/i.test( nav.userAgent ) || ( 'MacIntel' === nav.platform && nav.maxTouchPoints > 1 );
	}

	// Fill (or empty) the banner: a button when the browser can install
	// it, Apple's steps on iPhone and iPad, the generic hint elsewhere.
	function fillInstall() {
		const el = install.el;
		if ( ! el ) {
			return;
		}
		if ( isInstalled() ) {
			el.remove();
			install.el = null;
			return;
		}
		if ( install.prompt ) {
			const button = h( 'button', { type: 'button', class: 'dfx-button is-primary' }, S.installApp );
			button.addEventListener( 'click', async () => {
				const event = install.prompt;
				install.prompt = null;
				await event.prompt();
				const choice = await Promise.resolve( event.userChoice ).catch( () => null );
				if ( choice && 'accepted' === choice.outcome ) {
					install.installed = true;
				}
				fillInstall();
			} );
			el.replaceChildren( button );
		} else {
			el.replaceChildren( isIos() ? S.installIos : S.installHint );
		}
	}

	function installBanner() {
		if ( isInstalled() || ! isMobile() ) {
			return null;
		}
		install.el = h( 'div', { class: 'dfx-install', role: 'region', 'aria-label': S.installApp } );
		fillInstall();
		return install.el;
	}

	window.addEventListener( 'beforeinstallprompt', ( event ) => {
		event.preventDefault();
		install.prompt = event;
		fillInstall();
	} );

	window.addEventListener( 'appinstalled', () => {
		install.installed = true;
		install.prompt = null;
		fillInstall();
	} );

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
		// The daily message, once shared with the group, is marked as sent:
		// what reaches the list afterwards goes in the next one.
		const mark = async ( undo ) => {
			notify( await write( 'mark_shared', Object.assign( { group_id: state.group }, undo ? { undo: 1 } : {} ) ) );
			today();
		};
		let shared = null;
		if ( 'daily' === state.edition && ! result.offline ) {
			shared = result.data.shared_at
				? h(
						'p',
						{ class: 'dfx-shared' },
						S.sharedAt.replace( '%s', result.data.shared_at ),
						' ',
						h( 'button', { type: 'button', class: 'dfx-link', onclick: () => mark( true ) }, S.undo )
				  )
				: h(
						'p',
						{ class: 'dfx-shared' },
						h( 'button', { type: 'button', class: 'dfx-button', onclick: () => mark( false ) }, S.markShared ),
						h( 'span', { class: 'dfx-muted' }, ' ' + S.markSharedHelp )
				  );
		}
		frame(
			installBanner(),
			result.offline ? h( 'p', { class: 'dfx-notice' }, S.offline ) : null,
			h( 'div', { class: 'dfx-segments', role: 'group' }, edition( 'daily', S.daily ), edition( 'monthly', S.monthly ) ),
			h(
				'div',
				{ class: 'dfx-actions' },
				navigator.share ? h( 'button', { type: 'button', class: 'dfx-button is-primary', onclick: () => navigator.share( { text } ).catch( () => {} ) }, S.share ) : null,
				copy
			),
			shared,
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
								reviewNote( item, 'short' )
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

	/**
	 * Close an intention. Closing an event or an ongoing situation as
	 * answered can add a thanksgiving, whose line in the digests is previewed
	 * while its summary is written; once closed, the thanksgiving opens, to
	 * edit it.
	 *
	 * @param {Object} it Intention.
	 */
	function closeForm( it ) {
		const canThank = 'event' === it.type || 'ongoing' === it.type;
		const reason = select( 'reason', C.reasons, 'answered' );
		const box = h( 'input', { type: 'checkbox', name: 'thanksgiving', value: '1' } );
		const summary = h( 'input', { name: 'thanksgiving_summary', maxlength: 100 } );
		const line = h( 'p', { class: 'dfx-preview-line dfx-thanks-line' } );
		const preview = h( 'div', { class: 'dfx-preview dfx-thanks-preview', hidden: true }, h( 'span', { class: 'dfx-label' }, S.thanksWillAppear ), line );
		let timer = null;

		async function refresh() {
			const result = await api( 'thanksgiving_preview', { intention_id: it.id, summary: summary.value } );
			if ( result.ok ) {
				line.textContent = result.data.line;
			}
		}

		function sync() {
			preview.hidden = ! box.checked || 'answered' !== reason.value;
			if ( ! preview.hidden && ! line.textContent ) {
				refresh();
			}
		}

		box.addEventListener( 'change', sync );
		reason.addEventListener( 'change', sync );
		summary.addEventListener( 'input', () => {
			clearTimeout( timer );
			timer = setTimeout( refresh, 300 );
		} );

		return form(
			async ( data ) => {
				const result = await write( 'close', { intention_id: it.id, reason: data.get( 'reason' ), thanksgiving: data.get( 'thanksgiving' ) ? 1 : '', thanksgiving_summary: data.get( 'thanksgiving_summary' ) || '' } );
				notify( result );
				if ( result.ok && result.id ) {
					remember( { tab: state.tab, id: result.id } );
					return detail( result.id, true );
				}
				return detail( it.id, true );
			},
			field( S.closeAs, reason ),
			canThank ? check( S.addThanksgiving, box ) : null,
			canThank ? field( S.thanksSummary, summary ) : null,
			canThank ? preview : null,
			h( 'button', { class: 'dfx-button' }, S.close )
		);
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
				closeForm( it )
			);
		}


		if ( 'closed' === it.status ) {
			blocks.push( h( 'button', { type: 'button', class: 'dfx-button is-primary', onclick: () => act( id, 'reopen', {} ) }, S.reopen ) );
		}

		const linked = result.data.linked;
		const linkLabel = linked && ( 'thanksgiving' === it.type && 'thanksgiving' !== linked.type ? S.givesThanksFor : ( 'thanksgiving' === linked.type && 'thanksgiving' !== it.type ? S.itsThanksgiving : null ) );
		if ( linkLabel ) {
			blocks.unshift( h( 'p', { class: 'dfx-card dfx-linked' }, h( 'span', { class: 'dfx-label' }, linkLabel ), ' ', h( 'a', { href: '#', onclick: ( event ) => { event.preventDefault(); detail( linked.id ); } }, linked.line ) ) );
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
				h(
					'p',
					{ class: 'dfx-hero-actions' },
					result.data.ask ? h( 'a', { class: 'dfx-button dfx-ask', href: result.data.ask, target: '_blank', rel: 'noopener' }, S.askWhatsApp ) : null,
					open || 'pending' === it.status ? h( 'button', { type: 'button', class: 'dfx-button', onclick: () => editScreen( id ) }, S.edit ) : null
				),
				h(
					'p',
					{ class: 'dfx-item-meta' },
					h( 'span', { class: 'dfx-chip is-' + it.status, id: 'dfx-status' }, C.statuses[ it.status ] ),
					it.to_ask ? h( 'span', { class: 'dfx-chip is-ask' }, S.toAskMark ) : null,
					h( 'span', { class: 'dfx-chip' }, C.types[ it.type ] ),
					reviewNote( it, 'long' )
				)
			),
			h( 'details', { class: 'dfx-card' }, h( 'summary', {}, S.fullText ), h( 'p', { class: 'dfx-body' }, it.body ), h( 'small', { class: 'dfx-muted' }, S.neverPublished ) ),
			blocks,
			updates.length ? h( 'h2', {}, S.updates ) : null,
			updates.length ? h( 'ul', { class: 'dfx-timeline' }, updates.map( ( u ) => h( 'li', {}, h( 'small', {}, formatDate( u.created_at, { day: 'numeric', month: 'long' } ) + ( u.author ? ' · ' + u.author : '' ) ), h( 'p', {}, u.body ) ) ) ) : null
		);
	}

	/**
	 * The fields of an intention in five steps, in the order a request is
	 * told: who asks, who we pray for, what is happening, how it appears in
	 * the message and how we follow it up. The same form adds and edits;
	 * fields that do not apply are hidden (and not sent), and the line it
	 * will show is previewed while it is written.
	 *
	 * @param {Object}   it     Intention (an empty one to add).
	 * @param {boolean}  isNew  Whether it is being added.
	 * @param {Function} onsave Called with the intention fields to save.
	 */
	function intentionForm( it, isNew, onsave ) {
		const levels = Object.keys( C.levels );
		const allowed = Object.fromEntries( levels.slice( isNew ? 0 : Math.max( 0, levels.indexOf( it.discretion ) ) ).map( ( key ) => [ key, C.levels[ key ] ] ) );
		const words = ( text ) => String( text || '' ).trim().split( /\s+/ ).filter( Boolean );
		const input = ( name, value, extra ) => h( 'input', Object.assign( { name, value: value || '' }, extra || {} ) );
		const box = ( name, label, value ) => check( label, h( 'input', { type: 'checkbox', name, value: '1', checked: !! value } ) );
		const radios = ( name, options, value ) =>
			h(
				'div',
				{ class: 'dfx-choices' },
				Object.entries( options ).map( ( [ key, text ] ) => h( 'label', { class: 'dfx-choice' }, h( 'input', { type: 'radio', name, value: key, checked: key === value } ), h( 'span', {}, text ) ) )
			);
		const step = ( number, title, ...content ) => h( 'fieldset', { class: 'dfx-step' }, h( 'legend', {}, h( 'span', { class: 'dfx-step-number' }, String( number ) ), ' ', title ), content );
		const labelled = ( text, control, help ) => {
			const span = h( 'span', { class: 'dfx-label' }, text );
			return { span, el: h( 'label', { class: 'dfx-field' }, span, control, help ? h( 'small', {}, help ) : null ) };
		};

		const requesterCalled = labelled( S.calledAs, input( 'requester_first_name', it.requester_first_name ), S.calledAsHelp );
		const beneficiaryCalled = labelled( S.calledAs, input( 'beneficiary_first_name', it.beneficiary_first_name ), S.calledAsHelp );
		const relationship = labelled( S.relationshipToRequester, input( 'relationship', it.relationship, { maxlength: 100 } ), S.relationshipHelp );
		const date = labelled( S.date, input( 'reference_date', it.reference_date, { type: 'date' } ) );
		const time = field( S.time, input( 'reference_time', it.reference_time, { type: 'time' } ) );
		const anniversary = box( 'anniversary', S.rememberYearly, it.anniversary );
		const eventHint = h( 'small', { class: 'dfx-hint' }, S.eventHint );
		const beneficiary = h(
			'div',
			{ class: 'dfx-substep' },
			field( S.name, input( 'beneficiary_name', it.beneficiary_is_requester ? '' : it.beneficiary_name ) ),
			beneficiaryCalled.el,
			relationship.el,
			box( 'beneficiary_is_member', S.ourGroup, it.beneficiary_is_member )
		);
		const nextReview = isNew || 'pending' === it.status ? null : field( S.lookAgainOn, input( 'next_review', it.next_review, { type: 'date' } ) );
		const preview = h( 'p', { class: 'dfx-preview-line', id: 'dfx-preview' } );
		const previewNote = h( 'small', { class: 'dfx-preview-note' } );
		const follow = [ nextReview, field( S.prayUntil, input( 'ends_on', it.ends_on, { type: 'date' } ), S.endsOnHelp ), box( 'to_ask', S.toAskMark, it.to_ask ) ];

		const el = form(
			( data ) => onsave( collect( data ) ),
			step(
				1,
				S.stepWho,
				field( S.fullName, input( 'requester_name', it.requester_name, { required: true } ) ),
				requesterCalled.el,
				field( S.whatsapp, input( 'requester_phone', it.requester_phone, { type: 'tel' } ), S.whatsappHelp ),
				box( 'requester_is_member', S.ourGroup, it.requester_is_member ),
				it.requester_contact ? h( 'p', { class: 'dfx-muted' }, S.formContact + ' ' + it.requester_contact ) : null,
				field( S.whenAsked, input( 'asked_on', isNew ? '' : String( it.created_at || '' ).slice( 0, 10 ), { type: 'date', max: C.today } ), S.whenAskedHelp ),
				! isNew && it.activated_at ? field( S.whenListed, input( 'listed_on', String( it.activated_at ).slice( 0, 10 ), { type: 'date', max: C.today } ), S.whenListedHelp ) : null
			),
			step( 2, S.stepForWhom, radios( 'beneficiary_is_requester', { self: S.forThemselves, other: S.forSomeoneElse }, it.beneficiary_is_requester ? 'self' : 'other' ), beneficiary ),
			step(
				3,
				S.stepWhat,
				radios( 'type', C.kinds, it.type || 'ongoing' ),
				eventHint,
				date.el,
				time,
				anniversary,
				field( S.toldUs, h( 'textarea', { name: 'body', rows: 4, required: true }, it.body || '' ), S.neverPublished )
			),
			step(
				4,
				S.stepMessage,
				field( S.summary, input( 'summary', it.summary, { maxlength: 100, required: isNew || 'pending' !== it.status } ), S.summaryHelp ),
				h( 'div', { class: 'dfx-preview' }, h( 'span', { class: 'dfx-label' }, S.willAppear ), preview, previewNote ),
				radios( 'scope', C.scopes, it.scope || 'daily' ),
				field( S.topic, select( 'topic', C.topics, it.topic || '' ) ),
				box( 'priority', S.urgent, 'high' === it.priority ),
				h(
					'details',
					{ class: 'dfx-more' },
					h( 'summary', {}, S.discretionAndNames ),
					field( S.discretion, select( 'discretion', allowed, it.discretion || 'first_name' ) ),
					field( S.visibleRequester, input( 'requester_display_name', it.requester_display_name ) ),
					field( S.visibleBeneficiary, input( 'beneficiary_display_name', it.beneficiary_display_name ), S.descriptionHelp )
				)
			),
			isNew ? h( 'details', { class: 'dfx-step dfx-step-folded' }, h( 'summary', {}, h( 'span', { class: 'dfx-step-number' }, '5' ), ' ', S.stepFollow ), follow ) : step( 5, S.stepFollow, follow ),
			h( 'div', { class: 'dfx-actions' }, h( 'button', { class: 'dfx-button is-primary' }, isNew ? S.addIntention : S.saveChanges ) )
		);

		el.classList.add( 'dfx-steps' );

		const value = ( name ) => {
			const control = el.elements.namedItem( name );
			return control && 'value' in control ? control.value : '';
		};
		const show = ( node, visible ) => {
			node.hidden = ! visible;
			node.querySelectorAll( 'input, select, textarea' ).forEach( ( control ) => {
				control.disabled = ! visible;
			} );
		};

		// "How we call them" is offered when the name has more than one word:
		// with one word, both are the same.
		function calledField( called, nameValue, calledValue ) {
			show( called.el, words( nameValue ).length > 1 || '' !== calledValue.trim() );
			called.el.querySelector( 'input' ).placeholder = words( nameValue )[ 0 ] || '';
		}

		function sync() {
			calledField( requesterCalled, value( 'requester_name' ), value( 'requester_first_name' ) );
			const forThemselves = 'self' === value( 'beneficiary_is_requester' );
			show( beneficiary, ! forThemselves );
			if ( ! forThemselves ) {
				calledField( beneficiaryCalled, value( 'beneficiary_name' ), value( 'beneficiary_first_name' ) );
			}
			const requester = value( 'requester_first_name' ).trim() || words( value( 'requester_name' ) )[ 0 ] || '';
			relationship.span.textContent = requester ? S.relationshipTo.replace( '%s', requester ) : S.relationshipToRequester;

			const kind = value( 'type' );
			date.span.textContent = 'deceased' === kind ? S.dateOfDeath : S.date;
			show( date.el, 'ongoing' !== kind );
			date.el.querySelector( 'input' ).required = 'event' === kind || 'deceased' === kind;
			show( time, 'event' === kind );
			show( anniversary, 'deceased' === kind );
			eventHint.hidden = 'event' !== kind;

			if ( nextReview ) {
				nextReview.querySelector( 'input' ).disabled = '' !== value( 'ends_on' );
			}
		}

		let timer = null;
		async function refreshPreview() {
			const result = await api( 'preview', { group_id: it.group_id || state.group, intention_id: it.id || 0, intention: collect( new FormData( el ) ) } );
			if ( result.ok ) {
				preview.textContent = result.data.line;
				previewNote.textContent = '';
			} else if ( 'offline' !== result.code ) {
				previewNote.textContent = result.message;
			}
		}

		function changed() {
			sync();
			clearTimeout( timer );
			timer = setTimeout( refreshPreview, 300 );
		}

		el.addEventListener( 'input', changed );
		el.addEventListener( 'change', changed );
		sync();
		if ( ! isNew ) {
			refreshPreview();
		}
		return el;
	}

	/**
	 * The fields of the form, as the API takes them. Checkboxes always send
	 * a value, so that unticking one saves it as off.
	 *
	 * @param {FormData} data Form data.
	 */
	function collect( data ) {
		const intention = {};
		data.forEach( ( v, key ) => {
			intention[ key ] = v;
		} );
		[ 'requester_is_member', 'beneficiary_is_member', 'anniversary', 'to_ask' ].forEach( ( key ) => {
			intention[ key ] = data.get( key ) ? '1' : '';
		} );
		intention.beneficiary_is_requester = 'self' === data.get( 'beneficiary_is_requester' ) ? '1' : '';
		intention.priority = data.get( 'priority' ) ? 'high' : 'normal';
		return intention;
	}

	const MARKERS = { has_requester_is_member: 1, has_beneficiary_is_requester: 1, has_beneficiary_is_member: 1, has_anniversary: 1, has_to_ask: 1 };

	/**
	 * The form of a saved intention, on its own screen: Android's back
	 * button (or Cancel) returns to it without saving.
	 *
	 * @param {number}  id          Intention.
	 * @param {boolean} fromHistory Whether it is shown from the history.
	 */
	async function editScreen( id, fromHistory ) {
		if ( ! fromHistory ) {
			remember( { tab: state.tab, id, edit: true } );
		}
		const t = ticket();
		loading();
		const result = await api( 'get', { intention_id: id } );
		if ( stale( t ) ) {
			return;
		}
		if ( ! result.ok ) {
			return frame( h( 'p', { class: 'dfx-notice is-error' }, result.message ) );
		}
		const it = result.data.intention;
		frame(
			h( 'button', { type: 'button', class: 'dfx-back', onclick: () => history.back() }, '← ' + S.cancel ),
			h( 'h2', {}, S.editIntention ),
			intentionForm( it, false, async ( intention ) => {
				const saved = await write( 'save_intention', Object.assign( { intention_id: id, group_id: it.group_id, intention }, MARKERS ) );
				notify( saved );
				if ( saved.ok ) {
					state.saved = true;
					history.back();
					return;
				}
				render();
			} )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Add                                                                 */
	/* ------------------------------------------------------------------ */

	function addForm() {
		ticket();
		frame(
			h( 'h2', {}, S.addIntention ),
			intentionForm( { requester_is_member: true, type: 'ongoing', scope: 'daily', discretion: 'first_name' }, true, async ( intention ) => {
				const result = await write( 'save_intention', Object.assign( { intention_id: 0, group_id: state.group, intention }, MARKERS ) );
				notify( result );
				if ( result.ok && result.id ) {
					state.tab = 'intentions';
					return detail( result.id, true );
				}
				return render();
			} )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Members: the year by month, by the day of their birthday           */
	/* ------------------------------------------------------------------ */

	/**
	 * The members by month and day of their birthday (or of the death of
	 * those who died), January to December, with those without a date
	 * last. The current month opens on today, or on the next day with
	 * someone; if nobody is left this month, the next month with someone
	 * opens too.
	 */
	async function membersTab() {
		const t = ticket();
		loading();
		const result = await api( 'members', { group_id: state.group } );
		if ( stale( t ) ) {
			return;
		}
		if ( ! result.ok ) {
			return frame( h( 'p', { class: 'dfx-notice is-error' }, result.message ) );
		}

		const now = toDate( C.today );
		const thisMonth = now.getMonth() + 1;
		const byMonth = {};
		const undated = [];
		result.data.members.forEach( ( member ) => {
			if ( member.month ) {
				( byMonth[ member.month ] = byMonth[ member.month ] || [] ).push( member );
			} else {
				undated.push( member );
			}
		} );

		let target = null;
		for ( let k = 0; k < 12 && ! target; k++ ) {
			const month = ( ( thisMonth - 1 + k ) % 12 ) + 1;
			const next = ( byMonth[ month ] || [] ).find( ( member ) => k > 0 || member.day >= now.getDate() );
			if ( next ) {
				target = { month, day: next.day };
			}
		}

		const monthName = ( month ) => {
			const name = new Intl.DateTimeFormat( LOCALE, { month: 'long' } ).format( new Date( 2000, month - 1, 1 ) );
			return name.charAt( 0 ).toUpperCase() + name.slice( 1 );
		};

		const card = ( member ) => {
			let occasion = S.birthday;
			if ( 'anniversary' === member.kind ) {
				occasion = S.deathAnniversary + ' († ' + String( member.death_date ).slice( 0, 4 ) + ')';
			} else if ( member.years ) {
				occasion = S.turns.replace( '%d', member.years );
			}
			let when = null;
			if ( 0 === member.days ) {
				when = S.today;
			} else if ( 1 === member.days ) {
				when = S.tomorrow;
			}
			return h(
				'div',
				{ class: 'dfx-member' },
				h( 'p', { class: 'dfx-member-name' }, h( 'strong', {}, member.shown ), member.shown !== member.name ? h( 'span', { class: 'dfx-muted' }, ' · ' + member.name ) : null ),
				member.kind ? h( 'p', { class: 'dfx-occasion' }, when ? h( 'span', { class: 'dfx-chip is-ask' }, when ) : null, ' ', occasion ) : null,
				member.notes ? h( 'small', { class: 'dfx-muted' }, member.notes ) : null,
				member.whatsapp && 'anniversary' !== member.kind ? h( 'a', { class: 'dfx-button dfx-ask', href: member.whatsapp, target: '_blank', rel: 'noopener' }, S.whatsapp ) : null
			);
		};

		const days = ( members ) => {
			const groups = [];
			members.forEach( ( member ) => {
				const last = groups[ groups.length - 1 ];
				if ( last && last.day === member.day ) {
					last.members.push( member );
				} else {
					groups.push( { day: member.day, month: member.month, next: member.next, members: [ member ] } );
				}
			} );
			return groups.map( ( group ) =>
				h(
					'section',
					{ class: 'dfx-day' + ( 0 === group.members[ 0 ].days ? ' is-today' : '' ), id: 'dfx-day-' + group.month + '-' + group.day, tabindex: '-1' },
					h( 'h3', { class: 'dfx-day-title' }, h( 'span', { class: 'dfx-day-number' }, String( group.day ) ), ' ', formatDate( group.next, { weekday: 'long' } ) ),
					group.members.map( card )
				)
			);
		};

		const months = [];
		for ( let month = 1; month <= 12; month++ ) {
			const members = byMonth[ month ] || [];
			const open = month === thisMonth || ( target && month === target.month );
			months.push(
				h(
					'details',
					{ class: 'dfx-month', open: open ? true : null },
					h( 'summary', {}, monthName( month ), ' ', h( 'span', { class: 'dfx-count' }, String( members.length ) ) ),
					members.length ? days( members ) : h( 'p', { class: 'dfx-muted' }, S.nobody )
				)
			);
		}
		if ( undated.length ) {
			months.push( h( 'details', { class: 'dfx-month' }, h( 'summary', {}, S.noDate, ' ', h( 'span', { class: 'dfx-count' }, String( undated.length ) ) ), undated.map( card ) ) );
		}

		frame( h( 'h2', {}, S.members ), months );

		const day = target ? document.getElementById( 'dfx-day-' + target.month + '-' + target.day ) : null;
		if ( day ) {
			day.scrollIntoView( { block: 'start' } );
			day.focus( { preventScroll: true } );
		}
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
