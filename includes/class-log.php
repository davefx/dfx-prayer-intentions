<?php
/**
 * Audit log.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records every action with author and date, and failed channel deliveries.
 */
final class DFXPRAY_Log {

	use DFXPRAY_Singleton;

	/**
	 * Add an entry.
	 *
	 * @param string   $action       Machine name of the action.
	 * @param int|null $group_id     Group, if any.
	 * @param int|null $intention_id Intention, if any.
	 * @param array    $details      Extra data, stored as JSON.
	 * @param int|null $user_id      Author; defaults to the current user.
	 * @return int Entry ID.
	 */
	public function add( string $action, ?int $group_id = null, ?int $intention_id = null, array $details = array(), ?int $user_id = null ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'group_id'     => $group_id,
				'intention_id' => $intention_id,
				'user_id'      => $user_id ?? get_current_user_id(),
				'action'       => $action,
				'details'      => $details ? wp_json_encode( $details ) : null,
				'created_at'   => DFXPRAY_Clock::get_instance()->mysql(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get one entry.
	 *
	 * @param int $id Entry ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Entries for an intention, newest first.
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
	 * Entries for a group, newest first, optionally limited to some actions.
	 *
	 * @param int      $group_id Group ID.
	 * @param string[] $actions  Actions to include; empty for all.
	 * @param int      $limit    Maximum number of entries.
	 * @return array[]
	 */
	public function for_group( int $group_id, array $actions = array(), int $limit = 200 ): array {
		global $wpdb;

		$sql  = 'SELECT * FROM %i WHERE group_id = %d';
		$args = array( $this->table(), $group_id );

		if ( $actions ) {
			$sql .= ' AND action IN (' . implode( ',', array_fill( 0, count( $actions ), '%s' ) ) . ')';
			$args = array_merge( $args, $actions );
		}

		$sql   .= ' ORDER BY created_at DESC, id DESC LIMIT %d';
		$args[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql only holds placeholders.

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Delete every entry about the given intentions.
	 *
	 * @param int[] $intention_ids Intention IDs.
	 */
	public function delete_for_intentions( array $intention_ids ): void {
		global $wpdb;

		$intention_ids = array_map( 'intval', $intention_ids );
		if ( ! $intention_ids ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $intention_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE intention_id IN ({$placeholders})", $this->table(), ...$intention_ids ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete every entry of a group.
	 *
	 * @param int $group_id Group ID.
	 */
	public function delete_for_group( int $group_id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'group_id' => $group_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Cast a database row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'group_id'     => null === $row['group_id'] ? null : (int) $row['group_id'],
			'intention_id' => null === $row['intention_id'] ? null : (int) $row['intention_id'],
			'user_id'      => (int) $row['user_id'],
			'action'       => $row['action'],
			'details'      => $row['details'] ? (array) json_decode( $row['details'], true ) : array(),
			'created_at'   => $row['created_at'],
		);
	}

	/**
	 * Table name.
	 */
	private function table(): string {
		return DFXPRAY_Installer::get_instance()->get_table_names()['log'];
	}
}
