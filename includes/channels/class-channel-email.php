<?php
/**
 * Email channel.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends through wp_mail to the group's leaders (or a configured list), as
 * HTML with a plain-text alternative. Always private: it carries the leaders'
 * variant.
 */
final class DFXPRAY_Channel_Email implements DFXPRAY_Channel {

	use DFXPRAY_Singleton;

	/**
	 * Plain-text alternative of the message being sent.
	 *
	 * @var string
	 */
	private string $alt_body = '';

	/**
	 * Machine name.
	 */
	public function id(): string {
		return 'email';
	}

	/**
	 * Human name.
	 */
	public function label(): string {
		return __( 'Email', 'dfx-prayer-intentions' );
	}

	/**
	 * Always usable: without a list, it goes to the leaders.
	 *
	 * @param array $config Configuration.
	 */
	public function is_configured( array $config ): bool {
		return true;
	}

	/**
	 * Email only reaches leaders or the addresses the administrator chose.
	 *
	 * @param array $config Configuration.
	 */
	public function is_private( array $config ): bool {
		return true;
	}

	/**
	 * Send the email.
	 *
	 * @param string $subject  Subject.
	 * @param string $text     Plain text, WhatsApp syntax.
	 * @param array  $config   Configuration.
	 * @param int    $group_id Group ID.
	 * @return true|WP_Error
	 */
	public function send( string $subject, string $text, array $config, int $group_id ) {
		$recipients = $this->recipients( $config, $group_id );

		if ( ! $recipients ) {
			return new WP_Error( 'dfxpray_email_no_recipients', __( 'The group has no leaders with an email address.', 'dfx-prayer-intentions' ) );
		}

		$this->alt_body = $text;
		add_action( 'phpmailer_init', array( $this, 'set_alt_body' ) );

		$sent = wp_mail( $recipients, $subject, $this->to_html( $text ), array( 'Content-Type: text/html; charset=UTF-8' ) );

		remove_action( 'phpmailer_init', array( $this, 'set_alt_body' ) );
		$this->alt_body = '';

		return $sent ? true : new WP_Error( 'dfxpray_email_failed', __( 'WordPress could not send the email.', 'dfx-prayer-intentions' ) );
	}

	/**
	 * Add the plain-text alternative.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
	 */
	public function set_alt_body( $phpmailer ): void {
		$phpmailer->AltBody = $this->alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Addresses: the configured list, or every leader of the group.
	 *
	 * @param array $config   Configuration.
	 * @param int   $group_id Group ID.
	 * @return string[]
	 */
	public function recipients( array $config, int $group_id ): array {
		$list = array_filter( array_map( 'trim', explode( ',', (string) ( $config['recipients'] ?? '' ) ) ), 'is_email' );

		if ( $list ) {
			return array_values( $list );
		}

		$emails = array();
		foreach ( DFXPRAY_Groups::get_instance()->get_leaders( $group_id ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}

		return $emails;
	}

	/**
	 * WhatsApp syntax to simple HTML.
	 *
	 * @param string $text Text.
	 */
	private function to_html( string $text ): string {
		$html = esc_html( $text );
		$html = preg_replace( '/(^|[\s(])\*([^*\n]+)\*/u', '$1<strong>$2</strong>', $html );
		$html = preg_replace( '/(^|[\s(])_([^_\n]+)_/u', '$1<em>$2</em>', $html );
		$html = make_clickable( $html );

		return '<div style="font-family:sans-serif;line-height:1.5">' . nl2br( $html ) . '</div>';
	}
}
