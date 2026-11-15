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
	await expect( page.locator( '.dfx-created' ) ).toHaveText( /^Added on \w+, \w+ \d{1,2}, \d{4}$/ );
	await expect( page.locator( '#dfx-requester' ) ).toHaveText( 'Asked by Felipe Arizu' );
	await expect( page.locator( '.dfx-hero .dfx-review' ) ).toContainText( 'Next review:' );

	await page.getByLabel( 'New update' ).fill( 'Ha empezado un nuevo ciclo.' );
	await page.getByRole( 'button', { name: 'Add update' } ).click();
	await expect( page.locator( '.dfx-timeline' ) ).toContainText( 'Ha empezado un nuevo ciclo.' );

	await page.getByLabel( 'Close as' ).selectOption( 'expired' );
	await page.getByRole( 'button', { name: 'Close', exact: true } ).click();
	await expect( page.locator( '#dfx-status' ) ).toHaveText( 'Closed' );

	await page.getByRole( 'button', { name: 'Reopen' } ).click();
	await expect( page.locator( '#dfx-status' ) ).toHaveText( 'Active' );

	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	const card = page.locator( '.dfx-item', { hasText: 'Marina' } );
	await expect( card ).toContainText( 'Marina, esposa de Felipe: problemas de salud' );
	await expect( card.locator( '.dfx-leaf-day' ) ).toHaveText( /^\d{1,2}$/ );
	await expect( card.locator( '.dfx-requester' ) ).toHaveText( 'Asked by Felipe Arizu' );
} );

test( 'the list shows the urgent ones first, then the most recent', async ( { page } ) => {
	createIntention( group.id, { beneficiary_name: 'Ana', summary: 'antigua', asked_on: '2026-09-01' } );
	createIntention( group.id, { beneficiary_name: 'Berta', summary: 'reciente', asked_on: '2026-11-10' } );
	createIntention( group.id, { beneficiary_name: 'Clara', summary: 'urgente', asked_on: '2026-10-01', priority: 'high' } );
	await login( page, 'leader1' );
	await page.goto( APP );
	await page.getByRole( 'button', { name: 'Intentions' } ).click();

	await expect( page.locator( '.dfx-item .dfx-summary' ) ).toHaveText( [ 'urgente', 'reciente', 'antigua' ] );
} );

test( 'daily message and monthly list; the requester can be asked by WhatsApp', async ( { page } ) => {
	php( `DFXPRAY_Settings::get_instance()->save_group( ${ group.id }, array( 'index_layout' => 'proposals' ) ); echo 1;` );
	createIntention( group.id, { beneficiary_name: 'Fátima', relationship: 'sobrina de', summary: 'quimioterapia', topic: 'health', scope: 'monthly' } );
	createIntention( group.id, { beneficiary_name: 'Marina', relationship: 'esposa de', summary: 'problemas de salud', topic: 'health', requester_phone: '600 99 88 77' } );
	await login( page, 'leader1' );
	await page.goto( APP );

	await expect( page.locator( '#dfx-message' ) ).toContainText( '*For the health of*\n• Marina, esposa de Luis' );
	await expect( page.locator( '#dfx-message' ) ).not.toContainText( 'Fátima' );
	await page.getByRole( 'button', { name: 'Monthly list' } ).click();
	await expect( page.locator( '#dfx-message' ) ).toContainText( 'Fátima, sobrina de Luis: quimioterapia' );
	await expect( page.locator( '#dfx-message' ) ).toContainText( 'For those who study, sit exams or look for work' );

	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await page.locator( '.dfx-item', { hasText: 'Marina' } ).click();
	await expect( page.getByRole( 'link', { name: 'Ask by WhatsApp how it goes' } ) ).toHaveAttribute( 'href', /^https:\/\/wa\.me\/34600998877\?text=/ );

	await page.getByText( 'Edit', { exact: true } ).click();
	await page.locator( '.dfx-edit select[name="scope"]' ).selectOption( 'monthly' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfx-notice' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Today' } ).click();
	await page.getByRole( 'button', { name: 'Daily', exact: true } ).click();
	await expect( page.locator( '#dfx-message' ) ).not.toContainText( 'Marina' );
} );

test( 'the To ask tab groups by brother, with one WhatsApp question for all their intentions', async ( { page } ) => {
	createIntention( group.id, { requester_name: 'Titón Alonso', requester_phone: '606 45 37 87', beneficiary_name: 'Juan Pablo', relationship: 'conocido de', summary: 'tumor cerebral', to_ask: '1' } );
	createIntention( group.id, { requester_name: 'Titón Alonso', beneficiary_name: 'Beatriz', relationship: 'hija de un amigo de', summary: 'trasplante de riñón', to_ask: '1' } );
	createIntention( group.id, { requester_name: 'Ramón García', beneficiary_name: 'José', relationship: 'amigo de', summary: 'trasplante' } );
	await login( page, 'leader1' );
	await page.goto( APP );

	await page.getByRole( 'button', { name: 'To ask' } ).click();
	const titon = page.locator( '.dfx-ask-group', { hasText: 'Titón Alonso' } );
	await expect( titon.locator( '.dfx-item' ) ).toHaveCount( 2 );
	const href = await titon.getByRole( 'link', { name: 'Ask about all of them by WhatsApp' } ).getAttribute( 'href' );
	expect( href ).toMatch( /^https:\/\/wa\.me\/34606453787\?text=/ );
	expect( decodeURIComponent( href ) ).toContain( '• Juan Pablo (tumor cerebral)' );
	expect( decodeURIComponent( href ) ).toContain( '• Beatriz, hija de un amigo (trasplante de riñón)' );
	await expect( page.locator( '.dfx-ask-group', { hasText: 'Ramón García' } ) ).toHaveCount( 0 );

	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await page.locator( '.dfx-item', { hasText: 'José' } ).click();
	await page.getByText( 'Edit', { exact: true } ).click();
	await page.getByLabel( 'Pending to ask', { exact: true } ).check();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfx-hero .dfx-chip.is-ask' ) ).toHaveText( 'Pending to ask' );

	await page.getByLabel( 'New update' ).fill( 'Ya está en casa.' );
	await page.getByRole( 'button', { name: 'Add update' } ).click();
	await expect( page.locator( '.dfx-timeline' ) ).toContainText( 'Ya está en casa.' );
	await expect( page.locator( '.dfx-hero .dfx-chip.is-ask' ) ).toHaveCount( 0 );
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
	await page.getByLabel( 'Date of the request' ).fill( '2026-09-14' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfx-created' ) ).toHaveText( 'Added on Monday, September 14, 2026' );

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
