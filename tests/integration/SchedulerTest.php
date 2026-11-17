<?php
/**
 * Tests for the scheduled work: digest, hour alerts, expirations, notices.
 *
 * @package DFXPRAY
 */

/**
 * Scheduler behaviour.
 */
class SchedulerTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;
	use DFXPRAY_Http_Capture;

	/**
	 * A group.
	 *
	 * @var int
	 */
	private int $group;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		reset_phpmailer_instance();
		$this->freeze_time( '2026-09-24 06:00' );
		$leader      = self::factory()->user->create(
			array(
				'role'       => 'dfxpray_leader',
				'user_email' => 'ana@example.org',
			)
		);
		$this->group = $this->make_group( 'Santa Marta', array( $leader ) );
		// Only alerts on email for these tests, so each send is one email.
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
	 * Emails sent so far.
	 *
	 * @return array
	 */
	private function sent_mails(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * The digest waits for its time, runs once a day, and is stored for the panel.
	 */
	public function test_digest_once_per_day_at_its_time(): void {
		$scheduler = DFXPRAY_Scheduler::get_instance();

		$scheduler->tick();
		$this->assertNull( $scheduler->get_stored_digest( $this->group ) );

		$this->freeze_time( '2026-09-24 07:02' );
		$scheduler->tick();
		$stored = $scheduler->get_stored_digest( $this->group );
		$this->assertSame( '2026-09-24', $stored['date'] );
		$this->assertCount( 1, $this->sent_mails() );

		$this->freeze_time( '2026-09-24 09:00' );
		$scheduler->tick();
		$this->assertCount( 1, $this->sent_mails() );

		$this->freeze_time( '2026-09-25 07:00' );
		$scheduler->tick();
		$this->assertCount( 2, $this->sent_mails() );
	}

	/**
	 * Each group has its own time.
	 */
	public function test_digest_time_per_group(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_time' => '08:30' ) );

		$this->freeze_time( '2026-09-24 07:30' );
		DFXPRAY_Scheduler::get_instance()->tick();
		$this->assertNull( DFXPRAY_Scheduler::get_instance()->get_stored_digest( $this->group ) );

		$this->freeze_time( '2026-09-24 08:31' );
		DFXPRAY_Scheduler::get_instance()->tick();
		$this->assertNotNull( DFXPRAY_Scheduler::get_instance()->get_stored_digest( $this->group ) );
	}

	/**
	 * With "none" and nothing to tell, nothing is sent.
	 */
	public function test_empty_digest_not_sent_when_configured(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_when_empty' => 'none' ) );

		$this->freeze_time( '2026-09-24 07:05' );
		DFXPRAY_Scheduler::get_instance()->tick();

		$this->assertCount( 0, $this->sent_mails() );
		$this->assertNotNull( DFXPRAY_Scheduler::get_instance()->get_stored_digest( $this->group ) );
	}

	/**
	 * Archived groups get no digest.
	 */
	public function test_archived_group_skipped(): void {
		DFXPRAY_Groups::get_instance()->archive( $this->group );

		$this->freeze_time( '2026-09-24 07:05' );
		DFXPRAY_Scheduler::get_instance()->tick();

		$this->assertNull( DFXPRAY_Scheduler::get_instance()->get_stored_digest( $this->group ) );
	}

	/**
	 * The tick moves due intentions to review.
	 */
	public function test_tick_processes_expirations(): void {
		$id = $this->make_active(
			$this->group,
			array(
				'type'           => 'event',
				'reference_date' => '2026-09-20',
			)
		);
		$this->freeze_time( '2026-09-24 06:10' );

		DFXPRAY_Scheduler::get_instance()->tick();

		$this->assertSame( 'review', DFXPRAY_Intentions::get_instance()->get( $id )['status'] );
	}

	/**
	 * Hour alert fifteen minutes before, once.
	 */
	public function test_hour_alert_before_event(): void {
		$this->freeze_time( '2026-09-24 05:00' );
		$this->make_active(
			$this->group,
			array(
				'type'             => 'event',
				'reference_date'   => '2026-09-24',
				'reference_time'   => '09:30',
				'summary'          => 'operación',
				'beneficiary_name' => 'Carmen',
			)
		);
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_time' => '23:59' ) );
		$scheduler = DFXPRAY_Scheduler::get_instance();

		$this->freeze_time( '2026-09-24 09:10' );
		$scheduler->tick();
		$this->assertCount( 0, $this->sent_mails() );

		$this->freeze_time( '2026-09-24 09:16' );
		$scheduler->tick();
		$this->assertCount( 1, $this->sent_mails() );
		$this->assertStringContainsString( 'At 9:30: Carmen, madre de Luis: operación. United in prayer.', $this->sent_mails()[0]['body'] );

		$this->freeze_time( '2026-09-24 09:21' );
		$scheduler->tick();
		$this->assertCount( 1, $this->sent_mails() );

		$alerts = $scheduler->get_recent_alerts( $this->group );
		$this->assertCount( 1, $alerts );
		$this->assertStringContainsString( 'At 9:30', $alerts[0]['text'] );
	}

	/**
	 * Optional second alert at the exact time.
	 */
	public function test_exact_time_alert(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'alert_at_exact_time' => true,
				'digest_time'         => '23:59',
			)
		);
		$this->freeze_time( '2026-09-24 05:00' );
		$this->make_active(
			$this->group,
			array(
				'type'           => 'event',
				'reference_date' => '2026-09-24',
				'reference_time' => '09:30',
			)
		);
		$scheduler = DFXPRAY_Scheduler::get_instance();

		$this->freeze_time( '2026-09-24 09:16' );
		$scheduler->tick();
		$this->freeze_time( '2026-09-24 09:31' );
		$scheduler->tick();
		$this->freeze_time( '2026-09-24 09:36' );
		$scheduler->tick();

		$this->assertCount( 2, $this->sent_mails() );
		$this->assertStringContainsString( 'Right now', $this->sent_mails()[1]['body'] );
	}

	/**
	 * Alerts that fall in quiet hours are not sent.
	 */
	public function test_quiet_hours(): void {
		$this->freeze_time( '2026-09-23 12:00' );
		$this->make_active(
			$this->group,
			array(
				'type'           => 'event',
				'reference_date' => '2026-09-24',
				'reference_time' => '06:30',
			)
		);
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_time' => '23:59' ) );

		$this->freeze_time( '2026-09-24 06:16' );
		DFXPRAY_Scheduler::get_instance()->tick();

		$this->assertCount( 0, $this->sent_mails() );
	}

	/**
	 * Quiet hours wrap around midnight.
	 *
	 * @return array
	 */
	public function provide_quiet_times(): array {
		return array(
			array( '23:00', '07:00', '23:30', true ),
			array( '23:00', '07:00', '06:59', true ),
			array( '23:00', '07:00', '07:00', false ),
			array( '23:00', '07:00', '12:00', false ),
			array( '13:00', '16:00', '14:00', true ),
			array( '13:00', '16:00', '16:30', false ),
			array( '00:00', '00:00', '03:00', false ),
		);
	}

	/**
	 * Quiet hours arithmetic.
	 *
	 * @dataProvider provide_quiet_times
	 *
	 * @param string $start    Start.
	 * @param string $end      End.
	 * @param string $time     Time.
	 * @param bool   $expected Expected.
	 */
	public function test_is_quiet( string $start, string $end, string $time, bool $expected ): void {
		$this->assertSame( $expected, DFXPRAY_Scheduler::get_instance()->is_quiet( $time, $start, $end ) );
	}

	/**
	 * A new form request notifies leaders; only email carries the text.
	 */
	public function test_new_request_notice(): void {
		$this->make_pending( $this->group, array( 'body' => 'Secret health details' ) );

		$mails = $this->sent_mails();
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'Secret health details', $mails[0]['body'] );
		$this->assertStringContainsString( 'Santa Marta', $mails[0]['subject'] );
	}

	/**
	 * A group may keep the full text out of the email notice too.
	 */
	public function test_new_request_notice_without_the_text(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'notice_full_text' => false ) );

		$this->make_pending( $this->group, array( 'body' => 'Secret health details' ) );

		$mails = $this->sent_mails();
		$this->assertCount( 1, $mails );
		$this->assertStringNotContainsString( 'Secret health details', $mails[0]['body'] );
		$this->assertStringContainsString( 'Moderate it:', $mails[0]['body'] );
	}

	/**
	 * Manually created intentions do not notify.
	 */
	public function test_manual_creation_does_not_notify(): void {
		$this->make_active( $this->group );

		$this->assertCount( 0, $this->sent_mails() );
	}

	/**
	 * Retention deletes old closed and discarded intentions only.
	 */
	public function test_retention(): void {
		$this->freeze_time( '2026-01-01 10:00' );
		$old_closed    = $this->make_active( $this->group );
		$old_discarded = $this->make_pending( $this->group );
		$still_active  = $this->make_active( $this->group );
		DFXPRAY_Intentions::get_instance()->close( $old_closed, 'answered' );
		DFXPRAY_Intentions::get_instance()->discard( $old_discarded );
		$this->freeze_time( '2026-09-01 10:00' );
		$recent_closed = $this->make_active( $this->group );
		DFXPRAY_Intentions::get_instance()->close( $recent_closed, 'answered' );

		$this->freeze_time( '2026-09-24 03:00' );
		$deleted = DFXPRAY_Scheduler::get_instance()->daily();

		$this->assertSame( 2, $deleted );
		$intentions = DFXPRAY_Intentions::get_instance();
		$this->assertNull( $intentions->get( $old_closed ) );
		$this->assertNull( $intentions->get( $old_discarded ) );
		$this->assertNotNull( $intentions->get( $still_active ) );
		$this->assertNotNull( $intentions->get( $recent_closed ) );
	}

	/**
	 * A closed deceased whose anniversary is remembered is never deleted.
	 */
	public function test_retention_keeps_anniversaries(): void {
		$this->freeze_time( '2026-01-01 10:00' );
		$remembered = $this->make_active(
			$this->group,
			array(
				'type'           => 'deceased',
				'reference_date' => '2025-12-30',
				'anniversary'    => true,
			)
		);
		$forgotten  = $this->make_active(
			$this->group,
			array(
				'type'           => 'deceased',
				'reference_date' => '2025-12-30',
			)
		);
		DFXPRAY_Intentions::get_instance()->close( $remembered, 'expired' );
		DFXPRAY_Intentions::get_instance()->close( $forgotten, 'expired' );

		$this->freeze_time( '2026-09-24 03:00' );
		DFXPRAY_Scheduler::get_instance()->daily();

		$this->assertNotNull( DFXPRAY_Intentions::get_instance()->get( $remembered ) );
		$this->assertNull( DFXPRAY_Intentions::get_instance()->get( $forgotten ) );
	}

	/**
	 * The cron hooks are wired.
	 */
	public function test_cron_hooks_registered(): void {
		$this->assertNotFalse( has_action( 'dfxpray_tick', array( DFXPRAY_Scheduler::get_instance(), 'tick' ) ) );
		$this->assertNotFalse( has_action( 'dfxpray_daily', array( DFXPRAY_Scheduler::get_instance(), 'daily' ) ) );
	}
}
