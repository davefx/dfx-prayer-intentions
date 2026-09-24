<?php
/**
 * Telegram channel.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends through a Telegram bot to a private chat or a leaders' group.
 */
final class DFXPRAY_Channel_Telegram implements DFXPRAY_Channel {

	use DFXPRAY_Singleton;

	/**
	 * Telegram's maximum message length.
	 */
	private const MAX_LENGTH = 4096;

	/**
	 * Machine name.
	 */
	public function id(): string {
		return 'telegram';
	}

	/**
	 * Human name.
	 */
	public function label(): string {
		return __( 'Telegram', 'dfx-prayer-intentions' );
	}

	/**
	 * Needs a bot token and a chat ID.
	 *
	 * @param array $config Configuration.
	 */
	public function is_configured( array $config ): bool {
		return '' !== (string) ( $config['token'] ?? '' ) && '' !== (string) ( $config['chat_id'] ?? '' );
	}

	/**
	 * Private when the administrator says the chat is the leaders' one.
	 *
	 * @param array $config Configuration.
	 */
	public function is_private( array $config ): bool {
		return 'leaders' === ( $config['variant'] ?? 'shareable' );
	}

	/**
	 * Send, split into chunks Telegram accepts.
	 *
	 * @param string $subject  Unused: the text carries its own header.
	 * @param string $text     Text.
	 * @param array  $config   Configuration.
	 * @param int    $group_id Group ID.
	 * @return true|WP_Error
	 */
	public function send( string $subject, string $text, array $config, int $group_id ) {
		$url = 'https://api.telegram.org/bot' . rawurlencode( (string) $config['token'] ) . '/sendMessage';
		$url = str_replace( '%3A', ':', $url );

		foreach ( $this->split( $text ) as $chunk ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'body'    => array(
						'chat_id'                  => (string) $config['chat_id'],
						'text'                     => $chunk,
						'disable_web_page_preview' => 'true',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

				return new WP_Error(
					'dfxpray_telegram_failed',
					/* translators: 1: HTTP status, 2: Telegram's description. */
					sprintf( __( 'Telegram answered %1$d: %2$s', 'dfx-prayer-intentions' ), $code, (string) ( $body['description'] ?? '' ) )
				);
			}
		}

		return true;
	}

	/**
	 * Split a text on line breaks into chunks under the limit.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function split( string $text ): array {
		$chunks  = array();
		$current = '';

		foreach ( explode( "\n", $text ) as $line ) {
			while ( mb_strlen( $line ) > self::MAX_LENGTH ) {
				$chunks[] = mb_substr( $line, 0, self::MAX_LENGTH );
				$line     = mb_substr( $line, self::MAX_LENGTH );
			}

			$candidate = '' === $current ? $line : $current . "\n" . $line;
			if ( mb_strlen( $candidate ) > self::MAX_LENGTH ) {
				$chunks[] = $current;
				$current  = $line;
			} else {
				$current = $candidate;
			}
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}
}
