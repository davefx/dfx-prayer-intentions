/**
 * Shared helpers for the E2E specs: WP-CLI fixtures, login, captured mail
 * and a guard that fails any test during which the plugin logged a PHP error.
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );
const base = require( '@playwright/test' );

const SITE = path.resolve( __dirname, '..', '.wp', 'e2e-site' );
const DEBUG_LOG = path.join( SITE, 'wp-content', 'debug.log' );

/**
 * Run WP-CLI against the E2E site.
 *
 * @param {string[]} args Arguments.
 * @return {string} Output.
 */
function wp( args ) {
	return execFileSync( 'wp', [ `--path=${ SITE }`, ...args ], { encoding: 'utf8' } ).trim();
}

/**
 * Evaluate PHP in the site and decode its JSON output.
 *
 * @param {string} php Code that echoes JSON.
 * @return {any} Decoded value.
 */
function php( php ) {
	const output = wp( [ 'eval', php ] );
	return output ? JSON.parse( output ) : null;
}

/**
 * Empty every plugin table, captured mail and HTTP, and unfreeze the clock.
 */
function resetData() {
	wp( [
		'eval',
		`global $wpdb;
		foreach ( DFXPRAY_Installer::get_instance()->get_table_names() as $t ) { $wpdb->query( "TRUNCATE TABLE {$t}" ); }
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dfxpray\\\\_%' AND option_name NOT IN ('dfxpray_db_version')" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\\\_transient%dfxpray%'" );
		$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'dfxpray_active_group'" );
		wp_cache_flush();`,
	] );
}

/**
 * Create a group with leaders (user logins).
 *
 * @param {string}   name    Group name.
 * @param {string[]} leaders Leader logins.
 * @return {{id:number, slug:string}} Group.
 */
function createGroup( name, leaders = [] ) {
	return php( `
		$id = DFXPRAY_Groups::get_instance()->create( array( 'name' => ${ JSON.stringify( name ) } ) );
		$ids = array_map( static fn( $l ) => get_user_by( 'login', $l )->ID, ${ phpArray( leaders ) } );
		DFXPRAY_Groups::get_instance()->set_leaders( $id, $ids );
		echo wp_json_encode( DFXPRAY_Groups::get_instance()->get( $id ) );
	` );
}

/**
 * Create an intention directly.
 *
 * @param {number} groupId Group.
 * @param {Object} data    Fields.
 * @param {string} source  'form' or 'manual'.
 * @return {number} Intention ID.
 */
function createIntention( groupId, data, source = 'manual' ) {
	const defaults = {
		requester_name: 'Luis Gómez',
		beneficiary_name: 'Carmen Ruiz',
		relationship: 'madre de',
		type: 'ongoing',
		body: 'Please pray for my mother.',
		summary: 'Por su salud',
		discretion: 'first_name',
	};
	return php( `echo wp_json_encode( DFXPRAY_Intentions::get_instance()->create( ${ groupId }, json_decode( ${ JSON.stringify( JSON.stringify( { ...defaults, ...data } ) ) }, true ), '${ source }' ) );` );
}

/**
 * Freeze the plugin clock (site timezone), or unfreeze with null.
 *
 * @param {string|null} local "Y-m-d H:i".
 */
function freeze( local ) {
	if ( local ) {
		wp( [ 'option', 'update', 'dfxpray_frozen_now', local ] );
	} else {
		wp( [ 'option', 'delete', 'dfxpray_frozen_now' ] );
	}
}

/**
 * Run the five-minute cron tick now.
 */
function tick() {
	wp( [ 'eval', 'DFXPRAY_Scheduler::get_instance()->tick();' ] );
}

/**
 * Emails captured by the harness.
 *
 * @return {Array<{to:string[], subject:string, message:string}>} Mails.
 */
function mails() {
	return php( "echo wp_json_encode( get_option( 'dfxpray_e2e_mails', array() ) );" ) || [];
}

/**
 * PHP array literal from a list of strings.
 *
 * @param {string[]} list Strings.
 * @return {string} PHP code.
 */
function phpArray( list ) {
	return `array( ${ list.map( ( s ) => JSON.stringify( s ) ).join( ', ' ) } )`;
}

/**
 * Log in through wp-login.php.
 *
 * @param {import('@playwright/test').Page} page     Page.
 * @param {string}                          user     Login.
 * @param {string}                          password Password.
 */
async function login( page, user, password = user ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', password );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
}

/**
 * Test fixture that fails when the plugin logs a PHP error or notice.
 */
const test = base.test.extend( {
	phpErrors: [
		async ( {}, use ) => {
			const before = fs.existsSync( DEBUG_LOG ) ? fs.statSync( DEBUG_LOG ).size : 0;
			await use();
			const content = fs.existsSync( DEBUG_LOG ) ? fs.readFileSync( DEBUG_LOG, 'utf8' ).slice( before ) : '';
			const ours = content.split( '\n' ).filter( ( line ) => /PHP (Fatal|Warning|Notice|Deprecated|Parse)/.test( line ) && /dfx-prayer-intentions/.test( line ) );
			base.expect( ours, 'PHP errors logged by the plugin' ).toEqual( [] );
		},
		{ auto: true },
	],
} );

module.exports = { test, expect: base.expect, wp, php, resetData, createGroup, createIntention, freeze, tick, mails, login };
