<?php
/**
 * Updates: dated notes that may reach the group as news.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "The operation went well", "still in hospital": updates about an intention.
 */
final class DFXPRAY_Updates {

	use DFXPRAY_Singleton;

	/**
	 * Add an update.
	 *
	 * @param int    $intention_id Intention ID.
	 * @param string $body       Text.
	 * @param bool   $is_news      Whether it appears as news in the daily digest.
	 * @return int|WP_Error Update ID.
	 */
	public function add( int $intention_id, string $body, bool $is_news = false ) {
		global $wpdb;

		$body      = trim( sanitize_textarea_field( $body ) );
		$intention = DFXPRAY_Intentions::get_instance()->get( $intention_id );

		if ( '' === $body ) {
			return new WP_Error( 'dfxpray_update_empty', __( 'The update is empty.', 'dfx-prayer-intentions' ) );
		}

		if ( ! $intention ) {
			return new WP_Error( 'dfxpray_not_found', __( 'The intention does not exist.', 'dfx-prayer-intentions' ) );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'intention_id' => $intention_id,
				'author_id'    => get_current_user_id(),
				'body'         => $body,
				'is_news'      => $is_news ? 1 : 0,
				'created_at'   => DFXPRAY_Clock::get_instance()->mysql(),
			)
		);

		$id = (int) $wpdb->insert_id;
		DFXPRAY_Log::get_instance()->add( 'update_added', $intention['group_id'], $intention_id, array( 'update' => $id ) );

		return $id;
	}

	/**
	 * Updates of an intention, newest first.
	 *
	 * @param int $intention_id Intention ID.
	 * @return array[]
	 */
	public function for_intention( int $intention_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE intention_id = %d ORDER BY created_at DESC, id DESC', $this->table(), $intention_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * News updates of a group's listable intentions created since a moment.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $since    Local MySQL datetime.
	 * @return array[]
	 */
	public function news_since( int $group_id, string $since ): array {
		global $wpdb;

		$intentions = DFXPRAY_Installer::get_instance()->get_table_names()['intentions'];
		$rows       = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT u.* FROM %i u INNER JOIN %i i ON i.id = u.intention_id WHERE i.group_id = %d AND i.status IN ('active','review') AND u.is_news = 1 AND u.created_at >= %s ORDER BY u.created_at ASC, u.id ASC",
				$this->table(),
				$intentions,
				$group_id,
				$since
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Cast a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'intention_id' => (int) $row['intention_id'],
			'author_id'    => (int) $row['author_id'],
			'body'         => $row['body'],
			'is_news'      => (bool) $row['is_news'],
			'created_at'   => $row['created_at'],
		);
	}

	/**
	 * Table name.
	 */
	private function table(): string {
		return DFXPRAY_Installer::get_instance()->get_table_names()['updates'];
	}
}
