/**
 * The site administrator manages groups, leaders and global settings.
 */
const { test, expect, resetData, createGroup, login, php } = require( '../helpers' );

test.beforeEach( () => resetData() );

test( 'the administrator creates a group, assigns a leader, and the leader sees it', async ( { page, browser } ) => {
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-groups' );

	const create = page.locator( '.dfxpray-card', { hasText: 'New group' } );
	await create.getByLabel( 'Name' ).fill( 'Grupo San José' );
	await create.getByLabel( 'Internal description' ).fill( 'Martes a las 20:00' );
	await create.getByLabel( /Leader One/ ).check();
	await create.getByRole( 'button', { name: 'Create group' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Group saved.' );

	const card = page.locator( '.dfxpray-group', { hasText: 'Grupo San José' } );
	const url = ( await card.locator( 'code' ).textContent() ).trim();
	expect( url ).toMatch( /\/prayer-requests-[a-z0-9]{8}\/$/ );

	const leader = await browser.newPage();
	await login( leader, 'leader1' );
	await expect( leader.locator( '.dfxpray-card h2' ) ).toHaveText( [ 'Grupo San José' ] );
	await leader.close();

	const form = await page.request.get( url );
	expect( form.status() ).toBe( 200 );
} );

test( 'archiving a group closes its form; restoring opens it again', async ( { page } ) => {
	const group = createGroup( 'Santa Marta' );
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-groups' );

	await page.locator( '.dfxpray-group', { hasText: 'Santa Marta' } ).getByRole( 'button', { name: 'Archive' } ).click();
	expect( ( await page.request.get( `/${ group.slug }/` ) ).status() ).toBe( 404 );

	await page.locator( '.dfxpray-group', { hasText: 'Santa Marta' } ).getByRole( 'button', { name: 'Restore' } ).click();
	expect( ( await page.request.get( `/${ group.slug }/` ) ).status() ).toBe( 200 );
} );

test( 'deleting a group asks first and removes everything', async ( { page } ) => {
	createGroup( 'Santa Marta' );
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-groups' );

	const del = page.locator( '.dfxpray-group', { hasText: 'Santa Marta' } ).getByRole( 'button', { name: /Delete group/ } );
	page.once( 'dialog', ( dialog ) => dialog.dismiss() );
	await del.click();
	await expect( page.locator( '.dfxpray-group', { hasText: 'Santa Marta' } ) ).toHaveCount( 1 );

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await del.click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Group deleted.' );
	expect( php( "echo wp_json_encode( count( DFXPRAY_Groups::get_instance()->all() ) );" ) ).toBe( 0 );
} );

test( 'a new address can be generated; the old one stops working', async ( { page } ) => {
	const group = createGroup( 'Santa Marta', [ 'leader1' ] );
	await login( page, 'leader1' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-settings' );
	await expect( page.locator( 'body' ) ).toContainText( group.slug );

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.getByRole( 'button', { name: 'Generate a new address' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'new address' );

	const fresh = php( `echo wp_json_encode( DFXPRAY_Groups::get_instance()->get( ${ group.id } )['slug'] );` );
	expect( fresh ).not.toBe( group.slug );
	expect( ( await page.request.get( `/${ group.slug }/` ) ).status() ).toBe( 404 );
	expect( ( await page.request.get( `/${ fresh }/` ) ).status() ).toBe( 200 );
} );

test( 'global settings apply to groups unless a group overrides them', async ( { page } ) => {
	const group = createGroup( 'Santa Marta', [ 'leader1' ] );
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-global' );

	await page.getByLabel( /Delete closed and discarded intentions after/ ).fill( '60' );
	await page.getByLabel( 'Deceased: days after the death' ).fill( '40' );
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
	await expect( page.getByLabel( /Delete closed and discarded intentions after/ ) ).toHaveValue( '60' );

	await page.goto( `/wp-admin/admin.php?page=dfxpray-settings&group=${ group.id }` );
	await expect( page.getByLabel( 'Deceased: days after the death' ) ).toHaveValue( '40' );
	await expect( page.getByLabel( /Delete closed and discarded/ ) ).toHaveCount( 0 );
} );
