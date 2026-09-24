<?php
/**
 * Tests for uninstall.
 *
 * @package DFXPRAY
 */

/**
 * Uninstall behaviour. Runs last-ish and restores the schema afterwards.
 */
class UninstallTest extends DFXPRAY_Test_Case {

	/**
	 * Let DROP TABLE reach the real tables: the test suite otherwise rewrites
	 * it to DROP TEMPORARY TABLE.
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
	}

	/**
	 * Restore what uninstall removed.
	 */
	public function tear_down(): void {
		DFXPRAY_Installer::get_instance()->activate();
		parent::tear_down();
	}

	/**
	 * Uninstall removes tables, options, role and capabilities by default.
	 */
	public function test_uninstall_removes_everything(): void {
		global $wpdb;

		update_option( 'dfxpray_settings', array( 'retention_days' => 30 ) );
		update_option( 'dfxpray_digest_7', array( 'x' ) );

		DFXPRAY_Installer::get_instance()->uninstall();

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'dfxpray_intentions' ) ) );
		$this->assertFalse( get_option( 'dfxpray_settings' ) );
		$this->assertFalse( get_option( 'dfxpray_digest_7' ) );
		$this->assertNull( get_role( 'dfxpray_leader' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'dfxpray_manage' ) );
	}

	/**
	 * The administrator can choose to keep the data.
	 */
	public function test_uninstall_keeps_data_when_asked(): void {
		global $wpdb;

		DFXPRAY_Settings::get_instance()->save_global( array( 'delete_data_on_uninstall' => false ) );

		DFXPRAY_Installer::get_instance()->uninstall();

		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'dfxpray_intentions' ) ) );
		delete_option( 'dfxpray_settings' );
	}
}
