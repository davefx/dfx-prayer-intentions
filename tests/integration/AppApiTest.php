<?php
/**
 * Tests for the private API of the leaders' app.
 *
 * @package DFXPRAY
 */

/**
 * The app API: nonce, access, per-group permissions and every operation.
 */
class AppApiTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * API under test.
	 *
	 * @var DFXPRAY_App_API
	 */
	private DFXPRAY_App_API $api;

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
	 * A leader.
	 *
	 * @var int
	 */
	private int $leader;

	/**
	 * Set up: a leader of one group, Thursday 24 September 2026.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		$this->freeze_time( '2026-09-24 10:00' );
		$this->api    = DFXPRAY_App_API::get_instance();
		$this->leader = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );
		$this->mine   = $this->make_group( 'Mine', array( $this->leader ) );
		$this->other  = $this->make_group( 'Other' );
		wp_set_current_user( $this->leader );
	}

	/**
	 * Call the API as the app does.
	 *
	 * @param string $op      Operation.
	 * @param array  $request Other fields.
	 * @return array
	 */
	private function call( string $op, array $request = array() ): array {
		return $this->api->respond( array_merge( array( 'op' => $op, '_nonce' => wp_create_nonce( DFXPRAY_App_API::NONCE ) ), $request ) );
	}

	/**
	 * Without a valid nonce nothing is answered.
	 */
	public function test_nonce_required(): void {
		$result = $this->api->respond( array( 'op' => 'bootstrap', '_nonce' => 'bogus' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'nonce', $result['code'] );
	}

	/**
	 * Users who cannot reach the panel get nothing.
	 */
	public function test_non_leaders_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->call( 'bootstrap' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'forbidden', $result['code'] );
	}

	/**
	 * The app starts with the groups the user may manage, and no others.
	 */
	public function test_bootstrap_lists_own_groups(): void {
		$result = $this->call( 'bootstrap' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( $this->mine ), array_column( $result['data']['groups'], 'id' ) );
		$this->assertSame( 'Mine', $result['data']['groups'][0]['name'] );
	}

	/**
	 * The message of the day is the group's index.
	 */
	public function test_index(): void {
		$this->make_active( $this->mine, array( 'summary' => 'por su salud' ) );

		$result = $this->call( 'index', array( 'group_id' => $this->mine ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( DFXPRAY_Index_Composer::get_instance()->compose( $this->mine ), $result['data']['text'] );

		$monthly = $this->call( 'index', array( 'group_id' => $this->mine, 'edition' => 'monthly' ) );
		$this->assertSame( DFXPRAY_Index_Composer::get_instance()->compose( $this->mine, 'monthly' ), $monthly['data']['text'] );
	}

	/**
	 * Lists by state, with the line the index would show, and a search.
	 */
	public function test_list(): void {
		$active  = $this->make_active( $this->mine, array( 'beneficiary_name' => 'Carmen Ruiz', 'summary' => 'por su salud' ) );
		$pending = $this->make_pending( $this->mine, array( 'beneficiary_name' => 'Pedro Gil' ) );
		$this->make_active( $this->other );

		$current = $this->call( 'list', array( 'group_id' => $this->mine ) );
		$this->assertSame( array( $active ), array_column( $current['data']['items'], 'id' ) );
		$this->assertSame( 'Carmen, madre de Luis: por su salud', $current['data']['items'][0]['line'] );
		$this->assertSame( '2026-09-24 10:00:00', $current['data']['items'][0]['created_at'] );
		$this->assertSame( 'Luis Gómez', $current['data']['items'][0]['requester'] );

		$this->assertSame( array( $pending ), array_column( $this->call( 'list', array( 'group_id' => $this->mine, 'status' => 'pending' ) )['data']['items'], 'id' ) );
		$this->assertSame( array(), $this->call( 'list', array( 'group_id' => $this->mine, 'q' => 'Pedro' ) )['data']['items'] );
		$this->assertSame( array( $pending ), array_column( $this->call( 'list', array( 'group_id' => $this->mine, 'status' => 'pending', 'q' => 'Pedro' ) )['data']['items'], 'id' ) );
	}

	/**
	 * The list puts the urgent ones first, then the most recent requests.
	 */
	public function test_list_order(): void {
		$old           = $this->make_active( $this->mine, array( 'asked_on' => '2026-09-01', 'type' => 'deceased', 'reference_date' => '2026-09-01' ) );
		$urgent        = $this->make_active( $this->mine, array( 'asked_on' => '2026-09-05', 'priority' => 'high' ) );
		$recent        = $this->make_active( $this->mine, array( 'asked_on' => '2026-09-20' ) );
		$middle        = $this->make_active( $this->mine, array( 'asked_on' => '2026-09-10', 'type' => 'thanksgiving' ) );
		$urgent_recent = $this->make_active( $this->mine, array( 'asked_on' => '2026-09-15', 'priority' => 'high' ) );

		$items = $this->call( 'list', array( 'group_id' => $this->mine ) )['data']['items'];
		$this->assertSame( array( $urgent_recent, $urgent, $recent, $middle, $old ), array_column( $items, 'id' ) );
	}

	/**
	 * One intention with its updates, for its screen in the app.
	 */
	public function test_get(): void {
		$id = $this->make_active( $this->mine, array( 'summary' => 'por su salud' ) );
		DFXPRAY_Updates::get_instance()->add( $id, 'Sigue ingresada.' );

		$result = $this->call( 'get', array( 'intention_id' => $id ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $id, $result['data']['intention']['id'] );
		$this->assertSame( 'Carmen, madre de Luis: por su salud', $result['data']['line'] );
		$this->assertSame( 'Sigue ingresada.', $result['data']['updates'][0]['body'] );
		$this->assertSame( '', $result['data']['ask'] );

		DFXPRAY_Intentions::get_instance()->update( $id, array( 'requester_phone' => '600998877' ) );
		$this->assertStringStartsWith( 'https://wa.me/34600998877?text=', $this->call( 'get', array( 'intention_id' => $id ) )['data']['ask'] );
	}

	/**
	 * Reading another group's data is refused.
	 */
	public function test_other_group_cannot_be_read(): void {
		$id = $this->make_active( $this->other );

		$this->assertSame( 'forbidden', $this->call( 'index', array( 'group_id' => $this->other ) )['code'] );
		$this->assertSame( 'forbidden', $this->call( 'list', array( 'group_id' => $this->other ) )['code'] );
		$this->assertSame( 'forbidden', $this->call( 'get', array( 'intention_id' => $id ) )['code'] );
	}

	/**
	 * Actions go through the panel's actions, with their checks.
	 */
	public function test_actions(): void {
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
					'body'                => 'Tratamiento.',
					'summary'             => 'problemas de salud',
				),
			)
		);
		$this->assertTrue( $created['ok'], $created['message'] );
		$id = $created['id'];

		$this->assertTrue( $this->call( 'add_update', array( 'intention_id' => $id, 'body' => 'Mejor.' ) )['ok'] );
		$this->assertTrue( $this->call( 'close', array( 'intention_id' => $id, 'reason' => 'expired' ) )['ok'] );
		$this->assertTrue( $this->call( 'reopen', array( 'intention_id' => $id ) )['ok'] );
		$this->assertSame( 'active', DFXPRAY_Intentions::get_instance()->get( $id )['status'] );

		$this->assertSame( 'unknown', $this->call( 'delete_group', array( 'group_id' => $this->mine ) )['code'] );
	}

	/**
	 * IDOR: every write on another group's intention is refused.
	 *
	 * @return array
	 */
	public function provide_writes(): array {
		return array(
			array( 'save_intention', array( 'intention' => array( 'summary' => 'x' ) ) ),
			array( 'add_update', array( 'body' => 'x' ) ),
			array( 'close', array( 'reason' => 'expired' ) ),
			array( 'reopen', array() ),
			array( 'keep_active', array() ),
			array( 'approve', array( 'summary' => 'x' ) ),
			array( 'discard', array( 'reason' => 'spam' ) ),
			array( 'set_priority', array( 'priority' => 'high' ) ),
			array( 'postpone', array( 'date' => '2026-12-01' ) ),
		);
	}

	/**
	 * IDOR on writes.
	 *
	 * @dataProvider provide_writes
	 *
	 * @param string $op      Operation.
	 * @param array  $request Extra fields.
	 */
	public function test_other_group_cannot_be_written( string $op, array $request ): void {
		$id     = $this->make_active( $this->other );
		$before = DFXPRAY_Intentions::get_instance()->get( $id );

		$result = $this->call( $op, array_merge( array( 'intention_id' => $id ), $request ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'forbidden', $result['code'] );
		$this->assertEquals( $before, DFXPRAY_Intentions::get_instance()->get( $id ) );
	}
}
