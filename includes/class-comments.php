<?php
/**
 * Internal comments, for leaders only.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notes about contacts, calls and visits. They never leave the panel, except
 * in the leaders' digest when so configured.
 */
final class DFXPRAY_Comments {

	use DFXPRAY_Singleton;

	/**
	 * Comment types.
	 *
	 * @return array<string, string> Type => label.
	 */
	public function types(): array {
		return array(
			'contact' => __( 'Contact', 'dfx-prayer-intentions' ),
			'call'    => __( 'Call', 'dfx-prayer-intentions' ),
			'visit'   => __( 'Visit', 'dfx-prayer-intentions' ),
			'note'    => __( 'Note', 'dfx-prayer-intentions' ),
		);
	}

	/**
	 * Add a comment.
	 *
	 * @param int    $intention_id Intention ID.
	 * @param string $body         Text.
	 * @param string $type         Optional type.
	 * @return int|WP_Error Comment ID.
	 */
	public function add( int $intention_id, string $body, string $type = '' ) {
		global $wpdb;

		$body      = trim( sanitize_textarea_field( $body ) );
		$intention = DFXPRAY_Intentions::get_instance()->get( $intention_id );

		if ( '' === $body ) {
			return new WP_Error( 'dfxpray_comment_empty', __( 'The comment is empty.', 'dfx-prayer-intentions' ) );
		}

		if ( ! $intention ) {
			return new WP_Error( 'dfxpray_not_found', __( 'The intention does not exist.', 'dfx-prayer-intentions' ) );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'intention_id' => $intention_id,
				'author_id'    => get_current_user_id(),
				'type'         => $this->clean_type( $type ),
				'body'         => $body,
				'created_at'   => DFXPRAY_Clock::get_instance()->mysql(),
			)
		);

		$id = (int) $wpdb->insert_id;
		DFXPRAY_Log::get_instance()->add( 'comment_added', $intention['group_id'], $intention_id, array( 'comment' => $id ) );

		return $id;
	}

	/**
	 * Get a comment.
	 *
	 * @param int $id Comment ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Edit a comment; the edit is signed with the current user and date.
	 *
	 * @param int    $id   Comment ID.
	 * @param string $body New text.
	 * @param string $type New type.
	 * @return true|WP_Error
	 */
	public function update( int $id, string $body, string $type = '' ) {
		global $wpdb;

		$comment = $this->get( $id );
		$body    = trim( sanitize_textarea_field( $body ) );

		if ( ! $comment ) {
			return new WP_Error( 'dfxpray_not_found', __( 'The comment does not exist.', 'dfx-prayer-intentions' ) );
		}

		if ( '' === $body ) {
			return new WP_Error( 'dfxpray_comment_empty', __( 'The comment is empty.', 'dfx-prayer-intentions' ) );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'body'      => $body,
				'type'      => $this->clean_type( $type ),
				'edited_by' => get_current_user_id(),
				'edited_at' => DFXPRAY_Clock::get_instance()->mysql(),
			),
			array( 'id' => $id )
		);

		DFXPRAY_Log::get_instance()->add( 'comment_edited', $this->group_of( $comment ), $comment['intention_id'], array( 'comment' => $id ) );

		return true;
	}

	/**
	 * Delete a comment.
	 *
	 * @param int $id Comment ID.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$comment = $this->get( $id );
		if ( ! $comment ) {
			return;
		}

		$wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		DFXPRAY_Log::get_instance()->add( 'comment_deleted', $this->group_of( $comment ), $comment['intention_id'], array( 'comment' => $id ) );
	}

	/**
	 * Comments of an intention, newest first.
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
	 * Copy a comment into an update, so its content reaches the group.
	 *
	 * @param int  $id      Comment ID.
	 * @param bool $is_news Whether the update is news for the digest.
	 * @return int|WP_Error Update ID.
	 */
	public function convert_to_update( int $id, bool $is_news = true ) {
		$comment = $this->get( $id );
		if ( ! $comment ) {
			return new WP_Error( 'dfxpray_not_found', __( 'The comment does not exist.', 'dfx-prayer-intentions' ) );
		}

		$update_id = DFXPRAY_Updates::get_instance()->add( $comment['intention_id'], $comment['body'], $is_news );

		if ( ! is_wp_error( $update_id ) ) {
			DFXPRAY_Log::get_instance()->add(
				'comment_converted',
				$this->group_of( $comment ),
				$comment['intention_id'],
				array(
					'comment' => $id,
					'update'  => $update_id,
				)
			);
		}

		return $update_id;
	}

	/**
	 * Keep only known types.
	 *
	 * @param string $type Raw type.
	 */
	private function clean_type( string $type ): string {
		return array_key_exists( $type, $this->types() ) ? $type : '';
	}

	/**
	 * Group of a comment's intention.
	 *
	 * @param array $comment Comment.
	 */
	private function group_of( array $comment ): ?int {
		$intention = DFXPRAY_Intentions::get_instance()->get( $comment['intention_id'] );

		return $intention ? $intention['group_id'] : null;
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
			'type'         => $row['type'],
			'body'         => $row['body'],
			'created_at'   => $row['created_at'],
			'edited_by'    => null === $row['edited_by'] ? null : (int) $row['edited_by'],
			'edited_at'    => $row['edited_at'],
		);
	}

	/**
	 * Table name.
	 */
	private function table(): string {
		return DFXPRAY_Installer::get_instance()->get_table_names()['comments'];
	}
}
