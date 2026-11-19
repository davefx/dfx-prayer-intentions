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
	await expect( manual.locator( 'h1' ) ).toHaveText( 'Manual de DFX Prayer Intentions' );
	await expect( manual.locator( 'h2#grupos-responsables-y-permisos' ) ).toHaveText( 'Grupos, responsables y permisos' );
	await expect( manual.locator( 'h3', { hasText: 'Invitar a un líder' } ) ).toBeVisible();
	await expect( manual.locator( 'table.widefat' ).first() ).toBeVisible();

	const toc = manual.locator( '.dfxpray-manual-toc' );
	await toc.getByRole( 'link', { name: 'Grupos, responsables y permisos' } ).click();
	expect( page.url() ).toContain( '#grupos-responsables-y-permisos' );

	const image = manual.locator( 'img[src*="docs/manual/es/img/02-grupos.webp"]' );
	await image.scrollIntoViewIfNeeded();
	await expect.poll( () => image.evaluate( ( img ) => img.complete && img.naturalWidth ) ).toBeGreaterThan( 0 );

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
	await expect( page.locator( '.dfxpray-manual h1' ) ).toHaveText( 'Manual de DFX Prayer Intentions' );
} );
