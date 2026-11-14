<?php
/**
 * Tests for discretion levels and visible names.
 *
 * @package DFXPRAY
 */

/**
 * Discretion behaviour.
 */
class DiscretionTest extends DFXPRAY_Test_Case {

	/**
	 * Service under test.
	 *
	 * @var DFXPRAY_Discretion
	 */
	private DFXPRAY_Discretion $discretion;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->discretion = DFXPRAY_Discretion::get_instance();
	}

	/**
	 * The four levels of the specification.
	 *
	 * @return array
	 */
	public function provide_levels(): array {
		return array(
			'full name'  => array( 'full_name', 'Carmen López', 'Carmen López' ),
			'first name' => array( 'first_name', 'Carmen López', 'Carmen' ),
			'initials'   => array( 'initials', 'Carmen López', 'C. L.' ),
			'anonymous'  => array( 'anonymous', 'Carmen López', null ),
			'particles'  => array( 'initials', 'María de la Cruz Pérez', 'M. C. P.' ),
			'accents'    => array( 'initials', 'Íñigo Álvarez', 'Í. Á.' ),
			'spaces'     => array( 'first_name', '  Pablo   Ruiz ', 'Pablo' ),
		);
	}

	/**
	 * Each level shows the name as the specification's table says.
	 *
	 * @dataProvider provide_levels
	 *
	 * @param string      $level    Level.
	 * @param string      $name     Original name.
	 * @param string|null $expected Expected output.
	 */
	public function test_format_name( string $level, string $name, ?string $expected ): void {
		$this->assertSame( $expected, $this->discretion->format_name( $name, $level ) );
	}

	/**
	 * A first name set by hand replaces the first word; nothing is guessed.
	 */
	public function test_first_name_set_by_hand(): void {
		$this->assertSame( 'Juan Tomás', $this->discretion->format_name( 'Juan Tomás Rubio', 'first_name', '', 'Juan Tomás' ) );
		$this->assertSame( 'Juan', $this->discretion->format_name( 'Juan Tomás Rubio', 'first_name' ) );
		$this->assertSame( 'Titón', $this->discretion->format_name( 'Titón Alonso', 'first_name' ) );
		$this->assertSame( 'Juan Tomás Rubio', $this->discretion->format_name( 'Juan Tomás Rubio', 'full_name', '', 'Juan Tomás' ) );
		$this->assertSame( 'J. T. R.', $this->discretion->format_name( 'Juan Tomás Rubio', 'initials', '', 'Juan Tomás' ) );
		$this->assertSame( 'Nuestro hermano Juan', $this->discretion->format_name( 'Juan Tomás Rubio', 'first_name', 'Nuestro hermano Juan', 'Juan Tomás' ) );
	}

	/**
	 * The first name set by hand must be the first words of the name.
	 */
	public function test_first_name_must_open_the_name(): void {
		$this->assertTrue( $this->discretion->validate_first_name( 'Juan Tomás Rubio', 'Juan Tomás' ) );
		$this->assertTrue( $this->discretion->validate_first_name( 'Juan Tomás Rubio', 'juan  tomas' ) );
		$this->assertTrue( $this->discretion->validate_first_name( 'Juan Pablo', 'Juan Pablo' ) );
		$this->assertTrue( $this->discretion->validate_first_name( 'Mª Soledad', 'Mª Soledad' ) );
		$this->assertTrue( $this->discretion->validate_first_name( 'Titón Alonso', '' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->validate_first_name( 'Juan Tomás Rubio', 'Tomás' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->validate_first_name( 'Juan Tomás Rubio', 'Juan Rubio' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->validate_first_name( 'Juan Tomás Rubio', 'Juan Tomás Rubio Pérez' ) );
	}

	/**
	 * A visible name may repeat the first name set by hand, never a surname.
	 */
	public function test_visible_name_may_carry_the_first_name_set_by_hand(): void {
		$this->assertTrue( $this->discretion->validate_visible_name( 'Juan Tomás Rubio', 'first_name', 'Nuestro hermano Juan Tomás', 'Juan Tomás' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->validate_visible_name( 'Juan Tomás Rubio', 'first_name', 'Juan Tomás Rubio', 'Juan Tomás' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->validate_visible_name( 'Juan Tomás Rubio', 'first_name', 'Nuestro hermano Juan Tomás' ) );
	}

	/**
	 * The person description uses the first names set by hand.
	 */
	public function test_describe_with_first_names_set_by_hand(): void {
		$intention = array(
			'requester_name'           => 'Juan Tomás Rubio',
			'requester_first_name'     => 'Juan Tomás',
			'beneficiary_name'         => 'José Antonio Ruiz',
			'beneficiary_first_name'   => 'José Antonio',
			'beneficiary_is_requester' => false,
			'relationship'             => 'tío de',
			'discretion'               => 'first_name',
			'requester_display_name'   => '',
			'beneficiary_display_name' => '',
		);

		$this->assertSame( 'José Antonio, tío de Juan Tomás', $this->discretion->describe( $intention ) );
	}

	/**
	 * The visible name replaces the original when the level shows a name.
	 */
	public function test_visible_name_used_for_name_levels(): void {
		$this->assertSame( 'Nuestro hermano David Marín', $this->discretion->format_name( 'David Marín', 'full_name', 'Nuestro hermano David Marín' ) );
		$this->assertSame( 'Nuestro hermano David', $this->discretion->format_name( 'David Marín', 'first_name', 'Nuestro hermano David' ) );
	}

	/**
	 * The visible name is ignored for initials and anonymous.
	 */
	public function test_visible_name_ignored_for_other_levels(): void {
		$this->assertSame( 'D. M.', $this->discretion->format_name( 'David Marín', 'initials', 'David Marín' ) );
		$this->assertNull( $this->discretion->format_name( 'David Marín', 'anonymous', 'David' ) );
	}

	/**
	 * With first name only, the visible name cannot add surnames.
	 */
	public function test_visible_name_cannot_reveal_surnames(): void {
		$this->assertInstanceOf( WP_Error::class, $this->discretion->validate_visible_name( 'David Marín', 'first_name', 'Nuestro hermano David Marín' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->validate_visible_name( 'David Marín', 'first_name', 'David MARIN' ) );
		$this->assertTrue( $this->discretion->validate_visible_name( 'David Marín', 'first_name', 'Nuestro hermano David' ) );
		$this->assertTrue( $this->discretion->validate_visible_name( 'David Marín', 'full_name', 'Nuestro hermano David Marín' ) );
		$this->assertTrue( $this->discretion->validate_visible_name( 'David Marín', 'first_name', '' ) );
	}

	/**
	 * A surname fragment inside a longer word is not a surname.
	 */
	public function test_surname_match_is_by_whole_word(): void {
		$this->assertTrue( $this->discretion->validate_visible_name( 'Ana Gil', 'first_name', 'Ana, de Gilena' ) );
	}

	/**
	 * Leaders can harden the level, never relax it.
	 */
	public function test_harden(): void {
		$this->assertSame( 'initials', $this->discretion->harden( 'first_name', 'initials' ) );
		$this->assertSame( 'anonymous', $this->discretion->harden( 'initials', 'anonymous' ) );
		$this->assertSame( 'first_name', $this->discretion->harden( 'first_name', 'first_name' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->harden( 'initials', 'full_name' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->harden( 'initials', 'bogus' ) );
	}

	/**
	 * The person description joins beneficiary, relationship and requester.
	 */
	public function test_describe_person(): void {
		$intention = array(
			'requester_name'           => 'Luis Gómez',
			'beneficiary_name'         => 'Carmen Ruiz',
			'beneficiary_is_requester' => false,
			'relationship'             => 'madre de',
			'discretion'               => 'first_name',
			'requester_display_name'   => '',
			'beneficiary_display_name' => '',
		);

		$this->assertSame( 'Carmen, madre de Luis', $this->discretion->describe( $intention ) );

		$intention['discretion'] = 'initials';
		$this->assertSame( 'C. R., madre de L. G.', $this->discretion->describe( $intention ) );

		$intention['discretion'] = 'anonymous';
		$this->assertNull( $this->discretion->describe( $intention ) );

		$intention['discretion']   = 'full_name';
		$intention['relationship'] = '';
		$this->assertSame( 'Carmen Ruiz', $this->discretion->describe( $intention ) );
	}

	/**
	 * When praying for oneself, only the requester appears.
	 */
	public function test_describe_self(): void {
		$intention = array(
			'requester_name'           => 'Pablo Ruiz',
			'beneficiary_name'         => 'Pablo Ruiz',
			'beneficiary_is_requester' => true,
			'relationship'             => '',
			'discretion'               => 'first_name',
			'requester_display_name'   => '',
			'beneficiary_display_name' => '',
		);

		$this->assertSame( 'Pablo', $this->discretion->describe( $intention ) );
	}

	/**
	 * Levels are listed from the most open to the most discreet.
	 */
	public function test_levels_order(): void {
		$this->assertSame( array( 'full_name', 'first_name', 'initials', 'anonymous' ), array_keys( $this->discretion->levels() ) );
	}
}
