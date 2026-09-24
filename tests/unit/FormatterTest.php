<?php
/**
 * Tests for date and line formatting.
 *
 * @package DFXPRAY
 */

/**
 * Formatter behaviour.
 */
class FormatterTest extends DFXPRAY_Test_Case {

	/**
	 * Service under test.
	 *
	 * @var DFXPRAY_Formatter
	 */
	private DFXPRAY_Formatter $formatter;

	/**
	 * Set up: Thursday 24 September 2026.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		$this->freeze_time( '2026-09-24 10:00' );
		$this->formatter = DFXPRAY_Formatter::get_instance();
	}

	/**
	 * Relative day labels.
	 *
	 * @return array
	 */
	public function provide_days(): array {
		return array(
			'today'         => array( '2026-09-24', null, 'today' ),
			'tomorrow'      => array( '2026-09-25', null, 'tomorrow' ),
			'this week'     => array( '2026-09-28', null, 'Monday 28' ),
			'with time'     => array( '2026-09-28', '09:30', 'Monday 28, 9:30' ),
			'today at time' => array( '2026-09-24', '17:05', 'today, 17:05' ),
			'far away'      => array( '2026-10-15', null, '15/10' ),
			'past'          => array( '2026-09-20', null, '20/09' ),
		);
	}

	/**
	 * When label for events.
	 *
	 * @dataProvider provide_days
	 *
	 * @param string      $date     Date.
	 * @param string|null $time     Time.
	 * @param string      $expected Label.
	 */
	public function test_when( string $date, ?string $time, string $expected ): void {
		$this->assertSame( $expected, $this->formatter->when( $date, $time ) );
	}

	/**
	 * Short date and time.
	 */
	public function test_short_date_and_time(): void {
		$this->assertSame( '20/09', $this->formatter->short_date( '2026-09-20' ) );
		$this->assertSame( '9:30', $this->formatter->time( '09:30' ) );
	}

	/**
	 * Person label falls back to the anonymous wording.
	 */
	public function test_person_anonymous(): void {
		$this->assertSame( 'A private intention', $this->formatter->person( $this->intention( array( 'discretion' => 'anonymous' ) ) ) );
		$this->assertSame( 'Carmen, madre de Luis', $this->formatter->person( $this->intention() ) );
	}

	/**
	 * Build an intention array.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function intention( array $overrides = array() ): array {
		return array_merge(
			array(
				'requester_name'           => 'Luis Gómez',
				'beneficiary_name'         => 'Carmen Ruiz',
				'beneficiary_is_requester' => false,
				'relationship'             => 'madre de',
				'discretion'               => 'first_name',
				'requester_display_name'   => '',
				'beneficiary_display_name' => '',
			),
			$overrides
		);
	}
}
