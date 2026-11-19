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
		return $this->api->respond(
			array_merge(
				array(
					'op'     => $op,
					'_nonce' => wp_create_nonce( DFXPRAY_App_API::NONCE ),
				),
				$request
			)
		);
	}

	/**
	 * Without a valid nonce nothing is answered.
	 */
	public function test_nonce_required(): void {
		$result = $this->api->respond(
			array(
				'op'     => 'bootstrap',
				'_nonce' => 'bogus',
			)
		);

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
	 * An administrator's app shows and reaches only the groups they lead.
	 */
	public function test_admin_app_is_scoped_to_groups_they_lead(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		DFXPRAY_Groups::get_instance()->set_leaders( $this->mine, array( $this->leader, $admin ) );
		$id = $this->make_active( $this->other );
		wp_set_current_user( $admin );

		$this->assertSame( array( $this->mine ), array_column( $this->call( 'bootstrap' )['data']['groups'], 'id' ) );
		$this->assertTrue( $this->call( 'index', array( 'group_id' => $this->mine ) )['ok'] );
		$this->assertSame( 'forbidden', $this->call( 'index', array( 'group_id' => $this->other ) )['code'] );
		$this->assertSame( 'forbidden', $this->call( 'add_update', array( 'intention_id' => $id, 'body' => 'x' ) )['code'] );
		$this->assertSame( array(), DFXPRAY_Updates::get_instance()->for_intention( $id ) );
	}

	/**
	 * The message of the day is the group's index.
	 */
	public function test_index(): void {
		$this->make_active( $this->mine, array( 'summary' => 'por su salud' ) );

		$result = $this->call( 'index', array( 'group_id' => $this->mine ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( DFXPRAY_Index_Composer::get_instance()->compose( $this->mine ), $result['data']['text'] );

		$monthly = $this->call(
			'index',
			array(
				'group_id' => $this->mine,
				'edition'  => 'monthly',
			)
		);
		$this->assertSame( DFXPRAY_Index_Composer::get_instance()->compose( $this->mine, 'monthly' ), $monthly['data']['text'] );
	}

	/**
	 * Lists by state, with the line the index would show, and a search.
	 */
	public function test_list(): void {
		$active  = $this->make_active(
			$this->mine,
			array(
				'beneficiary_name' => 'Carmen Ruiz',
				'summary'          => 'por su salud',
			)
		);
		$pending = $this->make_pending( $this->mine, array( 'beneficiary_name' => 'Pedro Gil' ) );
		$this->make_active( $this->other );

		$current = $this->call( 'list', array( 'group_id' => $this->mine ) );
		$this->assertSame( array( $active ), array_column( $current['data']['items'], 'id' ) );
		$this->assertSame( 'Carmen, madre de Luis: por su salud', $current['data']['items'][0]['line'] );
		$this->assertSame( '2026-09-24 10:00:00', $current['data']['items'][0]['created_at'] );
		$this->assertSame( 'Luis Gómez', $current['data']['items'][0]['requester'] );

		$this->assertSame(
			array( $pending ),
			array_column(
				$this->call(
					'list',
					array(
						'group_id' => $this->mine,
						'status'   => 'pending',
					)
				)['data']['items'],
				'id'
			)
		);
		$this->assertSame(
			array(),
			$this->call(
				'list',
				array(
					'group_id' => $this->mine,
					'q'        => 'Pedro',
				)
			)['data']['items']
		);
		$this->assertSame(
			array( $pending ),
			array_column(
				$this->call(
					'list',
					array(
						'group_id' => $this->mine,
						'status'   => 'pending',
						'q'        => 'Pedro',
					)
				)['data']['items'],
				'id'
			)
		);
	}

	/**
	 * "To ask": the intentions marked to ask about and those due for review.
	 */
	public function test_list_to_ask(): void {
		$marked = $this->make_active( $this->mine, array( 'to_ask' => '1' ) );
		$due    = $this->make_active(
			$this->mine,
			array(
				'type'           => 'event',
				'reference_date' => '2026-09-01',
			)
		);
		DFXPRAY_Intentions::get_instance()->process_due();
		$this->make_active( $this->mine );
		$this->make_active( $this->other, array( 'to_ask' => '1' ) );

		$items = $this->call(
			'list',
			array(
				'group_id' => $this->mine,
				'status'   => 'ask',
			)
		)['data']['items'];

		$this->assertEqualsCanonicalizing( array( $marked, $due ), array_column( $items, 'id' ) );
		$this->assertArrayHasKey( 'ask', $items[0] );
	}

	/**
	 * "To ask" also comes grouped by requester, with one question for all.
	 */
	public function test_to_ask_grouped_by_requester(): void {
		$first  = $this->make_active(
			$this->mine,
			array(
				'requester_name'   => 'Titón Alonso',
				'requester_phone'  => '606453787',
				'beneficiary_name' => 'Juan Pablo',
				'to_ask'           => '1',
			)
		);
		$second = $this->make_active(
			$this->mine,
			array(
				'requester_name'   => 'Titón Alonso',
				'beneficiary_name' => 'Beatriz',
				'to_ask'           => '1',
			)
		);
		$other  = $this->make_active(
			$this->mine,
			array(
				'requester_name' => 'Ramón García',
				'to_ask'         => '1',
			)
		);

		$groups = $this->call(
			'list',
			array(
				'group_id' => $this->mine,
				'status'   => 'ask',
			)
		)['data']['groups'];

		$this->assertCount( 2, $groups );
		$titon = current( array_filter( $groups, static fn( $g ) => 'Titón Alonso' === $g['requester'] ) );
		$this->assertEqualsCanonicalizing( array( $first, $second ), $titon['ids'] );
		$this->assertStringContainsString( 'Juan%20Pablo', $titon['ask'] );
		$this->assertStringContainsString( 'Beatriz', $titon['ask'] );
		$ramon = current( array_filter( $groups, static fn( $g ) => 'Ramón García' === $g['requester'] ) );
		$this->assertSame( array( $other ), $ramon['ids'] );
		$this->assertSame( '', $ramon['ask'] );
	}

	/**
	 * The list puts the urgent ones first, then the most recent requests.
	 */
	public function test_list_order(): void {
		$old           = $this->make_active(
			$this->mine,
			array(
				'asked_on'       => '2026-09-01',
				'type'           => 'deceased',
				'reference_date' => '2026-09-01',
			)
		);
		$urgent        = $this->make_active(
			$this->mine,
			array(
				'asked_on' => '2026-09-05',
				'priority' => 'high',
			)
		);
		$recent        = $this->make_active( $this->mine, array( 'asked_on' => '2026-09-20' ) );
		$middle        = $this->make_active(
			$this->mine,
			array(
				'asked_on' => '2026-09-10',
				'type'     => 'thanksgiving',
			)
		);
		$urgent_recent = $this->make_active(
			$this->mine,
			array(
				'asked_on' => '2026-09-15',
				'priority' => 'high',
			)
		);

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
	 * The line an intention would show, while it is being written: a new
	 * one, or the changes to one already saved, without saving anything.
	 */
	public function test_preview(): void {
		$result = $this->call(
			'preview',
			array(
				'group_id'  => $this->mine,
				'intention' => array(
					'requester_name'   => 'Felipe Arizu',
					'beneficiary_name' => 'Marina',
					'relationship'     => 'esposa de',
					'type'             => 'ongoing',
					'body'             => 'Tratamiento.',
					'summary'          => 'problemas de salud',
				),
			)
		);
		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'Marina, esposa de Felipe: problemas de salud', $result['data']['line'] );
		$this->assertSame( array(), DFXPRAY_Intentions::get_instance()->query( $this->mine, array() ) );

		$id     = $this->make_active( $this->mine, array( 'summary' => 'por su salud' ) );
		$result = $this->call(
			'preview',
			array(
				'intention_id' => $id,
				'intention'    => array( 'summary' => 'ya en casa' ),
			)
		);
		$this->assertSame( 'Carmen, madre de Luis: ya en casa', $result['data']['line'] );
		$this->assertSame( 'por su salud', DFXPRAY_Intentions::get_instance()->get( $id )['summary'] );

		$result = $this->call(
			'preview',
			array(
				'intention_id' => $id,
				'intention'    => array(
					'type'           => 'deceased',
					'reference_date' => '',
				),
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid', $result['code'] );
	}

	/**
	 * The members of a group, by their next birthday or anniversary.
	 */
	public function test_members(): void {
		DFXPRAY_Members::get_instance()->create(
			$this->mine,
			array(
				'name'        => 'Teo Martín',
				'phone'       => '600 11 22 33',
				'birth_day'   => '30',
				'birth_month' => '9',
				'notes'       => 'XI Retiro - Mesa 1',
			)
		);
		DFXPRAY_Members::get_instance()->create(
			$this->mine,
			array(
				'name'        => 'Luis Gómez',
				'birth_day'   => '25',
				'birth_month' => '9',
			)
		);

		$result = $this->call( 'members', array( 'group_id' => $this->mine ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'Luis Gómez', 'Teo Martín' ), array_column( $result['data']['members'], 'name' ) );
		$this->assertSame( 'XI Retiro - Mesa 1', $result['data']['members'][1]['notes'] );
		$this->assertSame( 'https://wa.me/34600112233', $result['data']['members'][1]['whatsapp'] );
	}

	/**
	 * Reading another group's data is refused.
	 */
	public function test_other_group_cannot_be_read(): void {
		$id = $this->make_active( $this->other );

		$this->assertSame( 'forbidden', $this->call( 'index', array( 'group_id' => $this->other ) )['code'] );
		$this->assertSame( 'forbidden', $this->call( 'list', array( 'group_id' => $this->other ) )['code'] );
		$this->assertSame( 'forbidden', $this->call( 'get', array( 'intention_id' => $id ) )['code'] );
		$this->assertSame( 'forbidden', $this->call( 'members', array( 'group_id' => $this->other ) )['code'] );
		$this->assertSame( 'forbidden', $this->call( 'digest', array( 'group_id' => $this->other ) )['code'] );
		$this->assertSame( 'forbidden', $this->call( 'send_digest_now', array( 'group_id' => $this->other ) )['code'] );
		$this->assertNull( DFXPRAY_Scheduler::get_instance()->get_stored_digest( $this->other ) );
		$this->assertSame(
			'forbidden',
			$this->call(
				'preview',
				array(
					'group_id'  => $this->other,
					'intention' => array( 'summary' => 'x' ),
				)
			)['code']
		);
		$this->assertSame(
			'forbidden',
			$this->call(
				'preview',
				array(
					'group_id'     => $this->mine,
					'intention_id' => $id,
					'intention'    => array( 'summary' => 'x' ),
				)
			)['code']
		);
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

		$this->assertTrue(
			$this->call(
				'add_update',
				array(
					'intention_id' => $id,
					'body'         => 'Mejor.',
				)
			)['ok']
		);
		$this->assertTrue(
			$this->call(
				'close',
				array(
					'intention_id' => $id,
					'reason'       => 'expired',
				)
			)['ok']
		);
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
