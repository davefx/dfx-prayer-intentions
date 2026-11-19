/**
 * The site administrator manages groups, leaders and global settings.
 */
const { groupUrl, test, expect, resetData, createGroup, createIntention, login, php } = require( '../helpers' );

test.beforeEach( () => resetData() );

test( 'the administrator creates a group, assigns a leader, and the leader sees it', async ( { page, browser } ) => {
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray' );

	const create = page.locator( '.dfxpray-card', { hasText: 'New group' } );
	await create.getByLabel( 'Name' ).fill( 'Grupo San José' );
	await create.getByLabel( 'Internal description' ).fill( 'Martes a las 20:00' );
	await create.getByLabel( /Leader One/ ).check();
	await create.getByRole( 'button', { name: 'Create group' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Group saved.' );

	const card = page.locator( '.dfxpray-group', { hasText: 'Grupo San José' } );
	const url = ( await card.locator( 'code' ).textContent() ).trim();
	expect( url ).toMatch( /\/prayer-requests-[a-z0-9]{8}\/$/ );

	// The administrator manages the group but, not leading it, does not see its data.
	await expect( page.locator( '#toplevel_page_dfxpray .wp-submenu a' ) ).toHaveText( [ 'Groups', 'Global settings', 'Manual' ] );

	const leader = await browser.newPage();
	await login( leader, 'leader1' );
	await expect( leader.getByRole( 'heading', { level: 1 } ) ).toHaveText( 'Grupo San José' );
	await leader.close();

	const form = await page.request.get( url );
	expect( form.status() ).toBe( 200 );
} );

test( 'an administrator only sees the groups they lead, and manages the others from Manage groups', async ( { page } ) => {
	const mine = createGroup( 'Santa Marta', [ 'leader1', 'admin' ] );
	const other = createGroup( 'San Pablo', [ 'leader1' ] );
	createIntention( other.id, { summary: 'de otro grupo' }, 'form' );
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray' );

	await expect( page.locator( '#toplevel_page_dfxpray .wp-submenu a' ) ).toHaveText( [ 'Groups', 'Global settings', 'Santa Marta', 'Manual' ] );
	await expect( page.locator( '#toplevel_page_dfxpray .awaiting-mod' ) ).toHaveCount( 0 );
	await expect( page.locator( '.dfxpray-group-card' ) ).toHaveCount( 1 );
	await expect( page.locator( '.dfxpray-group-card' ) ).toContainText( 'Santa Marta' );

	for ( const tab of [ 'moderation', 'intentions', 'members', 'settings', 'log' ] ) {
		await page.goto( groupUrl( other.id, tab ) );
		await expect( page.locator( 'body' ) ).toContainText( /not allowed/ );
		await expect( page.locator( 'body' ) ).not.toContainText( 'de otro grupo' );
	}
	await page.goto( groupUrl( mine.id, 'settings' ) );
	await expect( page.locator( '.dfxpray-leaders' ) ).toContainText( 'Leader One' );

	await page.goto( '/wp-admin/admin.php?page=dfxpray' );
	const card = page.locator( '.dfxpray-group', { hasText: 'San Pablo' } );
	await card.getByLabel( 'Internal description' ).fill( 'Jueves' );
	await card.getByRole( 'button', { name: 'Save group' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Group saved.' );
	expect( php( `echo wp_json_encode( DFXPRAY_Groups::get_instance()->get( ${ other.id } )['description'] );` ) ).toBe( 'Jueves' );
} );

test( 'archiving a group closes its form; restoring opens it again', async ( { page } ) => {
	const group = createGroup( 'Santa Marta' );
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray' );

	await page.locator( '.dfxpray-group', { hasText: 'Santa Marta' } ).getByRole( 'button', { name: 'Archive' } ).click();
	expect( ( await page.request.get( `/${ group.slug }/` ) ).status() ).toBe( 404 );

	await page.locator( '.dfxpray-group', { hasText: 'Santa Marta' } ).getByRole( 'button', { name: 'Restore' } ).click();
	expect( ( await page.request.get( `/${ group.slug }/` ) ).status() ).toBe( 200 );
} );

test( 'deleting a group asks first and removes everything', async ( { page } ) => {
	createGroup( 'Santa Marta' );
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray' );

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
	await page.goto( groupUrl( group.id, 'settings' ) );
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
	const group = createGroup( 'Santa Marta', [ 'leader1', 'admin' ] );
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-global' );

	await page.getByLabel( /Delete closed and discarded intentions after/ ).fill( '60' );
	await page.getByLabel( 'Deceased: days after the death' ).fill( '40' );
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
	await expect( page.getByLabel( /Delete closed and discarded intentions after/ ) ).toHaveValue( '60' );

	await page.goto( groupUrl( group.id, 'settings' ) );
	await expect( page.getByLabel( 'Deceased: days after the death' ) ).toHaveValue( '40' );
	await expect( page.getByLabel( /Delete closed and discarded/ ) ).toHaveCount( 0 );
} );

test( 'the administrator looks a person up by phone, hands over their data and erases it', async ( { page } ) => {
	const group = createGroup( 'Santa Marta', [ 'leader1' ] );
	createIntention( group.id, { requester_contact: '600 11 12 22', body: 'Texto de Luis' } );
	php( `DFXPRAY_Members::get_instance()->create( ${ group.id }, array( 'name' => 'Luis Gómez', 'phone' => '+34600111222' ) ); echo 1;` );

	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray' );
	const card = page.locator( '.dfxpray-group', { hasText: 'Santa Marta' } );
	await card.getByLabel( 'Data controller' ).fill( 'Parroquia San Manuel González' );
	await card.getByLabel( 'Contact for data protection' ).fill( 'privacidad@example.org' );
	await card.getByRole( 'button', { name: 'Save group' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Group saved.' );

	await page.goto( '/wp-admin/admin.php?page=dfxpray-global' );
	await page.getByLabel( 'Provider of the service (data processor)' ).fill( 'David Marín Carreño' );
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );

	await page.getByPlaceholder( 'Email or phone' ).fill( '+34 600 111 222' );
	await page.getByRole( 'button', { name: 'Look up' } ).click();
	await expect( page.locator( '#dfxpray-privacy-export' ) ).toHaveValue( /Texto de Luis/ );
	await expect( page.locator( '#dfxpray-privacy-export' ) ).toHaveValue( /Name: Luis Gómez/ );

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.getByRole( 'button', { name: 'Erase everything found' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Personal data erased.' );
	expect( php( `echo wp_json_encode( array( count( DFXPRAY_Intentions::get_instance()->query( ${ group.id } ) ), count( DFXPRAY_Members::get_instance()->for_group( ${ group.id } ) ) ) );` ) ).toEqual( [ 0, 0 ] );

	const form = await page.request.get( `/${ group.slug }/` );
	const html = await form.text();
	expect( html ).toContain( 'Parroquia San Manuel González (privacidad@example.org).' );
	expect( html ).toContain( 'David Marín Carreño provides this tool free of charge to Parroquia San Manuel González as data processor' );

	await page.goto( '/wp-admin/admin.php?page=dfxpray' );
	await expect( page.locator( `#dfxpray-agreement-${ group.id }` ) ).toHaveValue( /1\. Parties\. Parroquia San Manuel González, as data controller, and David Marín Carreño, as data processor\./ );
} );

test( 'each leader acknowledges the duty of confidentiality once', async ( { page } ) => {
	createGroup( 'Santa Marta', [ 'leader1' ] );
	php( "delete_user_meta( get_user_by( 'login', 'leader1' )->ID, DFXPRAY_Admin::CONFIDENTIALITY_META ); echo 1;" );
	await login( page, 'leader1' );

	const notice = page.locator( '.dfxpray-confidentiality' );
	await expect( notice ).toContainText( 'You have a duty of confidentiality' );
	await notice.getByRole( 'button', { name: 'Understood' } ).click();

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Thank you.' );
	await expect( notice ).toHaveCount( 0 );
	await page.reload();
	await expect( notice ).toHaveCount( 0 );
} );
