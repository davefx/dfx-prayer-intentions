/**
 * Time-driven behaviour, with the plugin clock frozen: reviews, the daily
 * digest, hour alerts and delivery channels.
 */
const { groupUrl, test, expect, resetData, createGroup, createIntention, login, freeze, tick, mails, php } = require( '../helpers' );

let group;

test.beforeEach( () => {
	resetData();
	group = createGroup( 'Santa Marta', [ 'leader1' ] );
} );

test.afterEach( () => freeze( null ) );

test( 'due intentions go to review and stay in the index until a leader decides', async ( { page } ) => {
	freeze( '2026-09-20 10:00' );
	createIntention( group.id, { type: 'event', reference_date: '2026-09-21', summary: 'examen de Pablo' } );
	freeze( '2026-09-24 10:00' );
	tick();
	await login( page, 'leader1' );

	await expect( page.locator( '.dfxpray-tabs' ).getByRole( 'link', { name: 'Reviews' } ) ).toHaveText( 'Reviews 1' );
	await page.goto( groupUrl( group.id, 'index' ) );
	await expect( page.locator( '#dfxpray-index-text' ) ).toHaveValue( /examen de Pablo/ );

	await page.goto( groupUrl( group.id, 'reviews' ) );
	await page.getByRole( 'button', { name: 'Still current' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention kept active.' );
	await expect( page.getByText( 'No reviews due.' ) ).toBeVisible();
} );

test( 'the daily digest goes out at its time, once, and is kept in the panel', async ( { page } ) => {
	freeze( '2026-09-23 12:00' );
	createIntention( group.id, { type: 'event', reference_date: '2026-09-24', reference_time: '09:30', summary: 'operación', beneficiary_name: 'Carmen' } );
	createIntention( group.id, { body: 'Waiting' }, 'form' );
	const before = mails().length;

	freeze( '2026-09-24 06:55' );
	tick();
	expect( mails().length ).toBe( before );

	freeze( '2026-09-24 07:02' );
	tick();
	tick();
	const digests = mails().slice( before );
	expect( digests ).toHaveLength( 1 );
	expect( digests[ 0 ].subject ).toContain( 'Prayer digest' );

	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'digest' ) );
	await expect( page.getByText( /Generated and sent on/ ) ).toBeVisible();
	await expect( page.locator( '#dfxpray-digest-shareable' ) ).toHaveValue( /\*Today\*\n• 9:30 – Carmen, madre de Luis: operación/ );
	await expect( page.locator( '#dfxpray-digest-shareable' ) ).not.toHaveValue( /Leaders only/ );
	await expect( page.locator( '#dfxpray-digest-leaders' ) ).toHaveValue( /Pending moderation: 1/ );
} );

test( 'hour alerts, enabled from the group settings, go out before the event', async ( { page } ) => {
	freeze( '2026-09-24 05:00' );
	createIntention( group.id, { type: 'event', reference_date: '2026-09-24', reference_time: '09:30', summary: 'operación', beneficiary_name: 'Carmen' } );

	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'settings' ) );
	await page.locator( 'input[type="checkbox"][name="settings[channels][email][alerts]"]' ).check();
	await page.getByLabel( 'Daily digest at' ).fill( '23:00' );
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );

	const before = mails().length;
	freeze( '2026-09-24 09:16' );
	tick();
	const alerts = mails().slice( before );
	expect( alerts ).toHaveLength( 1 );
	expect( alerts[ 0 ].message ).toContain( 'At 9:30: Carmen, madre de Luis: operación. United in prayer.' );

	await page.goto( groupUrl( group.id, 'digest' ) );
	await expect( page.getByRole( 'heading', { name: 'Recent hour alerts' } ) ).toBeVisible();
	await expect( page.locator( '#dfxpray-alert-0' ) ).toHaveValue( /At 9:30/ );
} );

test( 'a late-night digest tells the group about tonight’s events, which get no alert', async ( { page } ) => {
	freeze( '2026-09-20 12:00' );
	createIntention( group.id, { type: 'event', reference_date: '2026-09-25', reference_time: '06:30', summary: 'operación', beneficiary_name: 'Carmen' } );

	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'settings' ) );
	await page.locator( 'input[type="checkbox"][name="settings[channels][email][alerts]"]' ).check();
	await page.getByLabel( 'Daily digest at' ).fill( '23:30' );
	await page.getByRole( 'button', { name: 'Save settings' } ).click();

	const before = mails().length;
	freeze( '2026-09-24 23:32' );
	tick();
	freeze( '2026-09-25 06:16' );
	tick();
	const sent = mails().slice( before );
	expect( sent ).toHaveLength( 1 );
	expect( sent[ 0 ].message ).toContain( 'Without an hour alert' );
	expect( sent[ 0 ].message ).toContain( 'tomorrow, 6:30 – Carmen, madre de Luis: operación' );
} );

test( 'Telegram and webhook channels are configured per group and can be tested', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'settings' ) );

	await page.getByLabel( 'Bot token' ).fill( '123:ABC' );
	await page.getByLabel( 'Chat ID' ).fill( '-100555' );
	await page.getByLabel( 'URL', { exact: true } ).fill( 'https://hooks.example.org/prayer' );
	await page.getByLabel( 'Signing secret' ).fill( 's3cret' );
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
	await expect( page.getByLabel( 'Chat ID' ) ).toHaveValue( '-100555' );

	await page.getByRole( 'button', { name: 'Send a test by Telegram' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Test message sent.' );
	await page.getByRole( 'button', { name: 'Send a test by Webhook' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Test message sent.' );

	const http = php( "echo wp_json_encode( get_option( 'dfxpray_e2e_http', array() ) );" );
	expect( http.map( ( r ) => r.url ) ).toEqual( [ 'https://api.telegram.org/bot123:ABC/sendMessage', 'https://hooks.example.org/prayer' ] );
	expect( JSON.parse( http[ 1 ].body ).event ).toBe( 'test' );

	await page.goto( groupUrl( group.id, 'log' ) );
	await expect( page.locator( '.dfxpray-table' ) ).toContainText( 'Delivered · webhook · test' );
} );

test( 'the digest can be generated and sent on demand', async ( { page } ) => {
	createIntention( group.id, { summary: 'recién llegada' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'digest' ) );
	await expect( page.getByText( /Preview: today’s digest has not been sent yet/ ) ).toBeVisible();

	await page.getByRole( 'button', { name: 'Generate and send now' } ).click();

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Digest generated and sent.' );
	await expect( page.getByText( /Generated and sent on/ ) ).toBeVisible();
	await expect( page.locator( '#dfxpray-digest-shareable' ) ).toHaveValue( /\*News\*\n• Carmen, madre de Luis: recién llegada/ );
} );
