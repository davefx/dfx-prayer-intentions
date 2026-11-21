<?php
/**
 * Tests for the record of the daily messages shared with the group.
 *
 * @package DFXPRAY
 */

/**
 * Shares behaviour.
 */
class SharesTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * Once a day, with its time; undone only for today; kept per group.
	 */
	public function test_mark_and_undo(): void {
		$group  = $this->make_group( 'Santa Marta' );
		$other  = $this->make_group( 'San Pablo' );
		$shares = DFXPRAY_Shares::get_instance();

		$this->freeze_time( '2026-09-20 09:00' );
		$this->assertNull( $shares->today( $group ) );
		$shares->mark( $group );
		$this->freeze_time( '2026-09-20 11:00' );
		$shares->mark( $group );
		$this->assertSame( '2026-09-20 09:00:00', $shares->today( $group ) );
		$this->assertNull( $shares->today( $other ) );

		$this->freeze_time( '2026-09-21 10:00' );
		$this->assertNull( $shares->today( $group ) );
		$shares->mark( $group );
		$this->assertSame( array( '2026-09-21 10:00:00', '2026-09-20 09:00:00' ), $shares->all( $group ) );

		$shares->undo( $group );
		$this->assertNull( $shares->today( $group ) );
		$this->assertSame( array( '2026-09-20 09:00:00' ), $shares->all( $group ) );

		DFXPRAY_Groups::get_instance()->delete( $group );
		$this->assertSame( array(), $shares->all( $group ) );
	}

	/**
	 * Only the last month is kept.
	 */
	public function test_keeps_a_month(): void {
		$group  = $this->make_group( 'Santa Marta' );
		$shares = DFXPRAY_Shares::get_instance();
		for ( $day = 1; $day <= 40; $day++ ) {
			$this->freeze_time( gmdate( 'Y-m-d', strtotime( '2026-08-01 +' . $day . ' days' ) ) . ' 09:00' );
			$shares->mark( $group );
		}

		$this->assertCount( DFXPRAY_Shares::KEEP, $shares->all( $group ) );
	}
}
