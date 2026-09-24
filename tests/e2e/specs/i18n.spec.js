/**
 * A site in Spanish, the plugin's base language.
 */
const { test, expect, resetData, createGroup, createIntention, login, freeze, wp } = require( '../helpers' );

test.beforeAll( () => wp( [ 'site', 'switch-language', 'es_ES' ] ) );
test.afterAll( () => wp( [ 'site', 'switch-language', 'en_US' ] ) );
test.beforeEach( () => resetData() );
test.afterEach( () => freeze( null ) );

test( 'the public form speaks Spanish and lives under /peticiones-…/', async ( { page } ) => {
	const group = createGroup( 'Santa Marta' );

	expect( group.slug ).toMatch( /^peticiones-[a-z0-9]{8}$/ );
	await page.goto( `/${ group.slug }/` );

	await expect( page.getByLabel( 'Tu nombre' ) ).toBeVisible();
	await page.getByLabel( /un momento concreto/ ).check();
	await expect( page.getByLabel( '¿Cuándo es?' ) ).toBeVisible();
	await expect( page.getByLabel( 'Nombre de pila' ) ).toBeChecked();
	await expect( page.getByRole( 'button', { name: 'Enviar mi petición' } ) ).toBeVisible();
} );

test( 'the index reads like the specification', async ( { page } ) => {
	freeze( '2026-09-24 10:00' );
	const group = createGroup( 'Santa Marta', [ 'leader1' ] );
	createIntention( group.id, { requester_name: 'Pablo Sanz', beneficiary_is_requester: true, type: 'event', reference_date: '2026-09-25', summary: 'entrevista de trabajo' } );
	createIntention( group.id, { type: 'event', reference_date: '2026-09-28', reference_time: '09:30', summary: 'operación' } );
	createIntention( group.id, { requester_name: 'Javier Pérez', beneficiary_name: 'Antonio Pérez', relationship: 'padre de', type: 'deceased', reference_date: '2026-09-20', summary: 'por su eterno descanso' } );

	await login( page, 'leader1' );
	await expect( page.getByRole( 'heading', { name: 'Inicio' } ) ).toBeVisible();
	await page.goto( '/wp-admin/admin.php?page=dfxpray-index' );

	await expect( page.locator( '#dfxpray-index-text' ) ).toHaveValue(
		[
			'🙏 *Intenciones activas* – 24/09',
			'',
			'*Eventos próximos*',
			'• Pablo: entrevista de trabajo _(mañana)_',
			'• Carmen, madre de Luis: operación _(lunes 28, 9:30)_',
			'',
			'*Por nuestros difuntos*',
			'• Antonio, padre de Javier: por su eterno descanso († 20/09)',
		].join( '\n' )
	);
	await expect( page.getByRole( 'button', { name: 'Copiar' } ) ).toBeVisible();
} );
