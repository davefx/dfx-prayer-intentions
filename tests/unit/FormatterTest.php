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

	/**
	 * A WhatsApp link to ask the requester how it goes; national numbers
	 * take the default country code.
	 */
	public function test_ask_link(): void {
		$intention = array(
			'requester_name'           => 'Ramón García',
			'requester_first_name'     => '',
			'requester_phone'          => '600998877',
			'requester_contact'        => '',
			'beneficiary_name'         => 'José',
			'beneficiary_first_name'   => '',
			'beneficiary_is_requester' => false,
			'summary'                  => 'trasplante',
		);

		$link = $this->formatter->ask_link( $intention );
		$this->assertStringStartsWith( 'https://wa.me/34600998877?text=', $link );
		$this->assertSame( 'Hi Ramón, how is José doing (trasplante)?', rawurldecode( substr( $link, strpos( $link, '=' ) + 1 ) ) );

		$intention['requester_phone'] = '+351912345678';
		$this->assertStringStartsWith( 'https://wa.me/351912345678?', $this->formatter->ask_link( $intention ) );

		$intention['requester_phone']   = '';
		$intention['requester_contact'] = 'ramon@example.com';
		$this->assertSame( '', $this->formatter->ask_link( $intention ) );

		$intention['requester_contact'] = '611 22 33 44';
		$this->assertStringStartsWith( 'https://wa.me/34611223344?', $this->formatter->ask_link( $intention ) );
	}

	/**
	 * One WhatsApp message asks a requester about all their intentions.
	 */
	public function test_ask_link_for_several(): void {
		$base = array(
			'requester_name'           => 'Titón Alonso',
			'requester_first_name'     => '',
			'requester_phone'          => '606453787',
			'requester_contact'        => '',
			'beneficiary_first_name'   => '',
			'beneficiary_is_requester' => false,
		);
		$juan    = $base + array( 'beneficiary_name' => 'Juan Pablo', 'summary' => 'tumor cerebral' );
		$beatriz = $base + array( 'beneficiary_name' => 'Beatriz', 'summary' => 'trasplante de riñón' );
		$self    = array_merge( $base, array( 'beneficiary_name' => 'Titón Alonso', 'beneficiary_is_requester' => true, 'summary' => 'vértigos' ) );

		$link = $this->formatter->ask_link_for( array( $juan, $beatriz, $self ) );
		$this->assertStringStartsWith( 'https://wa.me/34606453787?text=', $link );
		$this->assertSame(
			"Hi Titón, how are these intentions going?\n• Juan Pablo (tumor cerebral)\n• Beatriz (trasplante de riñón)\n• You (vértigos)",
			rawurldecode( substr( $link, strpos( $link, '=' ) + 1 ) )
		);

		$this->assertSame( $this->formatter->ask_link( $juan ), $this->formatter->ask_link_for( array( $juan ) ) );
		$this->assertSame( '', $this->formatter->ask_link_for( array() ) );
	}

	/**
	 * The question names each beneficiary as the requester would: without
	 * the part that points back to the requester, keeping a relationship
	 * that says something ("hija de unos amigos"), dropping a bare one.
	 */
	public function test_ask_link_names_the_beneficiary_for_the_requester(): void {
		$base = array(
			'requester_name'           => 'Javier López',
			'requester_first_name'     => '',
			'requester_phone'          => '650971212',
			'requester_contact'        => '',
			'beneficiary_first_name'   => '',
			'beneficiary_display_name' => '',
			'beneficiary_is_requester' => false,
			'relationship'             => '',
		);
		$son      = array_merge( $base, array( 'beneficiary_name' => 'Hijo', 'beneficiary_display_name' => 'El hijo de una compañera de N.H. Javier López', 'summary' => 'operación de una mano' ) );
		$carolina = array_merge( $base, array( 'beneficiary_name' => 'Carolina', 'relationship' => 'hija de unos amigos de', 'summary' => '18 años, epilepsia' ) );
		$jose     = array_merge( $base, array( 'beneficiary_name' => 'José', 'relationship' => 'amigo de', 'summary' => 'trasplante' ) );
		$beatriz  = array_merge( $base, array( 'beneficiary_name' => 'Beatriz', 'beneficiary_display_name' => 'Beatriz, hija de N.H. Carlos', 'summary' => 'trasplante de riñón' ) );

		$text = static fn( string $link ): string => rawurldecode( substr( $link, strpos( $link, '=' ) + 1 ) );

		$this->assertSame(
			"Hi Javier, how are these intentions going?\n• El hijo de una compañera (operación de una mano)\n• Carolina, hija de unos amigos (18 años, epilepsia)\n• José (trasplante)\n• Beatriz, hija de N.H. Carlos (trasplante de riñón)",
			$text( $this->formatter->ask_link_for( array( $son, $carolina, $jose, $beatriz ) ) )
		);
		$this->assertSame( 'Hi Javier, how is el hijo de una compañera doing (operación de una mano)?', $text( $this->formatter->ask_link( $son ) ) );
	}

	/**
	 * The greeting uses only the first name, even when the name to show
	 * carries a surname to tell brothers apart ("Javier Cavallé").
	 */
	public function test_ask_link_greets_by_first_name(): void {
		$intention = array(
			'requester_name'           => 'Javier Cavallé',
			'requester_first_name'     => 'Javier Cavallé',
			'requester_phone'          => '669711942',
			'requester_contact'        => '',
			'beneficiary_name'         => 'Manolo Campos',
			'beneficiary_first_name'   => 'Manolo Campos',
			'beneficiary_display_name' => '',
			'beneficiary_is_requester' => false,
			'relationship'             => '',
			'summary'                  => 'depresión',
		);

		$link = $this->formatter->ask_link( $intention );
		$this->assertSame( 'Hi Javier, how is Manolo Campos doing (depresión)?', rawurldecode( substr( $link, strpos( $link, '=' ) + 1 ) ) );
	}

	/**
	 * A summary that starts with the person's name does not repeat it:
	 * "Lourdes, …: Lourdes, ya está en casa" reads "Lourdes, …: ya está en casa".
	 */
	public function test_line_does_not_repeat_the_name(): void {
		$intention = array(
			'requester_name'           => 'Marcelo Ouviña',
			'requester_first_name'     => '',
			'requester_display_name'   => '',
			'requester_is_member'      => false,
			'beneficiary_name'         => 'Lourdes',
			'beneficiary_first_name'   => '',
			'beneficiary_display_name' => 'Lourdes, hija de un matrimonio del PAC',
			'beneficiary_is_member'    => false,
			'beneficiary_is_requester' => false,
			'relationship'             => '',
			'discretion'               => 'first_name',
			'type'                     => 'thanksgiving',
			'priority'                 => 'normal',
			'reference_date'           => null,
			'reference_time'           => null,
			'summary'                  => 'Lourdes, tras su trasplante de hígado, ya está en casa.',
		);

		$this->assertSame( 'Lourdes, hija de un matrimonio del PAC: tras su trasplante de hígado, ya está en casa.', $this->formatter->line( $intention ) );

		$intention['summary'] = 'Lourdes ya está en casa';
		$this->assertSame( 'Lourdes, hija de un matrimonio del PAC: ya está en casa', $this->formatter->line( $intention ) );

		$intention['summary'] = 'Lourditas ya está en casa';
		$this->assertSame( 'Lourdes, hija de un matrimonio del PAC: Lourditas ya está en casa', $this->formatter->line( $intention ) );
	}
}
