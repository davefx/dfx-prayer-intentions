/**
 * The public request form, as a visitor uses it.
 */
const { test, expect, resetData, createGroup, mails, php, wp } = require( '../helpers' );

test.beforeEach( () => resetData() );

test( 'the group page lives at its obfuscated URL and keeps robots away', async ( { page } ) => {
	const group = createGroup( 'Santa Marta', [ 'leader1' ] );

	const response = await page.goto( `/${ group.slug }/` );

	expect( response.status() ).toBe( 200 );
	expect( group.slug ).toMatch( /^prayer-requests-[a-z0-9]{8}$/ );
	expect( response.headers()[ 'x-robots-tag' ] ).toBe( 'noindex, nofollow' );
	expect( response.headers()[ 'cache-control' ] ).toContain( 'no-store' );
	expect( response.headers()[ 'x-litespeed-cache-control' ] ).toBe( 'no-cache' );
	await expect( page.locator( 'meta[name="robots"]' ) ).toHaveAttribute( 'content', /noindex, nofollow/ );
	await expect( page.getByRole( 'heading', { name: 'Santa Marta' } ) ).toBeVisible();

	const robots = await page.request.get( '/robots.txt' );
	expect( await robots.text() ).not.toContain( group.slug );
	const sitemap = await page.request.get( '/wp-sitemap.xml' );
	expect( await sitemap.text() ).not.toContain( group.slug );
} );

test( 'unknown slugs are not found', async ( { page } ) => {
	createGroup( 'Santa Marta' );

	const response = await page.goto( '/prayer-requests-zzzzzzzz/' );

	expect( response.status() ).toBe( 404 );
} );

test( 'the date is only asked when needed, and "for me" hides the beneficiary', async ( { page } ) => {
	const group = createGroup( 'Santa Marta' );
	await page.goto( `/${ group.slug }/` );

	await expect( page.locator( '[data-dfxpray-date]' ) ).toBeHidden();

	await page.getByLabel( /a moment in particular/ ).check();
	await expect( page.getByLabel( 'When is it?' ) ).toBeVisible();
	await expect( page.getByLabel( 'Time (optional)' ) ).toBeVisible();

	await page.getByLabel( /a death/ ).check();
	await expect( page.getByLabel( 'When did they pass away?' ) ).toBeVisible();
	await expect( page.getByLabel( 'Time (optional)' ) ).toBeHidden();

	await page.getByLabel( /giving thanks/ ).check();
	await expect( page.locator( '[data-dfxpray-date]' ) ).toBeHidden();

	await expect( page.getByLabel( 'Their name' ) ).toBeVisible();
	await page.getByLabel( 'For me' ).check();
	await expect( page.getByLabel( 'Their name' ) ).toBeHidden();
} );

test( 'a visitor sends a request: it enters as pending and the leaders are told', async ( { page } ) => {
	const group = createGroup( 'Santa Marta', [ 'leader1' ] );
	await page.goto( `/${ group.slug }/` );

	await page.getByLabel( 'Your name' ).fill( 'Luis Gómez' );
	await page.getByLabel( /A way to contact you/ ).fill( 'luis@example.org' );
	await page.getByLabel( 'Their name' ).fill( 'Carmen Ruiz' );
	await page.getByLabel( 'Their relationship to you' ).fill( 'madre de' );
	await page.getByLabel( /a moment in particular/ ).check();
	await page.getByLabel( 'When is it?' ).fill( '2030-10-05' );
	await page.getByLabel( 'Time (optional)' ).fill( '09:30' );
	await page.getByLabel( 'Your intention' ).fill( 'Operación de cadera, que salga bien.' );
	await page.getByLabel( 'Initials', { exact: true } ).check();
	await page.getByLabel( /I agree/ ).check();
	await page.getByRole( 'button', { name: 'Send my request' } ).click();

	await expect( page.getByText( 'Thank you. We have received your request' ) ).toBeVisible();
	await expect( page.locator( 'form' ) ).toHaveCount( 0 );

	const stored = php( `echo wp_json_encode( DFXPRAY_Intentions::get_instance()->query( ${ group.id } ) );` );
	expect( stored ).toHaveLength( 1 );
	expect( stored[ 0 ] ).toMatchObject( {
		status: 'pending',
		source: 'form',
		type: 'event',
		reference_date: '2030-10-05',
		reference_time: '09:30',
		discretion: 'initials',
		beneficiary_name: 'Carmen Ruiz',
	} );

	const sent = mails();
	expect( sent ).toHaveLength( 1 );
	expect( sent[ 0 ].to ).toEqual( [ 'leader1@example.org' ] );
	expect( sent[ 0 ].subject ).toContain( 'Santa Marta' );
	expect( sent[ 0 ].message ).toContain( 'Operación de cadera' );
} );

test( 'without consent the request goes back to the form, keeping what was typed', async ( { page } ) => {
	const group = createGroup( 'Santa Marta' );
	await page.goto( `/${ group.slug }/` );

	await page.getByLabel( 'Your name' ).fill( 'Luis' );
	await page.getByLabel( 'For me' ).check();
	await page.getByLabel( /a situation that goes on/ ).check();
	await page.getByLabel( 'Your intention' ).fill( 'Trabajo' );
	await page.getByRole( 'button', { name: 'Send my request' } ).click();

	await expect( page.getByRole( 'alert' ) ).toContainText( 'Please accept the privacy policy' );
	await expect( page.getByLabel( 'Your name' ) ).toHaveValue( 'Luis' );
	await expect( page.getByLabel( 'Your intention' ) ).toHaveValue( 'Trabajo' );
	expect( php( `echo wp_json_encode( DFXPRAY_Intentions::get_instance()->query( ${ group.id } ) );` ) ).toHaveLength( 0 );
} );

test( 'bots that fill the honeypot are told it worked, and nothing is stored', async ( { page } ) => {
	const group = createGroup( 'Santa Marta', [ 'leader1' ] );
	await page.goto( `/${ group.slug }/` );

	await page.getByLabel( 'Your name' ).fill( 'Bot' );
	await page.getByLabel( 'For me' ).check();
	await page.getByLabel( /a situation that goes on/ ).check();
	await page.getByLabel( 'Your intention' ).fill( 'Buy pills' );
	await page.getByLabel( /I agree/ ).check();
	await page.locator( '#dfxpray-website' ).evaluate( ( input ) => ( input.value = 'http://spam.example' ) );
	await page.getByRole( 'button', { name: 'Send my request' } ).click();

	await expect( page.getByText( 'Thank you. We have received your request' ) ).toBeVisible();
	expect( php( `echo wp_json_encode( DFXPRAY_Intentions::get_instance()->query( ${ group.id } ) );` ) ).toHaveLength( 0 );
	expect( mails() ).toHaveLength( 0 );
} );

test( 'a page embedding the shortcode shows the form and is kept out of search engines', async ( { page } ) => {
	const group = createGroup( 'Santa Marta' );
	const pageId = wp( [ 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Pedir oración', `--post_content=[dfxpray_form group="${ group.id }"]`, '--porcelain' ] );
	const url = wp( [ 'post', 'url', pageId ] );

	const response = await page.goto( url );

	expect( response.headers()[ 'x-robots-tag' ] ).toBe( 'noindex, nofollow' );
	expect( response.headers()[ 'cache-control' ] ).toContain( 'no-store' );
	await expect( page.getByRole( 'button', { name: 'Send my request' } ) ).toBeVisible();
	await expect( page.locator( 'input[name="dfxpray_group"]' ) ).toHaveValue( String( group.id ) );
} );

test( 'a page embedding the block shows the form', async ( { page } ) => {
	const group = createGroup( 'Santa Marta' );
	const pageId = wp( [ 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Bloque', `--post_content=<!-- wp:dfxpray/form {"groupId":${ group.id }} /-->`, '--porcelain' ] );

	await page.goto( wp( [ 'post', 'url', pageId ] ) );

	await expect( page.locator( 'input[name="dfxpray_group"]' ) ).toHaveValue( String( group.id ) );
} );

test( 'archived groups stop answering', async ( { page } ) => {
	const group = createGroup( 'Santa Marta' );
	php( `DFXPRAY_Groups::get_instance()->archive( ${ group.id } ); echo 'null';` );

	const response = await page.goto( `/${ group.slug }/` );

	expect( response.status() ).toBe( 404 );
} );
