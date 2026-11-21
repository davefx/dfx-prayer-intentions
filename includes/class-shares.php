<?php
/**
 * When each daily message was shared with the group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A leader marks the day's message as sent to the group once they have
 * shared it. Those moments, one a day, tell what went in which message, so
 * a thanksgiving written down after the day's message was shared goes in
 * the next one. The digest's schedule plays no part: the message is
 * shared by hand, whenever the leader can.
 */
final class DFXPRAY_Shares {

	use DFXPRAY_Singleton;

	/**
	 * How many moments are kept, the last first.
	 */
	public const KEEP = 31;

	/**
	 * Mark today's message as shared, now. Only the first time a day counts.
	 *
	 * @param int $group_id Group ID.
	 */
	public function mark( int $group_id ): void {
		if ( null !== $this->today( $group_id ) ) {
			return;
		}

		$all = $this->all( $group_id );
		array_unshift( $all, DFXPRAY_Clock::get_instance()->mysql() );
		update_option( $this->option( $group_id ), array_slice( $all, 0, self::KEEP ), false );
	}

	/**
	 * Undo today's mark.
	 *
	 * @param int $group_id Group ID.
	 */
	public function undo( int $group_id ): void {
		$today = DFXPRAY_Clock::get_instance()->today();
		$all   = array_values( array_filter( $this->all( $group_id ), static fn( string $moment ): bool => substr( $moment, 0, 10 ) !== $today ) );
		update_option( $this->option( $group_id ), $all, false );
	}

	/**
	 * When today's message was shared, if it was.
	 *
	 * @param int $group_id Group ID.
	 */
	public function today( int $group_id ): ?string {
		$all = $this->all( $group_id );

		return $all && substr( $all[0], 0, 10 ) === DFXPRAY_Clock::get_instance()->today() ? $all[0] : null;
	}

	/**
	 * Every moment kept, the last first.
	 *
	 * @param int $group_id Group ID.
	 * @return string[]
	 */
	public function all( int $group_id ): array {
		$all = get_option( $this->option( $group_id ), array() );

		return is_array( $all ) ? array_values( array_map( 'strval', $all ) ) : array();
	}

	/**
	 * Moment from which something goes in the next $messages messages: the
	 * share $messages back, not counting today's, which is the message
	 * already shared. Null when there are not that many shares.
	 *
	 * @param int $group_id Group ID.
	 * @param int $messages How many messages.
	 */
	public function since( int $group_id, int $messages ): ?string {
		$all   = $this->all( $group_id );
		$index = $messages - 1 + ( null !== $this->today( $group_id ) ? 1 : 0 );

		return $all[ $index ] ?? null;
	}

	/**
	 * Forget a deleted group.
	 *
	 * @param int $group_id Group ID.
	 */
	public function delete_for_group( int $group_id ): void {
		delete_option( $this->option( $group_id ) );
	}

	/**
	 * Option of a group.
	 *
	 * @param int $group_id Group ID.
	 */
	private function option( int $group_id ): string {
		return 'dfxpray_shared_' . $group_id;
	}
}
