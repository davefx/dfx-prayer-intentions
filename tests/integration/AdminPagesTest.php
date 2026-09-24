<?php
/**
 * Tests for the panel screens.
 *
 * @package DFXPRAY
 */

/**
 * Admin pages behaviour.
 */
class AdminPagesTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * Group led by the leader.
	 *
	 * @var int
	 */
	private int $mine;

	/**
	 * Group the leader does not lead.
	 *
	 * @var int
	 */
	private int $other;

	/**
	 * Leader of $mine.
	 *
	 * @var int
	 */
	private int $leader;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->freeze_time( '2026-09-24 10:00' );
		$this->leader = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );
		$this->mine   = $this->make_group( 'Mine', array( $this->leader ) );
		$this->other  = $this->make_group( 'Other' );
		wp_set_current_user( $this->leader );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Render a screen.
	 *
	 * @param string $page  Page slug.
	 * @param array  $query Query arguments.
	 */
	private function render( string $page, array $query = array() ): string {
		$_GET = array_merge( array( 'page' => $page ), $query );
		ob_start();
		DFXPRAY_Admin::get_instance()->render( $page );
		return (string) ob_get_clean();
	}

	/**
	 * Menu entries and who sees them.
	 */
	public function test_menu(): void {
		global $submenu, $menu;
		$menu    = array();
		$submenu = array();

		DFXPRAY_Admin::get_instance()->register_menu();

		$slugs = array_column( $submenu['dfxpray'], 2 );
		foreach ( array( 'dfxpray', 'dfxpray-moderation', 'dfxpray-intentions', 'dfxpray-reviews', 'dfxpray-index', 'dfxpray-digest', 'dfxpray-settings', 'dfxpray-log' ) as $slug ) {
			$this->assertContains( $slug, $slugs );
		}
		$this->assertNotContains( 'dfxpray-groups', $slugs );
		$this->assertNotContains( 'dfxpray-global', $slugs );

		$menu    = array();
		$submenu = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		DFXPRAY_Admin::get_instance()->register_menu();
		$slugs = array_column( $submenu['dfxpray'], 2 );
		$this->assertContains( 'dfxpray-groups', $slugs );
		$this->assertContains( 'dfxpray-global', $slugs );
	}

	/**
	 * Home shows counters for each of the user's groups only.
	 */
	public function test_home_counters(): void {
		$this->make_pending( $this->mine );
		$this->make_pending( $this->other );

		$html = $this->render( 'dfxpray' );

		$this->assertStringContainsString( 'Mine', $html );
		$this->assertStringNotContainsString( 'Other', $html );
		$this->assertMatchesRegularExpression( '/data-count="pending"[^>]*>\s*1\s*</', $html );
	}

	/**
	 * Moderation lists the active group's pending intentions only.
	 */
	public function test_moderation_lists_own_pending(): void {
		$this->make_pending( $this->mine, array( 'body' => 'My pending text' ) );
		$this->make_pending( $this->other, array( 'body' => 'Foreign pending text' ) );

		$html = $this->render( 'dfxpray-moderation' );

		$this->assertStringContainsString( 'My pending text', $html );
		$this->assertStringNotContainsString( 'Foreign pending text', $html );
		$this->assertStringContainsString( 'name="summary"', $html );
		$this->assertStringContainsString( 'maxlength="100"', $html );
	}

	/**
	 * The detail screen refuses other groups' intentions.
	 */
	public function test_detail_of_foreign_intention_refused(): void {
		$foreign = $this->make_active( $this->other, array( 'body' => 'Foreign secret' ) );

		$html = $this->render( 'dfxpray-intentions', array( 'view' => 'edit', 'id' => $foreign ) );

		$this->assertStringNotContainsString( 'Foreign secret', $html );
		$this->assertStringContainsString( 'not allowed', $html );
	}

	/**
	 * The detail screen shows everything a leader needs.
	 */
	public function test_detail_screen(): void {
		$id = $this->make_active( $this->mine, array( 'body' => 'Full text', 'requester_contact' => '600 000 000' ) );
		DFXPRAY_Updates::get_instance()->add( $id, 'An update', true );
		DFXPRAY_Comments::get_instance()->add( $id, 'A comment', 'call' );

		$html = $this->render( 'dfxpray-intentions', array( 'view' => 'edit', 'id' => $id ) );

		foreach ( array( 'Full text', '600 000 000', 'An update', 'A comment', 'value="add_comment"', 'value="add_update"', 'value="set_priority"', 'value="postpone"', 'value="ack_sent"', 'value="close"' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}
		$this->assertStringNotContainsString( 'value="transfer"', $html, 'Transfer needs a second group.' );
	}

	/**
	 * Transfer appears when the leader has another group.
	 */
	public function test_transfer_offered_with_two_groups(): void {
		$this->make_group( 'Second', array( $this->leader ) );
		$id = $this->make_active( $this->mine );

		$this->assertStringContainsString( 'value="transfer"', $this->render( 'dfxpray-intentions', array( 'view' => 'edit', 'id' => $id ) ) );
	}

	/**
	 * The list filters by the query.
	 */
	public function test_list_filters(): void {
		$this->make_active( $this->mine, array( 'beneficiary_name' => 'Carmencita' ) );
		$this->make_active( $this->mine, array( 'beneficiary_name' => 'Pablito' ) );

		$html = $this->render( 'dfxpray-intentions', array( 's' => 'pabl' ) );

		$this->assertStringContainsString( 'Pablito', $html );
		$this->assertStringNotContainsString( 'Carmencita', $html );
	}

	/**
	 * Index screen: text ready to copy, and a warning when too long.
	 */
	public function test_index_screen(): void {
		$this->make_active( $this->mine, array( 'summary' => 'Visible summary' ) );

		$html = $this->render( 'dfxpray-index' );

		$this->assertStringContainsString( 'Visible summary', $html );
		$this->assertStringContainsString( 'data-dfxpray-copy', $html );
		$this->assertStringNotContainsString( '4,000', $html );

		for ( $i = 0; $i < 60; $i++ ) {
			$this->make_active( $this->mine, array( 'summary' => str_repeat( 'x', 90 ) ) );
		}
		$this->assertStringContainsString( '4,000', $this->render( 'dfxpray-index' ) );
	}

	/**
	 * Digest screen shows both variants of the last digest and recent alerts.
	 */
	public function test_digest_screen(): void {
		DFXPRAY_Scheduler::get_instance()->maybe_send_digest( $this->mine, true );

		$html = $this->render( 'dfxpray-digest' );

		$this->assertStringContainsString( 'Prayer digest', $html );
		$this->assertSame( 2, substr_count( $html, 'data-dfxpray-copy' ) );
	}

	/**
	 * Settings screen, with the public URL.
	 */
	public function test_settings_screen(): void {
		$group = DFXPRAY_Groups::get_instance()->get( $this->mine );

		$html = $this->render( 'dfxpray-settings' );

		$this->assertStringContainsString( $group['slug'], $html );
		$this->assertStringContainsString( 'name="settings[digest_time]"', $html );
		$this->assertStringContainsString( 'name="settings[channels][telegram][token]"', $html );
		$this->assertStringNotContainsString( 'name="settings[retention_days]"', $html );
	}

	/**
	 * Admin screens refuse leaders.
	 */
	public function test_admin_screens_refuse_leaders(): void {
		$this->assertStringContainsString( 'not allowed', $this->render( 'dfxpray-groups' ) );
		$this->assertStringContainsString( 'not allowed', $this->render( 'dfxpray-global' ) );
	}

	/**
	 * Admin screens for administrators.
	 */
	public function test_admin_screens(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$groups = $this->render( 'dfxpray-groups' );
		$this->assertStringContainsString( 'Mine', $groups );
		$this->assertStringContainsString( 'Other', $groups );
		$this->assertStringContainsString( 'name="leaders[]"', $groups );

		$global = $this->render( 'dfxpray-global' );
		$this->assertStringContainsString( 'name="settings[retention_days]"', $global );
		$this->assertStringContainsString( 'name="panel_roles[]" value="editor"', $global );
	}

	/**
	 * With a single group, no selector; with several, a selector.
	 */
	public function test_group_selector(): void {
		$this->assertStringNotContainsString( 'dfxpray-group-switcher', $this->render( 'dfxpray-moderation' ) );

		$this->make_group( 'Second', array( $this->leader ) );
		$this->assertStringContainsString( 'dfxpray-group-switcher', $this->render( 'dfxpray-moderation' ) );
	}

	/**
	 * The group query argument switches the active group, only to own groups.
	 */
	public function test_group_switch_by_query(): void {
		$second = $this->make_group( 'Second', array( $this->leader ) );
		$admin  = DFXPRAY_Admin::get_instance();

		$_GET = array( 'group' => (string) $second );
		$admin->handle_group_switch();
		$this->assertSame( $second, DFXPRAY_Permissions::get_instance()->get_active_group() );

		$_GET = array( 'group' => (string) $this->other );
		$admin->handle_group_switch();
		$this->assertSame( $second, DFXPRAY_Permissions::get_instance()->get_active_group() );
	}

	/**
	 * Leaders land on the panel after logging in.
	 */
	public function test_login_redirect(): void {
		$user = get_userdata( $this->leader );

		$this->assertSame( admin_url( 'admin.php?page=dfxpray' ), apply_filters( 'login_redirect', admin_url(), '', $user ) );

		$subscriber = get_userdata( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( admin_url(), apply_filters( 'login_redirect', admin_url(), '', $subscriber ) );
	}

	/**
	 * Panel-only users go from the dashboard to the panel and do not see it in the menu.
	 */
	public function test_dashboard_leads_leaders_to_the_panel(): void {
		global $menu;
		$admin = DFXPRAY_Admin::get_instance();

		$this->assertSame( admin_url( 'admin.php?page=dfxpray' ), $admin->dashboard_redirect() );

		$menu = array( 2 => array( 'Dashboard', 'read', 'index.php' ) );
		$admin->hide_dashboard();
		$this->assertSame( array(), $menu );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_role( 'editor' )->add_cap( 'dfxpray_manage' );
		wp_set_current_user( $editor );
		$this->assertNull( $admin->dashboard_redirect() );
		get_role( 'editor' )->remove_cap( 'dfxpray_manage' );

		$this->assertNotFalse( has_action( 'load-index.php', array( $admin, 'maybe_redirect_dashboard' ) ) );
	}

	/**
	 * Leaders can open their own profile, to change their password.
	 */
	public function test_leader_can_edit_own_profile(): void {
		$this->assertTrue( current_user_can( 'read' ) );
		$this->assertFalse( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( 'list_users' ) );
	}

	/**
	 * Every screen carries the DFX footer, as in the other DFX plugins.
	 *
	 * @return array
	 */
	public function provide_pages(): array {
		$pages = array( 'dfxpray', 'dfxpray-moderation', 'dfxpray-intentions', 'dfxpray-reviews', 'dfxpray-index', 'dfxpray-digest', 'dfxpray-settings', 'dfxpray-log', 'dfxpray-groups', 'dfxpray-global' );

		return array_combine( $pages, array_map( static fn( $p ) => array( $p ), $pages ) );
	}

	/**
	 * The footer, on every screen, even when access is refused.
	 *
	 * @dataProvider provide_pages
	 *
	 * @param string $page Page slug.
	 */
	public function test_footer_on_every_screen( string $page ): void {
		$html = $this->render( $page );

		$this->assertStringContainsString( 'class="dfxpray-plugin-footer"', $html );
		$this->assertStringContainsString( 'Prayer intentions management features provided via <strong>DFX Prayer Intentions</strong> plugin by <a href="https://davefx.com/en/wordpress-plugins/">David Marín Carreño</a>. A.M.D.G.', $html );
	}

	/**
	 * The role name is translatable.
	 */
	public function test_role_name(): void {
		$this->assertSame( 'Prayer leader', wp_roles()->role_names['dfxpray_leader'] );
	}
}
