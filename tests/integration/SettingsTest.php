<?php
/**
 * Tests for global and per-group settings.
 *
 * @package DFXPRAY
 */

/**
 * Settings behaviour.
 */
class SettingsTest extends DFXPRAY_Test_Case {

	/**
	 * Specification defaults.
	 */
	public function test_defaults(): void {
		$settings = DFXPRAY_Settings::get_instance();

		$this->assertSame( 2, $settings->get( 'event_grace_days' ) );
		$this->assertSame( 30, $settings->get( 'deceased_days' ) );
		$this->assertSame( 30, $settings->get( 'ongoing_review_days' ) );
		$this->assertSame( 7, $settings->get( 'thanksgiving_days' ) );
		$this->assertSame( 3, $settings->get( 'recent_deceased_days' ) );
		$this->assertSame( 90, $settings->get( 'retention_days' ) );
		$this->assertSame( '07:00', $settings->get( 'digest_time' ) );
		$this->assertSame( 15, $settings->get( 'alert_lead_minutes' ) );
		$this->assertFalse( $settings->get( 'alert_at_exact_time' ) );
		$this->assertSame( '23:00', $settings->get( 'quiet_start' ) );
		$this->assertSame( '07:00', $settings->get( 'quiet_end' ) );
		$this->assertSame( 4000, DFXPRAY_Settings::INDEX_LENGTH_WARNING );
	}

	/**
	 * Global values override defaults.
	 */
	public function test_global_overrides_default(): void {
		$settings = DFXPRAY_Settings::get_instance();
		$settings->save_global( array( 'deceased_days' => 40, 'retention_days' => 60 ) );

		$this->assertSame( 40, $settings->get( 'deceased_days' ) );
		$this->assertSame( 60, $settings->get( 'retention_days' ) );
	}

	/**
	 * Group values override global ones, only for that group.
	 */
	public function test_group_overrides_global(): void {
		$groups   = DFXPRAY_Groups::get_instance();
		$a        = $groups->create( array( 'name' => 'A' ) );
		$b        = $groups->create( array( 'name' => 'B' ) );
		$settings = DFXPRAY_Settings::get_instance();

		$settings->save_global( array( 'digest_time' => '06:00' ) );
		$settings->save_group( $a, array( 'digest_time' => '08:15' ) );

		$this->assertSame( '08:15', $settings->get( 'digest_time', $a ) );
		$this->assertSame( '06:00', $settings->get( 'digest_time', $b ) );
	}

	/**
	 * Leaders cannot override global-only settings such as retention.
	 */
	public function test_group_cannot_override_global_only_keys(): void {
		$a        = DFXPRAY_Groups::get_instance()->create( array( 'name' => 'A' ) );
		$settings = DFXPRAY_Settings::get_instance();

		$settings->save_group( $a, array( 'retention_days' => 5000, 'turnstile_secret' => 'x' ) );

		$this->assertSame( 90, $settings->get( 'retention_days', $a ) );
		$this->assertSame( array(), DFXPRAY_Groups::get_instance()->get_settings( $a ) );
	}

	/**
	 * Values are sanitised to the type of their default.
	 */
	public function test_sanitises_values(): void {
		$settings = DFXPRAY_Settings::get_instance();

		$settings->save_global(
			array(
				'deceased_days'       => '-4',
				'digest_time'         => '25:99',
				'quiet_start'         => '22:30',
				'alert_at_exact_time' => '1',
				'index_footer'        => '<script>x</script>Ave María',
			)
		);

		$this->assertSame( 1, $settings->get( 'deceased_days' ) );
		$this->assertSame( '07:00', $settings->get( 'digest_time' ) );
		$this->assertSame( '22:30', $settings->get( 'quiet_start' ) );
		$this->assertTrue( $settings->get( 'alert_at_exact_time' ) );
		$this->assertSame( 'Ave María', $settings->get( 'index_footer' ) );
	}

	/**
	 * Channel settings merge key by key, so a partial override keeps the rest.
	 */
	public function test_channel_settings_merge_per_key(): void {
		$a        = DFXPRAY_Groups::get_instance()->create( array( 'name' => 'A' ) );
		$settings = DFXPRAY_Settings::get_instance();

		$settings->save_group( $a, array( 'channels' => array( 'telegram' => array( 'chat_id' => '-100123' ) ) ) );

		$channels = $settings->get( 'channels', $a );
		$this->assertSame( '-100123', $channels['telegram']['chat_id'] );
		$this->assertSame( 'shareable', $channels['telegram']['variant'] );
		$this->assertTrue( $channels['email']['digest'] );
	}

	/**
	 * Unknown channel variants fall back to the safe one.
	 */
	public function test_unknown_variant_falls_back_to_shareable(): void {
		$settings = DFXPRAY_Settings::get_instance();
		$settings->save_global( array( 'channels' => array( 'webhook' => array( 'variant' => 'everything' ) ) ) );

		$this->assertSame( 'shareable', $settings->get( 'channels' )['webhook']['variant'] );
	}

	/**
	 * Group scope lists which keys leaders may override.
	 */
	public function test_group_keys_exclude_global_only(): void {
		$keys = DFXPRAY_Settings::get_instance()->group_keys();

		$this->assertContains( 'digest_time', $keys );
		$this->assertContains( 'channels', $keys );
		$this->assertNotContains( 'retention_days', $keys );
		$this->assertNotContains( 'turnstile_secret', $keys );
	}
}
