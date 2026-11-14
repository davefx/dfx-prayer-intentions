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
	 * Compound first names and the names that must not be taken for one.
	 *
	 * @return array
	 */
	public function provide_compound_first_names(): array {
		return array(
			'two given names'          => array( 'José Antonio García', 'José Antonio' ),
			'no surname written'       => array( 'Juan Pablo', 'Juan Pablo' ),
			'abbreviated María'        => array( 'Mª Soledad', 'Mª Soledad' ),
			'familiar form'            => array( 'José Mari', 'José Mari' ),
			'many surnames'            => array( 'Luis Carlos Pérez de la Cuadra', 'Luis Carlos' ),
			'joined by a particle'     => array( 'María del Carmen Ruiz', 'María del Carmen' ),
			'unaccented'               => array( 'Jose Luis Noarbe', 'Jose Luis' ),
			'three given names'        => array( 'María José Antonia Ruiz', 'María José Antonia' ),
			'surname that is a name'   => array( 'Titón Alonso', 'Titón' ),
			'surname after a name'     => array( 'Marta Esteban', 'Marta' ),
			'particle, then a surname' => array( 'María de la Cruz Pérez', 'María' ),
			'single word'              => array( 'Argeme', 'Argeme' ),
		);
	}

	/**
	 * With "first name", a compound first name appears whole and no surname
	 * comes with it.
	 *
	 * @dataProvider provide_compound_first_names
	 *
	 * @param string $name     Original name.
	 * @param string $expected Expected first name.
	 */
	public function test_compound_first_name( string $name, string $expected ): void {
		$this->assertSame( $expected, $this->discretion->format_name( $name, 'first_name' ) );
	}

	/**
	 * A visible name may repeat a whole compound first name, never a surname.
	 */
	public function test_visible_name_may_carry_a_compound_first_name(): void {
		$this->assertTrue( $this->discretion->validate_visible_name( 'José Antonio García', 'first_name', 'Nuestro hermano José Antonio' ) );
		$this->assertTrue( $this->discretion->validate_visible_name( 'Mª Soledad', 'first_name', 'Mª Soledad' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->validate_visible_name( 'José Antonio García', 'first_name', 'José Antonio García' ) );
		$this->assertInstanceOf( WP_Error::class, $this->discretion->validate_visible_name( 'Titón Alonso', 'first_name', 'Titón Alonso' ) );
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
