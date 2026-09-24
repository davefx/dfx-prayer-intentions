<?php
/**
 * Shared base class for every PHPUnit test.
 *
 * @package DFXPRAY
 */

/**
 * Base test case.
 *
 * Singletons keep per-request state (the frozen clock, memoised settings).
 * That is correct in a real request and poison across tests in one process,
 * so every test starts from a clean slate.
 */
abstract class DFXPRAY_Test_Case extends WP_UnitTestCase {

	/**
	 * Reset plugin state before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_plugin_state();
	}

	/**
	 * Reset plugin state after each test.
	 */
	public function tear_down(): void {
		$this->reset_plugin_state();
		parent::tear_down();
	}

	/**
	 * Clear memoised state kept by the plugin singletons.
	 */
	protected function reset_plugin_state(): void {
		DFXPRAY_Clock::get_instance()->reset();

		if ( class_exists( 'DFXPRAY_Settings' ) ) {
			DFXPRAY_Settings::get_instance()->flush_cache();
		}
	}

	/**
	 * Freeze the plugin clock at a given local (site timezone) date and time.
	 *
	 * @param string $local Date and time in the site timezone, e.g. "2026-09-24 10:00".
	 */
	protected function freeze_time( string $local ): void {
		DFXPRAY_Clock::get_instance()->freeze( new DateTimeImmutable( $local, wp_timezone() ) );
	}
}
