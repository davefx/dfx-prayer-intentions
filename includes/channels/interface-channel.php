<?php
/**
 * Delivery channel contract.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A way of delivering digests, hour alerts and new-request notices.
 *
 * Adapters are registered through the dfxpray_channels filter, so adding one
 * never touches the core. Their per-group configuration lives under
 * settings['channels'][ id() ], or comes from the
 * dfxpray_channel_config_{id} filter for channels the settings screen does not
 * know about.
 */
interface DFXPRAY_Channel {

	/**
	 * Machine name.
	 */
	public function id(): string;

	/**
	 * Human name.
	 */
	public function label(): string;

	/**
	 * Whether the configuration has everything needed to send.
	 *
	 * @param array $config Channel configuration of the group.
	 */
	public function is_configured( array $config ): bool;

	/**
	 * Whether the channel only reaches leaders, and so may carry full data.
	 *
	 * @param array $config Channel configuration of the group.
	 */
	public function is_private( array $config ): bool;

	/**
	 * Deliver a text.
	 *
	 * @param string $subject  Subject or title.
	 * @param string $text     Plain text, WhatsApp syntax.
	 * @param array  $config   Channel configuration of the group.
	 * @param int    $group_id Group ID.
	 * @return true|WP_Error
	 */
	public function send( string $subject, string $text, array $config, int $group_id );
}
