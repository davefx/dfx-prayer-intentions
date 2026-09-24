<?php
/**
 * Builds and sends notices: new requests, digests and hour alerts.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns plugin events into channel messages.
 */
final class DFXPRAY_Notifier {

	use DFXPRAY_Singleton;

	/**
	 * Hook into plugin events.
	 */
	public function register_hooks(): void {
		add_action( 'dfxpray_intention_created', array( $this, 'on_intention_created' ), 10, 3 );
	}

	/**
	 * Tell the leaders about a new form request.
	 *
	 * @param int    $id       Intention ID.
	 * @param int    $group_id Group ID.
	 * @param string $source   'form' or 'manual'.
	 */
	public function on_intention_created( int $id, int $group_id, string $source ): void {
		if ( 'form' === $source ) {
			$this->notify_new_request( $id );
		}
	}

	/**
	 * Send the new-request notice. The full text only goes by email.
	 *
	 * @param int $id Intention ID.
	 * @return array Results per channel.
	 */
	public function notify_new_request( int $id ): array {
		$intention = DFXPRAY_Intentions::get_instance()->get( $id );
		$group     = $intention ? DFXPRAY_Groups::get_instance()->get( $intention['group_id'] ) : null;

		if ( ! $intention || ! $group ) {
			return array();
		}

		$url   = DFXPRAY_Admin::get_instance()->group_url( $group['id'], 'moderation' );
		$types = DFXPRAY_Lifecycle::get_instance()->types();
		$who   = $intention['beneficiary_is_requester']
			? $intention['requester_name']
			/* translators: 1: beneficiary, 2: requester. */
			: sprintf( __( '%1$s (asked by %2$s)', 'dfx-prayer-intentions' ), $intention['beneficiary_name'], $intention['requester_name'] );

		$leaders = implode(
			"\n\n",
			array(
				/* translators: %s: group name. */
				sprintf( __( '*New prayer request* in %s', 'dfx-prayer-intentions' ), $group['name'] ),
				$who . ' – ' . ( $types[ $intention['type'] ] ?? $intention['type'] ),
				$intention['body'],
				/* translators: %s: link to the moderation screen. */
				sprintf( __( 'Moderate it: %s', 'dfx-prayer-intentions' ), $url ),
			)
		);

		return DFXPRAY_Channels::get_instance()->dispatch(
			$group['id'],
			array(
				'kind'      => 'new_request',
				/* translators: %s: group name. */
				'subject'   => sprintf( __( 'New prayer request in %s', 'dfx-prayer-intentions' ), $group['name'] ),
				'leaders'   => $leaders,
				/* translators: 1: group name, 2: link to the moderation screen. */
				'shareable' => sprintf( __( 'A new prayer request is waiting for moderation in %1$s: %2$s', 'dfx-prayer-intentions' ), $group['name'], $url ),
			)
		);
	}

	/**
	 * Send a composed digest.
	 *
	 * @param int   $group_id Group ID.
	 * @param array $digest   Output of DFXPRAY_Digest_Composer::compose().
	 * @return array Results per channel.
	 */
	public function send_digest( int $group_id, array $digest ): array {
		$group = DFXPRAY_Groups::get_instance()->get( $group_id );

		return DFXPRAY_Channels::get_instance()->dispatch(
			$group_id,
			array(
				'kind'      => 'digest',
				/* translators: %s: group name. */
				'subject'   => sprintf( __( 'Prayer digest – %s', 'dfx-prayer-intentions' ), $group ? $group['name'] : '' ),
				'leaders'   => $digest['leaders'],
				'shareable' => $digest['shareable'],
			)
		);
	}

	/**
	 * Text of an hour alert.
	 *
	 * @param array  $intention Event intention with a time.
	 * @param string $moment    'before', 'now' or 'early' (ahead of quiet hours).
	 */
	public function alert_text( array $intention, string $moment ): string {
		$formatter = DFXPRAY_Formatter::get_instance();
		$what      = $formatter->person_with( $intention, $intention['summary'] );

		if ( 'early' === $moment ) {
			return sprintf(
				/* translators: 1: when (e.g. "tomorrow, 6:30"), 2: person and summary. */
				__( 'No alert during quiet hours – %1$s: %2$s. Let us pray at that moment.', 'dfx-prayer-intentions' ),
				$formatter->when( (string) $intention['reference_date'], (string) $intention['reference_time'] ),
				$what
			);
		}

		if ( 'now' === $moment ) {
			/* translators: %s: person and summary. */
			return sprintf( __( 'Right now: %s. United in prayer.', 'dfx-prayer-intentions' ), $what );
		}

		/* translators: 1: time, 2: person and summary. */
		return sprintf( __( 'At %1$s: %2$s. United in prayer.', 'dfx-prayer-intentions' ), $formatter->time( (string) $intention['reference_time'] ), $what );
	}

	/**
	 * Send an hour alert.
	 *
	 * @param array  $intention Intention.
	 * @param string $text      Alert text.
	 * @return array Results per channel.
	 */
	public function send_alert( array $intention, string $text ): array {
		return DFXPRAY_Channels::get_instance()->dispatch(
			$intention['group_id'],
			array(
				'kind'      => 'alert',
				'subject'   => __( 'Prayer alert', 'dfx-prayer-intentions' ),
				'leaders'   => $text,
				'shareable' => $text,
			)
		);
	}
}
