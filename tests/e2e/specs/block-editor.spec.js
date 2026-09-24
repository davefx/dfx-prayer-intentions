/**
 * The form block in the block editor.
 */
const { test, expect, resetData, createGroup, login, wp } = require( '../helpers' );

test.beforeEach( () => resetData() );

/**
 * Open a new page in the editor, without the welcome guide.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function openEditor( page ) {
	// An existing, empty page: new pages open the starter-patterns modal.
	const id = wp( [ 'post', 'create', '--post_type=page', '--post_status=draft', '--post_title=Form', '--porcelain' ] );
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.blocks.getBlockType( 'dfxpray/form' ) );
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'welcomeGuide', false );
	} );
}

test( 'with one group, the block previews that group’s form', async ( { page } ) => {
	const group = createGroup( 'Santa Marta' );
	await login( page, 'admin' );
	await openEditor( page );

	await page.evaluate( () => {
		const { dispatch } = window.wp.data;
		dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'dfxpray/form' ) );
	} );

	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await expect( canvas.locator( 'input[name="dfxpray_group"]' ) ).toHaveValue( String( group.id ) );
} );

test( 'with several groups, the block asks which one and previews it', async ( { page } ) => {
	createGroup( 'Santa Marta' );
	const second = createGroup( 'San José' );
	await login( page, 'admin' );
	await openEditor( page );

	await page.evaluate( () => {
		const { dispatch } = window.wp.data;
		const block = window.wp.blocks.createBlock( 'dfxpray/form' );
		dispatch( 'core/block-editor' ).insertBlocks( block );
		dispatch( 'core/block-editor' ).selectBlock( block.clientId );
	} );

	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await expect( canvas.getByText( 'Choose the group that will receive the requests' ) ).toBeVisible();

	await page.evaluate( () => window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/block' ) );
	await page.getByLabel( 'Requests go to' ).selectOption( { label: 'San José' } );

	await expect( canvas.locator( 'input[name="dfxpray_group"]' ) ).toHaveValue( String( second.id ) );
} );
