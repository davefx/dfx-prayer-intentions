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
		$mine = $this->make_active( $this->group, array( 'requester_contact' => 'Luis@Example.org', 'body' => 'My text' ) );
		DFXPRAY_Updates::get_instance()->add( $mine, 'An update' );
		$this->make_active( $this->group, array( 'requester_contact' => 'other@example.org' ) );

		$result = DFXPRAY_Privacy::get_instance()->export( 'luis@example.org', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$item   = $result['data'][0];
		$values = array_column( $item['data'], 'value', 'name' );
		$this->assertSame( 'dfxpray-intention-' . $mine, $item['item_id'] );
		$this->assertSame( 'My text', $values['Intention'] );
		$this->assertStringContainsString( 'An update', $values['Updates'] );
		$this->assertArrayNotHasKey( 'Internal comments', $values );
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
	 * A policy text is suggested.
	 */
	public function test_policy_text(): void {
		$text = DFXPRAY_Privacy::get_instance()->policy_text();

		$this->assertStringContainsString( 'prayer', $text );
		$this->assertStringContainsString( '90', $text );
		$this->assertNotFalse( has_action( 'admin_init', array( DFXPRAY_Privacy::get_instance(), 'add_policy_content' ) ) );
	}
}
