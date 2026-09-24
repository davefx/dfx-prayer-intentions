<?php
/**
 * Tests for the clock.
 *
 * @package DFXPRAY
 */

/**
 * Clock behaviour.
 */
class ClockTest extends DFXPRAY_Test_Case {

	/**
	 * Uses the site timezone.
	 */
	public function test_now_uses_the_site_timezone(): void {
		update_option( 'timezone_string', 'Europe/Madrid' );

		$now = DFXPRAY_Clock::get_instance()->now();

		$this->assertSame( 'Europe/Madrid', $now->getTimezone()->getName() );
	}

	/**
	 * Real time when not frozen.
	 */
	public function test_now_is_real_time_when_not_frozen(): void {
		$this->assertEqualsWithDelta( time(), DFXPRAY_Clock::get_instance()->now()->getTimestamp(), 5 );
	}

	/**
	 * Freezing fixes now, and today follows it.
	 */
	public function test_freeze_fixes_now_and_today(): void {
		update_option( 'timezone_string', 'Europe/Madrid' );
		$this->freeze_time( '2026-09-24 23:30' );

		$clock = DFXPRAY_Clock::get_instance();

		$this->assertSame( '2026-09-24 23:30', $clock->now()->format( 'Y-m-d H:i' ) );
		$this->assertSame( '2026-09-24', $clock->today() );
	}

	/**
	 * Reset returns to real time.
	 */
	public function test_reset_unfreezes(): void {
		$this->freeze_time( '2001-01-01 00:00' );
		DFXPRAY_Clock::get_instance()->reset();

		$this->assertGreaterThan( 2020, (int) DFXPRAY_Clock::get_instance()->now()->format( 'Y' ) );
	}

	/**
	 * MySQL formatted timestamp, in site local time, for storage.
	 */
	public function test_mysql_returns_local_datetime(): void {
		$this->freeze_time( '2026-09-24 07:05:09' );

		$this->assertSame( '2026-09-24 07:05:09', DFXPRAY_Clock::get_instance()->mysql() );
	}
}
