<?php
/**
 * Tests for the invitations to lead a group.
 *
 * @package DFXPRAY
 */

/**
 * Invitations behaviour.
 */
class InvitationsTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * Group led by the leader.
	 *
	 * @var int
	 */
	private int $group;

	/**
	 * Leader who invites.
	 *
	 * @var int
	 */
	private int $leader;

	/**
	 * Service.
	 *
	 * @var DFXPRAY_Invitations
	 */
	private DFXPRAY_Invitations $invitations;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		update_option( 'admin_email', 'admin@example.org' );
		$this->freeze_time( '2026-09-24 10:00' );
		$this->leader      = self::factory()->user->create(
			array(
				'role'         => 'dfxpray_leader',
				'display_name' => 'Ana Leader',
				'user_email'   => 'ana@example.org',
			)
		);
		$this->group       = $this->make_group( 'Santa Marta', array( $this->leader ) );
		$this->invitations = DFXPRAY_Invitations::get_instance();
		wp_set_current_user( $this->leader );
	}

	/**
	 * Sent mails.
	 *
	 * @return array
	 */
	private function sent_mails(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * The token in the last mail sent.
	 */
	private function last_token(): string {
		$mails = $this->sent_mails();
		$this->assertNotEmpty( $mails );
		$body = end( $mails )['body'];
		$this->assertSame( 1, preg_match( '/[?&]token=([A-Za-z0-9]+)/', $body, $match ), $body );

		return $match[1];
	}

	/**
	 * Invite someone and return the token sent.
	 *
	 * @param string $email Email.
	 * @param string $name  Name.
	 */
	private function invite( string $email = 'pedro@example.org', string $name = 'Pedro Gil' ): string {
		$id = $this->invitations->invite( $this->group, $name, $email );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		return $this->last_token();
	}

	/**
	 * An invitation is stored without its token, mailed with a link, and logged.
	 */
	public function test_invite_creates_and_mails(): void {
		global $wpdb;

		$id = $this->invitations->invite( $this->group, 'Pedro Gil', 'Pedro@Example.org' );

		$this->assertIsInt( $id );
		$invitation = $this->invitations->get( $id );
		$this->assertSame( $this->group, $invitation['group_id'] );
		$this->assertSame( 'Pedro Gil', $invitation['name'] );
		$this->assertSame( 'pedro@example.org', $invitation['email'] );
		$this->assertSame( $this->leader, $invitation['invited_by'] );
		$this->assertSame( '2026-09-24 10:00:00', $invitation['created_at'] );
		$this->assertSame( '2026-10-01 10:00:00', $invitation['expires_at'] );
		$this->assertNull( $invitation['accepted_at'] );

		$mails = $this->sent_mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'pedro@example.org', $mails[0]['to'][0][0] );
		$this->assertStringContainsString( 'Invitation to lead Santa Marta', $mails[0]['subject'] );
		$body = $mails[0]['body'];
		foreach ( array( 'Ana Leader', 'Santa Marta', 'illnesses, deaths, family situations', 'confidentiality', 'wp-login.php?action=dfxpray_invitation&token=', 'October 1, 2026', 'If you did not expect this invitation, ignore this email.' ) as $needle ) {
			$this->assertStringContainsString( $needle, $body );
		}

		$token = $this->last_token();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', DFXPRAY_Installer::get_instance()->get_table_names()['invitations'], $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertNotContains( $token, $row );
		$this->assertSame( hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ), $row['token_hash'] );

		$this->assertSame( 'invitation_sent', DFXPRAY_Log::get_instance()->for_group( $this->group )[0]['action'] );
		$this->assertSame( $id, $this->invitations->for_group( $this->group )[0]['id'] );
	}

	/**
	 * Inviting the same email again while pending refreshes the token and the
	 * expiry and sends it again, without a second invitation.
	 */
	public function test_resend_refreshes(): void {
		$first = $this->invite();
		$this->freeze_time( '2026-09-27 12:00' );
		$id = $this->invitations->invite( $this->group, 'Pedro Gil Sanz', 'PEDRO@example.org' );

		$second = $this->last_token();
		$this->assertNotSame( $first, $second );
		$this->assertCount( 1, $this->invitations->for_group( $this->group ) );
		$this->assertSame( '2026-10-04 12:00:00', $this->invitations->get( $id )['expires_at'] );
		$this->assertSame( 'Pedro Gil Sanz', $this->invitations->get( $id )['name'] );
		$this->assertNull( $this->invitations->find_valid( $first ) );
		$this->assertSame( $id, $this->invitations->find_valid( $second )['id'] );
	}

	/**
	 * Validation: email, existing leader, active group.
	 */
	public function test_validation(): void {
		$this->assertSame( 'dfxpray_invitation_email', $this->invitations->invite( $this->group, 'Pedro', 'not-an-email' )->get_error_code() );

		DFXPRAY_Groups::get_instance()->archive( $this->group );
		$this->assertSame( 'dfxpray_invitation_group', $this->invitations->invite( $this->group, 'Pedro', 'pedro@example.org' )->get_error_code() );
		$this->assertSame( 'dfxpray_invitation_group', $this->invitations->invite( 999999, 'Pedro', 'pedro@example.org' )->get_error_code() );
		$this->assertCount( 0, $this->sent_mails() );
	}

	/**
	 * Inviting someone who already leads the group changes nothing and says
	 * nothing different to the leader; only the invitee is told by email.
	 */
	public function test_inviting_a_current_leader_changes_nothing(): void {
		$result = $this->invitations->invite( $this->group, 'Ana', 'ANA@example.org' );

		$this->assertTrue( $result );
		$this->assertSame( array(), $this->invitations->for_group( $this->group ) );
		$mails = $this->sent_mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'ana@example.org', $mails[0]['to'][0][0] );
		$this->assertStringContainsString( 'already a leader', $mails[0]['body'] );
		$this->assertStringNotContainsString( 'token=', $mails[0]['body'] );
		$this->assertNotContains( 'invitation_sent', array_column( DFXPRAY_Log::get_instance()->for_group( $this->group ), 'action' ) );
	}

	/**
	 * After seven days the link no longer works.
	 */
	public function test_expiry(): void {
		$token = $this->invite();

		$this->freeze_time( '2026-10-01 09:59' );
		$this->assertNotNull( $this->invitations->find_valid( $token ) );

		$this->freeze_time( '2026-10-01 10:01' );
		$this->assertNull( $this->invitations->find_valid( $token ) );
		$this->assertSame( array(), $this->invitations->for_group( $this->group ) );
		$this->assertSame( 'dfxpray_invitation_invalid', $this->invitations->accept( $token )->get_error_code() );
		$this->assertFalse( get_user_by( 'email', 'pedro@example.org' ) );
	}

	/**
	 * Unknown tokens are refused.
	 */
	public function test_invalid_token(): void {
		$this->invite();

		$this->assertNull( $this->invitations->find_valid( 'nope' ) );
		$this->assertNull( $this->invitations->find_valid( '' ) );
		$this->assertSame( 'dfxpray_invitation_invalid', $this->invitations->accept( 'nope' )->get_error_code() );
	}

	/**
	 * A withdrawn invitation is gone, its link no longer works, and it is logged.
	 */
	public function test_withdraw(): void {
		$token = $this->invite();
		$id    = $this->invitations->find_valid( $token )['id'];

		$this->assertTrue( $this->invitations->withdraw( $id ) );

		$this->assertNull( $this->invitations->get( $id ) );
		$this->assertNull( $this->invitations->find_valid( $token ) );
		$this->assertContains( 'invitation_withdrawn', array_column( DFXPRAY_Log::get_instance()->for_group( $this->group ), 'action' ) );
		$this->assertSame( 'dfxpray_invitation_not_found', $this->invitations->withdraw( $id )->get_error_code() );
	}

	/**
	 * An archived group's invitations cannot be accepted.
	 */
	public function test_archived_group(): void {
		$token = $this->invite();
		DFXPRAY_Groups::get_instance()->archive( $this->group );

		$this->assertNull( $this->invitations->find_valid( $token ) );
		$this->assertSame( 'dfxpray_invitation_invalid', $this->invitations->accept( $token )->get_error_code() );
	}

	/**
	 * Someone without an account gets one, as leader of the group, and goes on
	 * to choose a password. The administrator is told and it is logged.
	 */
	public function test_accept_creates_account(): void {
		$token = $this->invite( 'pedro.gil+fe@example.org', 'Pedro Gil Sanz' );
		self::factory()->user->create( array( 'user_login' => 'pedrogilfe' ) );
		reset_phpmailer_instance();
		wp_set_current_user( 0 );

		$result = $this->invitations->accept( $token, 'Gr4ce-and-Peace', 'Gr4ce-and-Peace' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['created'] );
		$user = get_user_by( 'email', 'pedro.gil+fe@example.org' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( $result['user_id'], $user->ID );
		$this->assertSame( array( 'dfxpray_leader' ), $user->roles );
		$this->assertSame( 'Pedro Gil Sanz', $user->display_name );
		$this->assertSame( 'Pedro', $user->first_name );
		$this->assertSame( 'Gil Sanz', $user->last_name );
		$this->assertNotSame( 'pedrogilfe', $user->user_login );
		$this->assertStringStartsWith( 'pedrogilfe', $user->user_login );
		$this->assertContains( $this->group, DFXPRAY_Groups::get_instance()->get_group_ids_for_leader( $user->ID ) );
		$this->assertContains( $this->leader, DFXPRAY_Groups::get_instance()->get_leaders( $this->group ) );
		// The password they chose is theirs, and they go straight to the group.
		$this->assertTrue( wp_check_password( 'Gr4ce-and-Peace', $user->user_pass, $user->ID ) );
		$this->assertSame( DFXPRAY_Admin::get_instance()->group_url( $this->group ), $result['redirect'] );

		$this->assertNull( $this->invitations->find_valid( $token ) );
		$this->assertSame( 'dfxpray_invitation_invalid', $this->invitations->accept( $token )->get_error_code() );

		$mails = $this->sent_mails();
		$this->assertCount( 2, $mails );

		// A welcome that tells them how to come back: their email is their user.
		$this->assertSame( 'pedro.gil+fe@example.org', $mails[0]['to'][0][0] );
		$this->assertStringContainsString( 'Santa Marta', $mails[0]['subject'] );
		$this->assertStringContainsString( 'Hello, Pedro Gil Sanz: you are now a leader of the prayer group “Santa Marta”', $mails[0]['body'] );
		$this->assertStringContainsString( 'with your email, pedro.gil+fe@example.org, and the password you chose', $mails[0]['body'] );
		$this->assertStringContainsString( wp_login_url( DFXPRAY_Admin::get_instance()->group_url( $this->group ) ), $mails[0]['body'] );
		$this->assertStringContainsString( DFXPRAY_App::get_instance()->url(), $mails[0]['body'] );
		$this->assertStringContainsString( wp_lostpassword_url(), $mails[0]['body'] );
		$this->assertStringNotContainsString( 'Gr4ce-and-Peace', $mails[0]['body'] );

		$this->assertSame( 'admin@example.org', $mails[1]['to'][0][0] );
		$this->assertStringContainsString( 'Santa Marta', $mails[1]['subject'] );
		$this->assertStringContainsString( 'Pedro Gil Sanz', $mails[1]['body'] );
		$this->assertStringContainsString( 'Ana Leader', $mails[1]['body'] );

		$log = DFXPRAY_Log::get_instance()->for_group( $this->group, array( 'invitation_accepted' ) );
		$this->assertCount( 1, $log );
		$this->assertSame( $user->ID, $log[0]['user_id'] );
	}

	/**
	 * Without an account, the invitation is accepted only with a password of
	 * at least eight characters, typed twice the same; until then nothing
	 * changes and the link keeps working.
	 */
	public function test_new_account_needs_a_password(): void {
		$token = $this->invite();
		wp_set_current_user( 0 );

		$this->assertSame( 'dfxpray_invitation_password', $this->invitations->accept( $token )->get_error_code() );
		$this->assertSame( 'Choose a password of at least 8 characters.', $this->invitations->accept( $token, 'short', 'short' )->get_error_message() );
		$this->assertSame( 'The two passwords do not match.', $this->invitations->accept( $token, 'Gr4ce-and-Peace', 'Gr4ce-and-peace' )->get_error_message() );
		$this->assertFalse( get_user_by( 'email', 'pedro@example.org' ) );
		$this->assertNotNull( $this->invitations->find_valid( $token ) );

		$this->assertIsArray( $this->invitations->accept( $token, 'Gr4ce-and-Peace', 'Gr4ce-and-Peace' ) );
	}

	/**
	 * Someone with an account but no access to the panel gets the leader role
	 * beside their own, and is sent to log in.
	 */
	public function test_accept_existing_user_without_panel_access(): void {
		$subscriber = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'pedro@example.org',
			)
		);
		$token      = $this->invite();
		wp_set_current_user( 0 );

		$result = $this->invitations->accept( $token );

		$this->assertFalse( $result['created'] );
		$this->assertSame( $subscriber, $result['user_id'] );
		$user = get_user_by( 'id', $subscriber );
		$this->assertEqualsCanonicalizing( array( 'subscriber', 'dfxpray_leader' ), $user->roles );
		$this->assertTrue( DFXPRAY_Permissions::get_instance()->can_manage_group( $this->group, $subscriber ) );
		$this->assertStringContainsString( 'wp-login.php', $result['redirect'] );
		$this->assertStringContainsString( rawurlencode( 'page=dfxpray-group-' . $this->group ), $result['redirect'] );
		$this->assertCount( 0, array_filter( $this->sent_mails(), static fn( $m ) => 'pedro@example.org' === $m['to'][0][0] && str_contains( $m['body'], 'you are now a leader' ) ) );
	}

	/**
	 * An administrator keeps exactly their roles, and, logged in, goes
	 * straight to the group.
	 */
	public function test_accept_existing_admin_keeps_roles(): void {
		$admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'boss@example.org',
			)
		);
		$token = $this->invite( 'boss@example.org', 'Boss' );
		wp_set_current_user( $admin );

		$result = $this->invitations->accept( $token );

		$this->assertSame( array( 'administrator' ), get_user_by( 'id', $admin )->roles );
		$this->assertContains( $admin, DFXPRAY_Groups::get_instance()->get_leaders( $this->group ) );
		$this->assertSame( DFXPRAY_Admin::get_instance()->group_url( $this->group ), $result['redirect'] );
	}

	/**
	 * The daily job deletes invitations that expired, and those accepted more
	 * than seven days ago.
	 */
	public function test_daily_cleanup(): void {
		$this->freeze_time( '2026-09-20 10:00' );
		$expired = $this->invitations->find_valid( $this->invite( 'old@example.org' ) )['id'];
		$this->invitations->accept( $this->invite( 'acc@example.org' ), 'Gr4ce-and-Peace', 'Gr4ce-and-Peace' );
		$this->freeze_time( '2026-09-24 10:00' );
		$this->invitations->accept( $this->invite( 'recent@example.org' ), 'Gr4ce-and-Peace', 'Gr4ce-and-Peace' );
		$pending = $this->invitations->find_valid( $this->invite( 'pending@example.org' ) )['id'];

		$this->freeze_time( '2026-09-28 03:00' );
		DFXPRAY_Scheduler::get_instance()->daily();

		$emails = array_column( $this->all_rows(), 'email' );
		$this->assertNull( $this->invitations->get( $expired ) );
		$this->assertNotContains( 'acc@example.org', $emails );
		$this->assertContains( 'recent@example.org', $emails );
		$this->assertNotNull( $this->invitations->get( $pending ) );
	}

	/**
	 * Every invitation row.
	 *
	 * @return array[]
	 */
	private function all_rows(): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', DFXPRAY_Installer::get_instance()->get_table_names()['invitations'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Deleting a group deletes its invitations.
	 */
	public function test_group_deletion(): void {
		$this->invite();

		DFXPRAY_Groups::get_instance()->delete( $this->group );

		$this->assertSame( array(), $this->all_rows() );
	}

	/**
	 * The accept page: a GET shows the invitation and a form, never accepts.
	 */
	public function test_page_shows_without_accepting(): void {
		$token = $this->invite();
		wp_set_current_user( 0 );

		$html = $this->invitations->page_body( $token );

		$this->assertStringContainsString( 'Santa Marta', $html );
		$this->assertStringContainsString( 'Ana Leader', $html );
		$this->assertStringContainsString( 'illnesses, deaths, family situations', $html );
		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'name="token" value="' . $token . '"', $html );
		$this->assertStringContainsString( 'You will log in with your email, pedro@example.org. Choose your password:', $html );
		$this->assertStringContainsString( 'name="dfxpray_password"', $html );
		$this->assertStringContainsString( 'name="dfxpray_password_confirm"', $html );
		$this->assertStringContainsString( 'autocomplete="new-password"', $html );
		$this->assertStringContainsString( 'Accept and enter', $html );
		$this->assertNotNull( $this->invitations->find_valid( $token ) );
		$this->assertFalse( get_user_by( 'email', 'pedro@example.org' ) );

		// With an account already, no password is asked: they log in with it.
		self::factory()->user->create( array( 'user_email' => 'pedro@example.org' ) );
		$html = $this->invitations->page_body( $token );
		$this->assertStringContainsString( 'You already have an account with this email: after accepting, log in with it.', $html );
		$this->assertStringNotContainsString( 'name="dfxpray_password"', $html );
		$this->assertStringContainsString( 'Accept the invitation', $html );

		$invalid = $this->invitations->page_body( 'nope' );
		$this->assertStringContainsString( 'This invitation is not valid or has expired. Ask whoever invited you for a new one.', $invalid );
		$this->assertStringNotContainsString( 'method="post"', $invalid );
	}

	/**
	 * The accept page is reached through wp-login.php.
	 */
	public function test_login_action_registered(): void {
		$this->assertNotFalse( has_action( 'login_form_dfxpray_invitation', array( $this->invitations, 'handle_login_action' ) ) );
	}
}
