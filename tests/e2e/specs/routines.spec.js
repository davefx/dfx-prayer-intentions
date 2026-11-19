/**
 * Routines: a service account, marked by the administrator, keeps a group up
 * to date through the REST route with an application password.
 */
const { groupUrl, test, expect, resetData, createGroup, createIntention, login, mails, php } = require( '../helpers' );

test.beforeEach( () => resetData() );

test( 'a service account works with an application password, only in this plugin and only in its group', async ( { page, request } ) => {
	const group = createGroup( 'Santa Marta', [ 'leader1', 'leader2' ] );
	const other = createGroup( 'San Pablo', [ 'leader1' ] );
	createIntention( group.id, { summary: 'recién llegada' } );
	const hidden = createIntention( other.id, { summary: 'de otro grupo' } );

	await login( page, 'admin' );
	await page.goto( '/wp-admin/user-edit.php?user_id=' + php( "echo get_user_by( 'login', 'leader2' )->ID;" ) );
	await page.getByLabel( 'Only a routine uses this account' ).check();
	await page.getByRole( 'button', { name: 'Update User' } ).click();
	await expect( page.locator( '#message' ) ).toContainText( 'User updated.' );
	await expect( page.getByLabel( 'Only a routine uses this account' ) ).toBeChecked();

	const password = php( `
		$created = WP_Application_Passwords::create_new_application_password( get_user_by( 'login', 'leader2' )->ID, array( 'name' => 'rutina' ) );
		echo wp_json_encode( $created[0] );
	` );
	const auth = { Authorization: 'Basic ' + Buffer.from( 'leader2:' + password ).toString( 'base64' ) };
	const call = ( op, data = {}, headers = auth ) => request.post( '/?rest_route=/dfxpray/v1/' + op, { headers, data } );

	expect( ( await call( 'list', { group_id: group.id }, {} ) ).status() ).toBe( 401 );

	const list = await call( 'list', { group_id: group.id } );
	expect( list.status() ).toBe( 200 );
	expect( ( await list.json() ).data.items.map( ( i ) => i.line ) ).toEqual( [ 'Carmen, madre de Luis: recién llegada' ] );

	expect( ( await call( 'list', { group_id: other.id } ) ).status() ).toBe( 403 );
	expect( ( await call( 'get', { intention_id: hidden } ) ).status() ).toBe( 403 );
	expect( ( await request.get( '/?rest_route=/wp/v2/users/me', { headers: auth } ) ).status() ).toBe( 403 );

	const update = await call( 'add_update', { intention_id: php( `echo DFXPRAY_Intentions::get_instance()->query( ${ group.id } )[0]['id'];` ), body: '[24/9] Mejor.', is_news: 1 } );
	expect( update.status() ).toBe( 200 );

	const before = mails().length;
	expect( ( await call( 'send_digest_now', { group_id: group.id } ) ).status() ).toBe( 200 );
	const digest = await ( await call( 'digest', { group_id: group.id } ) ).json();
	expect( digest.data.sent_today ).toBe( true );
	expect( digest.data.shareable ).toContain( 'recién llegada' );
	const sent = mails().slice( before ).flatMap( ( m ) => m.to );
	expect( sent ).toContain( 'leader1@example.org' );
	expect( sent ).not.toContain( 'leader2@example.org' );

	await page.goto( '/wp-admin/admin.php?page=dfxpray' );
	await expect( page.locator( '.dfxpray-leader', { hasText: 'Leader Two' } ).first() ).toContainText( 'service account' );
	// The administrator does not lead the group: one of its leaders checks the digest.
	await page.context().clearCookies();
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'digest' ) );
	await expect( page.getByText( /Generated and sent on/ ) ).toBeVisible();
} );
