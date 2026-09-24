/**
 * Leaders only ever see and touch their own groups.
 */
const { test, expect, resetData, createGroup, createIntention, login, php } = require( '../helpers' );

let mine;
let other;

test.beforeEach( () => {
	resetData();
	mine = createGroup( 'Santa Marta', [ 'leader1' ] );
	other = createGroup( 'San José', [ 'leader2' ] );
} );

test( 'a leader cannot open another group’s intention by changing the URL', async ( { page } ) => {
	const foreign = createIntention( other.id, { body: 'Secreto de San José' } );
	await login( page, 'leader1' );

	await page.goto( `/wp-admin/admin.php?page=dfxpray-intentions&view=edit&id=${ foreign }` );

	await expect( page.getByText( 'You are not allowed to see this.' ) ).toBeVisible();
	await expect( page.locator( 'body' ) ).not.toContainText( 'Secreto de San José' );
} );

test( 'a forged request against another group’s intention is refused', async ( { page } ) => {
	createIntention( mine.id, {}, 'form' );
	const foreign = createIntention( other.id, {}, 'form' );
	await login( page, 'leader1' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-moderation' );

	// Reuse a genuine approve form, pointed at the other group's intention.
	const form = page.locator( 'form.dfxpray-approve' );
	await form.locator( 'input[name="intention_id"]' ).evaluate( ( input, id ) => ( input.value = String( id ) ), foreign );
	await form.getByLabel( 'Summary for the index' ).fill( 'hijacked' );
	await form.getByRole( 'button', { name: 'Approve' } ).click();

	await expect( page.locator( '.notice-error' ) ).toContainText( 'You are not allowed to do that.' );
	expect( php( `echo wp_json_encode( DFXPRAY_Intentions::get_instance()->get( ${ foreign } )['status'] );` ) ).toBe( 'pending' );
} );

test( 'leaders only see their own groups, and have no other WordPress permission', async ( { page } ) => {
	createIntention( other.id, { body: 'Of the other group' }, 'form' );
	await login( page, 'leader1' );

	await expect( page.locator( '.dfxpray-card h2' ) ).toHaveText( [ 'Santa Marta' ] );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-moderation' );
	await expect( page.locator( 'body' ) ).not.toContainText( 'Of the other group' );
	await expect( page.locator( '.dfxpray-group-switcher' ) ).toHaveCount( 0 );

	await expect( page.locator( '#adminmenu' ) ).not.toContainText( 'Groups' );
	await expect( page.locator( '#adminmenu' ) ).not.toContainText( 'Posts' );
	await expect( page.locator( '#adminmenu' ) ).not.toContainText( 'Dashboard' );

	await page.goto( '/wp-admin/' );
	expect( page.url() ).toContain( 'admin.php?page=dfxpray' );

	for ( const url of [ '/wp-admin/admin.php?page=dfxpray-groups', '/wp-admin/edit.php', '/wp-admin/users.php', '/wp-admin/options-general.php' ] ) {
		await page.goto( url );
		await expect( page.locator( 'body' ), url ).toContainText( /not allowed/ );
	}
} );

test( 'leaders can change their own password from their profile', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( '/wp-admin/profile.php' );

	await page.getByRole( 'button', { name: 'Set New Password' } ).click();
	await page.locator( '#pass1' ).fill( 'A-new-long-passphrase-2026' );
	await page.getByRole( 'button', { name: 'Update Profile' } ).click();
	await expect( page.locator( '#message' ) ).toContainText( 'Profile updated.' );

	await page.context().clearCookies();
	await login( page, 'leader1', 'A-new-long-passphrase-2026' );
	expect( page.url() ).toContain( 'admin.php?page=dfxpray' );
	php( "wp_set_password( 'leader1', get_user_by( 'login', 'leader1' )->ID ); echo 'null';" );
} );

test( 'a leader of two groups switches between them', async ( { page } ) => {
	php( `DFXPRAY_Groups::get_instance()->set_leaders( ${ other.id }, array( get_user_by( 'login', 'leader1' )->ID, get_user_by( 'login', 'leader2' )->ID ) ); echo 'null';` );
	createIntention( mine.id, { body: 'Pending in Santa Marta' }, 'form' );
	createIntention( other.id, { body: 'Pending in San José' }, 'form' );
	await login( page, 'leader1' );

	await expect( page.locator( '.dfxpray-card h2' ) ).toHaveText( [ 'San José', 'Santa Marta' ] );

	await page.goto( '/wp-admin/admin.php?page=dfxpray-moderation' );
	const switcher = page.getByLabel( 'Group:' );
	await switcher.selectOption( { label: 'San José' } );
	await expect( page.locator( 'body' ) ).toContainText( 'Pending in San José' );
	await expect( page.locator( 'body' ) ).not.toContainText( 'Pending in Santa Marta' );

	await page.getByLabel( 'Group:' ).selectOption( { label: 'Santa Marta' } );
	await expect( page.locator( 'body' ) ).toContainText( 'Pending in Santa Marta' );
} );

test( 'an intention moves between two groups led by the same leader', async ( { page } ) => {
	php( `DFXPRAY_Groups::get_instance()->set_leaders( ${ other.id }, array( get_user_by( 'login', 'leader1' )->ID ) ); echo 'null';` );
	const id = createIntention( mine.id );
	await login( page, 'leader1' );
	await page.goto( `/wp-admin/admin.php?page=dfxpray-intentions&view=edit&id=${ id }` );

	await page.getByLabel( 'Move to' ).selectOption( { label: 'San José' } );
	await page.getByRole( 'button', { name: 'Move', exact: true } ).click();

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention moved to the other group.' );
	expect( php( `echo wp_json_encode( DFXPRAY_Intentions::get_instance()->get( ${ id } )['group_id'] );` ) ).toBe( other.id );
} );
