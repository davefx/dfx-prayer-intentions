<?php
/**
 * Tests for GDPR tooling.
 *
 * @package DFXPRAY
 */

/**
 * Privacy behaviour.
 */
class PrivacyTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * A group.
	 *
	 * @var int
	 */
	private int $group;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->group = $this->make_group();
	}

	/**
	 * Exporter and eraser are registered with WordPress.
	 */
	public function test_registered(): void {
		$this->assertArrayHasKey( 'dfx-prayer-intentions', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'dfx-prayer-intentions', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	/**
	 * Export finds requests by the requester's contact, case-insensitively.
	 */
	public function test_export_by_contact(): void {
		$mine = $this->make_active(
			$this->group,
			array(
				'requester_contact' => 'Luis@Example.org',
				'body'              => 'My text',
				'key_dates'         => array(
					array(
						'date'  => '2026-09-30',
						'time'  => '12:00',
						'label' => 'Funeral',
					),
				),
			)
		);
		DFXPRAY_Updates::get_instance()->add( $mine, 'An update' );
		DFXPRAY_Comments::get_instance()->add( $mine, 'Called him', 'call' );
		$this->make_active( $this->group, array( 'requester_contact' => 'other@example.org' ) );

		$result = DFXPRAY_Privacy::get_instance()->export( 'luis@example.org', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$item   = $result['data'][0];
		$values = array_column( $item['data'], 'value', 'name' );
		$this->assertSame( 'dfxpray-intention-' . $mine, $item['item_id'] );
		$this->assertSame( 'My text', $values['Intention'] );
		$this->assertStringContainsString( 'An update', $values['Updates'] );
		$this->assertStringContainsString( 'Called him', $values['Leaders’ notes'] );
		$this->assertStringContainsString( '2026-09-30 12:00 Funeral', $values['Key dates'] );
	}

	/**
	 * Requests are found by the phone too, written in any format, whether it
	 * was given as the contact or kept as the requester's phone.
	 */
	public function test_find_by_phone_in_any_format(): void {
		$as_contact = $this->make_active( $this->group, array( 'requester_contact' => '600 11 12 22' ) );
		$as_phone   = $this->make_active(
			$this->group,
			array(
				'requester_contact' => '',
				'requester_phone'   => '+34600111222',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'requester_name'    => 'Ana Pérez',
				'requester_contact' => '600 11 12 23',
			)
		);

		foreach ( array( '600111222', '+34 600 11 12 22', '0034-600-111-222' ) as $phone ) {
			$ids = array_map( static fn( $item ) => $item['item_id'], DFXPRAY_Privacy::get_instance()->export( $phone, 1 )['data'] );
			sort( $ids );
			$this->assertSame( array( 'dfxpray-intention-' . $as_contact, 'dfxpray-intention-' . $as_phone ), $ids, $phone );
		}
	}

	/**
	 * The members of a group, found by their phone, can see and erase their
	 * data too.
	 */
	public function test_members_export_and_erase(): void {
		$members = DFXPRAY_Members::get_instance();
		$member  = $members->create(
			$this->group,
			array(
				'name'        => 'Ramón García',
				'called'      => 'Ramón',
				'phone'       => '+34 629 04 26 26',
				'birth_day'   => '5',
				'birth_month' => '3',
				'birth_year'  => '1970',
				'notes'       => 'II Retiro',
			)
		);
		$other   = $members->create(
			$this->group,
			array(
				'name'  => 'Luis Pérez',
				'phone' => '600 00 00 01',
			)
		);

		$export = DFXPRAY_Privacy::get_instance()->export( '629042626', 1 );
		$item   = array_values( array_filter( $export['data'], static fn( $i ) => 'dfxpray-member-' . $member === $i['item_id'] ) );
		$this->assertCount( 1, $item );
		$values = array_column( $item[0]['data'], 'value', 'name' );
		$this->assertSame( 'Ramón García', $values['Name'] );
		$this->assertSame( '5/3/1970', $values['Birthday'] );
		$this->assertSame( 'II Retiro', $values['Notes'] );

		$result = DFXPRAY_Privacy::get_instance()->erase( '+34629042626', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertNull( $members->get( $member ) );
		$this->assertNotNull( $members->get( $other ) );
	}

	/**
	 * Invitations to lead a group hold a name and an email: they are found,
	 * exported and erased by that email.
	 */
	public function test_invitations_export_and_erase(): void {
		$invitations = DFXPRAY_Invitations::get_instance();
		$mine        = $invitations->invite( $this->group, 'Pedro Gil', 'pedro@example.org' );
		$other       = $invitations->invite( $this->group, 'Juan', 'juan@example.org' );

		$export = DFXPRAY_Privacy::get_instance()->export( 'PEDRO@example.org', 1 );
		$item   = array_values( array_filter( $export['data'], static fn( $i ) => 'dfxpray-invitation-' . $mine === $i['item_id'] ) );
		$this->assertCount( 1, $export['data'] );
		$this->assertCount( 1, $item );
		$values = array_column( $item[0]['data'], 'value', 'name' );
		$this->assertSame( 'Pedro Gil', $values['Name'] );
		$this->assertSame( 'pedro@example.org', $values['Email'] );
		$this->assertSame( 'Group', array_keys( $values )[0] );

		$result = DFXPRAY_Privacy::get_instance()->erase( 'pedro@example.org', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertNull( $invitations->get( $mine ) );
		$this->assertNotNull( $invitations->get( $other ) );
	}

	/**
	 * An administrator can look a contact up and erase it from the global
	 * settings, also by phone, which WordPress' own tools do not take.
	 */
	public function test_privacy_lookup_and_erase_action(): void {
		$mine = $this->make_active( $this->group, array( 'requester_contact' => '600 11 12 22' ) );

		$this->assertCount( 1, DFXPRAY_Privacy::get_instance()->export( '600111222', 1 )['data'] );

		$result = DFXPRAY_Admin_Actions::get_instance()->run( 'privacy_erase', array( 'contact' => '+34 600111222' ) );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertNull( DFXPRAY_Intentions::get_instance()->get( $mine ) );
	}

	/**
	 * Erasing removes the requester's intentions and everything attached.
	 */
	public function test_erase_by_contact(): void {
		$mine  = $this->make_active( $this->group, array( 'requester_contact' => 'luis@example.org' ) );
		$other = $this->make_active( $this->group, array( 'requester_contact' => 'other@example.org' ) );
		DFXPRAY_Comments::get_instance()->add( $mine, 'Called' );

		$result = DFXPRAY_Privacy::get_instance()->erase( 'luis@example.org', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertNull( DFXPRAY_Intentions::get_instance()->get( $mine ) );
		$this->assertSame( array(), DFXPRAY_Comments::get_instance()->for_intention( $mine ) );
		$this->assertNotNull( DFXPRAY_Intentions::get_instance()->get( $other ) );
	}

	/**
	 * Empty contact never matches anything.
	 */
	public function test_empty_contact_matches_nothing(): void {
		$this->make_active( $this->group, array( 'requester_contact' => '' ) );

		$this->assertSame( array(), DFXPRAY_Privacy::get_instance()->export( '', 1 )['data'] );
	}

	/**
	 * The suggested policy describes the platform: who runs the site as
	 * processor, that each group's controller is on its form, the
	 * subprocessors, and everything else the GDPR asks to tell.
	 */
	public function test_policy_text(): void {
		DFXPRAY_Settings::get_instance()->save_global(
			array(
				'provider_name'    => 'David Marín Carreño',
				'provider_tax_id'  => '12345678Z',
				'provider_address' => 'Calle Mayor 1, Madrid',
				'provider_email'   => 'privacidad@example.org',
				'subprocessors'    => "Hosting: Hetzner (Germany)\nEmail: Brevo (France)",
			)
		);
		$text = DFXPRAY_Privacy::get_instance()->policy_text();

		foreach ( array( 'David Marín Carreño (12345678Z, Calle Mayor 1, Madrid, privacidad@example.org)', 'data processor (art. 28 of the GDPR)', 'shown on the form of that group', 'Hosting: Hetzner (Germany)', 'Email: Brevo (France)', 'prayer', '90 days', 'explicit consent', '9.2.a', 'WhatsApp', 'Telegram', 'Cloudflare', 'outside the European Union', 'art. 14.5.b', 'Members of the group', '9.2.d', 'at least 14 years old', 'withdraw your consent', 'supervisory authority', 'invite another person to lead it', '7 days' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text );
		}
		$this->assertNotFalse( has_action( 'admin_init', array( DFXPRAY_Privacy::get_instance(), 'add_policy_content' ) ) );
	}

	/**
	 * The data processing agreement of a group, ready to accept, names both
	 * parties and covers what article 28 requires.
	 */
	public function test_processing_agreement(): void {
		DFXPRAY_Settings::get_instance()->save_global(
			array(
				'provider_name'   => 'David Marín Carreño',
				'provider_tax_id' => '12345678Z',
				'subprocessors'   => 'Hosting: Hetzner (Germany)',
			)
		);
		DFXPRAY_Settings::get_instance()->save_group_privacy( $this->group, array( 'privacy_controller' => 'Parroquia San Manuel González' ) );

		$text = DFXPRAY_Privacy::get_instance()->processing_agreement( $this->group );

		foreach ( array( 'Parroquia San Manuel González', 'David Marín Carreño (12345678Z)', 'documented instructions', 'confidentiality', 'art. 32', 'Hosting: Hetzner (Germany)', 'without undue delay', 'delete all the data of the group', 'free of charge', 'health', 'religious beliefs' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text );
		}
	}
}
