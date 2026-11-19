/**
 * Installing the leaders' app: only on phones and tablets, in the panel
 * (on every screen) and in the app (at the top of «Today»), until it runs
 * installed.
 */
const base = require( '@playwright/test' );
const { test, expect, resetData, createGroup, login, groupUrl } = require( '../helpers' );

const APP = '/prayer-requests-app/';
let group;

test.beforeEach( () => {
	resetData();
	group = createGroup( 'Santa Marta', [ 'leader1' ] );
} );

test( 'on a computer, nothing about installing is shown', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray' );
	await expect( page.locator( '.dfxpray-install' ) ).toHaveCount( 0 );
	await expect( page.locator( '#toplevel_page_dfxpray .wp-submenu a', { hasText: 'Install the app' } ) ).toHaveCount( 0 );

	await page.goto( APP );
	await expect( page.locator( '.dfx-message' ) ).toBeVisible();
	await expect( page.locator( '.dfx-install' ) ).toHaveCount( 0 );
} );

test.describe( 'on a phone', () => {
	const { defaultBrowserType, ...pixel } = base.devices[ 'Pixel 7' ]; // eslint-disable-line no-unused-vars
	test.use( pixel );

	test( 'the panel always shows the link to install the app, which copies', async ( { page, context } ) => {
		await context.grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
		await login( page, 'leader1' );
		const url = new URL( APP, page.url() ).href;

		for ( const path of [ '/wp-admin/admin.php?page=dfxpray', groupUrl( group.id, 'digest' ), '/wp-admin/admin.php?page=dfxpray-manual' ] ) {
			await page.goto( path );
			const box = page.locator( '.dfxpray-install' );
			await expect( box.locator( 'a.dfxpray-install-url' ) ).toHaveAttribute( 'href', url );
			await expect( box.getByRole( 'button', { name: 'Copy link' } ) ).toBeVisible();
			await expect( box.getByRole( 'button', { name: /dismiss/i } ) ).toHaveCount( 0 );
		}

		await page.locator( '.dfxpray-install' ).getByRole( 'button', { name: 'Copy link' } ).click();
		expect( await page.evaluate( () => navigator.clipboard.readText() ) ).toBe( url );

		await page.goto( '/wp-admin/admin.php?page=dfxpray-app' );
		await expect( page.locator( '.dfxpray-install-how' ) ).toHaveAttribute( 'open', '' );
		await expect( page.locator( '.dfxpray-install-how' ) ).toContainText( 'iPhone (Safari)' );
	} );

	test( 'the app offers the browser’s install button until it is installed', async ( { page } ) => {
		await login( page, 'leader1' );
		await page.goto( APP );

		const banner = page.locator( '.dfx-install' );
		await expect( banner ).toContainText( 'To install it' );

		await page.evaluate( () => {
			window.__prompted = 0;
			const event = new Event( 'beforeinstallprompt', { cancelable: true } );
			event.prompt = () => {
				window.__prompted++;
				return Promise.resolve();
			};
			event.userChoice = Promise.resolve( { outcome: 'accepted' } );
			window.dispatchEvent( event );
		} );
		await banner.getByRole( 'button', { name: 'Install the app' } ).click();
		expect( await page.evaluate( () => window.__prompted ) ).toBe( 1 );
		await expect( banner ).toHaveCount( 0 );
	} );

	test( 'running installed, the app shows no banner', async ( { page } ) => {
		await page.addInitScript( () => {
			const original = window.matchMedia.bind( window );
			window.matchMedia = ( query ) => ( query.includes( 'display-mode: standalone' ) ? { matches: true, media: query, addEventListener() {}, removeEventListener() {} } : original( query ) );
		} );
		await login( page, 'leader1' );
		await page.goto( APP );
		await expect( page.locator( '.dfx-message' ) ).toBeVisible();
		await expect( page.locator( '.dfx-install' ) ).toHaveCount( 0 );
	} );
} );
