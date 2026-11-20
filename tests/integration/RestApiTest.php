<?php
/**
 * Tests for the routines' REST route and service accounts.
 *
 * @package DFXPRAY
 */

/**
 * The REST route answers only to application passwords, with the app's
 * operations and permissions; service accounts get no notices and their
 * application passwords work only for this plugin.
 */
class RestApiTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * Group led by the account.
	 *
	 * @var int
	 */
	private int $mine;

	/**
	 * Group the account does not lead.
	 *
	 * @var int
	 */
	private int $other;

	/**
	 * A leader used by a routine.
	 *
	 * @var int
	 */
	private int $robot;

	/**
	 * Set up: a service account leading one group.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		$this->freeze_time( '2026-09-24 05:05' );
		$this->robot = self::factory()->user->create(
			array(
				'role'       => 'dfxpray_leader',
				'user_email' => 'rutina@example.org',
			)
		);
		$this->mine  = $this->make_group( 'Mine', array( $this->robot ) );
		$this->other = $this->make_group( 'Other' );
		DFXPRAY_Service_Accounts::get_instance()->set( $this->robot, true );
	}

	/**
	 * Tear down: forget the application password.
	 */
	public function tear_down(): void {
		$GLOBALS['wp_rest_application_password_uuid'] = null;
		parent::tear_down();
	}

	/**
	 * Act as a user authenticated by an application password, as core does.
	 *
	 * @param int $user_id User.
	 */
	private function with_app_password( int $user_id ): void {
		wp_set_current_user( $user_id );
		$GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4();
	}

	/**
	 * Call the route.
	 *
	 * @param string $op     Operation.
	 * @param array  $params Body.
	 * @return WP_REST_Response
	 */
	private function call( string $op, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/dfxpray/v1/' . $op );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );

		return rest_do_request( $request );
	}

	/**
	 * Nobody gets in without an application password, not even with a session.
	 */
	public function test_only_application_passwords(): void {
		$this->assertSame( 401, $this->call( 'list', array( 'group_id' => $this->mine ) )->get_status() );

		wp_set_current_user( $this->robot );
		$this->assertSame( 401, $this->call( 'list', array( 'group_id' => $this->mine ) )->get_status() );

		$this->with_app_password( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'list', array( 'group_id' => $this->mine ) )->get_status() );
	}

	/**
	 * The app's reads and writes, scoped to the account's groups.
	 */
	public function test_reads_and_writes_of_own_group(): void {
		$this->with_app_password( $this->robot );

		$created = $this->call(
			'save_intention',
			array(
				'intention_id' => 0,
				'group_id'     => $this->mine,
				'intention'    => array(
					'requester_name'      => 'Felipe Arizu',
					'requester_is_member' => '1',
					'beneficiary_name'    => 'Marina',
					'relationship'        => 'esposa de',
					'type'                => 'ongoing',
					'body'                => '24/9: tratamiento.',
					'summary'             => 'problemas de salud',
				),
			)
		);
		$this->assertSame( 200, $created->get_status() );
		$id = $created->get_data()['id'];

		$this->assertSame( 200, $this->call( 'add_update', array( 'intention_id' => $id, 'body' => '[24/9] Mejor.' ) )->get_status() );

		$list = $this->call( 'list', array( 'group_id' => $this->mine ) )->get_data();
		$this->assertSame( array( $id ), array_column( $list['data']['items'], 'id' ) );

		$one = $this->call( 'get', array( 'intention_id' => $id ) )->get_data();
		$this->assertSame( '24/9: tratamiento.', $one['data']['intention']['body'] );
		$this->assertSame( '[24/9] Mejor.', $one['data']['updates'][0]['body'] );
	}

	/**
	 * A routine edits the members of its group (how they are called, the
	 * phone…), and only those: the rest of the member stays as it was.
	 */
	public function test_routine_edits_members_of_its_group_only(): void {
		$members = DFXPRAY_Members::get_instance();
		$mine    = $members->create(
			$this->mine,
			array(
				'name'        => 'Ignacio Díez Murugarren',
				'called'      => 'Ignacio Díez',
				'phone'       => '600 11 22 33',
				'birth_day'   => '3',
				'birth_month' => '5',
			)
		);
		$other   = $members->create( $this->other, array( 'name' => 'Pedro' ) );
		$this->with_app_password( $this->robot );

		$saved = $this->call(
			'save_member',
			array(
				'member_id' => $mine,
				'member'    => array( 'called' => 'Nacho Díez' ),
			)
		);
		$this->assertSame( 200, $saved->get_status(), wp_json_encode( $saved->get_data() ) );
		$member = $members->get( $mine );
		$this->assertSame( 'Nacho Díez', $member['called'] );
		$this->assertSame( 'Ignacio Díez Murugarren', $member['name'] );
		$this->assertSame( 3, (int) $member['birth_day'] );
		$this->assertNotSame( '', $member['phone'] );

		$created = $this->call(
			'save_member',
			array(
				'member_id' => 0,
				'group_id'  => $this->mine,
				'member'    => array( 'name' => 'Teo Martín' ),
			)
		);
		$this->assertSame( 200, $created->get_status() );
		$this->assertSame( $this->mine, $members->get( $created->get_data()['id'] )['group_id'] );

		foreach (
			array(
				array( 'member_id' => $other, 'member' => array( 'called' => 'x' ) ),
				array( 'member_id' => 0, 'group_id' => $this->other, 'member' => array( 'name' => 'x' ) ),
			) as $request
		) {
			$response = $this->call( 'save_member', $request );
			$this->assertSame( 403, $response->get_status() );
		}
		$this->assertSame( '', $members->get( $other )['called'] );
		$this->assertCount( 1, $members->for_group( $this->other ) );

		// Deleting stays in the panel.
		$this->assertNotSame( 200, $this->call( 'delete_member', array( 'member_id' => $mine ) )->get_status() );
		$this->assertNotNull( $members->get( $mine ) );
	}

	/**
	 * The digest is read and sent on demand, once the routine is done.
	 */
	public function test_digest_read_and_sent(): void {
		$this->make_active( $this->mine, array( 'summary' => 'recién llegada' ) );
		$this->with_app_password( $this->robot );

		$digest = $this->call( 'digest', array( 'group_id' => $this->mine ) )->get_data()['data'];
		$this->assertFalse( $digest['sent_today'] );
		$this->assertStringContainsString( 'recién llegada', $digest['shareable'] );

		$sent = $this->call( 'send_digest_now', array( 'group_id' => $this->mine ) );
		$this->assertSame( 200, $sent->get_status() );
		$this->assertTrue( $this->call( 'digest', array( 'group_id' => $this->mine ) )->get_data()['data']['sent_today'] );
	}

	/**
	 * IDOR: another group is neither read nor written.
	 */
	public function test_other_group_is_refused(): void {
		$id = $this->make_active( $this->other );
		$this->with_app_password( $this->robot );

		foreach (
			array(
				array( 'list', array( 'group_id' => $this->other ) ),
				array( 'get', array( 'intention_id' => $id ) ),
				array( 'digest', array( 'group_id' => $this->other ) ),
				array( 'send_digest_now', array( 'group_id' => $this->other ) ),
				array( 'add_update', array( 'intention_id' => $id, 'body' => 'x' ) ),
				array( 'close', array( 'intention_id' => $id, 'reason' => 'expired' ) ),
				array( 'thanksgiving_preview', array( 'intention_id' => $id, 'summary' => 'x' ) ),
			) as $call
		) {
			$response = $this->call( $call[0], $call[1] );
			$this->assertSame( 403, $response->get_status(), $call[0] );
			$this->assertSame( 'forbidden', $response->get_data()['code'], $call[0] );
		}
		$this->assertSame( 'active', DFXPRAY_Intentions::get_instance()->get( $id )['status'] );
		$this->assertNull( DFXPRAY_Scheduler::get_instance()->get_stored_digest( $this->other ) );
	}

	/**
	 * An administrator's application password reaches only the groups they
	 * lead, like anyone else's.
	 */
	public function test_admin_reaches_only_groups_they_lead(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		DFXPRAY_Groups::get_instance()->set_leaders( $this->mine, array( $this->robot, $admin ) );
		$id = $this->make_active( $this->other );
		$this->with_app_password( $admin );

		$this->assertSame( 200, $this->call( 'list', array( 'group_id' => $this->mine ) )->get_status() );
		$this->assertSame( 403, $this->call( 'list', array( 'group_id' => $this->other ) )->get_status() );
		$this->assertSame( 403, $this->call( 'get', array( 'intention_id' => $id ) )->get_status() );
		$this->assertSame( 403, $this->call( 'close', array( 'intention_id' => $id, 'reason' => 'expired' ) )->get_status() );
		$this->assertSame( 'active', DFXPRAY_Intentions::get_instance()->get( $id )['status'] );
	}

	/**
	 * Operations the panel has but the route does not offer stay out.
	 */
	public function test_unknown_operations(): void {
		$this->with_app_password( $this->robot );

		$this->assertSame( 400, $this->call( 'delete_group', array( 'group_id' => $this->mine ) )->get_status() );
		$this->assertNotNull( DFXPRAY_Groups::get_instance()->get( $this->mine ) );
	}

	/**
	 * A service account's application password opens nothing else in the site.
	 */
	public function test_service_account_password_only_works_here(): void {
		$this->with_app_password( $this->robot );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) )->get_status() );

		$person = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );
		$this->with_app_password( $person );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) )->get_status() );
	}

	/**
	 * Outside REST (XML-RPC), a service account's application password is refused.
	 */
	public function test_service_account_password_refused_outside_rest(): void {
		$accounts = DFXPRAY_Service_Accounts::get_instance();
		$robot    = get_userdata( $this->robot );
		$person   = get_userdata( self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) ) );

		$this->assertTrue( $accounts->check_app_password( new WP_Error(), $robot, false )->has_errors() );
		$this->assertFalse( $accounts->check_app_password( new WP_Error(), $robot, true )->has_errors() );
		$this->assertFalse( $accounts->check_app_password( new WP_Error(), $person, false )->has_errors() );
	}

	/**
	 * A service account gets no email: notices go to the people who lead.
	 */
	public function test_service_account_gets_no_email(): void {
		$person = self::factory()->user->create(
			array(
				'role'       => 'dfxpray_leader',
				'user_email' => 'david@example.org',
			)
		);
		DFXPRAY_Groups::get_instance()->set_leaders( $this->mine, array( $this->robot, $person ) );

		$this->assertSame( array( 'david@example.org' ), DFXPRAY_Channel_Email::get_instance()->recipients( array(), $this->mine ) );
	}

	/**
	 * Only administrators mark service accounts, from the user's profile.
	 */
	public function test_only_administrators_mark_service_accounts(): void {
		$accounts = DFXPRAY_Service_Accounts::get_instance();
		$person   = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );

		wp_set_current_user( $person );
		$_POST['dfxpray_service_account'] = '1';
		$accounts->save_profile( $person );
		$this->assertFalse( $accounts->is_service_account( $person ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$accounts->save_profile( $person );
		$this->assertTrue( $accounts->is_service_account( $person ) );

		unset( $_POST['dfxpray_service_account'] );
		$accounts->save_profile( $person );
		$this->assertFalse( $accounts->is_service_account( $person ) );
	}
}
