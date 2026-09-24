<?php
/**
 * Tests for the WhatsApp-ready index.
 *
 * @package DFXPRAY
 */

/**
 * Index composition.
 */
class IndexComposerTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * A group.
	 *
	 * @var int
	 */
	private int $group;

	/**
	 * Set up: Thursday 24 September 2026.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		$this->freeze_time( '2026-09-24 10:00' );
		$this->group = $this->make_group();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The example of the specification, in the English source strings.
	 */
	public function test_specification_example(): void {
		$this->make_active(
			$this->group,
			array(
				'requester_name'           => 'Pablo Sanz',
				'beneficiary_is_requester' => true,
				'type'                     => 'event',
				'reference_date'           => '2026-09-25',
				'summary'                  => 'entrevista de trabajo',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'requester_name'   => 'Luis Gómez',
				'beneficiary_name' => 'Carmen Ruiz',
				'relationship'     => 'madre de',
				'type'             => 'event',
				'reference_date'   => '2026-09-28',
				'reference_time'   => '09:30',
				'summary'          => 'operación',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'requester_name'   => 'Javier Pérez',
				'beneficiary_name' => 'Antonio Pérez',
				'relationship'     => 'padre de',
				'type'             => 'deceased',
				'reference_date'   => '2026-09-20',
				'summary'          => 'por su eterno descanso',
			)
		);
		$high = $this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Marta García',
				'relationship'     => '',
				'discretion'       => 'initials',
				'summary'          => 'tratamiento de quimioterapia',
			)
		);
		DFXPRAY_Intentions::get_instance()->set_priority( $high, 'high' );
		$this->make_active(
			$this->group,
			array(
				'discretion' => 'anonymous',
				'summary'    => 'por su familia',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'type'       => 'thanksgiving',
				'discretion' => 'anonymous',
				'summary'    => 'Por el trabajo encontrado de Luis',
			)
		);

		$expected = implode(
			"\n",
			array(
				'🙏 *Active intentions* – 24/09',
				'',
				'*Upcoming events*',
				'• Pablo: entrevista de trabajo _(tomorrow)_',
				'• Carmen, madre de Luis: operación _(Monday 28, 9:30)_',
				'',
				'*For our departed*',
				'• Antonio, padre de Javier: por su eterno descanso († 20/09)',
				'',
				'*The sick and difficult situations*',
				'• ❗ M. G.: tratamiento de quimioterapia',
				'• A private intention: por su familia',
				'',
				'*Thanksgiving*',
				'• A private intention: Por el trabajo encontrado de Luis',
			)
		);

		$this->assertSame( $expected, DFXPRAY_Index_Composer::get_instance()->compose( $this->group ) );
	}

	/**
	 * Only active and in-review intentions of the group appear.
	 */
	public function test_only_listable_intentions_of_the_group(): void {
		$this->make_active( $this->group, array( 'summary' => 'Visible' ) );
		$review = $this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-01', 'summary' => 'In review' ) );
		DFXPRAY_Intentions::get_instance()->process_due();
		$this->make_pending( $this->group );
		$closed = $this->make_active( $this->group, array( 'summary' => 'Closed' ) );
		DFXPRAY_Intentions::get_instance()->close( $closed, 'answered' );
		$this->make_active( $this->make_group( 'Other' ), array( 'summary' => 'Other group' ) );

		$text = DFXPRAY_Index_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( 'Visible', $text );
		$this->assertStringContainsString( 'In review', $text );
		$this->assertStringNotContainsString( 'Closed', $text );
		$this->assertStringNotContainsString( 'Other group', $text );
		$this->assertStringNotContainsString( 'Please pray', $text, 'The full text must never be published.' );
		$this->assertSame( 'review', DFXPRAY_Intentions::get_instance()->get( $review )['status'] );
	}

	/**
	 * High priority first, then by reference date.
	 */
	public function test_ordering_within_section(): void {
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-26', 'summary' => 'Second' ) );
		$this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-09-25', 'summary' => 'First' ) );
		$high = $this->make_active( $this->group, array( 'type' => 'event', 'reference_date' => '2026-10-10', 'summary' => 'Urgent' ) );
		DFXPRAY_Intentions::get_instance()->set_priority( $high, 'high' );

		$text = DFXPRAY_Index_Composer::get_instance()->compose( $this->group );

		$this->assertLessThan( strpos( $text, 'First' ), strpos( $text, 'Urgent' ) );
		$this->assertLessThan( strpos( $text, 'Second' ), strpos( $text, 'First' ) );
	}

	/**
	 * Header, footer and section emojis come from the group settings.
	 */
	public function test_header_footer_and_emojis(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_header'         => 'Intenciones {date}',
				'index_footer'         => 'Ave María',
				'index_section_emojis' => true,
			)
		);
		$this->make_active( $this->group );

		$text = DFXPRAY_Index_Composer::get_instance()->compose( $this->group );

		$this->assertStringStartsWith( 'Intenciones 24/09', $text );
		$this->assertStringEndsWith( "\n\nAve María", $text );
		$this->assertStringContainsString( '🤲 *The sick and difficult situations*', $text );
	}

	/**
	 * An empty index says so.
	 */
	public function test_empty_index(): void {
		$text = DFXPRAY_Index_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( 'There are no active intentions.', $text );
	}

	/**
	 * Each section can be filtered.
	 */
	public function test_section_filter(): void {
		$this->make_active( $this->group, array( 'summary' => 'Hidden by filter' ) );
		$filter = static function ( array $section ) {
			$section['lines'] = array( 'Replaced line' );
			return $section;
		};
		add_filter( 'dfxpray_index_section_ongoing', $filter );

		$text = DFXPRAY_Index_Composer::get_instance()->compose( $this->group );

		remove_filter( 'dfxpray_index_section_ongoing', $filter );
		$this->assertStringContainsString( '• Replaced line', $text );
		$this->assertStringNotContainsString( 'Hidden by filter', $text );
	}

	/**
	 * The template can be overridden.
	 */
	public function test_template_override(): void {
		$dir = get_temp_dir() . 'dfxpray-template-' . wp_generate_password( 6, false );
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', '<?php echo "CUSTOM " . count( $sections );' ); // phpcs:ignore
		$filter = static fn( $path, $name ) => 'index.php' === $name ? $dir . '/index.php' : $path;
		add_filter( 'dfxpray_template_path', $filter, 10, 2 );

		$this->make_active( $this->group );
		$text = DFXPRAY_Index_Composer::get_instance()->compose( $this->group );

		remove_filter( 'dfxpray_template_path', $filter, 10 );
		$this->assertSame( 'CUSTOM 1', $text );
	}

	/**
	 * Warning above 4,000 characters.
	 */
	public function test_length_warning(): void {
		$composer = DFXPRAY_Index_Composer::get_instance();

		$this->assertFalse( $composer->is_too_long( str_repeat( 'a', 4000 ) ) );
		$this->assertTrue( $composer->is_too_long( str_repeat( 'á', 4001 ) ) );
	}
}
