<?php
/**
 * Tests for the administrators' statistics of all the groups.
 *
 * @package DFXPRAY
 */

/**
 * Statistics behaviour.
 */
class StatisticsTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * Counts per group, figures only, for every group, archived ones too.
	 */
	public function test_counts_per_group_and_totals(): void {
		$leader = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );
		$first  = $this->make_group( 'First', array( $leader ) );
		$second = $this->make_group( 'Second' );
		$intent = DFXPRAY_Intentions::get_instance();

		// Two months ago: an old request, answered last week.
		$this->freeze_time( '2026-07-20 10:00' );
		$old = $this->make_active( $first );

		$this->freeze_time( '2026-09-17 10:00' );
		$intent->close( $old, 'answered' );

		// This month: one pending, one active, one closed as expired.
		$this->freeze_time( '2026-09-20 10:00' );
		$this->make_pending( $first );
		$this->make_active( $first );
		$expired = $this->make_active( $first );
		$intent->close( $expired, 'expired' );
		$this->make_pending( $second );

		DFXPRAY_Members::get_instance()->create( $first, array( 'name' => 'Pedro' ) );
		DFXPRAY_Members::get_instance()->create( $first, array( 'name' => 'Juan' ) );
		DFXPRAY_Members::get_instance()->create(
			$first,
			array(
				'name'       => 'Tomás',
				'death_date' => '2026-01-01',
			)
		);
		DFXPRAY_Groups::get_instance()->archive( $second );

		$this->freeze_time( '2026-09-24 10:00' );
		$stats = DFXPRAY_Statistics::get_instance()->for_all_groups();

		$this->assertSame( array( $first, $second ), array_keys( $stats['groups'] ) );
		$this->assertSame(
			array(
				'name'        => 'First',
				'status'      => 'active',
				'leaders'     => 1,
				'members'     => 2,
				'pending'     => 1,
				'listed'      => 1,
				'received_30' => 3,
				'closed_30'   => 2,
				'answered'    => 1,
				'total'       => 4,
			),
			$stats['groups'][ $first ]
		);
		$this->assertSame( 'archived', $stats['groups'][ $second ]['status'] );
		$this->assertSame( 0, $stats['groups'][ $second ]['leaders'] );
		$this->assertSame( 1, $stats['groups'][ $second ]['pending'] );

		$this->assertSame( 2, $stats['totals']['pending'] );
		$this->assertSame( 4, $stats['totals']['received_30'] );
		$this->assertSame( 5, $stats['totals']['total'] );
		$this->assertSame( 1, $stats['totals']['leaders'] );
	}

	/**
	 * An empty site still answers with zeros.
	 */
	public function test_no_groups(): void {
		$stats = DFXPRAY_Statistics::get_instance()->for_all_groups();

		$this->assertSame( array(), $stats['groups'] );
		$this->assertSame( 0, $stats['totals']['total'] );
	}
}
