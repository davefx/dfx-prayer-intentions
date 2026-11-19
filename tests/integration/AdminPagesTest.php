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

	/**
	 * A phone's User-Agent, for wp_is_mobile().
	 */
	private const PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

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
		unset( $_SERVER['HTTP_USER_AGENT'] );
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
			array( 'dfxpray', 'dfxpray-group-' . $this->mine, 'dfxpray-group-' . $second, 'dfxpray-manual' ),
			array_column( $submenu['dfxpray'], 2 )
		);
		$this->assertSame( array( 'Groups', 'Mine', 'Second', 'Manual' ), array_map( 'wp_strip_all_tags', array_column( $submenu['dfxpray'], 0 ) ) );

		$menu    = array();
		$submenu = array();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		DFXPRAY_Groups::get_instance()->set_leaders( $second, array( $this->leader, $admin ) );
		wp_set_current_user( $admin );
		DFXPRAY_Admin::get_instance()->register_menu();
		$this->assertSame(
			array( 'dfxpray', 'dfxpray-global', 'dfxpray-group-' . $second, 'dfxpray-manual' ),
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
	 * Even after today's digest went out, the screen shows the message as it
	 * is now, the same one as the app, and says when the digest was sent.
	 */
	public function test_digest_screen_after_sending(): void {
		$this->make_active( $this->mine, array( 'summary' => 'Sent summary' ) );
		DFXPRAY_Scheduler::get_instance()->maybe_send_digest( $this->mine, true );
		update_option( 'dfxpray_digest_' . $this->mine, array_merge( get_option( 'dfxpray_digest_' . $this->mine ), array( 'shareable' => 'Old format' ) ), false );
		$this->make_active( $this->mine, array( 'summary' => 'Added later' ) );

		$html = $this->tab( 'digest' );

		$this->assertStringContainsString( 'Generated and sent on', $html );
		$this->assertStringContainsString( esc_textarea( DFXPRAY_Index_Composer::get_instance()->compose( $this->mine ) ), $html );
		$this->assertStringContainsString( 'Added later', $html );
		$this->assertStringNotContainsString( 'Old format', $html );
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
	 * The group settings start with its leaders, the pending invitations
	 * with a button to withdraw them, and the form to invite someone.
	 */
	public function test_settings_screen_leaders(): void {
		$service = self::factory()->user->create(
			array(
				'role'         => 'dfxpray_leader',
				'display_name' => 'Rutina',
				'user_email'   => 'bot@example.org',
			)
		);
		DFXPRAY_Service_Accounts::get_instance()->set( $service, true );
		DFXPRAY_Groups::get_instance()->set_leaders( $this->mine, array( $this->leader, $service ) );
		self::factory()->user->create(
			array(
				'role'         => 'dfxpray_leader',
				'display_name' => 'Outsider',
				'user_email'   => 'outsider@example.org',
			)
		);
		$id = DFXPRAY_Invitations::get_instance()->invite( $this->mine, 'Pedro Gil', 'pedro@example.org' );
		DFXPRAY_Invitations::get_instance()->invite( $this->other, 'Juan', 'juan@example.org' );
		$leader = get_userdata( $this->leader );

		$html = $this->tab( 'settings' );

		$this->assertLessThan( strpos( $html, 'Public form' ), strpos( $html, 'dfxpray-leaders' ) );
		$this->assertStringContainsString( esc_html( $leader->display_name ), $html );
		$this->assertStringContainsString( 'Rutina', $html );
		$this->assertStringNotContainsString( 'bot@example.org', $html );
		$this->assertStringNotContainsString( esc_html( $leader->user_email ), $html );
		$this->assertStringNotContainsString( 'Outsider', $html );
		$this->assertStringNotContainsString( 'outsider@example.org', $html );
		$this->assertStringContainsString( ' · service account', $html );
		$this->assertStringContainsString( 'Pedro Gil', $html );
		$this->assertStringContainsString( 'pedro@example.org', $html );
		$this->assertStringContainsString( 'expires on', $html );
		$this->assertStringContainsString( 'name="dfxpray_action" value="withdraw_invitation"', $html );
		$this->assertStringContainsString( 'name="invitation_id" value="' . $id . '"', $html );
		$this->assertStringContainsString( 'name="dfxpray_action" value="invite_leader"', $html );
		$this->assertStringContainsString( 'Send invitation', $html );
		$this->assertStringContainsString( 'Only the administrator of the site can remove leaders.', $html );
		$this->assertStringNotContainsString( 'juan@example.org', $html );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		DFXPRAY_Groups::get_instance()->set_leaders( $this->mine, array( $this->leader, $service, $admin ) );
		wp_set_current_user( $admin );
		$this->assertStringContainsString( 'bot@example.org', $this->tab( 'settings' ) );
	}

	/**
	 * An administrator who leads one group gets a menu entry, a card and the
	 * tabs of that group only; the other is only in Manage groups.
	 */
	public function test_admin_sees_only_the_groups_they_lead(): void {
		global $submenu, $menu;
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		DFXPRAY_Groups::get_instance()->set_leaders( $this->mine, array( $this->leader, $admin ) );
		$this->make_pending( $this->other );
		wp_set_current_user( $admin );
		$menu    = array();
		$submenu = array();

		DFXPRAY_Admin::get_instance()->register_menu();

		$this->assertSame( array( 'dfxpray', 'dfxpray-global', 'dfxpray-group-' . $this->mine, 'dfxpray-manual' ), array_column( $submenu['dfxpray'], 2 ) );
		$this->assertStringNotContainsString( 'awaiting-mod', $menu[ array_key_last( $menu ) ][0] );

		foreach ( array( 'moderation', 'intentions', 'reviews', 'digest', 'monthly', 'members', 'settings', 'log' ) as $tab ) {
			$this->assertStringContainsString( 'not allowed', $this->tab( $tab, array(), $this->other ), $tab );
			$this->assertStringNotContainsString( 'not allowed', $this->tab( $tab ), $tab );
		}

		$groups = $this->render( 'dfxpray' );
		$this->assertSame( 1, substr_count( $groups, 'dfxpray-group-card' ) );
		$this->assertStringContainsString( 'Manage groups', $groups );
		$this->assertStringContainsString( 'value="' . $this->other . '"', $groups );
		$this->assertStringContainsString( 'Other', $groups );
	}

	/**
	 * An administrator who leads no group is told so, above Manage groups.
	 */
	public function test_admin_leading_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$groups = $this->render( 'dfxpray' );

		$this->assertStringContainsString( 'You are not assigned to any group yet.', $groups );
		$this->assertStringNotContainsString( 'dfxpray-group-card', $groups );
		$this->assertStringContainsString( 'Manage groups', $groups );
		$this->assertStringContainsString( 'Mine', $groups );
	}

	/**
	 * Each group's card in Manage groups lets administrators invite a leader
	 * and withdraw its pending invitations; the New group card does not, and
	 * leaders never see any of it.
	 */
	public function test_manage_groups_invitations(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$id = DFXPRAY_Invitations::get_instance()->invite( $this->other, 'Pedro Gil', 'pedro@example.org' );

		$html = $this->render( 'dfxpray' );

		$this->assertSame( 2, substr_count( $html, 'name="dfxpray_action" value="admin_invite_leader"' ) );
		$this->assertStringContainsString( 'name="dfxpray_action" value="admin_withdraw_invitation"', $html );
		$this->assertStringContainsString( 'name="invitation_id" value="' . $id . '"', $html );
		$this->assertStringContainsString( 'Pedro Gil (pedro@example.org) · expires on', $html );
		$new_card = substr( $html, strpos( $html, 'New group' ) );
		$this->assertStringNotContainsString( 'admin_invite_leader', $new_card );

		wp_set_current_user( $this->leader );
		$this->assertStringNotContainsString( 'admin_invite_leader', $this->render( 'dfxpray' ) );
		$this->assertStringNotContainsString( 'pedro@example.org', $this->render( 'dfxpray' ) );
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
	 * Each leader is reminded of their duty of confidentiality until they
	 * acknowledge it, once.
	 */
	public function test_confidentiality_notice_until_acknowledged(): void {
		$this->assertStringContainsString( 'duty of confidentiality', $this->tab( 'moderation' ) );

		$result = DFXPRAY_Admin_Actions::get_instance()->run( 'ack_confidentiality', array() );

		$this->assertTrue( $result['ok'] );
		$this->assertStringNotContainsString( 'duty of confidentiality', $this->tab( 'moderation' ) );
	}

	/**
	 * A group without a controller or an accepted processing agreement tells
	 * its leaders; the administrator records both in the groups page, where
	 * the agreement is ready to copy.
	 */
	public function test_processing_agreement_pending_and_recorded(): void {
		$this->assertStringContainsString( 'processing agreement', $this->tab( 'moderation' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$groups = $this->render( 'dfxpray' );
		$this->assertStringContainsString( 'name="privacy[privacy_controller]"', $groups );
		$this->assertStringContainsString( 'name="privacy[dpa_accepted_on]"', $groups );
		$this->assertStringContainsString( 'id="dfxpray-agreement-' . $this->mine . '"', $groups );

		$result = DFXPRAY_Admin_Actions::get_instance()->run(
			'save_group',
			array(
				'group_id' => $this->mine,
				'name'     => 'Mine',
				'leaders'  => array( $this->leader ),
				'privacy'  => array(
					'privacy_controller' => 'Parroquia San Manuel González',
					'dpa_accepted_on'    => '2026-11-17',
				),
			)
		);
		$this->assertTrue( $result['ok'], $result['message'] );

		wp_set_current_user( $this->leader );
		$this->assertStringNotContainsString( 'processing agreement', $this->tab( 'moderation' ) );
		$this->assertSame( 'Parroquia San Manuel González', DFXPRAY_Settings::get_instance()->get( 'privacy_controller', $this->mine ) );
	}

	/**
	 * The members tab reminds what may be written down.
	 */
	public function test_members_privacy_reminder(): void {
		$this->assertStringContainsString( 'Do not write health or other sensitive data in the notes.', $this->tab( 'members' ) );
	}

	/**
	 * The global settings hold the controller, its contact and the minimum
	 * age, and a lookup of a person's data by email or phone, with what was
	 * found ready to hand over and a button to erase it.
	 */
	public function test_privacy_lookup_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->make_active(
			$this->mine,
			array(
				'requester_contact' => '600 11 12 22',
				'body'              => 'Texto de Luis',
			)
		);
		DFXPRAY_Members::get_instance()->create(
			$this->mine,
			array(
				'name'  => 'Luis Gómez',
				'phone' => '+34600111222',
			)
		);

		$global = $this->render( 'dfxpray-global' );
		$this->assertStringContainsString( 'name="settings[provider_name]"', $global );
		$this->assertStringContainsString( 'name="settings[provider_tax_id]"', $global );
		$this->assertStringContainsString( 'name="settings[subprocessors]"', $global );
		$this->assertStringContainsString( 'name="settings[privacy_controller]"', $global );
		$this->assertStringContainsString( 'name="settings[consent_min_age]"', $global );
		$this->assertStringContainsString( 'name="privacy_lookup"', $global );

		$found = $this->render( 'dfxpray-global', array( 'privacy_lookup' => '+34 600 111 222' ) );
		$this->assertStringContainsString( 'Texto de Luis', $found );
		$this->assertStringContainsString( 'Luis Gómez', $found );
		$this->assertStringContainsString( 'id="dfxpray-privacy-export"', $found );
		$this->assertStringContainsString( 'value="privacy_erase"', $found );
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
			'manual' => array( 'dfxpray-manual', '' ),
			'app'    => array( 'dfxpray-app', '' ),
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
		$this->assertStringContainsString( '<a href="' . esc_url( DFXPRAY_Admin::get_instance()->url( 'dfxpray-manual' ) ) . '">Manual</a>', $html );
	}

	/**
	 * Every screen starts with the link to install the app, its Copy button
	 * and how to install it, and nothing to dismiss it.
	 *
	 * @dataProvider provide_pages
	 *
	 * @param string $page Page slug, or "group" for the leader's group.
	 * @param string $tab  Tab of the group page.
	 */
	public function test_install_box_on_every_screen( string $page, string $tab ): void {
		$_SERVER['HTTP_USER_AGENT'] = self::PHONE;
		$html                       = 'group' === $page ? $this->tab( $tab ) : $this->render( $page );
		$url  = DFXPRAY_App::get_instance()->url();

		$this->assertStringContainsString( 'class="dfxpray-install"', $html );
		$this->assertStringContainsString( 'Install the app on your phone', $html );
		$this->assertStringContainsString( '<a class="dfxpray-install-url" href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>', $html );
		$this->assertStringContainsString( 'data-dfxpray-copy-text="' . esc_attr( $url ) . '"', $html );
		$this->assertStringContainsString( 'Copy link', $html );
		$this->assertStringContainsString( 'Android (Chrome)', $html );
		$this->assertStringContainsString( 'iPhone (Safari)', $html );
		$this->assertStringNotContainsString( 'is-dismissible', substr( $html, strpos( $html, 'dfxpray-install' ), 1200 ) );
	}

	/**
	 * On a computer nothing about installing is shown, nor in the menu.
	 *
	 * @dataProvider provide_pages
	 *
	 * @param string $page Page slug, or "group" for the leader's group.
	 * @param string $tab  Tab of the group page.
	 */
	public function test_no_install_box_on_a_computer( string $page, string $tab ): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
		if ( 'dfxpray-app' === $page ) {
			$this->assertTrue( true, 'The page itself always shows how to install the app.' );
			return;
		}

		$html = 'group' === $page ? $this->tab( $tab ) : $this->render( $page );

		$this->assertStringNotContainsString( 'dfxpray-install', $html );
	}

	/**
	 * Administrators get the box too; users without panel access do not, and
	 * the menu has a page for it, open for everyone with access.
	 */
	public function test_install_box_and_page(): void {
		global $submenu, $menu;
		$_SERVER['HTTP_USER_AGENT'] = self::PHONE;
		$menu                       = array();
		$submenu = array();
		DFXPRAY_Admin::get_instance()->register_menu();
		$entries = array_column( $submenu['dfxpray'], 2 );
		$this->assertSame( array( 'dfxpray-app', 'dfxpray-manual' ), array_slice( $entries, -2 ) );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
		$menu                       = array();
		$submenu                    = array();
		DFXPRAY_Admin::get_instance()->register_menu();
		$this->assertNotContains( 'dfxpray-app', array_column( $submenu['dfxpray'], 2 ) );
		$_SERVER['HTTP_USER_AGENT'] = self::PHONE;

		$page = $this->render( 'dfxpray-app' );
		$this->assertStringContainsString( '<details class="dfxpray-install-how" open>', $page );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'class="dfxpray-install"', $this->render( 'dfxpray' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertStringNotContainsString( 'class="dfxpray-install"', $this->render( 'dfxpray-app' ) );
	}

	/**
	 * The manual opens for anyone with panel access, leaders and
	 * administrators, as the last entry of the menu; nobody else sees it.
	 */
	public function test_manual_page(): void {
		global $submenu, $menu;
		$menu    = array();
		$submenu = array();
		DFXPRAY_Admin::get_instance()->register_menu();
		$entries = $submenu['dfxpray'];
		$this->assertSame( 'dfxpray-manual', end( $entries )[2] );
		$this->assertSame( 'Manual', end( $entries )[0] );
		$this->assertSame( DFXPRAY_Installer::CAPABILITY, end( $entries )[1] );

		$html = $this->render( 'dfxpray-manual' );
		$this->assertStringContainsString( 'class="dfxpray-manual"', $html );
		$this->assertStringContainsString( 'dfxpray-manual-toc', $html );
		$this->assertStringContainsString( DFXPRAY_PLUGIN_URL . 'docs/manual/en/img/', $html );

		// A link of the manual can ask for the other language.
		$spanish = $this->render( 'dfxpray-manual', array( 'lang' => 'es' ) );
		$this->assertStringContainsString( DFXPRAY_PLUGIN_URL . 'docs/manual/es/img/', $spanish );
		$this->assertStringContainsString( 'Manual de DFX Prayer Intentions', $spanish );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'dfxpray-manual-toc', $this->render( 'dfxpray-manual' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$refused = $this->render( 'dfxpray-manual' );
		$this->assertStringContainsString( 'not allowed', $refused );
		$this->assertStringNotContainsString( 'dfxpray-manual-toc', $refused );
	}

	/**
	 * The role name is translatable.
	 */
	public function test_role_name(): void {
		$this->assertSame( 'Prayer leader', wp_roles()->role_names['dfxpray_leader'] );
	}
}
