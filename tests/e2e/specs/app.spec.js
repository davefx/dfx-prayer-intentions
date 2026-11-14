/**
 * The leaders' installable app, on a phone-sized screen.
 */
const { test, expect, resetData, createGroup, createIntention, login, php } = require( '../helpers' );

const APP = '/prayer-requests-app/';

let group;

test.use( { viewport: { width: 390, height: 844 } } );

test.beforeEach( () => {
	resetData();
	group = createGroup( 'Santa Marta', [ 'leader1' ] );
} );

test( 'only leaders get into the app; the rest are sent to log in', async ( { page } ) => {
	await page.goto( APP );
	await expect( page ).toHaveURL( /wp-login\.php\?redirect_to=/ );

	await login( page, 'leader1' );
	await page.goto( APP );
	await expect( page.getByRole( 'heading', { name: 'Prayer intentions' } ) ).toBeVisible();
} );

test( 'it is installable: manifest and service worker', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( APP );

	const manifest = await page.locator( 'link[rel="manifest"]' ).getAttribute( 'href' );
	const response = await page.request.get( manifest );
	expect( response.headers()[ 'content-type' ] ).toContain( 'application/manifest+json' );
	expect( ( await response.json() ).display ).toBe( 'standalone' );

	const scope = await page.evaluate( async () => ( await navigator.serviceWorker.ready ).scope );
	expect( scope ).toMatch( /\/prayer-requests-app\/$/ );
} );

test( 'today’s message, ready to copy', async ( { page } ) => {
	createIntention( group.id, { summary: 'quimioterapia' } );
	await login( page, 'leader1' );
	await page.goto( APP );

	await expect( page.locator( '#dfx-message' ) ).toContainText( 'Carmen, madre de Luis: quimioterapia' );
	await page.getByRole( 'button', { name: 'Copy' } ).click();
	await expect( page.getByRole( 'button', { name: 'Copied!' } ) ).toBeVisible();
	expect( await page.evaluate( () => navigator.clipboard.readText() ) ).toContain( 'Carmen, madre de Luis: quimioterapia' );
} );

test( 'a leader adds an intention and follows it up', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( APP );

	await page.getByRole( 'button', { name: 'Add', exact: true } ).click();
	await page.getByLabel( 'Requester', { exact: true } ).fill( 'Felipe Arizu' );
	await page.getByLabel( 'Beneficiary', { exact: true } ).fill( 'Marina' );
	await page.getByLabel( 'Relationship, e.g. mother of' ).fill( 'esposa de' );
	await page.getByLabel( 'Summary for the index' ).fill( 'problemas de salud' );
	await page.getByLabel( 'Full text' ).fill( 'En tratamiento.' );
	await page.getByRole( 'button', { name: 'Add intention' } ).click();

	await expect( page.locator( '#dfx-line' ) ).toHaveText( 'Marina, esposa de Felipe: problemas de salud' );

	await page.getByLabel( 'New update' ).fill( 'Ha empezado un nuevo ciclo.' );
	await page.getByRole( 'button', { name: 'Add update' } ).click();
	await expect( page.locator( '.dfx-timeline' ) ).toContainText( 'Ha empezado un nuevo ciclo.' );

	await page.getByLabel( 'Close as' ).selectOption( 'expired' );
	await page.getByRole( 'button', { name: 'Close', exact: true } ).click();
	await expect( page.locator( '.dfx-muted' ).first() ).toContainText( 'Closed' );

	await page.getByRole( 'button', { name: 'Reopen' } ).click();
	await expect( page.locator( '.dfx-muted' ).first() ).toContainText( 'Active' );

	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await expect( page.locator( '.dfx-list' ) ).toContainText( 'Marina, esposa de Felipe: problemas de salud' );
} );

test( 'every field of an active intention can be edited in the app', async ( { page } ) => {
	php( `DFXPRAY_Settings::get_instance()->save_group( ${ group.id }, array( 'member_prefix' => 'N.H.' ) ); echo 1;` );
	createIntention( group.id, { requester_name: 'Luis Sebastián', beneficiary_name: 'Marta', relationship: 'tía de', summary: 'descanse en paz' } );
	await login( page, 'leader1' );
	await page.goto( APP );
	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await page.locator( '.dfx-item', { hasText: 'Marta' } ).click();
	await expect( page.locator( '#dfx-line' ) ).toHaveText( 'Marta, tía de Luis: descanse en paz' );

	await page.getByText( 'Edit', { exact: true } ).click();
	await page.getByLabel( 'Name to show for the requester' ).fill( 'Luis Sebastián' );
	await page.getByLabel( 'The requester is a member of the group' ).check();
	await page.getByLabel( 'Relationship, e.g. mother of' ).fill( 'tía materna de' );
	await page.getByLabel( 'Full text' ).fill( 'Falleció el sábado.' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();

	await expect( page.locator( '#dfx-line' ) ).toHaveText( 'Marta, tía materna de N.H. Luis Sebastián: descanse en paz' );
	await expect( page.locator( '.dfx-body' ) ).toHaveText( 'Falleció el sábado.' );

	await page.getByText( 'Edit', { exact: true } ).click();
	await page.getByLabel( 'The requester is a member of the group' ).uncheck();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '#dfx-line' ) ).toHaveText( 'Marta, tía materna de Luis Sebastián: descanse en paz' );
} );

test( 'requests from the form are moderated in the app', async ( { page } ) => {
	createIntention( group.id, { beneficiary_name: 'Pedro', relationship: 'hermano de', summary: '' }, 'form' );
	await login( page, 'leader1' );
	await page.goto( APP );

	await page.getByRole( 'button', { name: 'Moderation' } ).click();
	await page.locator( '.dfx-item', { hasText: 'Pedro' } ).click();
	await page.locator( 'form', { has: page.getByRole( 'button', { name: 'Approve' } ) } ).getByLabel( 'Summary for the index' ).fill( 'busca trabajo' );
	await page.getByRole( 'button', { name: 'Approve' } ).click();

	await expect( page.getByRole( 'heading', { name: 'Waiting for moderation' } ) ).toBeVisible();
	await expect( page.locator( '.dfx-item', { hasText: 'Pedro' } ) ).toHaveCount( 0 );

	await page.getByRole( 'button', { name: 'Today' } ).click();
	await expect( page.locator( '#dfx-message' ) ).toContainText( 'Pedro, hermano de Luis: busca trabajo' );
} );

test( 'without connection it shows the last data and sends changes later', async ( { page, context } ) => {
	const id = createIntention( group.id, { summary: 'quimioterapia' } );
	await login( page, 'leader1' );
	await page.goto( APP );
	await expect( page.locator( '#dfx-message' ) ).toContainText( 'quimioterapia' );
	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await page.locator( '.dfx-item', { hasText: 'quimioterapia' } ).click();
	await expect( page.locator( '#dfx-line' ) ).toContainText( 'quimioterapia' );

	await context.setOffline( true );
	await page.getByLabel( 'New update' ).fill( 'Escrito sin cobertura.' );
	await page.getByRole( 'button', { name: 'Add update' } ).click();
	await page.getByRole( 'button', { name: 'Today' } ).click();
	await expect( page.locator( '.dfx-notice', { hasText: 'No connection' } ).first() ).toBeVisible();
	await expect( page.locator( '#dfx-message' ) ).toContainText( 'quimioterapia' );
	await expect( page.getByText( 'Waiting to be sent: 1' ) ).toBeVisible();

	await context.setOffline( false );
	await expect( page.getByText( 'Waiting to be sent: 1' ) ).toHaveCount( 0 );
	expect( php( `echo wp_json_encode( DFXPRAY_Updates::get_instance()->for_intention( ${ id } )[0]['body'] );` ) ).toBe( 'Escrito sin cobertura.' );
} );
