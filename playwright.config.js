// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

const port = process.env.DFXPRAY_E2E_PORT || '8890';

module.exports = defineConfig( {
	testDir: './tests/e2e/specs',
	// One WordPress, one database: tests share state, so they run one at a time.
	workers: 1,
	fullyParallel: false,
	retries: 0,
	timeout: 60_000,
	reporter: [ [ 'list' ] ],
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	use: {
		baseURL: `http://127.0.0.1:${ port }`,
		locale: 'en-US',
		timezoneId: 'Europe/Madrid',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				permissions: [ 'clipboard-read', 'clipboard-write' ],
			},
		},
	],
	webServer: {
		command: `php -S 127.0.0.1:${ port } -t tests/.wp/e2e-site tests/e2e/router.php`,
		url: `http://127.0.0.1:${ port }/wp-login.php`,
		reuseExistingServer: true,
		env: { PHP_CLI_SERVER_WORKERS: '4' },
		stdout: 'ignore',
		stderr: 'ignore',
	},
	outputDir: 'tests/.wp/e2e-results',
} );
