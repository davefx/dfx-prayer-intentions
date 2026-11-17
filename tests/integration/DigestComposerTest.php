<?php
/**
 * Tests for the daily and monthly digests.
 *
 * @package DFXPRAY
 */

/**
 * Digest composition: the shareable version is the app's message; the
 * leaders' one is that message with who asks for each intention and a
 * private block.
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
	 * Set up: Sunday 20 September 2026, 09:00.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		$this->freeze_time( '2026-09-20 09:00' );
		$this->group = $this->make_group();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The shareable daily digest is the daily message of the app, as it is.
	 */
	public function test_shareable_is_the_daily_message(): void {
		$this->make_active( $this->group, array( 'summary' => 'operación de cadera' ) );
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Rosa',
				'summary'          => 'para el diario',
				'scope'            => 'monthly',
			)
		);

		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertSame( DFXPRAY_Index_Composer::get_instance()->compose( $this->group ), $digest['shareable'] );
		$this->assertFalse( $digest['is_empty'] );
	}

	/**
	 * The leaders' version is the same message, saying who asks for each
	 * intention and how to reach them.
	 */
	public function test_leaders_see_who_asks_for_each_intention(): void {
		$this->make_active(
			$this->group,
			array(
				'summary'         => 'operación de cadera',
				'requester_phone' => '+34 600 11 12 22',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name'  => 'Rosa',
				'requester_name'    => 'Ana Pérez',
				'requester_contact' => '',
				'summary'           => 'examen',
			)
		);

		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( '• Carmen, madre de Luis: operación de cadera _(asked by Luis Gómez · +34600111222)_', $digest['leaders'] );
		$this->assertStringContainsString( '• Rosa, madre de Ana: examen _(asked by Ana Pérez)_', $digest['leaders'] );
		$this->assertStringNotContainsString( 'asked by', $digest['shareable'] );
		$this->assertStringNotContainsString( '600111222', $digest['shareable'] );
	}

	/**
	 * A group may leave the phones out of the leaders' version.
	 */
	public function test_leaders_version_without_phones(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_phones' => false ) );
		$this->make_active(
			$this->group,
			array(
				'summary'         => 'operación de cadera',
				'requester_phone' => '+34 600 11 12 22',
				'to_ask'          => true,
			)
		);

		$leaders = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group )['leaders'];

		$this->assertStringContainsString( 'operación de cadera _(asked by Luis Gómez)_', $leaders );
		$this->assertStringContainsString( '– ask Luis Gómez', $leaders );
		$this->assertStringNotContainsString( '600111222', $leaders );
	}

	/**
	 * Without anything private, the leaders' version has no private block.
	 */
	public function test_no_private_block_when_there_is_nothing_private(): void {
		$this->make_active( $this->group );
		$this->freeze_time( '2026-09-22 09:00' ); // No longer news.

		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringNotContainsString( '*🔒 Leaders only*', $digest['leaders'] );
	}

	/**
	 * After the message, the leaders' block: what waits in the panel, with
	 * links, in this order.
	 */
	public function test_private_block_with_the_panel(): void {
		$this->make_active(
			$this->group,
			array(
				'type'           => 'event',
				'reference_date' => '2026-09-18',
				'summary'        => 'Past exam',
			)
		);
		$this->make_pending( $this->group, array( 'beneficiary_name' => 'Pending person' ) );
		DFXPRAY_Intentions::get_instance()->process_due();

		$digest  = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );
		$leaders = $digest['leaders'];

		$this->assertStringStartsWith( DFXPRAY_Index_Composer::get_instance()->compose( $this->group, 'daily', true ), $leaders );
		$this->assertStringContainsString( "\n\n────────────\n*🔒 Leaders only*\n\n*Pending in the panel*\n• Pending moderation: 1 – " . admin_url( 'admin.php?page=dfxpray-group-' . $this->group . '&tab=moderation' ) . "\n• Reviews due: 1 – ", $leaders );
		$this->assertStringNotContainsString( 'Pending person', $digest['shareable'] );
		$this->assertStringNotContainsString( 'Leaders only', $digest['shareable'] );
	}

	/**
	 * "To ask" lists the intentions marked to ask about and those whose review
	 * comes in the next two days, with the requester to ask.
	 */
	public function test_to_ask(): void {
		$intentions = DFXPRAY_Intentions::get_instance();
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Rosa',
				'summary'          => 'marcada',
				'to_ask'           => true,
				'requester_phone'  => '+34600111222',
			)
		);
		$soon = $this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Juan',
				'summary'          => 'pronto',
			)
		);
		$late = $this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Pablo',
				'summary'          => 'más tarde',
			)
		);
		$intentions->postpone( $soon, '2026-09-22' );
		$intentions->postpone( $late, '2026-09-30' );

		$leaders = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group )['leaders'];

		$this->assertStringContainsString( "*To ask*\n", $leaders );
		$this->assertStringContainsString( '• Rosa, madre de Luis: marcada – ask Luis Gómez (+34600111222)', $leaders );
		$this->assertStringContainsString( '• Juan, madre de Luis: pronto – ask Luis Gómez (+34600111222), review on 22/09', $leaders );
		$this->assertStringNotContainsString( 'más tarde –', $leaders );
	}

	/**
	 * News of the last 24 hours only reach the leaders.
	 */
	public function test_news_of_the_last_day_for_leaders(): void {
		$old = $this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Tomás',
				'summary'          => 'Unemployment',
			)
		);
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Elena',
				'summary'          => 'Old one',
			)
		);

		$this->freeze_time( '2026-09-23 12:00' );
		DFXPRAY_Updates::get_instance()->add( $old, 'He found a job!', true );
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Nuria',
				'summary'          => 'New today',
			)
		);

		$this->freeze_time( '2026-09-24 07:00' );
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( "*News of the last 24 hours*\n• Nuria, madre de Luis: New today\n• Tomás, madre de Luis: He found a job!", $digest['leaders'] );
		$this->assertStringNotContainsString( 'Elena, madre de Luis: Old one', substr( $digest['leaders'], strpos( $digest['leaders'], 'Leaders only' ) ) );
		$this->assertStringNotContainsString( 'He found a job!', $digest['shareable'] );
	}

	/**
	 * Internal comments only reach the leaders, and only when configured.
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
	 * Today's appointments (key dates and timed events) reach the leaders.
	 */
	public function test_today_for_leaders(): void {
		$this->make_active(
			$this->group,
			array(
				'type'             => 'deceased',
				'reference_date'   => '2026-09-19',
				'beneficiary_name' => 'Antonio',
				'relationship'     => 'padre de',
				'requester_name'   => 'Javier',
				'summary'          => 'descanso eterno',
				'key_dates'        => array(
					array(
						'date'  => '2026-09-20',
						'time'  => '12:00',
						'label' => 'Funeral',
					),
				),
			)
		);
		$this->make_active(
			$this->group,
			array(
				'type'             => 'event',
				'reference_date'   => '2026-09-20',
				'reference_time'   => '17:00',
				'summary'          => 'Evening surgery',
				'beneficiary_name' => 'Rosa',
			)
		);

		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( "*Today*\n• 12:00 – Funeral: Antonio, padre de Javier\n• 17:00 – Rosa, madre de Luis: Evening surgery", $digest['leaders'] );
		$this->assertStringNotContainsString( 'Funeral', $digest['shareable'] );
	}

	/**
	 * Discretion applies in both versions; the full text never appears.
	 */
	public function test_discretion_applies(): void {
		$this->make_active(
			$this->group,
			array(
				'discretion' => 'anonymous',
				'summary'    => 'Private matter',
			)
		);

		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertStringContainsString( 'A private intention: Private matter', $digest['shareable'] );
		$this->assertStringNotContainsString( 'Carmen', $digest['shareable'] );
		$this->assertStringNotContainsString( 'Please pray', $digest['leaders'] );
		$this->assertStringNotContainsString( 'Please pray', $digest['shareable'] );
	}

	/**
	 * With no intentions, the group may choose not to send anything.
	 */
	public function test_empty_and_set_not_to_send(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'digest_when_empty' => 'none' ) );

		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertTrue( $digest['is_empty'] );
		$this->assertSame( '', $digest['shareable'] );
		$this->assertSame( '', $digest['leaders'] );
	}

	/**
	 * With no intentions, by default the message says so.
	 */
	public function test_empty_short_version(): void {
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $this->group );

		$this->assertTrue( $digest['is_empty'] );
		$this->assertStringContainsString( 'There are no active intentions.', $digest['shareable'] );
	}

	/**
	 * The monthly digest: the monthly list to share, and for the leaders the
	 * same list with who asks, what waits in the panel and what to ask.
	 */
	public function test_monthly(): void {
		$this->make_active(
			$this->group,
			array(
				'beneficiary_name' => 'Rosa',
				'summary'          => 'situación larga',
				'scope'            => 'monthly',
				'to_ask'           => true,
			)
		);
		$this->make_pending( $this->group, array( 'beneficiary_name' => 'Pending person' ) );

		$digest = DFXPRAY_Digest_Composer::get_instance()->compose_monthly( $this->group );

		$this->assertSame( DFXPRAY_Index_Composer::get_instance()->compose( $this->group, 'monthly' ), $digest['shareable'] );
		$this->assertStringStartsWith( DFXPRAY_Index_Composer::get_instance()->compose( $this->group, 'monthly', true ), $digest['leaders'] );
		$this->assertStringContainsString( 'Rosa, madre de Luis: situación larga _(asked by Luis Gómez)_', $digest['leaders'] );
		$this->assertStringContainsString( '*Pending in the panel*', $digest['leaders'] );
		$this->assertStringContainsString( "*To ask*\n• Rosa, madre de Luis: situación larga – ask Luis Gómez", $digest['leaders'] );
		$this->assertStringNotContainsString( 'News of the last 24 hours', $digest['leaders'] );
	}
}
