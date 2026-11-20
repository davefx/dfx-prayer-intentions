/**
 * The intention screen: comments, updates, quick actions and manual creation.
 */
const { groupUrl, test, expect, resetData, createGroup, createIntention, login, php } = require( '../helpers' );

let group;

test.beforeEach( () => {
	resetData();
	group = createGroup( 'Santa Marta', [ 'leader1', 'leader2' ] );
} );

test( 'an intention opens from the list of the group', async ( { page } ) => {
	const id = createIntention( group.id, { summary: 'desde la lista' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions' ) );

	await page.getByRole( 'link', { name: 'Carmen Ruiz' } ).click();

	expect( page.url() ).toContain( `page=dfxpray-group-${ group.id }&tab=intentions&view=edit&id=${ id }` );
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'Carmen, madre de Luis: desde la lista' );
} );

test( 'comments are shared among leaders, signed when edited, and can become updates', async ( { page, browser } ) => {
	const id = createIntention( group.id );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await page.getByLabel( 'New comment' ).fill( 'Llamé a Luis, sigue ingresada.' );
	await page.getByLabel( 'Type' ).first().selectOption( 'call' );
	await page.getByRole( 'button', { name: 'Add comment' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Comment added.' );

	const other = await browser.newPage();
	await login( other, 'leader2' );
	await other.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );
	await other.getByText( 'Edit', { exact: true } ).click();
	await other.getByLabel( 'Comment', { exact: true } ).fill( 'Llamé a Luis: ya está en casa.' );
	await other.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect( other.locator( '.dfxpray-timeline' ).nth( 1 ) ).toContainText( 'edited by Leader Two' );
	await other.close();

	await page.reload();
	await page.getByRole( 'button', { name: 'Turn into an update' } ).click();
	await expect( page.locator( '.dfxpray-timeline' ).first() ).toContainText( 'Llamé a Luis: ya está en casa.' );
	await expect( page.locator( '.dfxpray-timeline' ).first() ).toContainText( 'news' );
} );

test( 'quick actions: priority, acknowledgement, postponement', async ( { page } ) => {
	const id = createIntention( group.id, { summary: 'quimioterapia' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await page.getByRole( 'button', { name: 'Mark as high priority' } ).click();
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( '❗ Carmen, madre de Luis: quimioterapia' );

	await page.getByRole( 'button', { name: 'Acknowledgement sent' } ).click();
	await expect( page.locator( '.dfxpray-timeline li', { hasText: 'Acknowledgement sent to the requester.' } ) ).toContainText( 'Contact' );
	await expect( page.getByRole( 'button', { name: 'Acknowledgement sent' } ) ).toHaveCount( 0 );

	await page.getByLabel( 'Postpone review to' ).fill( '2031-01-15' );
	await page.getByRole( 'button', { name: 'Postpone' } ).click();
	await expect( page.getByLabel( 'Look at it again on' ) ).toHaveValue( '2031-01-15' );
} );

test( 'visible names cannot reveal more than the discretion level allows', async ( { page } ) => {
	const id = createIntention( group.id, { beneficiary_name: 'David Marín', summary: 'su operación' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await page.getByText( 'Discretion and names' ).click();
	await page.getByLabel( 'Description of the beneficiary instead of the name' ).fill( 'Nuestro hermano David Marín' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.notice-error' ) ).toContainText( 'cannot include surnames' );

	await page.getByText( 'Discretion and names' ).click();
	await page.getByLabel( 'Description of the beneficiary instead of the name' ).fill( 'Nuestro hermano David' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'Nuestro hermano David, madre de Luis: su operación' );
	await expect( page.locator( 'table.dfxpray-table' ).last() ).toContainText( 'Description changed: “” → “Nuestro hermano David”' );

	await expect( page.getByLabel( 'Discretion', { exact: true } ).locator( 'option' ) ).toHaveText( [ 'First name', 'Initials', 'Anonymous' ] );
} );

test( 'a leader sets how people are called: compound names, nicknames, a description', async ( { page } ) => {
	const id = createIntention( group.id, { requester_name: 'Juan Tomás Rubio', beneficiary_name: 'José Antonio Ruiz', relationship: 'tío de', summary: 'su operación' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'José, tío de Juan: su operación' );

	await page.getByLabel( 'How the beneficiary is called' ).fill( 'Pepe' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'Pepe, tío de Juan: su operación' );

	await page.getByLabel( 'How the beneficiary is called' ).fill( 'José Antonio' );
	await page.getByLabel( 'How the requester is called' ).fill( 'Juan Tomás' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'José Antonio, tío de Juan Tomás: su operación' );

	await page.getByText( 'Discretion and names' ).click();
	await page.getByLabel( 'Description of the beneficiary instead of the name' ).fill( 'Nuestro tío José Antonio' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'Nuestro tío José Antonio, tío de Juan Tomás: su operación' );
} );

test( 'the date of the request can be corrected', async ( { page } ) => {
	const id = createIntention( group.id );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await page.getByLabel( 'When they asked' ).fill( '2026-09-14' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.notice-success' ) ).toBeVisible();
	await expect( page.getByLabel( 'When they asked' ) ).toHaveValue( '2026-09-14' );
	expect( php( `echo wp_json_encode( DFXPRAY_Intentions::get_instance()->get( ${ id } )['created_at'] );` ) ).toMatch( /^2026-09-14 / );
} );

test( 'the panel keeps the edition, topic and phone, and shows both messages', async ( { page } ) => {
	const id = createIntention( group.id, { beneficiary_name: 'Fátima', relationship: 'sobrina de', summary: 'quimioterapia' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await page.locator( '#dfxpray-scope' ).selectOption( 'monthly' );
	await page.getByLabel( 'Topic' ).selectOption( 'health' );
	await page.getByLabel( 'Phone of the requester' ).fill( '+34 611 22 33 44' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.notice-success' ) ).toBeVisible();
	await expect( page.getByRole( 'link', { name: 'Ask by WhatsApp how it goes' } ) ).toHaveAttribute( 'href', /^https:\/\/wa\.me\/34611223344\?text=/ );

	await page.goto( groupUrl( group.id, 'digest' ) );
	await expect( page.locator( '#dfxpray-digest-shareable' ) ).not.toHaveValue( /Fátima/ );
	await page.goto( groupUrl( group.id, 'monthly' ) );
	await expect( page.locator( '#dfxpray-monthly-shareable' ) ).toHaveValue( /Fátima, sobrina de Luis: quimioterapia$/m );
	await expect( page.locator( '#dfxpray-monthly-leaders' ) ).toHaveValue( /Fátima, sobrina de Luis: quimioterapia _\(asked by Luis Gómez · \+34611223344\)_/ );
} );

test( 'the panel form follows the five steps and shows only the fields that apply', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions' ) );
	await page.getByRole( 'link', { name: 'Add intention' } ).click();

	await expect( page.locator( '.dfxpray-step > legend' ) ).toHaveText( [ /Who asks/, /Who we pray for/, /What is happening/, /How it appears in the message/, /Follow-up/ ] );

	await page.getByLabel( 'Full name' ).fill( 'Titón' );
	await expect( page.getByLabel( 'How the requester is called' ) ).toBeHidden();
	await page.getByLabel( 'Full name' ).fill( 'Titón Alonso' );
	await expect( page.getByLabel( 'How the requester is called' ) ).toHaveAttribute( 'placeholder', 'Titón' );

	await expect( page.locator( '#dfxpray-reference-date' ) ).toBeHidden();
	await page.getByLabel( 'Kind' ).selectOption( 'event' );
	await expect( page.locator( '#dfxpray-reference-date' ) ).toBeVisible();
	await expect( page.locator( 'label[for="dfxpray-reference-date"]' ) ).toHaveText( 'Date' );
	await expect( page.locator( '#dfxpray-reference-time' ) ).toBeVisible();
	await expect( page.getByLabel( 'Remember it every year' ) ).toBeHidden();
	await page.getByLabel( 'Kind' ).selectOption( 'deceased' );
	await expect( page.getByLabel( 'Date of death' ) ).toBeVisible();
	await expect( page.getByLabel( 'Remember it every year' ) ).toBeVisible();
	await expect( page.locator( '#dfxpray-reference-time' ) ).toBeHidden();

	await page.locator( '#dfxpray-for-themselves' ).check();
	await expect( page.locator( '#dfxpray-beneficiary-name' ) ).toBeHidden();
	await page.locator( '#dfxpray-for-themselves' ).uncheck();
	await expect( page.locator( '#dfxpray-beneficiary-name' ) ).toBeVisible();

	await expect( page.getByLabel( 'Discretion', { exact: true } ) ).toBeHidden();
	await page.getByText( 'Discretion and names' ).click();
	await expect( page.getByLabel( 'Discretion', { exact: true } ) ).toBeVisible();
} );

test( 'a leader adds an intention by hand, with a key date', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions' ) );
	await page.getByRole( 'link', { name: 'Add intention' } ).click();

	await page.getByLabel( 'Full name' ).fill( 'Javier Pérez' );
	await page.locator( '#dfxpray-beneficiary-name' ).fill( 'Antonio Pérez' );
	await page.getByLabel( 'What they are to the requester' ).fill( 'padre de' );
	await page.getByLabel( 'Kind' ).selectOption( 'deceased' );
	await page.getByLabel( 'Date of death' ).fill( '2026-09-20' );
	await page.locator( 'input[name="key_dates[date][]"]' ).first().fill( '2026-09-22' );
	await page.locator( 'input[name="key_dates[time][]"]' ).first().fill( '17:00' );
	await page.locator( 'input[name="key_dates[label][]"]' ).first().fill( 'Funeral' );
	await page.getByRole( 'button', { name: 'Add another date' } ).click();
	await expect( page.locator( 'input[name="key_dates[date][]"]' ) ).toHaveCount( 2 );
	await page.getByLabel( 'What they told us' ).fill( 'Falleció el domingo.' );
	await page.getByLabel( 'Summary', { exact: true } ).fill( 'por su eterno descanso' );
	await page.getByRole( 'button', { name: 'Add intention' } ).click();

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention saved.' );
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'Antonio, padre de Javier: por su eterno descanso († 20/09)' );
	await expect( page.locator( 'input[name="key_dates[label][]"]' ).first() ).toHaveValue( 'Funeral' );
} );

test( 'closing as answered adds a linked thanksgiving to the index', async ( { page } ) => {
	const id = createIntention( group.id, { summary: 'busca trabajo', beneficiary_name: 'Pablo', relationship: '', type: 'ongoing' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	const preview = page.locator( '.dfxpray-thanks-preview' );
	await page.getByLabel( 'Close as' ).selectOption( 'answered' );
	await expect( preview ).toBeHidden();
	await page.getByLabel( 'Answered: add a thanksgiving' ).check();
	await expect( preview ).toBeVisible();
	await expect( preview.locator( 'code' ) ).toHaveText( 'Pablo: busca trabajo' );
	await page.getByLabel( 'Summary of the thanksgiving' ).fill( 'por el trabajo encontrado' );
	await expect( preview.locator( 'code' ) ).toHaveText( 'Pablo: por el trabajo encontrado' );
	await page.getByLabel( 'Close as' ).selectOption( 'expired' );
	await expect( preview ).toBeHidden();
	await page.getByLabel( 'Close as' ).selectOption( 'answered' );
	await page.getByRole( 'button', { name: 'Close', exact: true } ).click();

	// The new thanksgiving opens, to be edited, linked to the intention.
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention closed. This is its thanksgiving: you can edit it here.' );
	await expect( page.getByLabel( 'Summary', { exact: true } ) ).toHaveValue( 'por el trabajo encontrado' );
	await expect( page.locator( '.dfxpray-linked' ) ).toContainText( 'Gives thanks for: Pablo: busca trabajo' );
	await page.getByLabel( 'Summary', { exact: true } ).fill( 'por el trabajo que ya tiene' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention saved.' );

	await page.locator( '.dfxpray-linked a' ).click();
	await expect( page.locator( '.dfxpray-linked' ) ).toContainText( 'Its thanksgiving: Pablo: por el trabajo que ya tiene' );

	await page.goto( groupUrl( group.id, 'digest' ) );
	const index = page.locator( '#dfxpray-digest-shareable' );
	await expect( index ).toHaveValue( /\*Thanksgiving\*\n• Pablo: por el trabajo que ya tiene/ );
	await expect( index ).not.toHaveValue( /busca trabajo/ );
} );

test( 'the day an intention reached the list can be moved back, to take it out of Especially', async ( { page } ) => {
	php( `DFXPRAY_Settings::get_instance()->save_group( ${ group.id }, array( 'index_layout' => 'proposals' ) ); echo 1;` );
	const id = createIntention( group.id, { beneficiary_name: 'Bea', relationship: '', summary: 'operada ayer', topic: 'health' } );
	const today = php( 'echo wp_json_encode( DFXPRAY_Clock::get_instance()->today() );' );
	const before = php( "echo wp_json_encode( DFXPRAY_Clock::get_instance()->local( DFXPRAY_Clock::get_instance()->today() )->modify( '-5 days' )->format( 'Y-m-d' ) );" );
	await login( page, 'leader1' );

	await page.goto( groupUrl( group.id, 'digest' ) );
	await expect( page.locator( '#dfxpray-digest-shareable' ) ).toHaveValue( /\*Especially\*\n• Bea: operada ayer/ );

	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id } ) );
	await expect( page.getByLabel( 'When it reached the list' ) ).toHaveValue( today );
	await page.getByLabel( 'When they asked' ).fill( before );
	await page.getByLabel( 'When it reached the list' ).fill( before );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention saved.' );
	await expect( page.getByLabel( 'When it reached the list' ) ).toHaveValue( before );

	await page.goto( groupUrl( group.id, 'digest' ) );
	const message = page.locator( '#dfxpray-digest-shareable' );
	await expect( message ).not.toHaveValue( /Especially/ );
	await expect( message ).toHaveValue( /\*For the health of\*\n• Bea/ );
} );

test( 'an intention closed by mistake can be reopened', async ( { page } ) => {
	const id = createIntention( group.id, { summary: 'quimioterapia' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await page.getByLabel( 'Close as' ).selectOption( 'expired' );
	await page.getByRole( 'button', { name: 'Close', exact: true } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention closed.' );
	await expect( page.locator( '.dfxpray-status' ) ).toHaveText( 'Closed' );

	await page.getByRole( 'button', { name: 'Reopen' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention reopened.' );
	await expect( page.locator( '.dfxpray-status' ) ).toHaveText( 'Active' );
	await expect( page.locator( 'table.dfxpray-table' ).last() ).toContainText( 'Reopened' );

	await page.goto( groupUrl( group.id, 'digest' ) );
	await expect( page.locator( '#dfxpray-digest-shareable' ) ).toHaveValue( /Carmen, madre de Luis: quimioterapia/ );
} );

test( 'the list filters by kind, status and name', async ( { page } ) => {
	createIntention( group.id, { beneficiary_name: 'Carmencita' } );
	createIntention( group.id, { beneficiary_name: 'Pablito', type: 'event', reference_date: '2030-01-01' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions' ) );

	await page.getByLabel( 'Kind' ).selectOption( 'event' );
	await page.getByRole( 'button', { name: 'Filter' } ).click();
	await expect( page.locator( '.dfxpray-table tbody' ) ).toContainText( 'Pablito' );
	await expect( page.locator( '.dfxpray-table tbody' ) ).not.toContainText( 'Carmencita' );

	await page.getByLabel( 'Kind' ).selectOption( '' );
	await page.getByPlaceholder( 'Search by name' ).fill( 'carmen' );
	await page.getByRole( 'button', { name: 'Filter' } ).click();
	await expect( page.locator( '.dfxpray-table tbody' ) ).toContainText( 'Carmencita' );
	await expect( page.locator( '.dfxpray-table tbody' ) ).not.toContainText( 'Pablito' );
} );
