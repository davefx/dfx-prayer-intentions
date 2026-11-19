/**
 * The user manual, inside the panel: from the menu, the footer and the app.
 */
const { test, expect, resetData, createGroup, login } = require( '../helpers' );

test.beforeEach( () => {
	resetData();
	createGroup( 'Santa Marta', [ 'leader1' ] );
} );

test( 'a leader opens the manual from the menu and reads it with its screenshots', async ( { page } ) => {
	await login( page, 'leader1' );

	const submenu = page.locator( '#toplevel_page_dfxpray .wp-submenu a' );
	await expect( submenu.last() ).toHaveText( 'Manual' );
	await submenu.last().click();
	expect( page.url() ).toContain( 'page=dfxpray-manual' );

	const manual = page.locator( '.dfxpray-manual' );
	await expect( manual.locator( 'h1' ) ).toHaveText( 'DFX Prayer Intentions User Manual' );
	await expect( manual.locator( 'h2#groups-leaders-and-permissions' ) ).toHaveText( 'Groups, leaders and permissions' );
	await expect( manual.locator( 'h3', { hasText: 'Inviting a leader' } ) ).toBeVisible();
	await expect( manual.locator( 'table.widefat' ).first() ).toBeVisible();

	const toc = manual.locator( '.dfxpray-manual-toc' );
	await toc.getByRole( 'link', { name: 'Groups, leaders and permissions' } ).click();
	expect( page.url() ).toContain( '#groups-leaders-and-permissions' );

	const image = manual.locator( 'img[src*="docs/manual/en/img/02-grupos.webp"]' );
	await image.scrollIntoViewIfNeeded();
	await expect.poll( () => image.evaluate( ( img ) => img.complete && img.naturalWidth ) ).toBeGreaterThan( 0 );

	// The link to the other language opens that manual in the panel, and back.
	await manual.getByRole( 'link', { name: 'Manual de DFX Prayer Intentions' } ).click();
	expect( page.url() ).toContain( 'page=dfxpray-manual&lang=es' );
	await expect( page.locator( '.dfxpray-manual h1' ) ).toHaveText( 'Manual de DFX Prayer Intentions' );
	await expect( page.locator( '.dfxpray-manual img[src*="docs/manual/es/img/02-grupos.webp"]' ) ).toHaveCount( 1 );
	await page.locator( '.dfxpray-manual' ).getByRole( 'link', { name: 'DFX Prayer Intentions User Manual' } ).click();
	await expect( page.locator( '.dfxpray-manual h1' ) ).toHaveText( 'DFX Prayer Intentions User Manual' );

	await page.goto( '/wp-admin/admin.php?page=dfxpray' );
	await page.locator( '.dfxpray-plugin-footer' ).getByRole( 'link', { name: 'Manual' } ).click();
	await expect( page.locator( '.dfxpray-manual h1' ) ).toBeVisible();
} );

test( 'the leaders’ app links to the manual in the panel', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( '/prayer-requests-app/' );

	const link = page.locator( 'a.dfx-manual' );
	await expect( link ).toHaveText( 'Manual' );
	await link.click();
	await expect( page.locator( '.dfxpray-manual h1' ) ).toHaveText( 'DFX Prayer Intentions User Manual' );
} );
