<?php
/**
 * Tests for the public request form.
 *
 * @package DFXPRAY
 */

/**
 * Form behaviour.
 */
class FormTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;
	use DFXPRAY_Http_Capture;

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
		reset_phpmailer_instance();
		$this->group = $this->make_group( 'Santa Marta' );
		wp_set_current_user( 0 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$this->stop_capturing_http();
		parent::tear_down();
	}

	/**
	 * A valid submission.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function post( array $overrides = array() ): array {
		return array_merge(
			array(
				'dfxpray_group'   => (string) $this->group,
				'dfxpray_nonce'   => wp_create_nonce( 'dfxpray_submit_' . $this->group ),
				'dfxpray_website' => '',
				'dfxpray_consent' => '1',
				'dfxpray_others'  => '1',
				'dfxpray_age'     => '1',
				'dfxpray'         => array(
					'requester_name'    => 'Luis',
					'requester_contact' => 'luis@example.org',
					'beneficiary_name'  => 'Carmen',
					'relationship'      => 'madre de',
					'type'              => 'event',
					'reference_date'    => '2026-09-28',
					'reference_time'    => '09:30',
					'body'              => 'Operación de cadera.',
					'discretion'        => 'initials',
				),
			),
			$overrides
		);
	}

	/**
	 * The rendered form holds every field in the specified order.
	 */
	public function test_render_contains_fields_in_order(): void {
		$html = DFXPRAY_Form::get_instance()->render( $this->group );

		$order = array(
			'dfxpray[requester_name]',
			'dfxpray[requester_contact]',
			'dfxpray[beneficiary_is_requester]',
			'dfxpray[beneficiary_name]',
			'dfxpray[relationship]',
			'dfxpray[type]',
			'dfxpray[reference_date]',
			'dfxpray[reference_time]',
			'dfxpray[body]',
			'dfxpray[discretion]',
			'dfxpray_consent',
			'dfxpray_others',
			'dfxpray_age',
		);

		$last = -1;
		foreach ( $order as $name ) {
			$position = strpos( $html, 'name="' . $name . '"' );
			$this->assertNotFalse( $position, "Missing {$name}" );
			$this->assertGreaterThan( $last, $position, "{$name} is out of order" );
			$last = $position;
		}

		$this->assertStringContainsString( 'name="dfxpray_website"', $html );
		$this->assertStringContainsString( 'name="dfxpray_nonce"', $html );
		$this->assertStringContainsString( 'value="first_name" checked', $html );
		$this->assertStringContainsString( admin_url( 'admin-post.php' ), $html );
		$this->assertStringContainsString( 'a moment in particular', $html );
	}

	/**
	 * The consent links to the privacy policy.
	 */
	public function test_consent_links_privacy_policy(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Privacy',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $page );

		$html = DFXPRAY_Form::get_instance()->render( $this->group );

		$this->assertStringContainsString( get_permalink( $page ), $html );
	}

	/**
	 * Archived or missing groups show no form.
	 */
	public function test_inactive_group_has_no_form(): void {
		DFXPRAY_Groups::get_instance()->archive( $this->group );

		$this->assertStringNotContainsString( '<form', DFXPRAY_Form::get_instance()->render( $this->group ) );
		$this->assertStringNotContainsString( '<form', DFXPRAY_Form::get_instance()->render( 999999 ) );
	}

	/**
	 * A valid submission enters as pending in the right group.
	 */
	public function test_valid_submission(): void {
		$result = DFXPRAY_Form::get_instance()->handle( $this->post(), '203.0.113.5' );

		$this->assertTrue( $result['ok'], $result['code'] ?? '' );
		$intention = DFXPRAY_Intentions::get_instance()->get( $result['id'] );
		$this->assertSame( 'pending', $intention['status'] );
		$this->assertSame( $this->group, $intention['group_id'] );
		$this->assertSame( 'initials', $intention['discretion'] );
		$this->assertSame( '09:30', $intention['reference_time'] );
	}

	/**
	 * Bad nonce.
	 */
	public function test_bad_nonce(): void {
		$result = DFXPRAY_Form::get_instance()->handle( $this->post( array( 'dfxpray_nonce' => 'nope' ) ), '203.0.113.5' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'expired', $result['code'] );
	}

	/**
	 * The honeypot silently swallows bots: they see success, nothing is stored.
	 */
	public function test_honeypot(): void {
		$result = DFXPRAY_Form::get_instance()->handle( $this->post( array( 'dfxpray_website' => 'http://spam' ) ), '203.0.113.5' );

		$this->assertTrue( $result['ok'] );
		$this->assertNull( $result['id'] );
		$this->assertSame( array(), DFXPRAY_Intentions::get_instance()->query( $this->group ) );
	}

	/**
	 * The explicit consent, the statement about the person prayed for and
	 * the minimum age are all required.
	 */
	public function test_consent_required(): void {
		foreach ( array( 'dfxpray_consent', 'dfxpray_others', 'dfxpray_age' ) as $box ) {
			$result = DFXPRAY_Form::get_instance()->handle( $this->post( array( $box => '' ) ), '203.0.113.5' );

			$this->assertFalse( $result['ok'], $box );
			$this->assertSame( 'consent', $result['code'], $box );
		}
		$this->assertSame( array(), DFXPRAY_Intentions::get_instance()->query( $this->group ) );
	}

	/**
	 * Before sending, the form gives the basic information on data
	 * protection: controller, purpose, legal basis, recipients and rights,
	 * and asks for explicit consent for health and belief data.
	 */
	public function test_basic_data_protection_information(): void {
		DFXPRAY_Settings::get_instance()->save_global(
			array(
				'privacy_controller' => 'Parroquia San Manuel González',
				'privacy_contact'    => 'privacidad@example.org',
				'consent_min_age'    => 16,
			)
		);

		$html = DFXPRAY_Form::get_instance()->render( $this->group );

		$this->assertStringContainsString( 'Parroquia San Manuel González (privacidad@example.org)', $html );
		$this->assertStringContainsString( 'to pray for your intention in the group “Santa Marta”', $html );
		$this->assertStringContainsString( 'arts. 6.1.a and 9.2.a GDPR', $html );
		$this->assertStringContainsString( 'including any about health or beliefs', $html );
		$this->assertStringContainsString( 'I am at least 16 years old.', $html );
		$this->assertStringContainsString( 'If I ask for someone else', $html );
	}

	/**
	 * Without a controller set, the site's name stands for it and no contact
	 * is shown; the minimum age is 14 by default.
	 */
	public function test_controller_defaults_to_the_site(): void {
		update_option( 'blogname', 'Mi parroquia' );

		$html = DFXPRAY_Form::get_instance()->render( $this->group );

		$this->assertStringContainsString( 'Mi parroquia.', $html );
		$this->assertStringContainsString( 'I am at least 14 years old.', $html );
	}

	/**
	 * Validation errors come back with the intention's error code.
	 */
	public function test_validation_error(): void {
		$post                    = $this->post();
		$post['dfxpray']['body'] = '';
		$result                  = DFXPRAY_Form::get_instance()->handle( $post, '203.0.113.5' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'dfxpray_body_required', $result['code'] );
	}

	/**
	 * Per-IP rate limit.
	 */
	public function test_rate_limit(): void {
		DFXPRAY_Settings::get_instance()->save_global( array( 'rate_limit_per_hour' => 2 ) );
		$form = DFXPRAY_Form::get_instance();

		$this->assertTrue( $form->handle( $this->post(), '198.51.100.7' )['ok'] );
		$this->assertTrue( $form->handle( $this->post(), '198.51.100.7' )['ok'] );
		$third = $form->handle( $this->post(), '198.51.100.7' );
		$this->assertFalse( $third['ok'] );
		$this->assertSame( 'rate_limited', $third['code'] );
		$this->assertTrue( $form->handle( $this->post(), '198.51.100.8' )['ok'] );
	}

	/**
	 * Turnstile, when configured, is verified with Cloudflare.
	 */
	public function test_turnstile(): void {
		DFXPRAY_Settings::get_instance()->save_global(
			array(
				'turnstile_site_key' => 'site',
				'turnstile_secret'   => 'secret',
			)
		);
		$form = DFXPRAY_Form::get_instance();

		$this->assertStringContainsString( 'cf-turnstile', $form->render( $this->group ) );

		$this->capture_http(
			array(
				'headers'  => array(),
				'body'     => '{"success":false}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			)
		);
		$failed = $form->handle( $this->post( array( 'cf-turnstile-response' => 'token' ) ), '203.0.113.5' );
		$this->assertSame( 'captcha', $failed['code'] );
		$this->assertSame( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $this->http_requests[0]['url'] );
		$this->assertSame( 'secret', $this->http_requests[0]['args']['body']['secret'] );
		$this->stop_capturing_http();

		$this->capture_http(
			array(
				'headers'  => array(),
				'body'     => '{"success":true}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			)
		);
		$this->assertTrue( $form->handle( $this->post( array( 'cf-turnstile-response' => 'token' ) ), '203.0.113.5' )['ok'] );
	}

	/**
	 * Submissions to archived groups are refused.
	 */
	public function test_archived_group_refuses(): void {
		DFXPRAY_Groups::get_instance()->archive( $this->group );

		$this->assertSame( 'unavailable', DFXPRAY_Form::get_instance()->handle( $this->post(), '203.0.113.5' )['code'] );
	}

	/**
	 * The confirmation message replaces the form after sending, and reveals nothing else.
	 */
	public function test_confirmation_message(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'confirmation_message' => 'Gracias, rezaremos.' ) );
		$_GET['dfxpray_sent'] = (string) $this->group;

		$html = DFXPRAY_Form::get_instance()->render( $this->group );

		unset( $_GET['dfxpray_sent'] );
		$this->assertStringContainsString( 'Gracias, rezaremos.', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * After an error, the draft is shown again with the message.
	 */
	public function test_draft_repopulates_after_error(): void {
		$key                   = DFXPRAY_Form::get_instance()->store_draft( array( 'requester_name' => 'Luis & "Ana"' ) );
		$_GET['dfxpray_error'] = 'consent';
		$_GET['dfxpray_draft'] = $key;

		$html = DFXPRAY_Form::get_instance()->render( $this->group );

		unset( $_GET['dfxpray_error'], $_GET['dfxpray_draft'] );
		$this->assertStringContainsString( 'value="Luis &amp; &quot;Ana&quot;"', $html );
		$this->assertStringContainsString( 'dfxpray-form__error', $html );
	}

	/**
	 * Redirects stay on the site.
	 */
	public function test_return_url_is_validated(): void {
		$form = DFXPRAY_Form::get_instance();

		$this->assertSame( home_url( '/x/' ), $form->safe_return_url( home_url( '/x/' ) ) );
		$this->assertSame( home_url( '/' ), $form->safe_return_url( 'https://evil.example.com/' ) );
	}

	/**
	 * Shortcode and block share the renderer.
	 */
	public function test_shortcode_and_block(): void {
		$this->assertStringContainsString( 'name="dfxpray_group" value="' . $this->group . '"', do_shortcode( '[dfxpray_form group="' . $this->group . '"]' ) );

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'dfxpray/form' ) );
		$html = render_block(
			array(
				'blockName'    => 'dfxpray/form',
				'attrs'        => array( 'groupId' => $this->group ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( 'name="dfxpray_group" value="' . $this->group . '"', $html );
	}

	/**
	 * With a single group, the shortcode needs no attribute.
	 */
	public function test_single_group_default(): void {
		$this->assertStringContainsString( 'name="dfxpray_group" value="' . $this->group . '"', do_shortcode( '[dfxpray_form]' ) );
	}
}
