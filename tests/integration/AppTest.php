<?php
/**
 * Tests for the leaders' app page: address, access, shell, manifest and
 * service worker.
 *
 * @package DFXPRAY
 */

/**
 * The installable app.
 */
class AppTest extends DFXPRAY_Test_Case {

	/**
	 * App under test.
	 *
	 * @var DFXPRAY_App
	 */
	private DFXPRAY_App $app;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->app = DFXPRAY_App::get_instance();
	}

	/**
	 * The app lives under the site, next to the forms' base.
	 */
	public function test_url(): void {
		$this->assertSame( home_url( '/prayer-requests-app/' ), $this->app->url() );
	}

	/**
	 * Only leaders get in; the rest are sent to log in or refused.
	 */
	public function test_access(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 'login', $this->app->access() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'forbidden', $this->app->access() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) ) );
		$this->assertSame( 'ok', $this->app->access() );
	}

	/**
	 * The shell links the manifest, registers the worker and carries the
	 * app's nonce and texts; it is never indexed.
	 */
	public function test_shell(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) ) );

		$html = $this->app->shell();

		$this->assertStringContainsString( '<link rel="manifest" href="' . esc_url( $this->app->url() . 'manifest.webmanifest' ) . '">', $html );
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow">', $html );
		$this->assertStringContainsString( '"nonce":"' . wp_create_nonce( DFXPRAY_App_API::NONCE ) . '"', $html );
		$this->assertStringContainsString( '"ajaxUrl":"' . str_replace( '/', '\/', admin_url( 'admin-ajax.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'assets/js/app.js', $html );
		$this->assertStringContainsString( '"share":"Share"', $html );
	}

	/**
	 * The manifest makes it installable, scoped to the app's address.
	 */
	public function test_manifest(): void {
		$manifest = $this->app->manifest();

		$this->assertSame( $this->app->url(), $manifest['start_url'] );
		$this->assertSame( $this->app->url(), $manifest['scope'] );
		$this->assertSame( 'standalone', $manifest['display'] );
		$this->assertSame( array( '192x192', '512x512' ), array_column( $manifest['icons'], 'sizes' ) );
		foreach ( $manifest['icons'] as $icon ) {
			$this->assertFileExists( DFXPRAY_PLUGIN_DIR . 'assets/images/' . basename( (string) wp_parse_url( $icon['src'], PHP_URL_PATH ) ) );
		}
	}

	/**
	 * The worker caches only the app's own files, by version, and never the
	 * API's answers.
	 */
	public function test_service_worker(): void {
		$js = $this->app->service_worker();

		$this->assertStringContainsString( 'dfxpray-app-' . DFXPRAY_VERSION, $js );
		$this->assertStringContainsString( 'assets/js/app.js', $js );
		$this->assertStringNotContainsString( 'admin-ajax', $js );
	}

	/**
	 * The three addresses are routed.
	 */
	public function test_rewrite_rules(): void {
		$this->assertSame(
			array(
				'^prayer\\-requests\\-app/?$'       => 'index.php?dfxpray_app=shell',
				'^prayer\\-requests\\-app/manifest\\.webmanifest$' => 'index.php?dfxpray_app=manifest',
				'^prayer\\-requests\\-app/sw\\.js$' => 'index.php?dfxpray_app=worker',
			),
			$this->app->rules()
		);
	}
}
