<?php
/**
 * PHPUnit bootstrap.
 *
 * Every PHPUnit test runs against a real WordPress install and a real
 * database. The "unit" suite holds the pure logic (lifecycle dates, discretion,
 * text composition); "integration" holds everything that touches the database,
 * hooks, capabilities or HTTP. End-to-end tests live in tests/e2e and run with
 * Playwright against a served site.
 *
 * @package DFXPRAY
 */

$dfxpray_candidates = array_filter(
	array(
		getenv( 'WP_TESTS_DIR' ),
		__DIR__ . '/.wp/wordpress-tests-lib',
		rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib',
	)
);

$dfxpray_tests_dir = '';

foreach ( $dfxpray_candidates as $dfxpray_candidate ) {
	$dfxpray_candidate = rtrim( $dfxpray_candidate, '/\\' );

	if ( file_exists( $dfxpray_candidate . '/includes/functions.php' ) ) {
		$dfxpray_tests_dir = $dfxpray_candidate;
		break;
	}
}

if ( '' === $dfxpray_tests_dir ) {
	fwrite( STDERR, "Could not find the WordPress test library.\nRun tests/bin/start-db.sh and tests/bin/install.sh, or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $dfxpray_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/dfx-prayer-intentions.php';
	}
);

/*
 * The test library installs WordPress fresh on every run, so the plugin's
 * activation routine (tables, role, capabilities) has to be run by hand once
 * WordPress is up. The tables are created outside the per-test transaction
 * because dbDelta issues DDL, which would commit it implicitly.
 */
tests_add_filter(
	'init',
	static function () {
		DFXPRAY_Installer::get_instance()->activate();
	},
	0
);

require $dfxpray_tests_dir . '/includes/bootstrap.php';

require_once ABSPATH . WPINC . '/pomo/po.php';
require_once __DIR__ . '/includes/class-test-case.php';
require_once __DIR__ . '/includes/trait-intention-factory.php';
require_once __DIR__ . '/includes/trait-http-capture.php';
