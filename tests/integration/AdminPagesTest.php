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
	 * Render a tab of a group's page (the leader's own group by default).
	 *
	 * @param string   $tab   Tab.
	 * @param array    $query Query arguments.
	 * @param int|null $group Group ID.
	 */
	private function tab( string $tab, array $query = array(), ?int $group = null ): string {
		return $this->render( 'dfxpray-group-' . ( $group ?? $this->mine ), array_merge( array( 'tab' => $tab ), $query ) );
	}

	/**
	 * Menu entries and who sees them.
	 */
	public function test_menu(): void {
		global $submenu, $menu;
		$second  = $this->make_group( 'Second', array( $this->leader ) );
		$menu    = array();
		$submenu = array();

		DFXPRAY_Admin::get_instance()->register_menu();

		$this->assertSame(
			array( 'dfxpray', 'dfxpray-group-' . $this->mine, 'dfxpray-group-' . $second ),
			array_column( $submenu['dfxpray'], 2 )
		);
		$this->assertSame( array( 'Groups', 'Mine', 'Second' ), array_map( 'wp_strip_all_tags', array_column( $submenu['dfxpray'], 0 ) ) );

		$menu    = array();
		$submenu = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		DFXPRAY_Admin::get_instance()->register_menu();
		$this->assertSame(
			array( 'dfxpray', 'dfxpray-global', 'dfxpray-group-' . $this->mine, 'dfxpray-group-' . $this->other, 'dfxpray-group-' . $second ),
			array_column( $submenu['dfxpray'], 2 )
		);
	}

	/**
	 * Each group entry shows its own pending count.
	 */
	public function test_menu_pending_bubble_per_group(): void {
		global $submenu, $menu;
		$this->make_pending( $this->mine );
		$this->make_pending( $this->mine );
		$menu    = array();
		$submenu = array();

		DFXPRAY_Admin::get_instance()->register_menu();

		$entry = array_values( array_filter( $submenu['dfxpray'], fn( $item ) => 'dfxpray-group-' . $this->mine === $item[2] ) )[0];
		$this->assertStringContainsString( 'awaiting-mod', $entry[0] );
		$this->assertStringContainsString( '>2<', $entry[0] );
	}

	/**
	 * A group's page: its name, and one tab per task, each linked to the group.
	 */
	public function test_group_page_tabs(): void {
		$html = $this->tab( 'moderation' );

		$this->assertStringContainsString( '<h1>Mine</h1>', $html );
		foreach ( array( 'moderation', 'intentions', 'reviews', 'digest', 'monthly', 'settings', 'log' ) as $tab ) {
			$this->assertStringContainsString( 'page=dfxpray-group-' . $this->mine . '&#038;tab=' . $tab, $html );
		}
		$this->assertMatchesRegularExpression( '/nav-tab nav-tab-active"[^>]*>Moderation/', $html );
		$this->assertStringNotContainsString( '&#038;tab=index', $html );
	}

	/**
	 * Without a tab, a group's page opens on moderation.
	 */
	public function test_group_page_defaults_to_moderation(): void {
		$this->make_pending( $this->mine, array( 'body' => 'Waiting text' ) );

		$this->assertStringContainsString( 'Waiting text', $this->render( 'dfxpray-group-' . $this->mine ) );
	}

	/**
	 * Another group's page is refused, whatever the tab.
	 */
	public function test_other_group_page_refused(): void {
		$this->make_pending( $this->other, array( 'body' => 'Foreign pending text' ) );

		foreach ( array( 'moderation', 'intentions', 'settings' ) as $tab ) {
			$html = $this->tab( $tab, array(), $this->other );
			$this->assertStringContainsString( 'not allowed', $html );
			$this->assertStringNotContainsString( 'Foreign pending text', $html );
			$this->assertStringNotContainsString( 'nav-tab', $html );
		}
	}

	/**
	 * Unknown tabs are refused.
	 */
	public function test_unknown_tab(): void {
		$this->assertStringContainsString( 'not allowed', $this->tab( 'secrets' ) );
	}

	/**
	 * The groups page shows counters for each of the user's groups only.
	 */
	public function test_groups_page_counters(): void {
		$this->make_pending( $this->mine );
		$this->make_pending( $this->other );

		$html = $this->render( 'dfxpray' );

		$this->assertStringContainsString( 'Mine', $html );
		$this->assertStringNotContainsString( 'Other', $html );
		$this->assertMatchesRegularExpression( '/data-count="pending"[^>]*>\s*1\s*</', $html );
		$this->assertStringContainsString( 'page=dfxpray-group-' . $this->mine . '&#038;tab=moderation', $html );
		$this->assertStringNotContainsString( 'name="leaders[]"', $html, 'Group management is for administrators.' );
	}

	/**
	 * Moderation lists the active group's pending intentions only.
	 */
	public function test_moderation_lists_own_pending(): void {
		$this->make_pending( $this->mine, array( 'body' => 'My pending text' ) );
		$this->make_pending( $this->other, array( 'body' => 'Foreign pending text' ) );

		$html = $this->tab( 'moderation' );

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

		$html = $this->tab(
			'intentions',
			array(
				'view' => 'edit',
				'id'   => $foreign,
			)
		);

		$this->assertStringNotContainsString( 'Foreign secret', $html );
		$this->assertStringContainsString( 'not allowed', $html );
	}

	/**
	 * The detail screen shows everything a leader needs.
	 */
	public function test_detail_screen(): void {
		$id = $this->make_active(
			$this->mine,
			array(
				'body'              => 'Full text',
				'requester_contact' => '600 000 000',
			)
		);
		DFXPRAY_Updates::get_instance()->add( $id, 'An update', true );
		DFXPRAY_Comments::get_instance()->add( $id, 'A comment', 'call' );

		$html = $this->tab(
			'intentions',
			array(
				'view' => 'edit',
				'id'   => $id,
			)
		);

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

		$this->assertStringContainsString(
			'value="transfer"',
			$this->tab(
				'intentions',
				array(
					'view' => 'edit',
					'id'   => $id,
				)
			)
		);
	}

	/**
	 * The list filters by the query.
	 */
	public function test_list_filters(): void {
		$this->make_active( $this->mine, array( 'beneficiary_name' => 'Carmencita' ) );
		$this->make_active( $this->mine, array( 'beneficiary_name' => 'Pablito' ) );

		$html = $this->tab( 'intentions', array( 's' => 'pabl' ) );

		$this->assertStringContainsString( 'Pablito', $html );
		$this->assertStringNotContainsString( 'Carmencita', $html );
	}

	/**
	 * The intentions list and the reviews link each intention to its card in
	 * the group's page, never to a page that no longer exists.
	 */
	public function test_lists_link_to_the_card_in_the_group(): void {
		$listed = $this->make_active( $this->mine, array( 'summary' => 'Listed' ) );
		$review = $this->make_active(
			$this->mine,
			array(
				'type'           => 'event',
				'reference_date' => '2026-01-01',
				'summary'        => 'Past',
			)
		);
		DFXPRAY_Intentions::get_instance()->process_due();

		$card = fn( int $id ) => esc_url(
			DFXPRAY_Admin::get_instance()->group_url(
				$this->mine,
				'intentions',
				array(
					'view' => 'edit',
					'id'   => $id,
				)
			)
		);

		$this->assertStringContainsString( $card( $listed ), $this->tab( 'intentions' ) );
		$this->assertStringContainsString( $card( $review ), $this->tab( 'reviews' ) );
		$this->assertStringNotContainsString( 'page=dfxpray-intentions', $this->tab( 'intentions' ) . $this->tab( 'reviews' ) );
	}

	/**
	 * Daily digest screen: the app's message to share and the leaders'
	 * version, the link to the app, and a warning when too long.
	 */
	public function test_digest_screen(): void {
		$this->make_active( $this->mine, array( 'summary' => 'Visible summary' ) );

		$html = $this->tab( 'digest' );

		$this->assertStringContainsString( 'Visible summary', $html );
		$this->assertStringContainsString( 'asked by Luis Gómez', $html );
		$this->assertStringContainsString( 'id="dfxpray-digest-shareable"', $html );
		$this->assertStringContainsString( 'id="dfxpray-digest-leaders"', $html );
		$this->assertStringContainsString( DFXPRAY_App::get_instance()->url(), $html );
		$this->assertStringNotContainsString( '4,000', $html );

		for ( $i = 0; $i < 60; $i++ ) {
			$this->make_active( $this->mine, array( 'summary' => str_repeat( 'x', 90 ) ) );
		}
		$this->assertStringContainsString( '4,000', $this->tab( 'digest' ) );
	}

	/**
	 * Once sent, the screen shows the digest that went out.
	 */
	public function test_digest_screen_after_sending(): void {
		$this->make_active( $this->mine, array( 'summary' => 'Sent summary' ) );
		DFXPRAY_Scheduler::get_instance()->maybe_send_digest( $this->mine, true );

		$html = $this->tab( 'digest' );

		$this->assertStringContainsString( 'Sent summary', $html );
		$this->assertSame( 2, substr_count( $html, 'data-dfxpray-copy' ) );
	}

	/**
	 * Monthly digest screen: the monthly list to share and the leaders' one.
	 */
	public function test_monthly_screen(): void {
		$this->make_active(
			$this->mine,
			array(
				'summary' => 'Long situation',
				'scope'   => 'monthly',
			)
		);

		$html = $this->tab( 'monthly' );

		$this->assertStringContainsString( 'id="dfxpray-monthly-shareable"', $html );
		$this->assertStringContainsString( 'id="dfxpray-monthly-leaders"', $html );
		$this->assertStringContainsString( 'Long situation', $html );
		$this->assertStringContainsString( 'asked by Luis Gómez', $html );
	}

	/**
	 * Settings screen, with the public URL.
	 */
	public function test_settings_screen(): void {
		$group = DFXPRAY_Groups::get_instance()->get( $this->mine );

		$html = $this->tab( 'settings' );

		$this->assertStringContainsString( $group['slug'], $html );
		$this->assertStringContainsString( 'name="settings[digest_time]"', $html );
		$this->assertStringContainsString( 'name="settings[channels][telegram][token]"', $html );
		$this->assertStringNotContainsString( 'name="settings[retention_days]"', $html );
	}

	/**
	 * Admin screens refuse leaders.
	 */
	public function test_admin_screens_refuse_leaders(): void {
		$this->assertStringContainsString( 'not allowed', $this->render( 'dfxpray-global' ) );
	}

	/**
	 * Admin screens for administrators.
	 */
	public function test_admin_screens(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$groups = $this->render( 'dfxpray' );
		$this->assertStringContainsString( 'Mine', $groups );
		$this->assertStringContainsString( 'Other', $groups );
		$this->assertStringContainsString( 'name="leaders[]"', $groups );

		$global = $this->render( 'dfxpray-global' );
		$this->assertStringContainsString( 'name="settings[retention_days]"', $global );
		$this->assertStringContainsString( 'name="panel_roles[]" value="editor"', $global );
	}

	/**
	 * Leaders land on the panel after logging in.
	 */
	public function test_login_redirect(): void {
		$user = get_userdata( $this->leader );

		$this->assertSame( admin_url( 'admin.php?page=dfxpray-group-' . $this->mine ), apply_filters( 'login_redirect', admin_url(), '', $user ), 'A single group: straight to it.' );

		$this->make_group( 'Second', array( $this->leader ) );
		$this->assertSame( admin_url( 'admin.php?page=dfxpray' ), apply_filters( 'login_redirect', admin_url(), '', $user ), 'Several groups: the groups page.' );

		$subscriber = get_userdata( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( admin_url(), apply_filters( 'login_redirect', admin_url(), '', $subscriber ) );
	}

	/**
	 * Leaders who came from the app go back to the app after logging in.
	 */
	public function test_login_redirect_back_to_the_app(): void {
		$user = get_userdata( $this->leader );
		$app  = DFXPRAY_App::get_instance()->url();

		$this->assertSame( $app, apply_filters( 'login_redirect', $app, $app, $user ) );
		$this->assertSame( admin_url( 'admin.php?page=dfxpray-group-' . $this->mine ), apply_filters( 'login_redirect', 'https://example.org/', 'https://example.org/', $user ) );
	}

	/**
	 * Panel-only users go from the dashboard to the panel and do not see it in the menu.
	 */
	public function test_dashboard_leads_leaders_to_the_panel(): void {
		global $menu;
		$admin = DFXPRAY_Admin::get_instance();

		$this->assertSame( admin_url( 'admin.php?page=dfxpray-group-' . $this->mine ), $admin->dashboard_redirect() );

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
		$pages = array(
			'groups' => array( 'dfxpray', '' ),
			'global' => array( 'dfxpray-global', '' ),
		);
		foreach ( array( 'moderation', 'intentions', 'reviews', 'digest', 'monthly', 'settings', 'log' ) as $tab ) {
			$pages[ $tab ] = array( 'group', $tab );
		}

		return $pages;
	}

	/**
	 * The footer, on every screen, even when access is refused.
	 *
	 * @dataProvider provide_pages
	 *
	 * @param string $page Page slug, or "group" for the leader's group.
	 * @param string $tab  Tab of the group page.
	 */
	public function test_footer_on_every_screen( string $page, string $tab ): void {
		$html = 'group' === $page ? $this->tab( $tab ) : $this->render( $page );

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
