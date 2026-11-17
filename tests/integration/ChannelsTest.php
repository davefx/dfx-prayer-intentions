<?php
/**
 * Tests for delivery channels.
 *
 * @package DFXPRAY
 */

/**
 * Channels behaviour.
 */
class ChannelsTest extends DFXPRAY_Test_Case {

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
		$leader_a    = self::factory()->user->create(
			array(
				'role'       => 'dfxpray_leader',
				'user_email' => 'ana@example.org',
			)
		);
		$leader_b    = self::factory()->user->create(
			array(
				'role'       => 'dfxpray_leader',
				'user_email' => 'ben@example.org',
			)
		);
		$this->group = $this->make_group( 'Santa Marta', array( $leader_a, $leader_b ) );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$this->stop_capturing_http();
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * A message with both variants.
	 *
	 * @param string $kind Kind.
	 * @return array
	 */
	private function message( string $kind = 'digest' ): array {
		return array(
			'kind'      => $kind,
			'subject'   => 'Prayer digest',
			'leaders'   => "*Leaders* text\nwith _detail_",
			'shareable' => 'Shareable text',
		);
	}

	/**
	 * The three built-in channels implement the interface.
	 */
	public function test_registry(): void {
		$channels = DFXPRAY_Channels::get_instance()->all();

		$this->assertSame( array( 'email', 'telegram', 'webhook' ), array_keys( $channels ) );
		foreach ( $channels as $channel ) {
			$this->assertInstanceOf( DFXPRAY_Channel::class, $channel );
		}
	}

	/**
	 * New channels plug in through a filter, without touching the core.
	 */
	public function test_new_channel_via_filter(): void {
		$custom = new class() implements DFXPRAY_Channel {
			/** @var array[] */
			public array $sent = array();
			public function id(): string {
				return 'custom';
			}
			public function label(): string {
				return 'Custom';
			}
			public function is_configured( array $config ): bool {
				return true;
			}
			public function is_private( array $config ): bool {
				return false;
			}
			public function send( string $subject, string $text, array $config, int $group_id ) {
				$this->sent[] = $text;
				return true;
			}
		};
		$filter = static function ( $channels ) use ( $custom ) {
			$channels['custom'] = $custom;
			return $channels;
		};
		add_filter( 'dfxpray_channels', $filter );
		$settings_filter = static function ( $config ) {
			return array(
				'digest'  => true,
				'variant' => 'shareable',
			);
		};
		add_filter( 'dfxpray_channel_config_custom', $settings_filter );

		DFXPRAY_Channels::get_instance()->dispatch( $this->group, $this->message() );

		remove_filter( 'dfxpray_channels', $filter );
		remove_filter( 'dfxpray_channel_config_custom', $settings_filter );
		$this->assertSame( array( 'Shareable text' ), $custom->sent );
	}

	/**
	 * Email goes to every leader of the group, HTML with a text alternative.
	 */
	public function test_email_to_leaders(): void {
		$results = DFXPRAY_Channels::get_instance()->dispatch( $this->group, $this->message() );

		$this->assertTrue( $results['email'] );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertEqualsCanonicalizing( array( 'ana@example.org', 'ben@example.org' ), array_column( $mailer->getToAddresses(), 0 ) );
		$this->assertStringContainsString( 'Prayer digest', $mailer->get_sent()->subject );
		$this->assertStringContainsString( '<strong>Leaders</strong>', $mailer->get_sent()->body );
		$this->assertStringContainsString( '<em>detail</em>', $mailer->get_sent()->body );
		$this->assertSame( "*Leaders* text\nwith _detail_", $mailer->AltBody );
	}

	/**
	 * A configured recipient list replaces the leaders.
	 */
	public function test_email_to_configured_list(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'channels' => array( 'email' => array( 'recipients' => 'a@x.org, b@x.org' ) ) ) );

		DFXPRAY_Channels::get_instance()->dispatch( $this->group, $this->message() );

		$this->assertEqualsCanonicalizing( array( 'a@x.org', 'b@x.org' ), array_column( tests_retrieve_phpmailer_instance()->getToAddresses(), 0 ) );
	}

	/**
	 * Channels only fire for the kinds they are enabled for.
	 */
	public function test_kind_flags(): void {
		$results = DFXPRAY_Channels::get_instance()->dispatch( $this->group, $this->message( 'alert' ) );

		$this->assertArrayNotHasKey( 'email', $results );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	/**
	 * Telegram posts the configured variant to the bot API.
	 */
	public function test_telegram(): void {
		$this->capture_http();
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'channels' => array(
					'email'    => array( 'digest' => false ),
					'telegram' => array(
						'digest'  => true,
						'token'   => '123:ABC',
						'chat_id' => '-100555',
					),
				),
			)
		);

		$results = DFXPRAY_Channels::get_instance()->dispatch( $this->group, $this->message() );

		$this->assertTrue( $results['telegram'] );
		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( 'https://api.telegram.org/bot123:ABC/sendMessage', $this->http_requests[0]['url'] );
		$this->assertSame( '-100555', $this->http_requests[0]['args']['body']['chat_id'] );
		$this->assertSame( 'Shareable text', $this->http_requests[0]['args']['body']['text'] );
	}

	/**
	 * Long Telegram messages are split under the API limit.
	 */
	public function test_telegram_splits_long_messages(): void {
		$this->capture_http();
		$config = array(
			'token'   => 't',
			'chat_id' => 'c',
			'variant' => 'shareable',
		);
		$text   = implode( "\n", array_fill( 0, 300, str_repeat( 'x', 30 ) ) );

		DFXPRAY_Channel_Telegram::get_instance()->send( 'S', $text, $config, $this->group );

		$this->assertGreaterThan( 1, count( $this->http_requests ) );
		foreach ( $this->http_requests as $request ) {
			$this->assertLessThanOrEqual( 4096, mb_strlen( $request['args']['body']['text'] ) );
		}
	}

	/**
	 * The webhook posts signed JSON.
	 */
	public function test_webhook_signed_json(): void {
		$this->capture_http();
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'channels' => array(
					'webhook' => array(
						'digest'  => true,
						'url'     => 'https://hooks.example.org/in',
						'secret'  => 's3cret',
						'variant' => 'leaders',
					),
				),
			)
		);

		DFXPRAY_Channels::get_instance()->dispatch( $this->group, $this->message() );

		$request = $this->http_requests[0];
		$this->assertSame( 'https://hooks.example.org/in', $request['url'] );
		$payload = json_decode( $request['args']['body'], true );
		$this->assertSame( 'digest', $payload['event'] );
		$this->assertSame( 'leaders', $payload['variant'] );
		$this->assertSame( "*Leaders* text\nwith _detail_", $payload['text'] );
		$this->assertSame( $this->group, $payload['group']['id'] );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $request['args']['body'], 's3cret' ), $request['args']['headers']['X-DFXPray-Signature'] );
	}

	/**
	 * New request notices never carry the text on non-private channels.
	 */
	public function test_new_request_never_leaks_text_to_open_channels(): void {
		$this->capture_http();
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'channels' => array(
					'webhook' => array(
						'new_request' => true,
						'url'         => 'https://hooks.example.org/in',
						'variant'     => 'leaders',
					),
				),
			)
		);

		DFXPRAY_Channels::get_instance()->dispatch( $this->group, $this->message( 'new_request' ) );

		$payload = json_decode( $this->http_requests[0]['args']['body'], true );
		$this->assertSame( 'Shareable text', $payload['text'] );
		$this->assertStringContainsString( 'Leaders', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	/**
	 * Failures are logged; successes too.
	 */
	public function test_failures_and_successes_are_logged(): void {
		$this->capture_http( new WP_Error( 'http_request_failed', 'Timeout' ) );
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'channels' => array(
					'webhook' => array(
						'digest' => true,
						'url'    => 'https://hooks.example.org/in',
					),
				),
			)
		);

		$results = DFXPRAY_Channels::get_instance()->dispatch( $this->group, $this->message() );

		$this->assertWPError( $results['webhook'] );
		$failed = DFXPRAY_Log::get_instance()->for_group( $this->group, array( 'channel_failed' ) );
		$this->assertCount( 1, $failed );
		$this->assertSame( 'webhook', $failed[0]['details']['channel'] );
		$this->assertSame( 'Timeout', $failed[0]['details']['error'] );
		$this->assertCount( 1, DFXPRAY_Log::get_instance()->for_group( $this->group, array( 'channel_sent' ) ) );
	}

	/**
	 * HTTP error codes count as failures.
	 */
	public function test_http_error_status_is_failure(): void {
		$this->capture_http(
			array(
				'headers'  => array(),
				'body'     => '{"ok":false,"description":"chat not found"}',
				'response' => array(
					'code'    => 400,
					'message' => 'Bad Request',
				),
				'cookies'  => array(),
			)
		);

		$result = DFXPRAY_Channel_Telegram::get_instance()->send(
			'S',
			'T',
			array(
				'token'   => 't',
				'chat_id' => 'c',
			),
			$this->group
		);

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'chat not found', $result->get_error_message() );
	}

	/**
	 * Unconfigured channels are skipped.
	 */
	public function test_unconfigured_channel_skipped(): void {
		DFXPRAY_Settings::get_instance()->save_group( $this->group, array( 'channels' => array( 'telegram' => array( 'digest' => true ) ) ) );

		$results = DFXPRAY_Channels::get_instance()->dispatch( $this->group, $this->message() );

		$this->assertArrayNotHasKey( 'telegram', $results );
	}

	/**
	 * Test sends ignore the kind flags.
	 */
	public function test_send_test_message(): void {
		$this->capture_http();
		DFXPRAY_Settings::get_instance()->save_group(
			$this->group,
			array(
				'channels' => array(
					'telegram' => array(
						'token'   => 't',
						'chat_id' => 'c',
					),
				),
			)
		);

		$this->assertTrue( DFXPRAY_Channels::get_instance()->send_test( $this->group, 'telegram' ) );
		$this->assertWPError( DFXPRAY_Channels::get_instance()->send_test( $this->group, 'webhook' ) );
		$this->assertStringContainsString( 'test', strtolower( $this->http_requests[0]['args']['body']['text'] ) );
	}
}
