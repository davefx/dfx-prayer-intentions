/**
 * The intention screen: comments, updates, quick actions and manual creation.
 */
const { groupUrl, test, expect, resetData, createGroup, createIntention, login, php } = require( '../helpers' );

let group;

test.beforeEach( () => {
	resetData();
	group = createGroup( 'Santa Marta', [ 'leader1', 'leader2' ] );
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
	await expect( page.getByLabel( 'Next review' ) ).toHaveValue( '2031-01-15' );
} );

test( 'visible names cannot reveal more than the discretion level allows', async ( { page } ) => {
	const id = createIntention( group.id, { beneficiary_name: 'David Marín', summary: 'su operación' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await page.getByLabel( 'Visible name of the beneficiary' ).fill( 'Nuestro hermano David Marín' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.notice-error' ) ).toContainText( 'cannot include surnames' );

	await page.getByLabel( 'Visible name of the beneficiary' ).fill( 'Nuestro hermano David' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'Nuestro hermano David, madre de Luis: su operación' );
	await expect( page.locator( 'table.dfxpray-table' ).last() ).toContainText( 'Visible name changed: “” → “Nuestro hermano David”' );

	await expect( page.getByLabel( 'Discretion', { exact: true } ).locator( 'option' ) ).toHaveText( [ 'First name', 'Initials', 'Anonymous' ] );
} );

test( 'a leader sets compound first names by hand', async ( { page } ) => {
	const id = createIntention( group.id, { requester_name: 'Juan Tomás Rubio', beneficiary_name: 'José Antonio Ruiz', relationship: 'tío de', summary: 'su operación' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'José, tío de Juan: su operación' );

	await page.getByLabel( 'Name to show for the beneficiary' ).fill( 'Antonio' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.notice-error' ) ).toContainText( 'first words of the full name' );

	await page.getByLabel( 'Name to show for the beneficiary' ).fill( 'José Antonio' );
	await page.getByLabel( 'Name to show for the requester' ).fill( 'Juan Tomás' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'José Antonio, tío de Juan Tomás: su operación' );

	await page.getByLabel( 'Visible name of the beneficiary' ).fill( 'Nuestro tío José Antonio' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'Nuestro tío José Antonio, tío de Juan Tomás: su operación' );
} );

test( 'a leader adds an intention by hand, with a key date', async ( { page } ) => {
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions' ) );
	await page.getByRole( 'link', { name: 'Add intention' } ).click();

	await page.getByRole( 'textbox', { name: 'Requester', exact: true } ).fill( 'Javier Pérez' );
	await page.locator( '#dfxpray-beneficiary-name' ).fill( 'Antonio Pérez' );
	await page.getByLabel( 'Relationship', { exact: true } ).fill( 'padre de' );
	await page.getByLabel( 'Kind' ).selectOption( 'deceased' );
	await page.getByLabel( 'Reference date' ).fill( '2026-09-20' );
	await page.locator( 'input[name="key_dates[date][]"]' ).first().fill( '2026-09-22' );
	await page.locator( 'input[name="key_dates[time][]"]' ).first().fill( '17:00' );
	await page.locator( 'input[name="key_dates[label][]"]' ).first().fill( 'Funeral' );
	await page.getByRole( 'button', { name: 'Add another date' } ).click();
	await expect( page.locator( 'input[name="key_dates[date][]"]' ) ).toHaveCount( 2 );
	await page.getByLabel( 'Full text' ).fill( 'Falleció el domingo.' );
	await page.getByLabel( 'Summary for the index' ).fill( 'por su eterno descanso' );
	await page.getByRole( 'button', { name: 'Add intention' } ).click();

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention saved.' );
	await expect( page.locator( '.dfxpray-preview code' ) ).toHaveText( 'Antonio, padre de Javier: por su eterno descanso († 20/09)' );
	await expect( page.locator( 'input[name="key_dates[label][]"]' ).first() ).toHaveValue( 'Funeral' );
} );

test( 'closing as answered adds a linked thanksgiving to the index', async ( { page } ) => {
	const id = createIntention( group.id, { summary: 'busca trabajo', beneficiary_name: 'Pablo', relationship: '', type: 'ongoing' } );
	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'intentions', { view: 'edit', id: id } ) );

	await page.getByLabel( 'Close as' ).selectOption( 'answered' );
	await page.getByLabel( 'Answered: add a thanksgiving' ).check();
	await page.getByLabel( 'Summary of the thanksgiving' ).fill( 'por el trabajo encontrado' );
	await page.getByRole( 'button', { name: 'Close', exact: true } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Intention closed.' );

	await page.goto( groupUrl( group.id, 'index' ) );
	const index = page.locator( '#dfxpray-index-text' );
	await expect( index ).toHaveValue( /\*Thanksgiving\*\n• Pablo: por el trabajo encontrado/ );
	await expect( index ).not.toHaveValue( /busca trabajo/ );
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

	await page.goto( groupUrl( group.id, 'index' ) );
	await expect( page.locator( '#dfxpray-index-text' ) ).toHaveValue( /Carmen, madre de Luis: quimioterapia/ );
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
