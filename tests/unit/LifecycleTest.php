<?php
/**
 * Tests for lifecycle dates by type.
 *
 * @package DFXPRAY
 */

/**
 * Lifecycle behaviour.
 */
class LifecycleTest extends DFXPRAY_Test_Case {

	/**
	 * Service under test.
	 *
	 * @var DFXPRAY_Lifecycle
	 */
	private DFXPRAY_Lifecycle $lifecycle;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->lifecycle = DFXPRAY_Lifecycle::get_instance();
	}

	/**
	 * Default review dates, one case per type.
	 *
	 * @return array
	 */
	public function provide_initial_reviews(): array {
		return array(
			'event: date + 2 days'                => array( 'event', '2026-09-28', '2026-09-24', '2026-09-30' ),
			'deceased: death + 30 days'           => array( 'deceased', '2026-09-20', '2026-09-24', '2026-10-20' ),
			'ongoing: activation + 30 days'       => array( 'ongoing', '2026-01-10', '2026-09-24', '2026-10-24' ),
			'ongoing without date'                => array( 'ongoing', null, '2026-09-24', '2026-10-24' ),
			'thanksgiving: reference + 7 days'    => array( 'thanksgiving', '2026-09-23', '2026-09-24', '2026-09-30' ),
			'thanksgiving without date: today +7' => array( 'thanksgiving', null, '2026-09-24', '2026-10-01' ),
		);
	}

	/**
	 * Review date on activation.
	 *
	 * @dataProvider provide_initial_reviews
	 *
	 * @param string      $type      Type.
	 * @param string|null $reference Reference date.
	 * @param string      $today     Activation day.
	 * @param string      $expected  Expected review date.
	 */
	public function test_initial_review( string $type, ?string $reference, string $today, string $expected ): void {
		$this->freeze_time( $today . ' 10:00' );

		$this->assertSame( $expected, $this->lifecycle->initial_review_date( $type, $reference ) );
	}

	/**
	 * Group overrides change the deadlines.
	 */
	public function test_group_deadlines_apply(): void {
		$this->freeze_time( '2026-09-24 10:00' );
		$group = DFXPRAY_Groups::get_instance()->create( array( 'name' => 'A' ) );
		DFXPRAY_Settings::get_instance()->save_group( $group, array( 'deceased_days' => 40 ) );

		$this->assertSame( '2026-10-30', $this->lifecycle->initial_review_date( 'deceased', '2026-09-20', $group ) );
	}

	/**
	 * "Still current" pushes the review forward from today.
	 */
	public function test_keep_active_review(): void {
		$this->freeze_time( '2026-09-24 10:00' );

		$this->assertSame( '2026-10-24', $this->lifecycle->keep_active_review_date( 'ongoing' ) );
		$this->assertSame( '2026-10-24', $this->lifecycle->keep_active_review_date( 'event' ) );
	}

	/**
	 * Which types need a reference date.
	 */
	public function test_requires_reference_date(): void {
		$this->assertTrue( $this->lifecycle->requires_reference_date( 'event' ) );
		$this->assertTrue( $this->lifecycle->requires_reference_date( 'deceased' ) );
		$this->assertFalse( $this->lifecycle->requires_reference_date( 'ongoing' ) );
		$this->assertFalse( $this->lifecycle->requires_reference_date( 'thanksgiving' ) );
	}

	/**
	 * Only events accept a time.
	 */
	public function test_accepts_time(): void {
		$this->assertTrue( $this->lifecycle->accepts_time( 'event' ) );
		$this->assertFalse( $this->lifecycle->accepts_time( 'deceased' ) );
	}

	/**
	 * The four types.
	 */
	public function test_types(): void {
		$this->assertSame( array( 'event', 'deceased', 'ongoing', 'thanksgiving' ), array_keys( $this->lifecycle->types() ) );
	}

	/**
	 * State machine transitions from the specification.
	 *
	 * @return array
	 */
	public function provide_transitions(): array {
		return array(
			array( 'pending', 'active', true ),
			array( 'pending', 'discarded', true ),
			array( 'active', 'review', true ),
			array( 'review', 'active', true ),
			array( 'review', 'closed', true ),
			array( 'active', 'closed', true ),
			array( 'pending', 'closed', false ),
			array( 'closed', 'active', false ),
			array( 'discarded', 'active', false ),
			array( 'active', 'pending', false ),
		);
	}

	/**
	 * Transitions allowed by the state diagram.
	 *
	 * @dataProvider provide_transitions
	 *
	 * @param string $from    From state.
	 * @param string $to      To state.
	 * @param bool   $allowed Expected.
	 */
	public function test_transitions( string $from, string $to, bool $allowed ): void {
		$this->assertSame( $allowed, $this->lifecycle->can_transition( $from, $to ) );
	}

	/**
	 * Only event and ongoing may produce a linked thanksgiving on closing.
	 */
	public function test_thanksgiving_follow_up_types(): void {
		$this->assertTrue( $this->lifecycle->can_spawn_thanksgiving( 'event' ) );
		$this->assertTrue( $this->lifecycle->can_spawn_thanksgiving( 'ongoing' ) );
		$this->assertFalse( $this->lifecycle->can_spawn_thanksgiving( 'deceased' ) );
		$this->assertFalse( $this->lifecycle->can_spawn_thanksgiving( 'thanksgiving' ) );
	}
}
