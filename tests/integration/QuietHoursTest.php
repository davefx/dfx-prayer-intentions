<?php
/**
 * Hour alerts that fall in quiet hours are never lost: the next digest lists
 * them, whatever time it goes out; if no digest did, an early alert goes out
 * just before quiet hours begin.
 *
 * @package DFXPRAY
 */

/**
 * Quiet hours behaviour.
 */
class QuietHoursTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;
	use DFXPRAY_Http_Capture;

	/**
	 * A group.
	 *
	 * @var int
	 */
	private int $group;

	/**
	 * Set up: quiet 23:00–07:00 (default), alerts by email.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		reset_phpmailer_instance();
		$this->freeze_time( '2026-09-24 12:00' );
		$leader      = self::factory()->user->create( array( 'role' => 'dfxpray_leader', 'user_email' => 'ana@example.org' ) );
		$this->group = $this->make_group( 'Santa Marta', array( $leader ) );
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'channels' => array( 'email' => array( 'alerts' => true ) ) ) );
		$this->capture_http();
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$this->stop_capturing_http();
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * Mail bodies sent so far.
	 *
	 * @return string[]
	 */
	private function bodies(): array {
		return array_column( tests_retrieve_phpmailer_instance()->mock_sent, 'body' );
	}

	/**
	 * A late-night digest lists tonight's silent events in their own section,
	 * and they are not listed twice.
	 */
	public function test_late_night_digest_lists_silent_events(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_time' => '23:30' ) );
		$this->freeze_time( '2026-09-20 12:00' ); // Old enough not to be news.
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25', 'reference_time' => '06:30', 'summary' => 'operación', 'beneficiary_name' => 'Carmen' ) );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25', 'reference_time' => '10:00', 'summary' => 'examen', 'beneficiary_name' => 'Pablo' ) );

		$this->freeze_time( '2026-09-24 23:31' );
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( "*Without an hour alert*\n• tomorrow, 6:30 – Carmen, madre de Luis: operación", $digest['shareable'] );
		$this->assertSame( 1, substr_count( $digest['shareable'], 'operación' ) );
		$this->assertStringContainsString( "*Tomorrow*\n• 10:00 – Pablo, madre de Luis: examen", $digest['shareable'] );
	}

	/**
	 * A morning digest lists tonight's late event, which would get no alert.
	 */
	public function test_morning_digest_lists_tonights_late_event(): void {
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25', 'reference_time' => '23:30', 'summary' => 'viaje', 'beneficiary_name' => 'Rosa' ) );

		$this->freeze_time( '2026-09-25 07:05' );
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( "*Without an hour alert*\n• today, 23:30 – Rosa, madre de Luis: viaje", $digest['shareable'] );
		$this->assertFalse( $digest['is_empty'] );
	}

	/**
	 * Events beyond the next digest are left for that digest.
	 */
	public function test_only_until_the_next_digest(): void {
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-26', 'reference_time' => '06:30', 'summary' => 'lejos' ) );

		$this->freeze_time( '2026-09-24 23:31' );
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringNotContainsString( 'Without an hour alert', $digest['shareable'] );
	}

	/**
	 * Covered by a digest: no early alert.
	 */
	public function test_no_early_alert_when_a_digest_covered_it(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_time' => '21:00' ) );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25', 'reference_time' => '06:30', 'summary' => 'operación' ) );
		$scheduler = DFXPRAY_Scheduler::get_instance();

		$this->freeze_time( '2026-09-24 21:02' );
		$scheduler->tick();
		$this->freeze_time( '2026-09-24 22:45' );
		$scheduler->tick();
		$this->freeze_time( '2026-09-25 06:16' );
		$scheduler->tick();

		$this->assertCount( 1, $this->bodies(), 'Only the digest.' );
		$this->assertStringContainsString( 'Without an hour alert', $this->bodies()[0] );
	}

	/**
	 * Not covered by any digest: an early alert just before quiet hours.
	 */
	public function test_early_alert_when_no_digest_covered_it(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_time' => '07:00' ) );
		$this->freeze_time( '2026-09-24 07:05' );
		DFXPRAY_Scheduler::get_instance()->tick();
		reset_phpmailer_instance();

		// Added after this morning's digest, for tomorrow before the next one.
		$this->freeze_time( '2026-09-24 15:00' );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25', 'reference_time' => '06:30', 'summary' => 'operación', 'beneficiary_name' => 'Carmen' ) );
		$scheduler = DFXPRAY_Scheduler::get_instance();

		$this->freeze_time( '2026-09-24 22:20' );
		$scheduler->tick();
		$this->assertCount( 0, $this->bodies(), 'Too early.' );

		$this->freeze_time( '2026-09-24 22:35' );
		$scheduler->tick();
		$this->freeze_time( '2026-09-24 22:50' );
		$scheduler->tick();
		$this->freeze_time( '2026-09-25 06:16' );
		$scheduler->tick();

		$this->assertCount( 1, $this->bodies() );
		$this->assertStringContainsString( 'No alert during quiet hours – tomorrow, 6:30: Carmen, madre de Luis: operación. Let us pray at that moment.', $this->bodies()[0] );
	}

	/**
	 * One early alert per event, even with the exact-time alert also silenced.
	 */
	public function test_one_early_alert_per_event(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_time' => '07:00', 'alert_at_exact_time' => true ) );
		$this->freeze_time( '2026-09-24 07:05' );
		DFXPRAY_Scheduler::get_instance()->tick();
		reset_phpmailer_instance();
		$this->freeze_time( '2026-09-24 15:00' );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25', 'reference_time' => '05:00' ) );

		$this->freeze_time( '2026-09-24 22:40' );
		DFXPRAY_Scheduler::get_instance()->tick();
		$this->freeze_time( '2026-09-24 22:50' );
		DFXPRAY_Scheduler::get_instance()->tick();

		$this->assertCount( 1, $this->bodies() );
	}

	/**
	 * Start of the quiet period containing a moment.
	 *
	 * @return array
	 */
	public function provide_quiet_starts(): array {
		return array(
			'after midnight' => array( '2026-09-25 06:15', '23:00', '07:00', '2026-09-24 23:00' ),
			'before midnight' => array( '2026-09-24 23:15', '23:00', '07:00', '2026-09-24 23:00' ),
			'same-day range' => array( '2026-09-24 14:00', '13:00', '16:00', '2026-09-24 13:00' ),
		);
	}

	/**
	 * Quiet period start.
	 *
	 * @dataProvider provide_quiet_starts
	 *
	 * @param string $moment   Moment.
	 * @param string $start    Quiet start.
	 * @param string $end      Quiet end.
	 * @param string $expected Start of that quiet period.
	 */
	public function test_quiet_start_of( string $moment, string $start, string $end, string $expected ): void {
		$at = DFXPRAY_Clock::get_instance()->local( $moment );

		$this->assertSame( $expected, DFXPRAY_Scheduler::get_instance()->quiet_start_of( $at, $start, $end )->format( 'Y-m-d H:i' ) );
	}
}
