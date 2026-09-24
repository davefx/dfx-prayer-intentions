/**
 * A leader moderates requests and shares the index.
 */
const { test, expect, resetData, createGroup, createIntention, login, php } = require( '../helpers' );

let group;

test.beforeEach( () => {
	resetData();
	group = createGroup( 'Santa Marta', [ 'leader1', 'leader2' ] );
} );

test( 'leaders land on their panel after logging in, with their counters', async ( { page } ) => {
	createIntention( group.id, { body: 'Pending one' }, 'form' );

	await login( page, 'leader1' );

	expect( page.url() ).toContain( 'admin.php?page=dfxpray' );
	await expect( page.getByRole( 'heading', { name: 'Santa Marta' } ) ).toBeVisible();
	await expect( page.locator( '[data-count="pending"]' ) ).toHaveText( '1' );
	await expect( page.locator( '#adminmenu .awaiting-mod' ).first() ).toHaveText( '1' );

	const footer = page.locator( '.dfxpray-plugin-footer' );
	await expect( footer ).toBeVisible();
	await expect( footer ).toContainText( 'provided via DFX Prayer Intentions plugin by David Marín Carreño. A.M.D.G.' );
	const box = await footer.boundingBox();
	const viewport = page.viewportSize();
	expect( Math.round( box.y + box.height ) ).toBe( viewport.height );
	expect( box.x ).toBeGreaterThanOrEqual( 150 );
	await page.locator( '#collapse-button' ).click();
	await expect.poll( async () => ( await footer.boundingBox() ).x ).toBeLessThan( 60 );
	await page.locator( '#collapse-button' ).click();
} );

test( 'approving with a summary activates the intention and puts it in the index', async ( { page, context } ) => {
	createIntention( group.id, { body: 'Mi madre está enferma, rezad por ella.', discretion: 'first_name' }, 'form' );
	await login( page, 'leader1' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-moderation' );

	const item = page.locator( '.dfxpray-moderation-item' );
	await expect( item ).toContainText( 'Mi madre está enferma' );
	await expect( item ).toContainText( 'Carmen, madre de Luis' );
	await expect( item.getByLabel( 'Discretion' ).locator( 'option' ) ).toHaveText( [ 'First name', 'Initials', 'Anonymous' ] );

	await item.getByLabel( 'Summary for the index' ).fill( 'por su salud' );
	await item.getByRole( 'button', { name: 'Approve' } ).click();

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention approved.' );
	await expect( page.getByText( 'Nothing waiting for moderation.' ) ).toBeVisible();

	await page.goto( '/wp-admin/admin.php?page=dfxpray-index' );
	const index = page.locator( '#dfxpray-index-text' );
	await expect( index ).toHaveValue( /\*The sick and difficult situations\*\n• Carmen, madre de Luis: por su salud/ );
	await expect( index ).not.toHaveValue( /enferma/ );

	await page.getByRole( 'button', { name: 'Copy' } ).click();
	await expect( page.getByRole( 'button', { name: 'Copied!' } ) ).toBeVisible();
	const clipboard = await page.evaluate( () => navigator.clipboard.readText() );
	expect( clipboard ).toContain( '• Carmen, madre de Luis: por su salud' );
	await context.clearPermissions();
} );

test( 'approving without a summary is refused', async ( { page } ) => {
	const id = createIntention( group.id, {}, 'form' );
	await login( page, 'leader1' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-moderation' );

	await page.locator( '.dfxpray-moderation-item' ).getByLabel( 'Summary for the index' ).evaluate( ( input ) => input.removeAttribute( 'required' ) );
	await page.getByRole( 'button', { name: 'Approve' } ).click();

	await expect( page.locator( '.notice-error' ) ).toContainText( 'Write the summary for the index' );
	expect( php( `echo wp_json_encode( DFXPRAY_Intentions::get_instance()->get( ${ id } )['status'] );` ) ).toBe( 'pending' );
} );

test( 'spam is discarded and duplicates are merged into the existing intention', async ( { page } ) => {
	const existing = createIntention( group.id, { summary: 'Existing one' } );
	const spam = createIntention( group.id, { body: 'Cheap watches' }, 'form' );
	const duplicate = createIntention( group.id, { body: 'Same again', requester_name: 'Ana Pérez' }, 'form' );
	await login( page, 'leader1' );
	await page.goto( '/wp-admin/admin.php?page=dfxpray-moderation' );

	const spamCard = page.locator( '.dfxpray-moderation-item', { hasText: 'Cheap watches' } );
	await spamCard.getByRole( 'button', { name: 'Discard' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention discarded.' );

	const dupCard = page.locator( '.dfxpray-moderation-item', { hasText: 'Same again' } );
	await dupCard.getByLabel( 'Existing intention' ).selectOption( String( existing ) );
	await dupCard.getByRole( 'button', { name: 'Merge as duplicate' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Duplicate merged.' );

	const statuses = php( `$i = DFXPRAY_Intentions::get_instance(); echo wp_json_encode( array( $i->get( ${ spam } )['status'], $i->get( ${ duplicate } )['close_reason'] ) );` );
	expect( statuses ).toEqual( [ 'discarded', 'duplicate' ] );

	await page.goto( `/wp-admin/admin.php?page=dfxpray-intentions&view=edit&id=${ existing }` );
	await expect( page.locator( '.dfxpray-timeline' ).last() ).toContainText( 'Merged duplicate request from Ana Pérez' );
} );

test( 'the index warns when it is too long for one message', async ( { page } ) => {
	for ( let i = 0; i < 45; i++ ) {
		createIntention( group.id, { summary: 'x'.repeat( 95 ) } );
	}
	await login( page, 'leader1' );

	await page.goto( '/wp-admin/admin.php?page=dfxpray-index' );

	await expect( page.locator( '.notice-warning' ) ).toContainText( 'over 4,000' );
} );
