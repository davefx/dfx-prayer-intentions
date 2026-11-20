/**
 * Leaders invite other people to lead their group, by email: the invitee
 * accepts on a page of wp-login.php, choosing their password there, and goes
 * straight into the group.
 */
const { groupUrl, test, expect, resetData, createGroup, login, mails, php, freeze } = require( '../helpers' );

const EMAIL = 'pedro.invited@example.org';
let group;

/**
 * The accept link sent to an address.
 *
 * @param {string} to Email.
 * @return {string} Link.
 */
function linkSentTo( to ) {
	const sent = mails().filter( ( m ) => m.to.includes( to ) );
	expect( sent.length ).toBeGreaterThan( 0 );
	const match = sent[ sent.length - 1 ].message.match( /https?:\/\/\S+[?&]token=[A-Za-z0-9]+/ );
	expect( match ).not.toBeNull();
	return match[ 0 ];
}

test.beforeEach( () => {
	resetData();
	php( `foreach ( array( ${ JSON.stringify( EMAIL ) } ) as $e ) { $u = get_user_by( 'email', $e ); if ( $u ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u->ID ); } } echo 1;` );
	group = createGroup( 'Santa Marta', [ 'leader1', 'leader2' ] );
} );

test( 'a leader invites someone new, who accepts, chooses a password and reaches the group', async ( { page, browser } ) => {
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'settings' ) );

	const leaders = page.locator( '.dfxpray-leaders' );
	await expect( leaders.locator( '.dfxpray-leader-list' ) ).toContainText( 'Leader Two' );
	await expect( leaders ).not.toContainText( 'leader2@example.org' );
	await expect( leaders ).not.toContainText( 'admin@example.org' );

	const form = page.locator( 'form.dfxpray-invite-leader' );
	await form.getByLabel( 'Name' ).fill( 'Pedro Gil' );
	await form.getByLabel( 'Email' ).fill( EMAIL );
	await form.getByRole( 'button', { name: 'Send invitation' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Invitation sent.' );
	await expect( page.locator( '.dfxpray-invitation-list' ) ).toContainText( `Pedro Gil (${ EMAIL }) · expires on` );

	const link = linkSentTo( EMAIL );
	expect( link ).toContain( 'wp-login.php?action=dfxpray_invitation' );

	const context = await browser.newContext();
	const invitee = await context.newPage();
	await invitee.goto( link );
	await expect( invitee.locator( '.dfxpray-invitation' ) ).toContainText( 'Leader One invites you to be a leader of the prayer group “Santa Marta”' );
	await expect( invitee.locator( '.dfxpray-invitation' ) ).toContainText( 'illnesses, deaths, family situations' );
	// Opening the link (as an email scanner would) accepts nothing.
	expect( php( `echo wp_json_encode( (bool) get_user_by( 'email', ${ JSON.stringify( EMAIL ) } ) );` ) ).toBe( false );

	// No account yet: the invitation itself asks for the password, and says
	// that the email is what they log in with.
	const accept = invitee.locator( 'form.dfxpray-invitation-accept' );
	await expect( accept ).toContainText( `You will log in with your email, ${ EMAIL }. Choose your password:` );
	const password = 'Gr4ce-and-Peace-2026!';
	await accept.getByLabel( 'Password', { exact: true } ).fill( password );
	await accept.getByLabel( 'Repeat the password' ).fill( 'something else' );
	await accept.getByRole( 'button', { name: 'Accept and enter' } ).click();
	await expect( invitee.locator( '#login_error' ) ).toContainText( 'The two passwords do not match.' );
	expect( php( `echo wp_json_encode( (bool) get_user_by( 'email', ${ JSON.stringify( EMAIL ) } ) );` ) ).toBe( false );

	await accept.getByLabel( 'Password', { exact: true } ).fill( password );
	await accept.getByLabel( 'Repeat the password' ).fill( password );
	await accept.getByRole( 'button', { name: 'Accept and enter' } ).click();

	// Straight into the group, already logged in.
	await expect( invitee ).toHaveURL( new RegExp( `page=dfxpray-group-${ group.id }` ) );
	await expect( invitee.locator( 'h1' ) ).toHaveText( 'Santa Marta' );

	const user = php( `$u = get_user_by( 'email', ${ JSON.stringify( EMAIL ) } ); echo wp_json_encode( array( 'login' => $u->user_login, 'roles' => $u->roles, 'name' => $u->display_name ) );` );
	expect( user.roles ).toEqual( [ 'dfxpray_leader' ] );
	expect( user.name ).toBe( 'Pedro Gil' );

	const welcome = mails().find( ( m ) => m.to.includes( EMAIL ) && m.message.includes( 'you are now a leader' ) );
	expect( welcome.message ).toContain( `with your email, ${ EMAIL }, and the password you chose` );

	// Coming back another day: the email and the password open the group.
	await context.clearCookies();
	await login( invitee, EMAIL, password );
	await expect( invitee ).toHaveURL( /wp-admin/ );
	await context.close();

	const notice = mails().find( ( m ) => m.to.includes( 'admin@example.org' ) );
	expect( notice.subject ).toContain( 'New leader in Santa Marta' );
	expect( notice.message ).toContain( 'Pedro Gil' );

	await page.goto( groupUrl( group.id, 'log' ) );
	await expect( page.locator( '.dfxpray-table' ) ).toContainText( 'Invitation accepted: new leader' );
	await expect( page.locator( '.dfxpray-table' ) ).toContainText( 'Invitation to lead the group sent' );
} );

test( 'a withdrawn or expired invitation does not open', async ( { page, browser } ) => {
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'settings' ) );
	const form = page.locator( 'form.dfxpray-invite-leader' );
	await form.getByLabel( 'Name' ).fill( 'Pedro Gil' );
	await form.getByLabel( 'Email' ).fill( EMAIL );
	await form.getByRole( 'button', { name: 'Send invitation' } ).click();
	const withdrawn = linkSentTo( EMAIL );

	await page.locator( '.dfxpray-invitation-list' ).getByRole( 'button', { name: 'Withdraw' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Invitation withdrawn.' );
	await expect( page.locator( '.dfxpray-invitation-list' ) ).toHaveCount( 0 );

	const context = await browser.newContext();
	const invitee = await context.newPage();
	await invitee.goto( withdrawn );
	await expect( invitee.locator( '.dfxpray-invitation-invalid' ) ).toHaveText( 'This invitation is not valid or has expired. Ask whoever invited you for a new one.' );
	await expect( invitee.getByRole( 'button', { name: 'Accept the invitation' } ) ).toHaveCount( 0 );

	await form.getByLabel( 'Name' ).fill( 'Pedro Gil' );
	await form.getByLabel( 'Email' ).fill( EMAIL );
	await form.getByRole( 'button', { name: 'Send invitation' } ).click();
	const expired = linkSentTo( EMAIL );
	const later = php( `echo wp_json_encode( DFXPRAY_Clock::get_instance()->now()->modify( '+8 days' )->format( 'Y-m-d H:i' ) );` );
	freeze( later );
	await invitee.goto( expired );
	await expect( invitee.locator( '.dfxpray-invitation-invalid' ) ).toBeVisible();
	await expect( invitee.getByRole( 'button', { name: 'Accept the invitation' } ) ).toHaveCount( 0 );
	freeze( null );
	await context.close();
} );

test( 'an administrator invites from Manage groups to a group they do not lead, and the invitee becomes its leader', async ( { page, browser } ) => {
	const empty = createGroup( 'San Pablo' );
	await login( page, 'admin' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray' );

	const card = page.locator( '.dfxpray-group', { hasText: 'San Pablo' } );
	const form = card.locator( 'form.dfxpray-invite-leader' );
	await form.getByLabel( 'Name' ).fill( 'Pedro Gil' );
	await form.getByLabel( 'Email' ).fill( EMAIL );
	await form.getByRole( 'button', { name: 'Send invitation' } ).click();
	await expect( page ).toHaveURL( /page=dfxpray(&|$)/ );
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Invitation sent.' );
	await expect( page.locator( '.dfxpray-group', { hasText: 'San Pablo' } ).locator( '.dfxpray-invitation-list' ) ).toContainText( `Pedro Gil (${ EMAIL }) · expires on` );
	await expect( page.locator( '.dfxpray-card', { hasText: 'New group' } ).locator( 'form.dfxpray-invite-leader' ) ).toHaveCount( 0 );

	const link = linkSentTo( EMAIL );
	const context = await browser.newContext();
	const invitee = await context.newPage();
	await invitee.goto( link );
	await expect( invitee.locator( '.dfxpray-invitation' ) ).toContainText( 'invites you to be a leader of the prayer group “San Pablo”' );
	await invitee.locator( 'form.dfxpray-invitation-accept' ).getByLabel( 'Password', { exact: true } ).fill( 'Gr4ce-and-Peace-2026!' );
	await invitee.locator( 'form.dfxpray-invitation-accept' ).getByLabel( 'Repeat the password' ).fill( 'Gr4ce-and-Peace-2026!' );
	await invitee.getByRole( 'button', { name: 'Accept and enter' } ).click();
	await expect( invitee ).toHaveURL( new RegExp( `page=dfxpray-group-${ empty.id }` ) );
	await context.close();

	const leaders = php( `echo wp_json_encode( array_map( static fn( $id ) => get_userdata( $id )->user_email, DFXPRAY_Groups::get_instance()->get_leaders( ${ empty.id } ) ) );` );
	expect( leaders ).toEqual( [ EMAIL ] );
} );
