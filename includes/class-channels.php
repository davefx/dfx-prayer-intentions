<?php
/**
 * Channel registry and dispatcher.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knows every channel, decides which one gets which variant, and logs every
 * delivery, successful or not.
 */
final class DFXPRAY_Channels {

	use DFXPRAY_Singleton;

	/**
	 * Message kinds, mapped to the channel setting that enables them.
	 */
	private const KIND_FLAGS = array(
		'digest'      => 'digest',
		'alert'       => 'alerts',
		'new_request' => 'new_request',
	);

	/**
	 * Every registered channel, keyed by ID.
	 *
	 * @return array<string, DFXPRAY_Channel>
	 */
	public function all(): array {
		$channels = array(
			'email'    => DFXPRAY_Channel_Email::get_instance(),
			'telegram' => DFXPRAY_Channel_Telegram::get_instance(),
			'webhook'  => DFXPRAY_Channel_Webhook::get_instance(),
		);

		/**
		 * Filters the delivery channels. Add an object implementing
		 * DFXPRAY_Channel to register a new one.
		 *
		 * @param array<string, DFXPRAY_Channel> $channels Channels.
		 */
		$channels = apply_filters( 'dfxpray_channels', $channels );

		return array_filter( $channels, static fn( $channel ) => $channel instanceof DFXPRAY_Channel );
	}

	/**
	 * Configuration of a channel for a group.
	 *
	 * @param string $channel_id Channel ID.
	 * @param int    $group_id   Group ID.
	 * @return array
	 */
	public function config( string $channel_id, int $group_id ): array {
		$channels = DFXPRAY_Settings::get_instance()->get( 'channels', $group_id );
		$config   = (array) ( $channels[ $channel_id ] ?? array() );

		/**
		 * Filters the configuration of one channel for a group.
		 *
		 * @param array $config   Configuration.
		 * @param int   $group_id Group ID.
		 */
		return (array) apply_filters( "dfxpray_channel_config_{$channel_id}", $config, $group_id );
	}

	/**
	 * Send a message through every channel enabled for its kind.
	 *
	 * @param int   $group_id Group ID.
	 * @param array $message  kind, subject, leaders (full text), shareable (text without private data).
	 * @return array<string, true|WP_Error> Result per channel that was attempted.
	 */
	public function dispatch( int $group_id, array $message ): array {
		$kind    = (string) $message['kind'];
		$flag    = self::KIND_FLAGS[ $kind ] ?? $kind;
		$results = array();

		foreach ( $this->all() as $id => $channel ) {
			$config = $this->config( $id, $group_id );

			if ( empty( $config[ $flag ] ) || ! $channel->is_configured( $config ) ) {
				continue;
			}

			$variant = $this->variant_for( $channel, $config, $kind );
			$text    = (string) ( $message[ $variant ] ?? '' );

			if ( '' === $text ) {
				continue;
			}

			$results[ $id ] = $this->deliver( $channel, $group_id, $kind, $variant, (string) $message['subject'], $text, $config );
		}

		return $results;
	}

	/**
	 * Send a test message through one channel, whatever its kind flags.
	 *
	 * @param int    $group_id   Group ID.
	 * @param string $channel_id Channel ID.
	 * @return true|WP_Error
	 */
	public function send_test( int $group_id, string $channel_id ) {
		$channels = $this->all();
		$config   = $this->config( $channel_id, $group_id );

		if ( ! isset( $channels[ $channel_id ] ) || ! $channels[ $channel_id ]->is_configured( $config ) ) {
			return new WP_Error( 'dfxpray_channel_not_configured', __( 'The channel is not configured yet.', 'dfx-prayer-intentions' ) );
		}

		$text = __( '🙏 This is a test message from DFX Prayer Intentions. If you can read it, the channel works.', 'dfx-prayer-intentions' );

		return $this->deliver( $channels[ $channel_id ], $group_id, 'test', 'shareable', __( 'Test message', 'dfx-prayer-intentions' ), $text, $config );
	}

	/**
	 * Which variant a channel gets. New-request notices only carry the text
	 * through email; a digest's full variant only goes to private channels.
	 *
	 * @param DFXPRAY_Channel $channel Channel.
	 * @param array           $config  Configuration.
	 * @param string          $kind    Message kind.
	 */
	private function variant_for( DFXPRAY_Channel $channel, array $config, string $kind ): string {
		if ( 'new_request' === $kind ) {
			return 'email' === $channel->id() ? 'leaders' : 'shareable';
		}

		return $channel->is_private( $config ) ? 'leaders' : 'shareable';
	}

	/**
	 * Send and log.
	 *
	 * @param DFXPRAY_Channel $channel  Channel.
	 * @param int             $group_id Group ID.
	 * @param string          $kind     Message kind.
	 * @param string          $variant  Variant.
	 * @param string          $subject  Subject.
	 * @param string          $text     Text.
	 * @param array           $config   Configuration.
	 * @return true|WP_Error
	 */
	private function deliver( DFXPRAY_Channel $channel, int $group_id, string $kind, string $variant, string $subject, string $text, array $config ) {
		if ( $channel instanceof DFXPRAY_Channel_Webhook ) {
			$channel->set_context( $kind, $variant );
		}

		$result = $channel->send( $subject, $text, $config, $group_id );
		$ok     = true === $result;

		$details = array(
			'channel' => $channel->id(),
			'kind'    => $kind,
			'variant' => $variant,
		);
		if ( ! $ok ) {
			$details['error'] = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error', 'dfx-prayer-intentions' );
		}

		DFXPRAY_Log::get_instance()->add( $ok ? 'channel_sent' : 'channel_failed', $group_id, null, $details );

		return $ok ? true : ( is_wp_error( $result ) ? $result : new WP_Error( 'dfxpray_channel_failed', $details['error'] ) );
	}
}
