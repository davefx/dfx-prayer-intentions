/**
 * The leaders' installable app, on a phone-sized screen.
 */
const { test, expect, resetData, createGroup, createIntention, freeze, tick, login, php } = require( '../helpers' );

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
	const who = page.getByRole( 'group', { name: 'Who asks' } );
	await who.getByLabel( 'Full name' ).fill( 'Felipe Arizu' );
	await expect( who.getByLabel( 'How we call them' ) ).toHaveAttribute( 'placeholder', 'Felipe' );
	const forWhom = page.getByRole( 'group', { name: 'Who we pray for' } );
	await forWhom.getByLabel( 'Name', { exact: true } ).fill( 'Marina' );
	await forWhom.getByLabel( 'What they are to Felipe' ).fill( 'esposa de' );
	await page.getByLabel( 'What they told us' ).fill( 'En tratamiento.' );
	await page.getByLabel( 'Summary' ).fill( 'problemas de salud' );
	await expect( page.locator( '#dfx-preview' ) ).toHaveText( 'Marina, esposa de Felipe: problemas de salud' );
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
	createIntention( group.id, { beneficiary_name: 'Marina', relationship: 'esposa de', summary: 'problemas de salud', topic: 'health', requester_phone: '600 99 88 77', asked_on: '2026-01-02' } );
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

	await page.getByRole( 'button', { name: 'Edit' } ).click();
	await page.getByLabel( 'Only the monthly list' ).check();
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
	await page.getByRole( 'button', { name: 'Edit' } ).click();
	await page.getByLabel( 'Pending to ask', { exact: true } ).check();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfx-hero .dfx-chip.is-ask' ) ).toHaveText( 'Pending to ask' );

	await page.getByLabel( 'New update' ).fill( 'Ya está en casa.' );
	await page.getByRole( 'button', { name: 'Add update' } ).click();
	await expect( page.locator( '.dfx-timeline' ) ).toContainText( 'Ya está en casa.' );
	await expect( page.locator( '.dfx-hero .dfx-chip.is-ask' ) ).toHaveCount( 0 );
} );

test( 'the back button moves through the app and never leaves it for the login page', async ( { page } ) => {
	createIntention( group.id, { beneficiary_name: 'Marina', relationship: 'esposa de', summary: 'problemas de salud' } );
	await page.goto( APP );
	await expect( page ).toHaveURL( /wp-login\.php/ );
	await page.fill( '#user_login', 'leader1' );
	await page.fill( '#user_pass', 'leader1' );
	await Promise.all( [ page.waitForURL( /prayer-requests-app/ ), page.click( '#wp-submit' ) ] );
	await expect( page.locator( '#dfx-message' ) ).toBeVisible();

	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await page.locator( '.dfx-item', { hasText: 'Marina' } ).click();
	await expect( page.locator( '#dfx-line' ) ).toBeVisible();

	await page.goBack();
	await expect( page.locator( '.dfx-item', { hasText: 'Marina' } ) ).toBeVisible();
	await page.goBack();
	await expect( page.locator( '#dfx-message' ) ).toBeVisible();

	await page.goBack();
	await expect( page ).toHaveURL( /prayer-requests-app/ );
	await expect( page.locator( '#dfx-message' ) ).toBeVisible();
	await page.goBack();
	await expect( page ).toHaveURL( /prayer-requests-app/ );
	await expect( page.locator( '#dfx-message' ) ).toBeVisible();
} );

test( 'the intention form follows five steps and shows only the fields that apply', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( APP );
	await page.getByRole( 'button', { name: 'Add', exact: true } ).click();

	await expect( page.locator( '.dfx-step > legend, .dfx-step > summary' ) ).toHaveText( [ /Who asks/, /Who we pray for/, /What is happening/, /How it appears in the message/, /Follow-up/ ] );

	const who = page.getByRole( 'group', { name: 'Who asks' } );
	await who.getByLabel( 'Full name' ).fill( 'Titón' );
	await expect( who.getByLabel( 'How we call them' ) ).toBeHidden();
	await who.getByLabel( 'Full name' ).fill( 'Titón Alonso' );
	await expect( who.getByLabel( 'How we call them' ) ).toBeVisible();

	const forWhom = page.getByRole( 'group', { name: 'Who we pray for' } );
	await forWhom.getByLabel( 'For themselves' ).check();
	await expect( forWhom.getByLabel( 'Name', { exact: true } ) ).toBeHidden();
	await forWhom.getByLabel( 'For someone else' ).check();
	await expect( forWhom.getByLabel( 'Name', { exact: true } ) ).toBeVisible();

	const what = page.getByRole( 'group', { name: 'What is happening' } );
	await expect( what.getByLabel( 'A situation' ) ).toBeChecked();
	await expect( what.locator( 'input[name="reference_date"]' ) ).toBeHidden();
	await what.getByLabel( 'A death' ).check();
	await expect( what.getByLabel( 'Date of death' ) ).toBeVisible();
	await expect( what.getByLabel( 'Remember it every year' ) ).toBeVisible();
	await expect( what.getByLabel( 'Time' ) ).toBeHidden();
	await what.getByLabel( 'Something with a date' ).check();
	await expect( what.getByLabel( 'Date', { exact: true } ) ).toBeVisible();
	await expect( what.getByLabel( 'Time' ) ).toBeVisible();
	await expect( what.getByLabel( 'Remember it every year' ) ).toBeHidden();

	await expect( page.locator( 'select[name="discretion"]' ) ).toBeHidden();
	await page.getByText( 'Discretion and names' ).click();
	await expect( page.locator( 'select[name="discretion"]' ) ).toBeVisible();
} );

test( 'every field of an active intention can be edited in the app', async ( { page } ) => {
	php( `DFXPRAY_Settings::get_instance()->save_group( ${ group.id }, array( 'member_prefix' => 'N.H.' ) ); echo 1;` );
	createIntention( group.id, { requester_name: 'Luis Sebastián', beneficiary_name: 'Marta', relationship: 'tía de', summary: 'descanse en paz' } );
	await login( page, 'leader1' );
	await page.goto( APP );
	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await page.locator( '.dfx-item', { hasText: 'Marta' } ).click();
	await expect( page.locator( '#dfx-line' ) ).toHaveText( 'Marta, tía de Luis: descanse en paz' );

	await page.getByRole( 'button', { name: 'Edit' } ).click();
	await page.goBack();
	await expect( page.locator( '#dfx-line' ) ).toHaveText( 'Marta, tía de Luis: descanse en paz' );
	await page.getByRole( 'button', { name: 'Edit' } ).click();
	const who = page.getByRole( 'group', { name: 'Who asks' } );
	await who.getByLabel( 'How we call them' ).fill( 'Luis Sebastián' );
	await who.getByLabel( 'One of our group' ).check();
	await page.getByLabel( /^What they are to/ ).fill( 'tía materna de' );
	await page.getByLabel( 'What they told us' ).fill( 'Falleció el sábado.' );
	await expect( page.locator( '#dfx-preview' ) ).toHaveText( 'Marta, tía materna de N.H. Luis Sebastián: descanse en paz' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();

	await expect( page.locator( '#dfx-line' ) ).toHaveText( 'Marta, tía materna de N.H. Luis Sebastián: descanse en paz' );
	await expect( page.locator( '.dfx-body' ) ).toHaveText( 'Falleció el sábado.' );

	await page.getByRole( 'button', { name: 'Edit' } ).click();
	await page.getByLabel( 'When they asked' ).fill( '2026-09-14' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfx-created' ) ).toHaveText( 'Added on Monday, September 14, 2026' );

	await page.getByRole( 'button', { name: 'Edit' } ).click();
	await page.getByRole( 'group', { name: 'Who asks' } ).getByLabel( 'One of our group' ).uncheck();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '#dfx-line' ) ).toHaveText( 'Marta, tía materna de Luis Sebastián: descanse en paz' );
} );

test( 'an intention prayed for until a day leaves the message by itself the day after', async ( { page } ) => {
	freeze( '2026-09-24 10:00' );
	createIntention( group.id, { beneficiary_name: 'Pontevedra', relationship: '', summary: 'por sus frutos' } );
	await login( page, 'leader1' );
	await page.goto( APP );
	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await page.locator( '.dfx-item', { hasText: 'Pontevedra' } ).click();

	await page.getByRole( 'button', { name: 'Edit' } ).click();
	await page.getByLabel( 'Or pray for it only until' ).fill( '2026-09-27' );
	await expect( page.getByLabel( 'Look at it again on' ) ).toBeDisabled();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfx-hero .dfx-review' ) ).toHaveText( 'Until September 27' );

	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await expect( page.locator( '.dfx-item', { hasText: 'Pontevedra' } ).locator( '.dfx-review' ) ).toHaveText( 'Until Sep 27' );
	await page.getByRole( 'button', { name: 'Today' } ).click();
	await expect( page.locator( '#dfx-message' ) ).toContainText( 'Pontevedra: por sus frutos' );

	freeze( '2026-09-28 05:00' );
	await page.reload();
	await expect( page.locator( '#dfx-message' ) ).not.toContainText( 'Pontevedra' );
	tick();
	await page.getByRole( 'button', { name: 'Intentions' } ).click();
	await expect( page.locator( '.dfx-item', { hasText: 'Pontevedra' } ) ).toHaveCount( 0 );
	freeze( null );
} );

test( 'the Members tab goes through the year by month and opens on today or the next day with someone', async ( { page } ) => {
	freeze( '2026-11-16 10:00' );
	php( `$m = DFXPRAY_Members::get_instance();
		$m->create( ${ group.id }, array( 'name' => 'Luis Gómez', 'birth_day' => '10', 'birth_month' => '1', 'birth_year' => '1960' ) );
		$m->create( ${ group.id }, array( 'name' => 'Teo Martín', 'phone' => '600 11 22 33', 'birth_day' => '20', 'birth_month' => '11', 'notes' => 'XI Retiro - Mesa 1' ) );
		$m->create( ${ group.id }, array( 'name' => 'Ramón Vallvé', 'death_date' => '2023-12-27' ) );
		$m->create( ${ group.id }, array( 'name' => 'Pepe Ruiz' ) );
		echo 1;` );
	await login( page, 'leader1' );
	await page.goto( APP );
	await page.getByRole( 'button', { name: 'Members' } ).click();

	const tops = await page.locator( '.dfx-nav button' ).evaluateAll( ( buttons ) => buttons.map( ( b ) => Math.round( b.getBoundingClientRect().top ) ) );
	expect( tops ).toHaveLength( 6 );
	expect( new Set( tops ).size, 'The six tabs fit in one row on a phone.' ).toBe( 1 );

	const months = page.locator( '.dfx-month > summary' );
	await expect( months ).toHaveCount( 13 );
	await expect( months.first() ).toContainText( 'January' );
	await expect( months.nth( 10 ) ).toContainText( 'November' );
	await expect( months.last() ).toContainText( 'No date' );
	await expect( page.locator( '.dfx-month[open] > summary' ) ).toHaveCount( 1 );
	await expect( page.locator( '.dfx-month[open] > summary' ) ).toContainText( 'November' );

	const focused = page.locator( '.dfx-day:focus' );
	await expect( focused ).toContainText( 'Teo' );
	const headerBottom = await page.locator( '.dfx-header' ).evaluate( ( el ) => el.getBoundingClientRect().bottom );
	expect( await focused.evaluate( ( el ) => el.getBoundingClientRect().top ), 'The day is not hidden under the header.' ).toBeGreaterThanOrEqual( headerBottom );
	const teo = page.locator( '.dfx-member', { hasText: 'Teo' } );
	await expect( teo ).toContainText( 'Birthday' );
	await expect( teo ).toContainText( 'XI Retiro - Mesa 1' );
	await expect( teo.getByRole( 'link', { name: 'WhatsApp' } ) ).toHaveAttribute( 'href', 'https://wa.me/34600112233' );

	await months.nth( 11 ).click();
	await expect( page.locator( '.dfx-member', { hasText: 'Ramón' } ) ).toContainText( 'Anniversary of his death († 2023)' );
	await months.first().click();
	await expect( page.locator( '.dfx-member', { hasText: 'Luis' } ) ).toContainText( 'Turns 67' );
	freeze( null );
} );

test( 'requests from the form are moderated in the app', async ( { page } ) => {
	createIntention( group.id, { beneficiary_name: 'Pedro', relationship: 'hermano de', summary: '' }, 'form' );
	await login( page, 'leader1' );
	await page.goto( APP );

	await page.getByRole( 'button', { name: 'Moderation' } ).click();
	await page.locator( '.dfx-item', { hasText: 'Pedro' } ).click();
	await page.locator( 'form', { has: page.getByRole( 'button', { name: 'Approve' } ) } ).getByLabel( 'Summary' ).fill( 'busca trabajo' );
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
