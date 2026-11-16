<?php
/**
 * Tests for the panel actions controller: nonces and per-group permissions.
 *
 * @package DFXPRAY
 */

/**
 * Admin actions behaviour.
 */
class AdminActionsTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;
	use DFXPRAY_Http_Capture;

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
	 * Controller.
	 *
	 * @var DFXPRAY_Admin_Actions
	 */
	private DFXPRAY_Admin_Actions $actions;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		$this->freeze_time( '2026-09-24 10:00' );
		$this->leader  = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );
		$this->mine    = $this->make_group( 'Mine', array( $this->leader ) );
		$this->other   = $this->make_group( 'Other' );
		$this->actions = DFXPRAY_Admin_Actions::get_instance();
		wp_set_current_user( $this->leader );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$this->stop_capturing_http();
		parent::tear_down();
	}

	/**
	 * Run an action with a valid nonce.
	 *
	 * @param string $action  Action.
	 * @param array  $request Request data.
	 * @return array
	 */
	private function run_action( string $action, array $request = array() ): array {
		$request['_wpnonce'] = $request['_wpnonce'] ?? wp_create_nonce( 'dfxpray_' . $action );

		return $this->actions->handle( $action, $request );
	}

	/**
	 * Approve in own group.
	 */
	public function test_leader_approves_in_own_group(): void {
		$id = $this->make_pending( $this->mine );

		$result = $this->run_action( 'approve', array( 'intention_id' => $id, 'summary' => 'Por su salud', 'discretion' => 'initials' ) );

		$this->assertTrue( $result['ok'], $result['message'] );
		$intention = DFXPRAY_Intentions::get_instance()->get( $id );
		$this->assertSame( 'active', $intention['status'] );
		$this->assertSame( 'initials', $intention['discretion'] );
	}

	/**
	 * Every intention action is refused on another group's intention.
	 *
	 * @return array
	 */
	public function provide_intention_actions(): array {
		return array(
			array( 'approve', array( 'summary' => 'x' ) ),
			array( 'discard', array( 'reason' => 'spam' ) ),
			array( 'save_intention', array( 'summary' => 'x' ) ),
			array( 'add_comment', array( 'body' => 'x' ) ),
			array( 'add_update', array( 'body' => 'x' ) ),
			array( 'set_priority', array( 'priority' => 'high' ) ),
			array( 'postpone', array( 'date' => '2026-12-01' ) ),
			array( 'ack_sent', array() ),
			array( 'keep_active', array() ),
			array( 'close', array( 'reason' => 'answered' ) ),
			array( 'reopen', array() ),
			array( 'transfer', array( 'group_id' => 0 ) ),
		);
	}

	/**
	 * IDOR: acting on another group's intention.
	 *
	 * @dataProvider provide_intention_actions
	 *
	 * @param string $action  Action.
	 * @param array  $request Extra data.
	 */
	public function test_other_group_intentions_are_forbidden( string $action, array $request ): void {
		$id = $this->make_pending( $this->other );
		$before = DFXPRAY_Intentions::get_instance()->get( $id );

		$result = $this->run_action( $action, array_merge( array( 'intention_id' => $id ), $request ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'forbidden', $result['code'] );
		$this->assertEquals( $before, DFXPRAY_Intentions::get_instance()->get( $id ) );
	}

	/**
	 * Leaders add, edit and delete the brothers of their group; never those
	 * of another group (IDOR), whatever group they claim.
	 */
	public function test_member_actions(): void {
		$members = DFXPRAY_Members::get_instance();

		$created = $this->run_action( 'save_member', array( 'group_id' => $this->mine, 'member' => array( 'name' => 'Ramón García', 'birth_day' => '24', 'birth_month' => '9' ) ) );
		$this->assertTrue( $created['ok'] );
		$id = (int) $created['id'];
		$this->assertSame( $this->mine, $members->get( $id )['group_id'] );

		$this->assertTrue( $this->run_action( 'save_member', array( 'member_id' => $id, 'member' => array( 'called' => 'Ramón García' ) ) )['ok'] );
		$this->assertSame( 'Ramón García', $members->get( $id )['called'] );

		$theirs = $members->create( $this->other, array( 'name' => 'Pedro' ) );
		foreach ( array( 'save_member', 'delete_member' ) as $action ) {
			$result = $this->run_action( $action, array( 'member_id' => $theirs, 'group_id' => $this->mine, 'member' => array( 'name' => 'Hacked' ) ) );
			$this->assertSame( 'forbidden', $result['code'], $action );
		}
		$this->assertSame( 'Pedro', $members->get( $theirs )['name'] );
		$this->assertSame( 'forbidden', $this->run_action( 'save_member', array( 'group_id' => $this->other, 'member' => array( 'name' => 'Intruso' ) ) )['code'] );

		$this->assertTrue( $this->run_action( 'delete_member', array( 'member_id' => $id ) )['ok'] );
		$this->assertNull( $members->get( $id ) );
	}

	/**
	 * A wrong nonce stops everything.
	 */
	public function test_bad_nonce(): void {
		$id = $this->make_pending( $this->mine );

		$result = $this->run_action( 'approve', array( 'intention_id' => $id, 'summary' => 'x', '_wpnonce' => 'bad' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'nonce', $result['code'] );
		$this->assertSame( 'pending', DFXPRAY_Intentions::get_instance()->get( $id )['status'] );
	}

	/**
	 * Users without the capability can do nothing.
	 */
	public function test_subscriber_forbidden(): void {
		$id = $this->make_pending( $this->mine );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		DFXPRAY_Groups::get_instance()->set_leaders( $this->mine, array( $this->leader, $subscriber ) );
		wp_set_current_user( $subscriber );

		$result = $this->run_action( 'approve', array( 'intention_id' => $id, 'summary' => 'x' ) );

		$this->assertSame( 'forbidden', $result['code'] );
	}

	/**
	 * Manual creation, in own group only, with key dates from form arrays.
	 */
	public function test_save_new_intention(): void {
		$request = array(
			'intention_id' => 0,
			'group_id'     => $this->mine,
			'intention'    => array(
				'requester_name'   => 'Luis',
				'beneficiary_name' => 'Antonio',
				'relationship'     => 'padre de',
				'type'             => 'deceased',
				'reference_date'   => '2026-09-20',
				'body'             => 'Rest',
				'summary'          => 'Descanso eterno',
				'discretion'       => 'first_name',
				'priority'         => 'normal',
			),
			'key_dates'    => array(
				'date'  => array( '2026-09-22', '' ),
				'time'  => array( '17:00', '' ),
				'label' => array( 'Funeral', '' ),
			),
		);

		$result = $this->run_action( 'save_intention', $request );

		$this->assertTrue( $result['ok'], $result['message'] );
		$intention = DFXPRAY_Intentions::get_instance()->get( $result['id'] );
		$this->assertSame( 'manual', $intention['source'] );
		$this->assertSame( 'active', $intention['status'] );
		$this->assertSame( array( array( 'date' => '2026-09-22', 'time' => '17:00', 'label' => 'Funeral' ) ), $intention['key_dates'] );

		$request['group_id'] = $this->other;
		$this->assertSame( 'forbidden', $this->run_action( 'save_intention', $request )['code'] );
	}

	/**
	 * Editing an existing intention.
	 */
	public function test_save_existing_intention(): void {
		$id = $this->make_active( $this->mine );

		$result = $this->run_action(
			'save_intention',
			array(
				'intention_id' => $id,
				'intention'    => array( 'summary' => 'New summary', 'beneficiary_display_name' => 'Nuestra hermana Carmen' ),
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'New summary', DFXPRAY_Intentions::get_instance()->get( $id )['summary'] );
	}

	/**
	 * Validation errors come back as messages.
	 */
	public function test_validation_error_message(): void {
		$id = $this->make_pending( $this->mine );

		$result = $this->run_action( 'approve', array( 'intention_id' => $id ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'dfxpray_summary_required', $result['code'] );
		$this->assertNotSame( '', $result['message'] );
	}

	/**
	 * Comments: add, edit, delete, convert, with cross-group protection.
	 */
	public function test_comment_actions(): void {
		$id = $this->make_active( $this->mine );

		$this->assertTrue( $this->run_action( 'add_comment', array( 'intention_id' => $id, 'body' => 'Called', 'type' => 'call' ) )['ok'] );
		$comment = DFXPRAY_Comments::get_instance()->for_intention( $id )[0]['id'];

		$this->assertTrue( $this->run_action( 'edit_comment', array( 'comment_id' => $comment, 'body' => 'Called twice', 'type' => 'call' ) )['ok'] );
		$this->assertTrue( $this->run_action( 'convert_comment', array( 'comment_id' => $comment, 'is_news' => '1' ) )['ok'] );
		$this->assertTrue( $this->run_action( 'delete_comment', array( 'comment_id' => $comment ) )['ok'] );
		$this->assertSame( 'Called twice', DFXPRAY_Updates::get_instance()->for_intention( $id )[0]['body'] );

		$foreign = DFXPRAY_Comments::get_instance()->add( $this->make_active( $this->other ), 'Secret' );
		foreach ( array( 'edit_comment', 'delete_comment', 'convert_comment' ) as $action ) {
			$this->assertSame( 'forbidden', $this->run_action( $action, array( 'comment_id' => $foreign, 'body' => 'x' ) )['code'], $action );
		}
	}

	/**
	 * Merge only with an intention of the same group.
	 */
	public function test_merge(): void {
		$target    = $this->make_active( $this->mine );
		$duplicate = $this->make_pending( $this->mine );
		$foreign   = $this->make_active( $this->other );

		$this->assertSame( 'forbidden', $this->run_action( 'merge', array( 'intention_id' => $duplicate, 'target_id' => $foreign ) )['code'] );
		$this->assertTrue( $this->run_action( 'merge', array( 'intention_id' => $duplicate, 'target_id' => $target ) )['ok'] );
	}

	/**
	 * Close answered with a thanksgiving.
	 */
	public function test_close_with_thanksgiving(): void {
		$id = $this->make_active( $this->mine );

		$result = $this->run_action( 'close', array( 'intention_id' => $id, 'reason' => 'answered', 'thanksgiving' => '1', 'thanksgiving_summary' => 'Gracias' ) );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'thanksgiving', DFXPRAY_Intentions::get_instance()->get( $result['id'] )['type'] );
	}

	/**
	 * Transfer between two own groups.
	 */
	public function test_transfer(): void {
		$second = $this->make_group( 'Second', array( $this->leader ) );
		$id     = $this->make_active( $this->mine );

		$this->assertSame( 'dfxpray_transfer_forbidden', $this->run_action( 'transfer', array( 'intention_id' => $id, 'group_id' => $this->other ) )['code'] );
		$this->assertTrue( $this->run_action( 'transfer', array( 'intention_id' => $id, 'group_id' => $second ) )['ok'] );
	}

	/**
	 * Quick actions.
	 */
	public function test_quick_actions(): void {
		$id = $this->make_active( $this->mine );

		$this->assertTrue( $this->run_action( 'set_priority', array( 'intention_id' => $id, 'priority' => 'high' ) )['ok'] );
		$this->assertTrue( $this->run_action( 'postpone', array( 'intention_id' => $id, 'date' => '2026-12-01' ) )['ok'] );
		$this->assertTrue( $this->run_action( 'ack_sent', array( 'intention_id' => $id ) )['ok'] );
		$this->assertTrue( $this->run_action( 'add_update', array( 'intention_id' => $id, 'body' => 'Better', 'is_news' => '1' ) )['ok'] );

		$intention = DFXPRAY_Intentions::get_instance()->get( $id );
		$this->assertSame( 'high', $intention['priority'] );
		$this->assertTrue( $intention['ack_sent'] );
	}

	/**
	 * Leaders edit their group's settings, not global-only keys nor other groups.
	 */
	public function test_group_settings(): void {
		$settings = array(
			'digest_time'    => '08:00',
			'retention_days' => 1000,
			'channels'       => array( 'email' => array( 'digest' => '0', 'alerts' => '1' ) ),
		);

		$this->assertTrue( $this->run_action( 'save_group_settings', array( 'group_id' => $this->mine, 'settings' => $settings ) )['ok'] );
		$this->assertSame( '08:00', DFXPRAY_Settings::get_instance()->get( 'digest_time', $this->mine ) );
		$this->assertSame( 90, DFXPRAY_Settings::get_instance()->get( 'retention_days', $this->mine ) );
		$this->assertFalse( DFXPRAY_Settings::get_instance()->get( 'channels', $this->mine )['email']['digest'] );

		$this->assertSame( 'forbidden', $this->run_action( 'save_group_settings', array( 'group_id' => $this->other, 'settings' => $settings ) )['code'] );

		$this->assertTrue( $this->run_action( 'save_group_settings', array( 'group_id' => $this->mine, 'reset' => '1' ) )['ok'] );
		$this->assertSame( '07:00', DFXPRAY_Settings::get_instance()->get( 'digest_time', $this->mine ) );
	}

	/**
	 * Leaders may regenerate their group's URL.
	 */
	public function test_regenerate_slug(): void {
		$before = DFXPRAY_Groups::get_instance()->get( $this->mine )['slug'];

		$this->assertTrue( $this->run_action( 'regenerate_slug', array( 'group_id' => $this->mine ) )['ok'] );
		$this->assertNotSame( $before, DFXPRAY_Groups::get_instance()->get( $this->mine )['slug'] );
		$this->assertSame( 'forbidden', $this->run_action( 'regenerate_slug', array( 'group_id' => $this->other ) )['code'] );
	}

	/**
	 * Channel test from the settings screen.
	 */
	public function test_channel_test(): void {
		$result = $this->run_action( 'test_channel', array( 'group_id' => $this->mine, 'channel' => 'email' ) );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertNotFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	/**
	 * Send the digest now.
	 */
	public function test_send_digest_now(): void {
		$this->assertTrue( $this->run_action( 'send_digest_now', array( 'group_id' => $this->mine ) )['ok'] );
		$this->assertNotNull( DFXPRAY_Scheduler::get_instance()->get_stored_digest( $this->mine ) );
	}

	/**
	 * Group management and global settings belong to administrators only.
	 *
	 * @return array
	 */
	public function provide_admin_only_actions(): array {
		return array(
			array( 'save_group', array( 'group_id' => 0, 'name' => 'New' ) ),
			array( 'archive_group', array( 'group_id' => 0 ) ),
			array( 'restore_group', array( 'group_id' => 0 ) ),
			array( 'delete_group', array( 'group_id' => 0 ) ),
			array( 'save_global_settings', array( 'settings' => array( 'retention_days' => 5 ) ) ),
		);
	}

	/**
	 * Leaders cannot run administrator actions, even on their own group.
	 *
	 * @dataProvider provide_admin_only_actions
	 *
	 * @param string $action  Action.
	 * @param array  $request Request.
	 */
	public function test_admin_only_actions( string $action, array $request ): void {
		if ( array_key_exists( 'group_id', $request ) && 0 === $request['group_id'] && 'save_group' !== $action ) {
			$request['group_id'] = $this->mine;
		}

		$this->assertSame( 'forbidden', $this->run_action( $action, $request )['code'] );
		$this->assertNotNull( DFXPRAY_Groups::get_instance()->get( $this->mine ) );
		$this->assertSame( 'active', DFXPRAY_Groups::get_instance()->get( $this->mine )['status'] );
	}

	/**
	 * Administrators create groups, assign leaders, archive and delete.
	 */
	public function test_admin_group_management(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->run_action( 'save_group', array( 'group_id' => 0, 'name' => 'Nuevo', 'description' => 'Desc', 'leaders' => array( $this->leader ) ) );
		$this->assertTrue( $result['ok'], $result['message'] );
		$new = $result['id'];
		$this->assertSame( array( $this->leader ), DFXPRAY_Groups::get_instance()->get_leaders( $new ) );

		$this->assertTrue( $this->run_action( 'save_group', array( 'group_id' => $new, 'name' => 'Renamed', 'description' => '', 'leaders' => array() ) )['ok'] );
		$this->assertSame( 'Renamed', DFXPRAY_Groups::get_instance()->get( $new )['name'] );
		$this->assertSame( array(), DFXPRAY_Groups::get_instance()->get_leaders( $new ) );

		$this->assertTrue( $this->run_action( 'archive_group', array( 'group_id' => $new ) )['ok'] );
		$this->assertSame( 'archived', DFXPRAY_Groups::get_instance()->get( $new )['status'] );
		$this->assertTrue( $this->run_action( 'restore_group', array( 'group_id' => $new ) )['ok'] );
		$this->assertTrue( $this->run_action( 'delete_group', array( 'group_id' => $new ) )['ok'] );
		$this->assertNull( DFXPRAY_Groups::get_instance()->get( $new ) );
	}

	/**
	 * Administrators save global settings.
	 */
	public function test_admin_global_settings(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( $this->run_action( 'save_global_settings', array( 'settings' => array( 'retention_days' => 60, 'delete_data_on_uninstall' => '0' ) ) )['ok'] );

		$this->assertSame( 60, DFXPRAY_Settings::get_instance()->get( 'retention_days' ) );
		$this->assertFalse( DFXPRAY_Settings::get_instance()->get( 'delete_data_on_uninstall' ) );
	}

	/**
	 * Administrators choose which other roles reach the panel.
	 */
	public function test_admin_sets_panel_roles(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( $this->run_action( 'save_global_settings', array( 'settings' => array(), 'panel_roles' => array( 'editor' ) ) )['ok'] );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'dfxpray_manage' ) );

		$this->assertTrue( $this->run_action( 'save_global_settings', array( 'settings' => array(), 'has_panel_roles' => '1' ) )['ok'] );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'dfxpray_manage' ) );
	}

	/**
	 * Unknown actions are refused.
	 */
	public function test_unknown_action(): void {
		$this->assertSame( 'unknown', $this->run_action( 'drop_database' )['code'] );
	}
}
