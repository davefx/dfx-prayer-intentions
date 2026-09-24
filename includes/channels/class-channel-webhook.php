<?php
/**
 * Generic webhook channel.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POSTs signed JSON to a URL: Slack, Discord, n8n or anything else.
 *
 * The signature is "sha256=" followed by the HMAC-SHA256 of the raw body with
 * the configured secret, in the X-DFXPray-Signature header.
 */
final class DFXPRAY_Channel_Webhook implements DFXPRAY_Channel {

	use DFXPRAY_Singleton;

	/**
	 * Kind of message being sent, set by the dispatcher.
	 *
	 * @var string
	 */
	private string $event = 'message';

	/**
	 * Variant being sent, set by the dispatcher.
	 *
	 * @var string
	 */
	private string $variant = 'shareable';

	/**
	 * Machine name.
	 */
	public function id(): string {
		return 'webhook';
	}

	/**
	 * Human name.
	 */
	public function label(): string {
		return __( 'Webhook', 'dfx-prayer-intentions' );
	}

	/**
	 * Needs a URL.
	 *
	 * @param array $config Configuration.
	 */
	public function is_configured( array $config ): bool {
		return '' !== (string) ( $config['url'] ?? '' );
	}

	/**
	 * Private when the administrator says the endpoint only reaches leaders.
	 *
	 * @param array $config Configuration.
	 */
	public function is_private( array $config ): bool {
		return 'leaders' === ( $config['variant'] ?? 'shareable' );
	}

	/**
	 * Describe what is being sent, for the JSON payload.
	 *
	 * @param string $event   Kind of message.
	 * @param string $variant Variant.
	 */
	public function set_context( string $event, string $variant ): void {
		$this->event   = $event;
		$this->variant = $variant;
	}

	/**
	 * Send the JSON.
	 *
	 * @param string $subject  Subject.
	 * @param string $text     Text.
	 * @param array  $config   Configuration.
	 * @param int    $group_id Group ID.
	 * @return true|WP_Error
	 */
	public function send( string $subject, string $text, array $config, int $group_id ) {
		$group = DFXPRAY_Groups::get_instance()->get( $group_id );
		$body  = wp_json_encode(
			array(
				'event'        => $this->event,
				'variant'      => $this->variant,
				'subject'      => $subject,
				'text'         => $text,
				'group'        => array(
					'id'   => $group_id,
					'name' => $group ? $group['name'] : '',
				),
				'site'         => home_url( '/' ),
				'generated_at' => DFXPRAY_Clock::get_instance()->now()->format( DATE_ATOM ),
			)
		);

		$headers = array(
			'Content-Type'    => 'application/json; charset=utf-8',
			'X-DFXPray-Event' => $this->event,
		);

		if ( '' !== (string) ( $config['secret'] ?? '' ) ) {
			$headers['X-DFXPray-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, (string) $config['secret'] );
		}

		$response = wp_remote_post(
			(string) $config['url'],
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status. */
			return new WP_Error( 'dfxpray_webhook_failed', sprintf( __( 'The webhook answered with status %d.', 'dfx-prayer-intentions' ), $code ) );
		}

		return true;
	}
}
