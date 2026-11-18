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
		$review = $this->make_active(
			$this->group,
			array(
				'type'           => 'event',
				'reference_date' => '2026-09-01',
				'summary'        => 'In review',
			)
		);
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
		$this->make_active(
			$this->group,
			array(
				'type'           => 'event',
				'reference_date' => '2026-09-26',
				'summary'        => 'Second',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'type'           => 'event',
				'reference_date' => '2026-09-25',
				'summary'        => 'First',
			)
		);
		$high = $this->make_active(
			$this->group,
			array(
				'type'           => 'event',
				'reference_date' => '2026-10-10',
				'summary'        => 'Urgent',
			)
		);
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
	 * The "prayer proposals" layout: sections by priority, members with their
	 * prefix, shared summaries in one line, and only the recently departed.
	 */
	public function test_proposals_layout(): void {
		$this->freeze_time( '2026-09-20 10:00' );
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout'  => 'proposals',
				'member_prefix' => 'N.H.',
				'index_header'  => "*Prayer proposals*\n_{long_date}_\n\nGood morning.",
				'index_footer'  => 'A hug.',
			)
		);
		$member = array( 'requester_is_member' => true );

		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Javier Cavallé',
				'beneficiary_name' => 'Antonio',
				'relationship'     => 'amigo de',
				'summary'          => 'tumor de páncreas',
				'priority'         => 'high',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'requester_name'   => 'Luis Gómez',
				'beneficiary_name' => 'Carmen',
				'relationship'     => 'madre de',
				'type'             => 'event',
				'reference_date'   => '2026-09-28',
				'reference_time'   => '09:30',
				'summary'          => 'operación',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'           => 'Ramón',
				'beneficiary_is_requester' => true,
				'summary'                  => 'problemas en el hombro',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Felipe Arizu',
				'beneficiary_name' => 'Marina',
				'relationship'     => 'esposa de',
				'summary'          => 'problemas de salud',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'David Marín',
				'beneficiary_name' => 'Irene',
				'relationship'     => 'hija de',
				'summary'          => 'problemas de salud',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Álvaro Linares',
				'beneficiary_name' => 'Argeme',
				'relationship'     => 'amiga de',
				'type'             => 'thanksgiving',
				'reference_date'   => '2026-09-24',
				'summary'          => 'salió muy bien de su operación',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'requester_name'   => 'Alberto',
				'beneficiary_name' => 'Isabel',
				'relationship'     => 'madre de un amigo de',
				'type'             => 'deceased',
				'reference_date'   => '2026-09-20',
				'summary'          => 'descanse en paz',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'requester_name'   => 'Pedro',
				'beneficiary_name' => 'Juana',
				'relationship'     => 'abuela de',
				'type'             => 'deceased',
				'reference_date'   => '2026-08-01',
				'summary'          => 'descanse en paz',
			)
		);

		$expected = implode(
			"\n",
			array(
				'*Prayer proposals*',
				'_Thursday, September 24, 2026_',
				'',
				'Good morning.',
				'',
				'*Especially*',
				'• Antonio, amigo de N.H. Javier: tumor de páncreas',
				'',
				'*Also for*',
				'• N.H. Ramón: problemas en el hombro',
				'• Problemas de salud: Marina, esposa de N.H. Felipe; Irene, hija de N.H. David',
				'• Carmen, madre de Luis: operación _(Monday 28, 9:30)_',
				'',
				'*We give thanks*',
				'• Argeme, amiga de N.H. Álvaro: salió muy bien de su operación',
				'',
				'*For our departed*',
				'• Isabel, madre de un amigo de Alberto: descanse en paz († 20/09)',
				'',
				'A hug.',
			)
		);

		$this->freeze_time( '2026-09-24 10:00' );
		$this->assertSame( $expected, DFXPRAY_Index_Composer::get_instance()->compose( $this->group ) );
	}

	/**
	 * Two editions: the daily one, short (urgent ones, one line for health,
	 * the other daily ones, current affairs, thanks and the departed of the
	 * last week); the monthly one with every intention, by topic.
	 */
	public function test_daily_and_monthly_editions(): void {
		$this->freeze_time( '2026-09-20 10:00' );
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout'   => 'proposals',
				'member_prefix'  => 'N.H.',
				'index_header'   => '*Daily*',
				'monthly_header' => '*Monthly*',
				'index_footer'   => '',
			)
		);
		$member = array( 'requester_is_member' => true );

		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Javier Cavallé',
				'beneficiary_name' => 'Antonio',
				'relationship'     => 'amigo de',
				'summary'          => 'tumor de páncreas',
				'priority'         => 'high',
				'topic'            => 'health',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Felipe Arizu',
				'beneficiary_name' => 'Marina',
				'relationship'     => 'esposa de',
				'summary'          => 'problemas de salud',
				'topic'            => 'health',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'           => 'Ramón',
				'beneficiary_is_requester' => true,
				'summary'                  => 'problemas en el hombro',
				'topic'                    => 'health',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Manolo',
				'beneficiary_name' => 'Fátima',
				'relationship'     => 'sobrina de',
				'summary'          => 'quimioterapia',
				'topic'            => 'health',
				'scope'            => 'monthly',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Axel',
				'beneficiary_name' => 'Lucía',
				'relationship'     => 'hija de',
				'summary'          => 'depresión',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'           => 'Alejandro',
				'beneficiary_is_requester' => true,
				'summary'                  => 'su academia',
				'topic'                    => 'economic',
				'scope'                    => 'monthly',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'requester_name'   => 'Javier Cavallé',
				'beneficiary_name' => 'Ceuta',
				'relationship'     => '',
				'summary'          => 'por los ceutíes',
				'topic'            => 'current',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Álvaro',
				'beneficiary_name' => 'Argeme',
				'relationship'     => 'amiga de',
				'type'             => 'thanksgiving',
				'reference_date'   => '2026-09-24',
				'summary'          => 'salió bien',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Alejandro',
				'beneficiary_name' => 'Pilar',
				'relationship'     => 'esposa de',
				'type'             => 'thanksgiving',
				'reference_date'   => '2026-09-21',
				'summary'          => 'ya tiene el alta',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'requester_name'   => 'Alberto',
				'beneficiary_name' => 'Isabel',
				'relationship'     => 'madre de un amigo de',
				'type'             => 'deceased',
				'reference_date'   => '2026-09-20',
				'summary'          => 'descanse en paz',
			)
		);
		$this->make_active(
			$this->group,
			$member + array(
				'requester_name'   => 'Teo',
				'beneficiary_name' => 'Antonio',
				'relationship'     => 'amigo de',
				'type'             => 'deceased',
				'reference_date'   => '2026-09-10',
				'summary'          => 'descanse en paz',
			)
		);

		$this->freeze_time( '2026-09-24 10:00' );
		$composer = DFXPRAY_Index_Composer::get_instance();

		$this->assertSame(
			implode(
				"\n",
				array(
					'*Daily*',
					'',
					'*Especially*',
					'• Antonio, amigo de N.H. Javier: tumor de páncreas',
					'',
					'*For the health of*',
					'• N.H. Ramón',
					'• Marina, esposa de N.H. Felipe',
					'',
					'*Also for*',
					'• Lucía, hija de N.H. Axel: depresión',
					'',
					'*We also pray for*',
					'• Ceuta: por los ceutíes',
					'',
					'*We give thanks*',
					'• Argeme, amiga de N.H. Álvaro: salió bien',
					'',
					'*For our departed*',
					'• Isabel, madre de un amigo de Alberto: descanse en paz († 20/09)',
				)
			),
			$composer->compose( $this->group )
		);

		$this->assertSame(
			implode(
				"\n",
				array(
					'*Monthly*',
					'',
					'*Especially*',
					'• Antonio, amigo de N.H. Javier: tumor de páncreas',
					'',
					'*For the sick*',
					'• N.H. Ramón: problemas en el hombro',
					'• Marina, esposa de N.H. Felipe: problemas de salud',
					'• Fátima, sobrina de N.H. Manolo: quimioterapia',
					'',
					'*Studies, exams and job interviews*',
					'• For those who study, sit exams or look for work',
					'',
					'*Economic or legal problems*',
					'• N.H. Alejandro: su academia',
					'',
					'*Other intentions*',
					'• Lucía, hija de N.H. Axel: depresión',
					'',
					'*We also pray for*',
					'• Ceuta: por los ceutíes',
					'',
					'*We give thanks*',
					'• Pilar, esposa de N.H. Alejandro: ya tiene el alta',
					'• Argeme, amiga de N.H. Álvaro: salió bien',
					'',
					'*For our departed*',
					'• Antonio, amigo de N.H. Teo: descanse en paz († 10/09)',
					'• Isabel, madre de un amigo de Alberto: descanse en paz († 20/09)',
				)
			),
			$composer->compose( $this->group, 'monthly' )
		);
	}

	/**
	 * The monthly list gives thanks again for the good news of the month,
	 * also for those already closed after their days in the daily message.
	 */
	public function test_monthly_list_recovers_the_thanks_of_the_month(): void {
		$intentions = DFXPRAY_Intentions::get_instance();
		$recent     = $this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Argeme',
				'relationship'     => '',
				'type'             => 'thanksgiving',
				'reference_date'   => '2026-09-05',
				'summary'          => 'salió bien',
			)
		);
		$old        = $this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Rosa',
				'relationship'     => '',
				'type'             => 'thanksgiving',
				'reference_date'   => '2026-08-10',
				'summary'          => 'ya en casa',
			)
		);
		$intentions->close( $recent, 'expired' );
		$intentions->close( $old, 'expired' );

		$monthly = DFXPRAY_Index_Composer::get_instance()->compose( $this->group, 'monthly' );

		$this->assertStringContainsString( '• Argeme: salió bien', $monthly );
		$this->assertStringNotContainsString( 'Rosa', $monthly );
		$this->assertStringNotContainsString( 'Argeme', DFXPRAY_Index_Composer::get_instance()->compose( $this->group ) );
	}

	/**
	 * The news of the day before (and of the same morning) goes first, in
	 * "Especially", the departed too; from the next day on, they go back to
	 * their place.
	 */
	public function test_news_of_the_day_before_go_first(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout' => 'proposals',
				'index_header' => '*Daily*',
				'index_footer' => '',
			)
		);

		$this->freeze_time( '2026-09-20 10:00' );
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Rosa',
				'relationship'     => '',
				'summary'          => 'de antes',
			)
		);
		$this->freeze_time( '2026-09-23 21:00' );
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Pedro',
				'relationship'     => '',
				'type'             => 'deceased',
				'reference_date'   => '2026-09-22',
				'summary'          => 'descanse en paz',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Lucía',
				'relationship'     => '',
				'summary'          => 'operación',
			)
		);

		$this->freeze_time( '2026-09-24 05:00' );
		$this->assertSame(
			implode(
				"\n",
				array(
					'*Daily*',
					'',
					'*Especially*',
					'• Pedro: descanse en paz († 22/09)',
					'',
					'• Lucía: operación',
					'',
					'*Also for*',
					'• Rosa: de antes',
				)
			),
			DFXPRAY_Index_Composer::get_instance()->compose( $this->group )
		);

		$this->freeze_time( '2026-09-25 05:00' );
		$text = DFXPRAY_Index_Composer::get_instance()->compose( $this->group );
		$this->assertStringNotContainsString( '*Especially*', $text );
		$this->assertStringContainsString( "*For our departed*\n• Pedro: descanse en paz († 22/09)", $text );
	}

	/**
	 * An old intention that turns serious again is news too: a news update
	 * or a reopening bring it to "Especially" with its summary for the same
	 * days as a new one, and so do a request approved in moderation days
	 * after it was sent and an old case a leader writes down late. An update not marked as news leaves it in its place.
	 */
	public function test_news_updates_reopenings_and_approvals_go_first(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout' => 'proposals',
				'index_header' => '*Daily*',
				'index_footer' => '',
			)
		);

		$this->freeze_time( '2026-09-01 10:00' );
		$health    = array(
			'relationship' => '',
			'topic'        => 'health',
		);
		$readmit   = $this->make_active( $this->group, array( 'beneficiary_name' => 'Ignacio', 'summary' => 'reingresado por una infección' ) + $health );
		$quiet     = $this->make_active( $this->group, array( 'beneficiary_name' => 'Rosa', 'summary' => 'sigue igual' ) + $health );
		$reopened  = $this->make_active( $this->group, array( 'beneficiary_name' => 'Carlos', 'summary' => 'nueva cura' ) + $health );
		$intention = DFXPRAY_Intentions::get_instance();
		$intention->close( $reopened, 'expired' );
		$approved = $this->make_pending( $this->group, array( 'beneficiary_name' => 'Luisa', 'relationship' => '' ) );

		$this->freeze_time( '2026-09-23 21:00' );
		$this->assertTrue( $intention->approve( $approved, array( 'summary' => 'pide oración', 'topic' => 'health' ) ) );
		$this->make_active( $this->group, array( 'beneficiary_name' => 'Pepe', 'summary' => 'apuntado tarde', 'asked_on' => '2026-08-01' ) + $health );
		DFXPRAY_Updates::get_instance()->add( $readmit, 'Lo han vuelto a ingresar', true );
		DFXPRAY_Updates::get_instance()->add( $quiet, 'Sin cambios', false );
		$this->assertTrue( $intention->reopen( $reopened ) );

		$this->freeze_time( '2026-09-24 05:00' );
		$this->assertSame(
			implode(
				"\n",
				array(
					'*Daily*',
					'',
					'*Especially*',
					'• Ignacio: reingresado por una infección',
					'• Carlos: nueva cura',
					'• Luisa: pide oración',
					'• Pepe: apuntado tarde',
					'',
					'*For the health of*',
					'• Rosa',
				)
			),
			DFXPRAY_Index_Composer::get_instance()->compose( $this->group )
		);

		$this->freeze_time( '2026-09-25 05:00' );
		$this->assertStringNotContainsString( '*Especially*', DFXPRAY_Index_Composer::get_instance()->compose( $this->group ) );
	}

	/**
	 * With one day, a thanksgiving goes in exactly one daily message:
	 * the one of the day of the good news, or of the day it reaches the
	 * list, if it is written down later.
	 */
	public function test_thanksgivings_appear_once_counted_from_when_they_reach_the_list(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout'      => 'proposals',
				'index_header'      => '',
				'index_footer'      => '',
				'daily_thanks_days' => 1,
			)
		);
		$thanks = array(
			'relationship' => '',
			'type'         => 'thanksgiving',
		);

		$this->freeze_time( '2026-09-17 05:00' );
		$this->make_active( $this->group, array( 'beneficiary_name' => 'Luis', 'summary' => 'salió bien', 'reference_date' => '2026-09-17' ) + $thanks );
		$this->freeze_time( '2026-09-18 05:00' );
		$this->make_active( $this->group, array( 'beneficiary_name' => 'Manolo', 'summary' => 'todo muy bien', 'reference_date' => '2026-09-17' ) + $thanks );

		$composer = DFXPRAY_Index_Composer::get_instance();
		$this->freeze_time( '2026-09-17 05:00' );
		$this->assertStringContainsString( '• Luis: salió bien', $composer->compose( $this->group ) );
		$this->freeze_time( '2026-09-18 05:00' );
		$text = $composer->compose( $this->group );
		$this->assertStringNotContainsString( 'Luis', $text );
		$this->assertStringContainsString( '• Manolo: todo muy bien', $text );
		$this->freeze_time( '2026-09-19 05:00' );
		$this->assertStringNotContainsString( 'Manolo', $composer->compose( $this->group ) );
	}

	/**
	 * The brothers who celebrate their birthday that day open "Especially",
	 * unless the group turns it off.
	 */
	public function test_birthdays_open_the_daily_message(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout'  => 'proposals',
				'member_prefix' => 'N.H.',
				'index_header'  => '*Daily*',
				'index_footer'  => '',
			)
		);
		$members = DFXPRAY_Members::get_instance();
		$members->create(
			$this->group,
			array(
				'name'        => 'Ramón García Sanz',
				'called'      => 'Ramón García',
				'birth_day'   => '24',
				'birth_month' => '9',
			)
		);
		$members->create(
			$this->group,
			array(
				'name'        => 'Teo Martín',
				'birth_day'   => '24',
				'birth_month' => '9',
			)
		);
		$members->create(
			$this->group,
			array(
				'name'        => 'Luis',
				'birth_day'   => '25',
				'birth_month' => '9',
			)
		);

		$this->freeze_time( '2026-09-20 10:00' );
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Antonio',
				'relationship'     => '',
				'summary'          => 'tumor',
				'priority'         => 'high',
			)
		);
		$this->freeze_time( '2026-09-23 21:00' );
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Pedro',
				'relationship'     => '',
				'type'             => 'deceased',
				'reference_date'   => '2026-09-23',
				'summary'          => 'descanse en paz',
			)
		);
		$this->freeze_time( '2026-09-24 05:00' );

		$this->assertSame(
			implode(
				"\n",
				array(
					'*Daily*',
					'',
					'*Especially*',
					'• Pedro: descanse en paz († 23/09)',
					'',
					'• N.H. Ramón García and N.H. Teo: on their birthday',
					'',
					'• Antonio: tumor',
				)
			),
			DFXPRAY_Index_Composer::get_instance()->compose( $this->group )
		);
		$this->assertStringNotContainsString( 'birthday', DFXPRAY_Index_Composer::get_instance()->compose( $this->group, 'monthly' ) );

		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'daily_birthdays' => false ) );
		$this->assertStringNotContainsString( 'birthday', DFXPRAY_Index_Composer::get_instance()->compose( $this->group ) );
	}

	/**
	 * Members who would show the same name get their surname: "Juan Carlos
	 * Cuenca", "Javier López"; the others keep theirs.
	 */
	public function test_birthdays_tell_namesakes_apart(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout'  => 'proposals',
				'member_prefix' => 'N.H.',
				'index_header'  => '*Daily*',
				'index_footer'  => '',
			)
		);
		$members = DFXPRAY_Members::get_instance();
		$members->create(
			$this->group,
			array(
				'name'        => 'Juan Carlos Cuenca Alcázar',
				'called'      => 'Juan Carlos',
				'birth_day'   => '24',
				'birth_month' => '9',
			)
		);
		$members->create(
			$this->group,
			array(
				'name'   => 'Juan Carlos Espidel',
				'called' => 'Juan Carlos',
			)
		);
		$members->create(
			$this->group,
			array(
				'name'        => 'Javier de la Torre',
				'birth_day'   => '24',
				'birth_month' => '9',
			)
		);
		$members->create( $this->group, array( 'name' => 'Javier López' ) );
		$members->create(
			$this->group,
			array(
				'name'        => 'Teo Martín',
				'birth_day'   => '24',
				'birth_month' => '9',
			)
		);

		$this->freeze_time( '2026-09-24 05:00' );
		$this->assertSame(
			"*Daily*\n\n*Especially*\n• N.H. Javier de la Torre, N.H. Juan Carlos Cuenca and N.H. Teo: on their birthday",
			DFXPRAY_Index_Composer::get_instance()->compose( $this->group )
		);
	}

	/**
	 * A member who has died is remembered on the anniversary of his death,
	 * not on his birthday.
	 */
	public function test_death_anniversaries_open_the_daily_message(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout'  => 'proposals',
				'member_prefix' => 'N.H.',
				'index_header'  => '*Daily*',
				'index_footer'  => '',
			)
		);
		$members = DFXPRAY_Members::get_instance();
		$members->create(
			$this->group,
			array(
				'name'        => 'Juan Pérez',
				'birth_day'   => '24',
				'birth_month' => '9',
				'death_date'  => '2025-03-10',
			)
		);
		$members->create(
			$this->group,
			array(
				'name'       => 'Pedro Gil',
				'death_date' => '2024-09-24',
			)
		);

		$this->freeze_time( '2026-09-24 05:00' );
		$this->assertSame(
			"*Daily*\n\n*Especially*\n• N.H. Pedro: on the anniversary of his death († 2024)",
			DFXPRAY_Index_Composer::get_instance()->compose( $this->group )
		);
	}

	/**
	 * "Especially" keeps the departed, the birthdays and the serious
	 * intentions apart, with a blank line between them: an anniversary of a
	 * death goes with the departed, never next to a birthday.
	 */
	public function test_especially_keeps_its_cases_apart(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout'  => 'proposals',
				'member_prefix' => 'N.H.',
				'index_header'  => '*Daily*',
				'index_footer'  => '',
			)
		);
		$members = DFXPRAY_Members::get_instance();
		$members->create(
			$this->group,
			array(
				'name'        => 'Teo Martín',
				'birth_day'   => '24',
				'birth_month' => '9',
			)
		);
		$members->create(
			$this->group,
			array(
				'name'       => 'Pedro Gil',
				'death_date' => '2024-09-24',
			)
		);

		$this->freeze_time( '2026-09-20 10:00' );
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Antonio',
				'relationship'     => '',
				'summary'          => 'tumor',
				'priority'         => 'high',
			)
		);
		$this->freeze_time( '2026-09-23 21:00' );
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Rosa',
				'relationship'     => '',
				'type'             => 'deceased',
				'reference_date'   => '2026-09-23',
				'summary'          => 'descanse en paz',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Lucía',
				'relationship'     => '',
				'summary'          => 'operación',
			)
		);
		$this->freeze_time( '2026-09-24 05:00' );

		$this->assertSame(
			implode(
				"\n",
				array(
					'*Daily*',
					'',
					'*Especially*',
					'• Rosa: descanse en paz († 23/09)',
					'• N.H. Pedro: on the anniversary of his death († 2024)',
					'',
					'• N.H. Teo: on his birthday',
					'',
					'• Lucía: operación',
					'• Antonio: tumor',
				)
			),
			DFXPRAY_Index_Composer::get_instance()->compose( $this->group )
		);
	}

	/**
	 * A departed whose anniversary is remembered goes with the departed on
	 * that day, even after the intention closed, in both layouts.
	 */
	public function test_anniversaries_of_the_departed(): void {
		$id = $this->make_active(
			$this->group,
			array(
				'type'             => 'deceased',
				'reference_date'   => '2025-09-24',
				'anniversary'      => true,
				'beneficiary_name' => 'Abuela Pilar',
				'summary'          => 'descanso',
			)
		);
		DFXPRAY_Intentions::get_instance()->close( $id, 'expired' );
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_header' => '*Daily*',
				'index_footer' => '',
			)
		);
		$this->freeze_time( '2026-09-24 05:00' );

		$this->assertSame(
			"*Daily*\n\n*For our departed*\n• Abuela, madre de Luis: on the anniversary of their death († 2025)",
			DFXPRAY_Index_Composer::get_instance()->compose( $this->group )
		);

		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'index_layout' => 'proposals' ) );
		$this->assertSame(
			"*Daily*\n\n*Especially*\n• Abuela, madre de Luis: on the anniversary of their death († 2025)",
			DFXPRAY_Index_Composer::get_instance()->compose( $this->group )
		);
	}

	/**
	 * The leaders' edition says, after each person, who asks and their phone;
	 * the shared one never does.
	 */
	public function test_leaders_edition_says_who_asks(): void {
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'index_layout' => 'proposals',
				'index_header' => '*Daily*',
				'index_footer' => '',
			)
		);
		$this->freeze_time( '2026-09-10 10:00' ); // Old enough not to be news.
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Marina',
				'requester_name'   => 'Felipe Arizu',
				'relationship'     => 'esposa de',
				'summary'          => 'cáncer',
				'topic'            => 'health',
				'requester_phone'  => '+34600111222',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Irene',
				'requester_name'   => 'David Marín',
				'relationship'     => 'hija de',
				'summary'          => 'problemas de salud',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Laura',
				'requester_name'   => 'Eduardo Ruiz',
				'relationship'     => 'cuñada de',
				'summary'          => 'problemas de salud',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Pablo',
				'summary'          => 'trabajo',
			)
		);
		$this->freeze_time( '2026-09-24 05:00' );

		$this->assertSame(
			implode(
				"\n",
				array(
					'*Daily*',
					'',
					'*For the health of*',
					'• Marina, esposa de Felipe _(asked by Felipe Arizu · +34600111222)_',
					'',
					'*Also for*',
					'• Problemas de salud: Irene, hija de David _(asked by David Marín)_; Laura, cuñada de Eduardo _(asked by Eduardo Ruiz)_',
					'• Pablo, madre de Luis: trabajo _(asked by Luis Gómez)_',
				)
			),
			DFXPRAY_Index_Composer::get_instance()->compose( $this->group, 'daily', true )
		);
		$this->assertStringNotContainsString( 'asked by', DFXPRAY_Index_Composer::get_instance()->compose( $this->group ) );
	}

	/**
	 * Other groups keep the layout by type, without member prefixes.
	 */
	public function test_layout_by_type_is_the_default(): void {
		$this->make_active(
			$this->group,
			array(
				'requester_is_member' => true,
				'summary'             => 'por su salud',
			)
		);

		$text = DFXPRAY_Index_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( '*The sick and difficult situations*', $text );
		$this->assertStringContainsString( '• Carmen, madre de Luis: por su salud', $text );
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
