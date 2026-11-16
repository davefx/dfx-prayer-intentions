/**
 * The brothers of a group, kept in the panel, and their birthdays in the
 * daily message.
 */
const { groupUrl, test, expect, resetData, createGroup, login, php } = require( '../helpers' );

let group;

test.beforeEach( () => {
	resetData();
	group = createGroup( 'Santa Marta', [ 'leader1' ] );
} );

test( 'leaders keep the brothers, and those whose birthday it is open the daily message', async ( { page } ) => {
	php( `DFXPRAY_Settings::get_instance()->save_group( ${ group.id }, array( 'index_layout' => 'proposals', 'member_prefix' => 'N.H.' ) ); echo 1;` );
	const today = php( `echo wp_json_encode( DFXPRAY_Clock::get_instance()->today() );` );
	const [ , month, day ] = today.split( '-' ).map( Number );

	await login( page, 'leader1' );
	await page.goto( groupUrl( group.id, 'members' ) );
	await expect( page.getByText( 'No members yet.' ) ).toBeVisible();

	const add = page.locator( 'form.dfxpray-add-member' );
	await add.getByLabel( 'Full name' ).fill( 'Ramón García Sanz' );
	await add.getByLabel( 'How he is called' ).fill( 'Ramón García' );
	await add.getByLabel( 'Phone (WhatsApp)' ).fill( '600 11 22 33' );
	await add.getByLabel( 'Day of birth' ).fill( String( day ) );
	await add.getByLabel( 'Month of birth' ).selectOption( String( month ) );
	await add.getByLabel( 'Notes' ).fill( 'XI Retiro - Mesa 1' );
	await add.getByRole( 'button', { name: 'Add member' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Member saved.' );
	await expect( page.locator( 'table.dfxpray-members' ) ).toContainText( 'Ramón García Sanz' );
	await expect( page.locator( 'table.dfxpray-members' ) ).toContainText( '600112233' );
	await expect( page.locator( '.dfxpray-member-notes' ) ).toHaveText( 'XI Retiro - Mesa 1' );

	await page.goto( groupUrl( group.id, 'index' ) );
	await expect( page.locator( '#dfxpray-index-text' ) ).toHaveValue( /\*Especially\*\n• N\.H\. Ramón García: on his birthday/ );

	await page.goto( groupUrl( group.id, 'members' ) );
	await page.getByText( 'Edit', { exact: true } ).click();
	await page.locator( 'details.dfxpray-edit-member' ).getByLabel( 'How he is called' ).fill( 'Ramón' );
	await page.locator( 'details.dfxpray-edit-member' ).getByRole( 'button', { name: 'Save' } ).click();
	await expect( page.locator( 'table.dfxpray-members' ) ).toContainText( 'Ramón' );

	const deathDay = `${ Number( today.slice( 0, 4 ) ) - 2 }${ today.slice( 4 ) }`;
	await page.getByText( 'Edit', { exact: true } ).click();
	await page.locator( 'details.dfxpray-edit-member' ).getByLabel( 'Date of death' ).fill( deathDay );
	await page.locator( 'details.dfxpray-edit-member' ).getByRole( 'button', { name: 'Save' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Member saved.' );
	await page.goto( groupUrl( group.id, 'index' ) );
	await expect( page.locator( '#dfxpray-index-text' ) ).toHaveValue( new RegExp( `• N\\.H\\. Ramón: on the anniversary of his death \\(† ${ deathDay.slice( 0, 4 ) }\\)` ) );
	await expect( page.locator( '#dfxpray-index-text' ) ).not.toHaveValue( /on his birthday/ );

	await page.goto( groupUrl( group.id, 'members' ) );
	await page.getByText( 'Edit', { exact: true } ).click();
	await page.locator( 'details.dfxpray-edit-member' ).getByRole( 'button', { name: 'Delete' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Member deleted.' );
	await expect( page.getByText( 'No members yet.' ) ).toBeVisible();
} );
