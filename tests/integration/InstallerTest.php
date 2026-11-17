<?php
/**
 * Tests for activation: tables, role and capabilities.
 *
 * @package DFXPRAY
 */

/**
 * Installer behaviour.
 */
class InstallerTest extends DFXPRAY_Test_Case {

	/**
	 * All tables named in the specification exist.
	 */
	public function test_creates_all_tables(): void {
		global $wpdb;

		foreach ( array( 'groups', 'group_leaders', 'intentions', 'updates', 'comments', 'log' ) as $table ) {
			$name = $wpdb->prefix . 'dfxpray_' . $table;
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ), "Missing table {$name}" );
		}
	}

	/**
	 * The leader role exists with only the plugin capability.
	 */
	public function test_creates_leader_role_with_panel_and_profile_only(): void {
		$role = get_role( 'dfxpray_leader' );

		$this->assertNotNull( $role );
		$this->assertEqualsCanonicalizing(
			array(
				'dfxpray_manage' => true,
				'read'           => true,
			),
			$role->capabilities
		);
	}

	/**
	 * Sites created before leaders could reach their profile get "read" on upgrade.
	 */
	public function test_upgrade_gives_existing_leader_role_read(): void {
		get_role( 'dfxpray_leader' )->remove_cap( 'read' );
		update_option( 'dfxpray_db_version', '1' );

		DFXPRAY_Installer::get_instance()->maybe_upgrade();

		$this->assertTrue( get_role( 'dfxpray_leader' )->has_cap( 'read' ) );
	}

	/**
	 * Administrators can manage intentions too.
	 */
	public function test_grants_capability_to_administrators(): void {
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'dfxpray_manage' ) );
	}

	/**
	 * Running activation twice is harmless.
	 */
	public function test_activation_is_idempotent(): void {
		DFXPRAY_Installer::get_instance()->activate();
		DFXPRAY_Installer::get_instance()->activate();

		$this->assertSame( DFXPRAY_Installer::DB_VERSION, get_option( 'dfxpray_db_version' ) );
	}

	/**
	 * Activation schedules the recurring cron tick and the daily retention job.
	 */
	public function test_activation_schedules_cron_events(): void {
		wp_clear_scheduled_hook( 'dfxpray_tick' );
		wp_clear_scheduled_hook( 'dfxpray_daily' );

		DFXPRAY_Installer::get_instance()->activate();

		$this->assertNotFalse( wp_next_scheduled( 'dfxpray_tick' ) );
		$this->assertNotFalse( wp_next_scheduled( 'dfxpray_daily' ) );
	}

	/**
	 * Deactivation unschedules cron events but keeps the data.
	 */
	public function test_deactivation_unschedules_cron_events(): void {
		DFXPRAY_Installer::get_instance()->activate();
		DFXPRAY_Installer::get_instance()->deactivate();

		$this->assertFalse( wp_next_scheduled( 'dfxpray_tick' ) );
		$this->assertFalse( wp_next_scheduled( 'dfxpray_daily' ) );
		$this->assertNotNull( get_role( 'dfxpray_leader' ) );

		DFXPRAY_Installer::get_instance()->activate();
	}
}
