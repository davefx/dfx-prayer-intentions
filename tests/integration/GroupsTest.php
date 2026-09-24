<?php
/**
 * Tests for groups and leader assignment.
 *
 * @package DFXPRAY
 */

/**
 * Groups behaviour.
 */
class GroupsTest extends DFXPRAY_Test_Case {

	/**
	 * A new group gets an obfuscated slug with a random segment.
	 */
	public function test_create_generates_obfuscated_slug(): void {
		$groups = DFXPRAY_Groups::get_instance();

		$id    = $groups->create( array( 'name' => 'Grupo Santa Marta', 'description' => 'Internal' ) );
		$group = $groups->get( $id );

		$this->assertSame( 'Grupo Santa Marta', $group['name'] );
		$this->assertSame( 'Internal', $group['description'] );
		$this->assertSame( 'active', $group['status'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+-[a-z0-9]{8}$/', $group['slug'] );
	}

	/**
	 * Two groups never share a slug.
	 */
	public function test_slugs_are_unique(): void {
		$groups = DFXPRAY_Groups::get_instance();

		$a = $groups->get( $groups->create( array( 'name' => 'A' ) ) );
		$b = $groups->get( $groups->create( array( 'name' => 'B' ) ) );

		$this->assertNotSame( $a['slug'], $b['slug'] );
	}

	/**
	 * A name is mandatory.
	 */
	public function test_create_requires_a_name(): void {
		$this->assertInstanceOf( WP_Error::class, DFXPRAY_Groups::get_instance()->create( array( 'name' => '  ' ) ) );
	}

	/**
	 * Regenerating the slug changes the random segment and asks for a rewrite flush.
	 */
	public function test_regenerate_slug(): void {
		$groups = DFXPRAY_Groups::get_instance();
		$id     = $groups->create( array( 'name' => 'A' ) );
		$before = $groups->get( $id )['slug'];
		delete_option( 'dfxpray_flush_rewrite' );

		$after = $groups->regenerate_slug( $id );

		$this->assertNotSame( $before, $after );
		$this->assertSame( $after, $groups->get( $id )['slug'] );
		$this->assertSame( $id, $groups->get_by_slug( $after )['id'] );
		$this->assertNull( $groups->get_by_slug( $before ) );
		$this->assertEquals( 1, get_option( 'dfxpray_flush_rewrite' ) );
	}

	/**
	 * Updating changes name and description.
	 */
	public function test_update(): void {
		$groups = DFXPRAY_Groups::get_instance();
		$id     = $groups->create( array( 'name' => 'A' ) );

		$groups->update( $id, array( 'name' => 'B', 'description' => 'New' ) );

		$this->assertSame( 'B', $groups->get( $id )['name'] );
		$this->assertSame( 'New', $groups->get( $id )['description'] );
	}

	/**
	 * Archived groups are excluded from the active list.
	 */
	public function test_archive_and_restore(): void {
		$groups = DFXPRAY_Groups::get_instance();
		$a      = $groups->create( array( 'name' => 'A' ) );
		$b      = $groups->create( array( 'name' => 'B' ) );

		$groups->archive( $a );

		$this->assertSame( array( $b ), array_column( $groups->all( 'active' ), 'id' ) );
		$this->assertCount( 2, $groups->all() );

		$groups->restore( $a );
		$this->assertCount( 2, $groups->all( 'active' ) );
	}

	/**
	 * Leaders can be assigned and read back in both directions.
	 */
	public function test_leaders_assignment(): void {
		$groups = DFXPRAY_Groups::get_instance();
		$a      = $groups->create( array( 'name' => 'A' ) );
		$b      = $groups->create( array( 'name' => 'B' ) );
		$u1     = self::factory()->user->create();
		$u2     = self::factory()->user->create();

		$groups->set_leaders( $a, array( $u1, $u2 ) );
		$groups->set_leaders( $b, array( $u1 ) );

		$this->assertEqualsCanonicalizing( array( $u1, $u2 ), $groups->get_leaders( $a ) );
		$this->assertEqualsCanonicalizing( array( $a, $b ), $groups->get_group_ids_for_leader( $u1 ) );
		$this->assertSame( array( $a ), $groups->get_group_ids_for_leader( $u2 ) );

		$groups->set_leaders( $a, array( $u1 ) );
		$this->assertSame( array( $u1 ), $groups->get_leaders( $a ) );
	}

	/**
	 * Setting overrides are stored per group.
	 */
	public function test_settings_overrides_roundtrip(): void {
		$groups = DFXPRAY_Groups::get_instance();
		$id     = $groups->create( array( 'name' => 'A' ) );

		$groups->save_settings( $id, array( 'digest_time' => '08:30' ) );

		$this->assertSame( array( 'digest_time' => '08:30' ), $groups->get_settings( $id ) );
	}

	/**
	 * Deleting a group removes everything that belongs to it.
	 */
	public function test_delete_cascades(): void {
		global $wpdb;

		$groups = DFXPRAY_Groups::get_instance();
		$id     = $groups->create( array( 'name' => 'A' ) );
		$other  = $groups->create( array( 'name' => 'B' ) );
		$groups->set_leaders( $id, array( self::factory()->user->create() ) );

		$tables = DFXPRAY_Installer::get_instance()->get_table_names();
		foreach ( array( $id, $other ) as $group_id ) {
			$wpdb->insert(
				$tables['intentions'],
				array(
					'group_id'         => $group_id,
					'requester_name'   => 'R',
					'beneficiary_name' => 'B',
					'body'             => 'Text',
					'type'             => 'ongoing',
					'created_at'       => '2026-01-01 00:00:00',
					'updated_at'       => '2026-01-01 00:00:00',
				)
			);
			$intention = $wpdb->insert_id;
			$wpdb->insert( $tables['updates'], array( 'intention_id' => $intention, 'body' => 'u', 'created_at' => '2026-01-01' ) );
			$wpdb->insert( $tables['comments'], array( 'intention_id' => $intention, 'body' => 'c', 'created_at' => '2026-01-01' ) );
			DFXPRAY_Log::get_instance()->add( 'x', $group_id, $intention );
		}

		$groups->delete( $id );

		$this->assertNull( $groups->get( $id ) );
		$this->assertSame( array(), $groups->get_leaders( $id ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['intentions']}" ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['updates']}" ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['comments']}" ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['log']} WHERE group_id = %d", $id ) ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['log']} WHERE group_id = %d AND action = 'x'", $other ) ) );
	}
}
