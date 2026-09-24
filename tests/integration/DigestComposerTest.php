<?php
/**
 * Tests for the daily digest.
 *
 * @package DFXPRAY
 */

/**
 * Digest composition.
 */
class DigestComposerTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * A group.
	 *
	 * @var int
	 */
	private int $group;

	/**
	 * Set up: Thursday 24 September 2026, 07:00.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		$this->freeze_time( '2026-09-20 09:00' );
		$this->group = $this->make_group();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * All six sections in order, and the leaders-only one only for leaders.
	 */
	public function test_sections_in_order(): void {
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-24', 'reference_time' => '17:00', 'summary' => 'Evening surgery', 'beneficiary_name' => 'Rosa' ) );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-24', 'reference_time' => '09:30', 'summary' => 'Morning exam', 'beneficiary_name' => 'Juan' ) );
		$this->make_active(
			$this->group,
			array(
				'type'             => 'deceased',
				'reference_date'   => '2026-09-22',
				'beneficiary_name' => 'Antonio',
				'relationship'     => 'padre de',
				'requester_name'   => 'Javier',
				'summary'          => 'descanso eterno',
				'key_dates'        => array( array( 'date' => '2026-09-24', 'time' => '12:00', 'label' => 'Funeral' ) ),
			)
		);
		$old_high = $this->make_active( $this->group, array( 'summary' => 'Long illness', 'beneficiary_name' => 'Elena' ) );
		DFXPRAY_Intentions::get_instance()->set_priority( $old_high, 'high' );
		$old = $this->make_active( $this->group, array( 'summary' => 'Unemployment', 'beneficiary_name' => 'Tomás' ) );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25', 'summary' => 'Job interview', 'beneficiary_name' => 'Pablo' ) );

		$this->freeze_time( '2026-09-23 12:00' );
		DFXPRAY_Updates::get_instance()->add( $old, 'He found a job!', true );
		$this->make_active( $this->group, array( 'summary' => 'New today', 'beneficiary_name' => 'Nuria' ) );
		$this->make_pending( $this->group, array( 'beneficiary_name' => 'Pending person' ) );

		$this->freeze_time( '2026-09-24 07:00' );
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$shareable = $digest['shareable'];
		$positions = array_map(
			static fn( $needle ) => strpos( $shareable, $needle ),
			array( '*Today*', '*News*', '*High priority*', '*Tomorrow*', '*Recently departed*' )
		);
		$this->assertNotContains( false, $positions, $shareable );
		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions );

		$this->assertStringContainsString( "• 9:30 – Juan, madre de Luis: Morning exam\n• 12:00 – Funeral: Antonio, padre de Javier\n• 17:00 – Rosa, madre de Luis: Evening surgery", $shareable );
		$this->assertStringContainsString( '• Tomás, madre de Luis: He found a job!', $shareable );
		$this->assertStringContainsString( '• Nuria, madre de Luis: New today', $shareable );
		$this->assertStringContainsString( "*High priority*\n• Elena, madre de Luis: Long illness", $shareable );
		$this->assertStringContainsString( "*Tomorrow*\n• Pablo, madre de Luis: Job interview", $shareable );
		$this->assertStringContainsString( "*Recently departed*\n• Antonio, padre de Javier: descanso eterno († 22/09)", $shareable );
		$this->assertStringNotContainsString( 'Pending person', $shareable );
		$this->assertStringNotContainsString( '*Leaders only*', $shareable );

		$this->assertStringContainsString( '*Leaders only*', $digest['leaders'] );
		$this->assertStringContainsString( 'Pending moderation: 1', $digest['leaders'] );
		$this->assertStringContainsString( admin_url( 'admin.php?page=dfxpray-moderation' ), $digest['leaders'] );
		$this->assertFalse( $digest['is_empty'] );
	}

	/**
	 * Old news is not news.
	 */
	public function test_news_window_is_24_hours(): void {
		$this->make_active( $this->group, array( 'summary' => 'Old one' ) );

		$this->freeze_time( '2026-09-24 07:00' );
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringNotContainsString( 'Old one', $digest['shareable'] );
	}

	/**
	 * Anniversaries of the dead are remembered when requested.
	 */
	public function test_anniversary(): void {
		$this->make_active(
			$this->group,
			array(
				'type'             => 'deceased',
				'reference_date'   => '2025-09-24',
				'anniversary'      => true,
				'beneficiary_name' => 'Abuela Pilar',
				'summary'          => 'descanso',
			)
		);

		$this->freeze_time( '2026-09-24 07:00' );
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( 'Anniversary: Abuela, madre de Luis', $digest['shareable'] );
	}

	/**
	 * Nothing to tell: short version by default.
	 */
	public function test_empty_short_version(): void {
		$this->freeze_time( '2026-09-24 07:00' );
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertTrue( $digest['is_empty'] );
		$this->assertStringContainsString( 'Nothing new today', $digest['shareable'] );
	}

	/**
	 * Discretion applies in the shareable version; the text never appears.
	 */
	public function test_discretion_applies(): void {
		$this->freeze_time( '2026-09-23 12:00' );
		$this->make_active( $this->group, array( 'discretion' => 'anonymous', 'summary' => 'Private matter' ) );

		$this->freeze_time( '2026-09-24 07:00' );
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( 'A private intention: Private matter', $digest['shareable'] );
		$this->assertStringNotContainsString( 'Carmen', $digest['shareable'] );
		$this->assertStringNotContainsString( 'Please pray', $digest['leaders'] );
	}

	/**
	 * Internal comments only reach the leaders' version, and only when configured.
	 */
	public function test_comments_in_leaders_digest_when_configured(): void {
		$id = $this->make_active( $this->group );
		$this->freeze_time( '2026-09-23 12:00' );
		DFXPRAY_Comments::get_instance()->add( $id, 'Visited her at home', 'visit' );
		$this->freeze_time( '2026-09-24 07:00' );

		$without = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_include_comments' => true ) );
		$with = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringNotContainsString( 'Visited her at home', $without['leaders'] );
		$this->assertStringContainsString( 'Visited her at home', $with['leaders'] );
		$this->assertStringNotContainsString( 'Visited her at home', $with['shareable'] );
	}

	/**
	 * Reviews due are listed for leaders.
	 */
	public function test_reviews_listed_for_leaders(): void {
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-20', 'summary' => 'Past exam' ) );
		$this->freeze_time( '2026-09-24 07:00' );
		DFXPRAY_Intentions::get_instance()->process_due();

		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( 'Reviews due: 1', $digest['leaders'] );
	}
}
