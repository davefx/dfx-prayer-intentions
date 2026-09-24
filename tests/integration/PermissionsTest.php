<?php
/**
 * Tests for per-group permissions.
 *
 * @package DFXPRAY
 */

/**
 * Permissions behaviour.
 */
class PermissionsTest extends DFXPRAY_Test_Case {

	/**
	 * Group A.
	 *
	 * @var int
	 */
	private int $a;

	/**
	 * Group B.
	 *
	 * @var int
	 */
	private int $b;

	/**
	 * Leader of A only.
	 *
	 * @var int
	 */
	private int $leader;

	/**
	 * Create two groups and a leader of the first one.
	 */
	public function set_up(): void {
		parent::set_up();

		$groups       = DFXPRAY_Groups::get_instance();
		$this->a      = $groups->create( array( 'name' => 'A' ) );
		$this->b      = $groups->create( array( 'name' => 'B' ) );
		$this->leader = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );
		$groups->set_leaders( $this->a, array( $this->leader ) );
	}

	/**
	 * A leader only manages their own groups.
	 */
	public function test_leader_manages_only_assigned_groups(): void {
		$permissions = DFXPRAY_Permissions::get_instance();

		$this->assertTrue( $permissions->can_manage_group( $this->a, $this->leader ) );
		$this->assertFalse( $permissions->can_manage_group( $this->b, $this->leader ) );
		$this->assertSame( array( $this->a ), $permissions->group_ids_for_user( $this->leader ) );
	}

	/**
	 * Administrators manage every group.
	 */
	public function test_admin_manages_all_groups(): void {
		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$permissions = DFXPRAY_Permissions::get_instance();

		$this->assertTrue( $permissions->is_admin( $admin ) );
		$this->assertTrue( $permissions->can_manage_group( $this->b, $admin ) );
		$this->assertEqualsCanonicalizing( array( $this->a, $this->b ), $permissions->group_ids_for_user( $admin ) );
	}

	/**
	 * Without the capability, group assignment is not enough.
	 */
	public function test_assignment_without_capability_grants_nothing(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		DFXPRAY_Groups::get_instance()->set_leaders( $this->a, array( $subscriber ) );

		$this->assertFalse( DFXPRAY_Permissions::get_instance()->can_manage_group( $this->a, $subscriber ) );
		$this->assertSame( array(), DFXPRAY_Permissions::get_instance()->group_ids_for_user( $subscriber ) );
	}

	/**
	 * The capability can be granted to any other role.
	 */
	public function test_capability_on_another_role_works(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_role( 'editor' )->add_cap( 'dfxpray_manage' );
		DFXPRAY_Groups::get_instance()->set_leaders( $this->b, array( $editor ) );

		$this->assertTrue( DFXPRAY_Permissions::get_instance()->can_manage_group( $this->b, $editor ) );

		get_role( 'editor' )->remove_cap( 'dfxpray_manage' );
	}

	/**
	 * Archived groups disappear for leaders but not for administrators.
	 */
	public function test_archived_groups_hidden_from_leaders(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		DFXPRAY_Groups::get_instance()->archive( $this->a );

		$this->assertFalse( DFXPRAY_Permissions::get_instance()->can_manage_group( $this->a, $this->leader ) );
		$this->assertTrue( DFXPRAY_Permissions::get_instance()->can_manage_group( $this->a, $admin ) );
	}

	/**
	 * The active group is remembered per user and must be one of theirs.
	 */
	public function test_active_group(): void {
		$groups = DFXPRAY_Groups::get_instance();
		$c      = $groups->create( array( 'name' => 'C' ) );
		$groups->set_leaders( $c, array( $this->leader ) );
		$permissions = DFXPRAY_Permissions::get_instance();

		$this->assertSame( $this->a, $permissions->get_active_group( $this->leader ) );

		$this->assertTrue( $permissions->set_active_group( $c, $this->leader ) );
		$this->assertSame( $c, $permissions->get_active_group( $this->leader ) );

		$this->assertFalse( $permissions->set_active_group( $this->b, $this->leader ) );
		$this->assertSame( $c, $permissions->get_active_group( $this->leader ) );
	}

	/**
	 * A user with no groups has no active group.
	 */
	public function test_no_groups_means_no_active_group(): void {
		$lonely = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );

		$this->assertSame( 0, DFXPRAY_Permissions::get_instance()->get_active_group( $lonely ) );
	}

	/**
	 * The administrator grants the panel capability to other roles, and takes it back.
	 */
	public function test_panel_roles(): void {
		$permissions = DFXPRAY_Permissions::get_instance();

		$this->assertSame( array(), $permissions->panel_roles() );

		$permissions->set_panel_roles( array( 'editor', 'author', 'administrator', 'nonexistent' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'dfxpray_manage' ) );
		$this->assertTrue( get_role( 'author' )->has_cap( 'dfxpray_manage' ) );
		$this->assertEqualsCanonicalizing( array( 'editor', 'author' ), $permissions->panel_roles() );

		$permissions->set_panel_roles( array( 'author' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'dfxpray_manage' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'dfxpray_manage' ), 'Administrators always keep it.' );
		$this->assertTrue( get_role( 'dfxpray_leader' )->has_cap( 'dfxpray_manage' ), 'Leaders always keep it.' );

		$permissions->set_panel_roles( array() );
	}

	/**
	 * Roles that may be offered in the settings screen.
	 */
	public function test_configurable_roles_exclude_fixed_ones(): void {
		$roles = array_keys( DFXPRAY_Permissions::get_instance()->configurable_roles() );

		$this->assertContains( 'editor', $roles );
		$this->assertNotContains( 'administrator', $roles );
		$this->assertNotContains( 'dfxpray_leader', $roles );
	}
}
