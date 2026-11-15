<?php
/**
 * Tests for the intentions service.
 *
 * @package DFXPRAY
 */

/**
 * Intentions behaviour.
 */
class IntentionsTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * Service under test.
	 *
	 * @var DFXPRAY_Intentions
	 */
	private DFXPRAY_Intentions $intentions;

	/**
	 * A group.
	 *
	 * @var int
	 */
	private int $group;

	/**
	 * A leader of the group.
	 *
	 * @var int
	 */
	private int $leader;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		$this->freeze_time( '2026-09-24 10:00' );
		$this->intentions = DFXPRAY_Intentions::get_instance();
		$this->leader     = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );
		$this->group      = $this->make_group( 'A', array( $this->leader ) );
		wp_set_current_user( $this->leader );
	}

	/**
	 * Form submissions enter as pending, with first-name discretion by default.
	 */
	public function test_form_submission_is_pending(): void {
		$data = $this->form_data();
		unset( $data['discretion'] );

		$intention = $this->intentions->get( $this->make_pending( $this->group, $data ) );

		$this->assertSame( 'pending', $intention['status'] );
		$this->assertSame( 'form', $intention['source'] );
		$this->assertSame( 'first_name', $intention['discretion'] );
		$this->assertSame( 'normal', $intention['priority'] );
		$this->assertSame( $this->group, $intention['group_id'] );
		$this->assertSame( '', $intention['summary'] );
		$this->assertNull( $intention['next_review'] );
		$this->assertFalse( $intention['ack_sent'] );
	}

	/**
	 * The form cannot set leader-only fields.
	 */
	public function test_form_cannot_set_leader_fields(): void {
		$id = $this->make_pending(
			$this->group,
			array(
				'summary'                => 'Injected',
				'priority'               => 'high',
				'requester_display_name' => 'X',
				'requester_first_name'   => 'Luis',
			)
		);

		$intention = $this->intentions->get( $id );
		$this->assertSame( '', $intention['summary'] );
		$this->assertSame( 'normal', $intention['priority'] );
		$this->assertSame( '', $intention['requester_display_name'] );
		$this->assertSame( '', $intention['requester_first_name'] );
	}

	/**
	 * Manual creation is active at once, with a review date.
	 */
	public function test_manual_creation_is_active(): void {
		$intention = $this->intentions->get( $this->make_active( $this->group ) );

		$this->assertSame( 'active', $intention['status'] );
		$this->assertSame( 'manual', $intention['source'] );
		$this->assertSame( '2026-10-24', $intention['next_review'] );
		$this->assertSame( '2026-09-24 10:00:00', $intention['activated_at'] );
	}

	/**
	 * Manual creation needs a summary.
	 */
	public function test_manual_creation_requires_summary(): void {
		$result = $this->intentions->create( $this->group, $this->form_data(), 'manual' );

		$this->assertWPError( $result );
		$this->assertSame( 'dfxpray_summary_required', $result->get_error_code() );
	}

	/**
	 * Required fields.
	 *
	 * @return array
	 */
	public function provide_invalid_data(): array {
		return array(
			'no requester'          => array( array( 'requester_name' => '' ), 'dfxpray_requester_required' ),
			'no beneficiary'        => array( array( 'beneficiary_name' => '' ), 'dfxpray_beneficiary_required' ),
			'no text'               => array( array( 'body' => ' ' ), 'dfxpray_body_required' ),
			'bad type'              => array( array( 'type' => 'party' ), 'dfxpray_type_invalid' ),
			'event without date'    => array( array( 'type' => 'event' ), 'dfxpray_date_required' ),
			'deceased without date' => array( array( 'type' => 'deceased' ), 'dfxpray_date_required' ),
			'bad date'              => array( array( 'type' => 'event', 'reference_date' => '2026-02-31' ), 'dfxpray_date_invalid' ),
			'bad time'              => array( array( 'type' => 'event', 'reference_date' => '2026-09-28', 'reference_time' => '9h' ), 'dfxpray_time_invalid' ),
			'bad discretion'        => array( array( 'discretion' => 'public' ), 'dfxpray_discretion_invalid' ),
			'long summary'          => array( array( 'summary' => str_repeat( 'a', 101 ) ), 'dfxpray_summary_too_long' ),
		);
	}

	/**
	 * Invalid data is refused with a precise error.
	 *
	 * @dataProvider provide_invalid_data
	 *
	 * @param array  $overrides Overrides.
	 * @param string $code      Expected error code.
	 */
	public function test_validation( array $overrides, string $code ): void {
		$result = $this->intentions->create( $this->group, $this->form_data( $overrides ), 'manual' );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * "For me" copies the requester into the beneficiary.
	 */
	public function test_for_myself(): void {
		$id = $this->make_pending(
			$this->group,
			array(
				'beneficiary_is_requester' => '1',
				'beneficiary_name'         => '',
				'relationship'             => 'ignored',
			)
		);

		$intention = $this->intentions->get( $id );
		$this->assertTrue( $intention['beneficiary_is_requester'] );
		$this->assertSame( 'Luis Gómez', $intention['beneficiary_name'] );
		$this->assertSame( '', $intention['relationship'] );
	}

	/**
	 * A time is kept only for events.
	 */
	public function test_time_only_for_events(): void {
		$event    = $this->make_pending( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-28', 'reference_time' => '09:30' ) );
		$deceased = $this->make_pending( $this->group, array( 'type' => 'deceased', 'reference_date' => '2026-09-20', 'reference_time' => '09:30' ) );

		$this->assertSame( '09:30', $this->intentions->get( $event )['reference_time'] );
		$this->assertNull( $this->intentions->get( $deceased )['reference_time'] );
	}

	/**
	 * Key dates are stored as a sorted list and invalid ones dropped.
	 */
	public function test_key_dates(): void {
		$id = $this->make_active(
			$this->group,
			array(
				'type'           => 'deceased',
				'reference_date' => '2026-09-20',
				'key_dates'      => array(
					array( 'date' => '2026-10-20', 'label' => 'Misa de mes' ),
					array( 'date' => '2026-09-22', 'time' => '17:00', 'label' => 'Funeral' ),
					array( 'date' => 'nope', 'label' => 'Bad' ),
					array( 'date' => '2026-09-23', 'label' => '' ),
				),
			)
		);

		$this->assertSame(
			array(
				array( 'date' => '2026-09-22', 'time' => '17:00', 'label' => 'Funeral' ),
				array( 'date' => '2026-10-20', 'time' => '', 'label' => 'Misa de mes' ),
			),
			$this->intentions->get( $id )['key_dates']
		);
	}

	/**
	 * Approving needs a summary and activates the intention.
	 */
	public function test_approve(): void {
		$id = $this->make_pending( $this->group );

		$this->assertWPError( $this->intentions->approve( $id ) );
		$this->assertTrue( $this->intentions->approve( $id, array( 'summary' => 'Por su salud' ) ) );

		$intention = $this->intentions->get( $id );
		$this->assertSame( 'active', $intention['status'] );
		$this->assertSame( 'Por su salud', $intention['summary'] );
		$this->assertSame( '2026-10-24', $intention['next_review'] );
		$this->assertSame( 'intention_approved', DFXPRAY_Log::get_instance()->for_intention( $id )[0]['action'] );
		$this->assertSame( $this->leader, DFXPRAY_Log::get_instance()->for_intention( $id )[0]['user_id'] );
	}

	/**
	 * Approving an already active intention is refused.
	 */
	public function test_approve_twice_is_refused(): void {
		$id = $this->make_active( $this->group );

		$this->assertWPError( $this->intentions->approve( $id, array( 'summary' => 'x' ) ) );
	}

	/**
	 * Discarding a pending intention.
	 */
	public function test_discard(): void {
		$id = $this->make_pending( $this->group );

		$this->assertTrue( $this->intentions->discard( $id, 'spam' ) );

		$intention = $this->intentions->get( $id );
		$this->assertSame( 'discarded', $intention['status'] );
		$this->assertSame( 'spam', $intention['close_reason'] );
		$this->assertSame( '2026-09-24 10:00:00', $intention['closed_at'] );
	}

	/**
	 * Merging a duplicate keeps its text as a comment on the existing intention.
	 */
	public function test_merge_duplicate(): void {
		$existing  = $this->make_active( $this->group );
		$duplicate = $this->make_pending( $this->group, array( 'body' => 'Same request again.', 'requester_name' => 'Ana' ) );

		$this->assertTrue( $this->intentions->merge( $duplicate, $existing ) );

		$merged = $this->intentions->get( $duplicate );
		$this->assertSame( 'discarded', $merged['status'] );
		$this->assertSame( 'duplicate', $merged['close_reason'] );
		$this->assertSame( $existing, $merged['linked_id'] );

		$comments = DFXPRAY_Comments::get_instance()->for_intention( $existing );
		$this->assertCount( 1, $comments );
		$this->assertStringContainsString( 'Same request again.', $comments[0]['body'] );
		$this->assertStringContainsString( 'Ana', $comments[0]['body'] );
	}

	/**
	 * Merging across groups is refused.
	 */
	public function test_merge_across_groups_is_refused(): void {
		$other     = $this->make_group( 'B', array( $this->leader ) );
		$existing  = $this->make_active( $other );
		$duplicate = $this->make_pending( $this->group );

		$this->assertWPError( $this->intentions->merge( $duplicate, $existing ) );
	}

	/**
	 * Due intentions move to review; others stay.
	 */
	public function test_process_due_moves_to_review(): void {
		$due      = $this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-22' ) );
		$not_due  = $this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-23' ) );
		$pending  = $this->make_pending( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-01' ) );

		$this->assertSame( 1, $this->intentions->process_due() );

		$this->assertSame( 'review', $this->intentions->get( $due )['status'] );
		$this->assertSame( 'active', $this->intentions->get( $not_due )['status'] );
		$this->assertSame( 'pending', $this->intentions->get( $pending )['status'] );
		$this->assertSame( 0, DFXPRAY_Log::get_instance()->for_intention( $due )[0]['user_id'] );
	}

	/**
	 * The deceased close themselves when their month is over, also those
	 * already waiting in review; the rest still go to review.
	 */
	public function test_deceased_close_themselves(): void {
		$recent  = $this->make_active( $this->group, array( 'type' => 'deceased', 'reference_date' => '2026-09-10' ) );
		$event   = $this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-01' ) );
		$waiting = $this->make_active( $this->group, array( 'type' => 'deceased', 'reference_date' => '2026-08-01' ) );
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'deceased_auto_close' => false ) );
		$this->intentions->process_due();
		$this->assertSame( 'review', $this->intentions->get( $waiting )['status'] );
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'deceased_auto_close' => true ) );
		$due = $this->make_active( $this->group, array( 'type' => 'deceased', 'reference_date' => '2026-08-20' ) );

		$this->intentions->process_due();

		foreach ( array( $due, $waiting ) as $id ) {
			$intention = $this->intentions->get( $id );
			$this->assertSame( 'closed', $intention['status'] );
			$this->assertSame( 'expired', $intention['close_reason'] );
		}
		$this->assertSame( 'active', $this->intentions->get( $recent )['status'] );
		$this->assertSame( 'review', $this->intentions->get( $event )['status'] );
	}

	/**
	 * "Still current" reactivates with a new review date.
	 */
	public function test_keep_active(): void {
		$id = $this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-01' ) );
		$this->intentions->process_due();

		$this->assertTrue( $this->intentions->keep_active( $id ) );

		$intention = $this->intentions->get( $id );
		$this->assertSame( 'active', $intention['status'] );
		$this->assertSame( '2026-10-24', $intention['next_review'] );
	}

	/**
	 * Closing records the reason.
	 */
	public function test_close_with_reason(): void {
		$id = $this->make_active( $this->group );

		$this->assertWPError( $this->intentions->close( $id, 'bored' ) );
		$this->assertTrue( $this->intentions->close( $id, 'withdrawn' ) );

		$intention = $this->intentions->get( $id );
		$this->assertSame( 'closed', $intention['status'] );
		$this->assertSame( 'withdrawn', $intention['close_reason'] );
		$this->assertSame( '2026-09-24 10:00:00', $intention['closed_at'] );
	}

	/**
	 * Closing as answered can spawn a linked thanksgiving.
	 */
	public function test_close_answered_spawns_thanksgiving(): void {
		$id = $this->make_active(
			$this->group,
			array(
				'discretion'             => 'initials',
				'summary'                => 'Busca trabajo',
				'beneficiary_name'       => 'Juan Tomás Rubio',
				'beneficiary_first_name' => 'Juan Tomás',
			)
		);

		$new_id = $this->intentions->close( $id, 'answered', true, 'Por el trabajo encontrado' );

		$this->assertIsInt( $new_id );
		$thanks = $this->intentions->get( $new_id );
		$this->assertSame( 'thanksgiving', $thanks['type'] );
		$this->assertSame( 'active', $thanks['status'] );
		$this->assertSame( 'Por el trabajo encontrado', $thanks['summary'] );
		$this->assertSame( $id, $thanks['linked_id'] );
		$this->assertSame( 'initials', $thanks['discretion'] );
		$this->assertSame( 'Juan Tomás', $thanks['beneficiary_first_name'] );
		$this->assertSame( '2026-09-24', $thanks['reference_date'] );
		$this->assertSame( '2026-10-01', $thanks['next_review'] );
		$this->assertSame( $new_id, $this->intentions->get( $id )['linked_id'] );
	}

	/**
	 * A deceased intention cannot spawn a thanksgiving.
	 */
	public function test_deceased_cannot_spawn_thanksgiving(): void {
		$id = $this->make_active( $this->group, array( 'type' => 'deceased', 'reference_date' => '2026-09-20' ) );

		$this->assertTrue( $this->intentions->close( $id, 'expired', true ) );
	}

	/**
	 * Closed intentions cannot be reopened.
	 */
	public function test_closed_is_final(): void {
		$id = $this->make_active( $this->group );
		$this->intentions->close( $id, 'answered' );

		$this->assertWPError( $this->intentions->keep_active( $id ) );
		$this->assertWPError( $this->intentions->close( $id, 'answered' ) );
	}

	/**
	 * A closed intention can be reopened by mistake: back to active, with a
	 * new review date, the closing data cleared and the history kept.
	 */
	public function test_reopen(): void {
		$id = $this->make_active( $this->group );
		$this->intentions->close( $id, 'expired' );

		$this->assertTrue( $this->intentions->reopen( $id ) );

		$intention = $this->intentions->get( $id );
		$this->assertSame( 'active', $intention['status'] );
		$this->assertSame( '', $intention['close_reason'] );
		$this->assertNull( $intention['closed_at'] );
		$this->assertSame( '2026-10-24', $intention['next_review'] );
		$this->assertSame( 'intention_reopened', DFXPRAY_Log::get_instance()->for_intention( $id )[0]['action'] );

		$this->assertWPError( $this->intentions->reopen( $id ) );

		$discarded = $this->make_pending( $this->group );
		$this->intentions->discard( $discarded, 'spam' );
		$this->assertWPError( $this->intentions->reopen( $discarded ) );
	}

	/**
	 * Leaders can harden discretion but not relax it.
	 */
	public function test_update_discretion_hardening(): void {
		$id = $this->make_active( $this->group, array( 'discretion' => 'initials' ) );

		$this->assertWPError( $this->intentions->update( $id, array( 'discretion' => 'first_name' ) ) );
		$this->assertTrue( $this->intentions->update( $id, array( 'discretion' => 'anonymous' ) ) );
		$this->assertSame( 'anonymous', $this->intentions->get( $id )['discretion'] );
	}

	/**
	 * Visible names are validated against the level and logged.
	 */
	public function test_update_visible_names(): void {
		$id = $this->make_active( $this->group, array( 'beneficiary_name' => 'David Marín' ) );

		$this->assertWPError( $this->intentions->update( $id, array( 'beneficiary_display_name' => 'Nuestro hermano David Marín' ) ) );
		$this->assertTrue( $this->intentions->update( $id, array( 'beneficiary_display_name' => 'Nuestro hermano David' ) ) );

		$this->assertSame( 'Nuestro hermano David', $this->intentions->get( $id )['beneficiary_display_name'] );

		$entry = DFXPRAY_Log::get_instance()->for_intention( $id )[0];
		$this->assertSame( 'visible_name_changed', $entry['action'] );
		$this->assertSame( 'beneficiary', $entry['details']['person'] );
		$this->assertSame( '', $entry['details']['old'] );
		$this->assertSame( 'Nuestro hermano David', $entry['details']['new'] );
	}

	/**
	 * Leaders set a compound first name by hand; it must open the name.
	 */
	public function test_update_first_names(): void {
		$id = $this->make_active( $this->group, array( 'beneficiary_name' => 'Juan Tomás Rubio' ) );

		$error = $this->intentions->update( $id, array( 'beneficiary_first_name' => 'Tomás' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'dfxpray_first_name_invalid', $error->get_error_code() );

		$this->assertTrue( $this->intentions->update( $id, array( 'beneficiary_first_name' => 'Juan Tomás' ) ) );
		$intention = $this->intentions->get( $id );
		$this->assertSame( 'Juan Tomás', $intention['beneficiary_first_name'] );
		$this->assertStringStartsWith( 'Juan Tomás, ', DFXPRAY_Discretion::get_instance()->describe( $intention ) );

		$this->assertWPError( $this->intentions->update( $id, array( 'beneficiary_name' => 'Pedro Rubio' ) ) );
	}

	/**
	 * Leaders may set the day the intention was asked for (one written down
	 * later, or brought from another list); never a day still to come.
	 */
	public function test_asked_on(): void {
		$id = $this->make_active( $this->group );
		$this->assertSame( '2026-09-24 10:00:00', $this->intentions->get( $id )['created_at'] );

		$this->assertTrue( $this->intentions->update( $id, array( 'asked_on' => '2026-09-14' ) ) );
		$this->assertSame( '2026-09-14 10:00:00', $this->intentions->get( $id )['created_at'] );

		$this->assertTrue( $this->intentions->update( $id, array( 'asked_on' => '' ) ) );
		$this->assertSame( '2026-09-14 10:00:00', $this->intentions->get( $id )['created_at'] );

		foreach ( array( '2026-09-25', '2026-02-30', 'ayer' ) as $wrong ) {
			$error = $this->intentions->update( $id, array( 'asked_on' => $wrong ) );
			$this->assertWPError( $error, $wrong );
			$this->assertSame( 'dfxpray_asked_on_invalid', $error->get_error_code() );
		}
		$this->assertSame( '2026-09-14 10:00:00', $this->intentions->get( $id )['created_at'] );

		$manual = $this->make_active( $this->group, array( 'asked_on' => '2026-09-01' ) );
		$this->assertSame( '2026-09-01 10:00:00', $this->intentions->get( $manual )['created_at'] );

		$form = $this->make_pending( $this->group, array_merge( $this->form_data(), array( 'asked_on' => '2026-09-01' ) ) );
		$this->assertSame( '2026-09-24 10:00:00', $this->intentions->get( $form )['created_at'] );
	}

	/**
	 * Lists go from the most recent request; urgent_first puts the urgent ones before.
	 */
	public function test_query_order(): void {
		$old    = $this->make_active( $this->group, array( 'asked_on' => '2026-09-01' ) );
		$urgent = $this->make_active( $this->group, array( 'asked_on' => '2026-09-05', 'priority' => 'high' ) );
		$recent = $this->make_active( $this->group, array( 'asked_on' => '2026-09-20' ) );

		$this->assertSame( array( $recent, $urgent, $old ), array_column( $this->intentions->query( $this->group ), 'id' ) );
		$this->assertSame( array( $urgent, $recent, $old ), array_column( $this->intentions->query( $this->group, array( 'urgent_first' => true ) ), 'id' ) );
	}

	/**
	 * Leaders choose the edition (daily or monthly) and the topic.
	 */
	public function test_scope_and_topic(): void {
		$id = $this->make_active( $this->group );
		$this->assertSame( 'daily', $this->intentions->get( $id )['scope'] );
		$this->assertSame( '', $this->intentions->get( $id )['topic'] );

		$this->assertTrue( $this->intentions->update( $id, array( 'scope' => 'monthly', 'topic' => 'health' ) ) );
		$this->assertSame( 'monthly', $this->intentions->get( $id )['scope'] );
		$this->assertSame( 'health', $this->intentions->get( $id )['topic'] );

		$this->assertSame( 'dfxpray_scope_invalid', $this->intentions->update( $id, array( 'scope' => 'weekly' ) )->get_error_code() );
		$this->assertSame( 'dfxpray_topic_invalid', $this->intentions->update( $id, array( 'topic' => 'sports' ) )->get_error_code() );

		$form = $this->make_pending( $this->group, array_merge( $this->form_data(), array( 'scope' => 'monthly', 'topic' => 'health' ) ) );
		$this->assertSame( 'daily', $this->intentions->get( $form )['scope'] );
		$this->assertSame( '', $this->intentions->get( $form )['topic'] );
	}

	/**
	 * The requester's phone is kept clean and offered again for their next
	 * requests, so leaders can ask them by WhatsApp how it goes.
	 */
	public function test_requester_phone(): void {
		$first = $this->make_active( $this->group, array( 'requester_name' => 'Ramón García', 'requester_phone' => '+34 600 11 22 33' ) );
		$this->assertSame( '+34600112233', $this->intentions->get( $first )['requester_phone'] );

		$next = $this->make_active( $this->group, array( 'requester_name' => 'ramón garcía' ) );
		$this->assertSame( '+34600112233', $this->intentions->get( $next )['requester_phone'] );

		$other_group = $this->make_active( $this->make_group( 'B', array( $this->leader ) ), array( 'requester_name' => 'Ramón García' ) );
		$this->assertSame( '', $this->intentions->get( $other_group )['requester_phone'] );

		$this->assertSame( 'dfxpray_phone_invalid', $this->intentions->update( $first, array( 'requester_phone' => 'call me' ) )->get_error_code() );

		$this->assertTrue( $this->intentions->update( $first, array( 'requester_phone' => '600 99 88 77' ) ) );
		$this->assertSame( '600998877', $this->intentions->get( $first )['requester_phone'] );
	}

	/**
	 * Leaders mark the intentions whose state they must ask about; an update
	 * (the answer) or closing it clears the mark.
	 */
	public function test_to_ask(): void {
		$id = $this->make_active( $this->group );
		$this->assertFalse( $this->intentions->get( $id )['to_ask'] );

		$this->assertTrue( $this->intentions->update( $id, array( 'to_ask' => '1' ) ) );
		$this->assertTrue( $this->intentions->get( $id )['to_ask'] );

		DFXPRAY_Updates::get_instance()->add( $id, 'Ya está en casa.' );
		$this->assertFalse( $this->intentions->get( $id )['to_ask'] );

		$this->intentions->update( $id, array( 'to_ask' => '1' ) );
		$this->intentions->close( $id, 'expired' );
		$this->assertFalse( $this->intentions->get( $id )['to_ask'] );

		$form = $this->make_pending( $this->group, array_merge( $this->form_data(), array( 'to_ask' => '1' ) ) );
		$this->assertFalse( $this->intentions->get( $form )['to_ask'] );
	}

	/**
	 * Praying for oneself shares the requester's first name.
	 */
	public function test_for_myself_shares_the_first_name(): void {
		$id = $this->make_active(
			$this->group,
			array(
				'requester_name'           => 'Juan Tomás Rubio',
				'requester_first_name'     => 'Juan Tomás',
				'beneficiary_is_requester' => '1',
				'beneficiary_name'         => '',
			)
		);

		$this->assertSame( 'Juan Tomás', $this->intentions->get( $id )['beneficiary_first_name'] );
	}

	/**
	 * Leaders mark who belongs to the group; praying for oneself shares it.
	 */
	public function test_member_flags(): void {
		$id = $this->make_active( $this->group, array( 'requester_is_member' => '1' ) );

		$intention = $this->intentions->get( $id );
		$this->assertTrue( $intention['requester_is_member'] );
		$this->assertFalse( $intention['beneficiary_is_member'] );

		$this->assertTrue( $this->intentions->update( $id, array( 'beneficiary_is_member' => '1' ) ) );
		$this->assertTrue( $this->intentions->get( $id )['beneficiary_is_member'] );

		$self = $this->make_active(
			$this->group,
			array(
				'requester_is_member'      => '1',
				'beneficiary_is_requester' => '1',
				'beneficiary_name'         => '',
			)
		);
		$this->assertTrue( $this->intentions->get( $self )['beneficiary_is_member'] );

		$pending = $this->make_pending( $this->group, array( 'requester_is_member' => '1' ) );
		$this->assertFalse( $this->intentions->get( $pending )['requester_is_member'] );
	}

	/**
	 * Visible names are rechecked when the level hardens.
	 */
	public function test_visible_names_cleared_when_level_hides_names(): void {
		$id = $this->make_active( $this->group, array( 'beneficiary_name' => 'David Marín' ) );
		$this->intentions->update( $id, array( 'beneficiary_display_name' => 'Hermano David' ) );

		$this->intentions->update( $id, array( 'discretion' => 'initials' ) );

		$this->assertSame( '', $this->intentions->get( $id )['beneficiary_display_name'] );
	}

	/**
	 * Changing the date of an active event recomputes the review date.
	 */
	public function test_update_date_recomputes_review(): void {
		$id = $this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-28' ) );

		$this->intentions->update( $id, array( 'reference_date' => '2026-10-05' ) );

		$this->assertSame( '2026-10-07', $this->intentions->get( $id )['next_review'] );
	}

	/**
	 * Priority, postponement and acknowledgement.
	 */
	public function test_quick_actions(): void {
		$id = $this->make_active( $this->group );

		$this->assertTrue( $this->intentions->set_priority( $id, 'high' ) );
		$this->assertWPError( $this->intentions->set_priority( $id, 'urgent' ) );
		$this->assertTrue( $this->intentions->postpone( $id, '2026-12-01' ) );
		$this->assertWPError( $this->intentions->postpone( $id, '2026-09-01' ) );
		$this->assertTrue( $this->intentions->mark_ack_sent( $id ) );

		$intention = $this->intentions->get( $id );
		$this->assertSame( 'high', $intention['priority'] );
		$this->assertSame( '2026-12-01', $intention['next_review'] );
		$this->assertTrue( $intention['ack_sent'] );

		$comments = DFXPRAY_Comments::get_instance()->for_intention( $id );
		$this->assertCount( 1, $comments );
		$this->assertSame( 'contact', $comments[0]['type'] );
		$this->assertSame( $this->leader, $comments[0]['author_id'] );
	}

	/**
	 * Marking the acknowledgement twice adds a single comment.
	 */
	public function test_ack_is_idempotent(): void {
		$id = $this->make_active( $this->group );

		$this->intentions->mark_ack_sent( $id );
		$this->intentions->mark_ack_sent( $id );

		$this->assertCount( 1, DFXPRAY_Comments::get_instance()->for_intention( $id ) );
	}

	/**
	 * Transfer requires managing both groups and is logged in both.
	 */
	public function test_transfer(): void {
		$b  = $this->make_group( 'B', array( $this->leader ) );
		$c  = $this->make_group( 'C' );
		$id = $this->make_active( $this->group );

		$this->assertWPError( $this->intentions->transfer( $id, $c ) );
		$this->assertTrue( $this->intentions->transfer( $id, $b ) );

		$this->assertSame( $b, $this->intentions->get( $id )['group_id'] );
		$this->assertCount( 1, DFXPRAY_Log::get_instance()->for_group( $this->group, array( 'intention_transferred_out' ) ) );
		$this->assertCount( 1, DFXPRAY_Log::get_instance()->for_group( $b, array( 'intention_transferred_in' ) ) );
	}

	/**
	 * Listing filters.
	 */
	public function test_query_filters(): void {
		$a = $this->make_active( $this->group, array( 'beneficiary_name' => 'Carmen' ) );
		$b = $this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-30', 'beneficiary_name' => 'Pablo' ) );
		$this->intentions->set_priority( $b, 'high' );
		$c     = $this->make_pending( $this->group );
		$other = $this->make_active( $this->make_group( 'B' ) );

		$ids = fn( $args ) => array_column( $this->intentions->query( $this->group, $args ), 'id' );

		$this->assertEqualsCanonicalizing( array( $a, $b, $c ), $ids( array() ) );
		$this->assertSame( array( $c ), $ids( array( 'status' => 'pending' ) ) );
		$this->assertSame( array( $b ), $ids( array( 'type' => 'event' ) ) );
		$this->assertSame( array( $b ), $ids( array( 'priority' => 'high' ) ) );
		$this->assertSame( array( $b ), $ids( array( 'search' => 'pab' ) ) );
		$this->assertSame( array( $b ), $ids( array( 'date_from' => '2026-09-29' ) ) );
		$this->assertNotContains( $other, $ids( array() ) );
	}

	/**
	 * Home counters.
	 */
	public function test_counts(): void {
		$this->make_pending( $this->group );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-24' ) );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25' ) );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25' ) );
		$due = $this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-01' ) );
		$this->intentions->process_due();

		$this->assertSame(
			array(
				'pending'  => 1,
				'review'   => 1,
				'today'    => 1,
				'tomorrow' => 2,
			),
			$this->intentions->counts( $this->group )
		);
	}

	/**
	 * Deleting cascades to updates, comments and log.
	 */
	public function test_delete_cascades(): void {
		$id = $this->make_active( $this->group );
		DFXPRAY_Updates::get_instance()->add( $id, 'Update' );
		DFXPRAY_Comments::get_instance()->add( $id, 'Comment' );

		$this->intentions->delete( array( $id ) );

		$this->assertNull( $this->intentions->get( $id ) );
		$this->assertSame( array(), DFXPRAY_Updates::get_instance()->for_intention( $id ) );
		$this->assertSame( array(), DFXPRAY_Comments::get_instance()->for_intention( $id ) );
		$this->assertSame( array(), DFXPRAY_Log::get_instance()->for_intention( $id ) );
	}
}
