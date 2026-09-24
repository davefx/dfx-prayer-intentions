<?php
/**
 * Tests for the audit log.
 *
 * @package DFXPRAY
 */

/**
 * Audit log behaviour.
 */
class LogTest extends DFXPRAY_Test_Case {

	/**
	 * Entries record author, date, action and details.
	 */
	public function test_add_records_author_date_and_details(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$this->freeze_time( '2026-09-24 10:00:00' );

		$log = DFXPRAY_Log::get_instance();
		$id  = $log->add( 'intention_approved', 3, 7, array( 'summary' => 'x' ) );

		$entry = $log->get( $id );
		$this->assertSame( 'intention_approved', $entry['action'] );
		$this->assertSame( 3, $entry['group_id'] );
		$this->assertSame( 7, $entry['intention_id'] );
		$this->assertSame( $user, $entry['user_id'] );
		$this->assertSame( '2026-09-24 10:00:00', $entry['created_at'] );
		$this->assertSame( array( 'summary' => 'x' ), $entry['details'] );
	}

	/**
	 * Entries can be listed per intention, newest first.
	 */
	public function test_for_intention_returns_newest_first(): void {
		$log = DFXPRAY_Log::get_instance();

		$this->freeze_time( '2026-09-24 10:00:00' );
		$log->add( 'first', 1, 5 );
		$this->freeze_time( '2026-09-24 11:00:00' );
		$log->add( 'second', 1, 5 );
		$log->add( 'other', 1, 6 );

		$actions = array_column( $log->for_intention( 5 ), 'action' );

		$this->assertSame( array( 'second', 'first' ), $actions );
	}

	/**
	 * Entries can be listed per group and filtered by action.
	 */
	public function test_for_group_filters_by_action(): void {
		$log = DFXPRAY_Log::get_instance();
		$log->add( 'channel_failed', 1 );
		$log->add( 'intention_created', 1, 3 );
		$log->add( 'channel_failed', 2 );

		$entries = $log->for_group( 1, array( 'channel_failed' ) );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'channel_failed', $entries[0]['action'] );
	}

	/**
	 * Deleting by intention removes only that intention's entries.
	 */
	public function test_delete_for_intentions(): void {
		$log = DFXPRAY_Log::get_instance();
		$log->add( 'a', 1, 5 );
		$log->add( 'b', 1, 6 );

		$log->delete_for_intentions( array( 5 ) );

		$this->assertSame( array(), $log->for_intention( 5 ) );
		$this->assertCount( 1, $log->for_intention( 6 ) );
	}
}
