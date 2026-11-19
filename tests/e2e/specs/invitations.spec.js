/**
 * Leaders invite other people to lead their group, by email: the invitee
 * accepts on a page of wp-login.php, chooses a password and reaches the group.
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

	await invitee.getByRole( 'button', { name: 'Accept the invitation' } ).click();
	await expect( invitee ).toHaveURL( /wp-login\.php\?action=rp/ );
	const password = 'Gr4ce-and-Peace-2026!';
	await invitee.locator( '#pass1' ).fill( password );
	await invitee.locator( '#wp-submit' ).click();
	await expect( invitee.locator( '.message, .notice' ).first() ).toContainText( 'Your password has been reset' );

	const user = php( `$u = get_user_by( 'email', ${ JSON.stringify( EMAIL ) } ); echo wp_json_encode( array( 'login' => $u->user_login, 'roles' => $u->roles, 'name' => $u->display_name ) );` );
	expect( user.roles ).toEqual( [ 'dfxpray_leader' ] );
	expect( user.name ).toBe( 'Pedro Gil' );

	await login( invitee, user.login, password );
	await expect( invitee ).toHaveURL( new RegExp( `page=dfxpray-group-${ group.id }` ) );
	await expect( invitee.locator( 'h1' ) ).toHaveText( 'Santa Marta' );
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
