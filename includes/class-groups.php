<?php
/**
 * Groups repository and leader assignment.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Independent prayer groups. No data ever crosses from one group to another.
 */
final class DFXPRAY_Groups {

	use DFXPRAY_Singleton;

	/**
	 * Characters used in the random slug segment.
	 */
	private const SLUG_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

	/**
	 * Create a group.
	 *
	 * @param array $data Name and optional description.
	 * @return int|WP_Error Group ID.
	 */
	public function create( array $data ) {
		global $wpdb;

		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return new WP_Error( 'dfxpray_group_name', __( 'The group needs a name.', 'dfx-prayer-intentions' ) );
		}

		$now = DFXPRAY_Clock::get_instance()->mysql();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->tables()['groups'],
			array(
				'name'        => $name,
				'description' => sanitize_textarea_field( $data['description'] ?? '' ),
				'slug'        => $this->generate_slug(),
				'status'      => 'active',
				'settings'    => wp_json_encode( array() ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		$id = (int) $wpdb->insert_id;

		update_option( 'dfxpray_flush_rewrite', 1 );
		DFXPRAY_Log::get_instance()->add( 'group_created', $id, null, array( 'name' => $name ) );

		return $id;
	}

	/**
	 * Get a group.
	 *
	 * @param int $id Group ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->tables()['groups'], $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Get a group by its public slug.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public function get_by_slug( string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', $this->tables()['groups'], $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * All groups, optionally filtered by status, ordered by name.
	 *
	 * @param string|null $status 'active', 'archived' or null for all.
	 * @return array[]
	 */
	public function all( ?string $status = null ): array {
		global $wpdb;

		$table = $this->tables()['groups'];
		$rows  = null === $status
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC, id ASC', $table ), ARRAY_A ) // phpcs:ignore WordPress.DB
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY name ASC, id ASC', $table, $status ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Update name and description.
	 *
	 * @param int   $id   Group ID.
	 * @param array $data Name and/or description.
	 * @return true|WP_Error
	 */
	public function update( int $id, array $data ) {
		global $wpdb;

		$fields = array();
		if ( array_key_exists( 'name', $data ) ) {
			$fields['name'] = sanitize_text_field( $data['name'] );
			if ( '' === $fields['name'] ) {
				return new WP_Error( 'dfxpray_group_name', __( 'The group needs a name.', 'dfx-prayer-intentions' ) );
			}
		}
		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = sanitize_textarea_field( $data['description'] );
		}

		$fields['updated_at'] = DFXPRAY_Clock::get_instance()->mysql();
		$wpdb->update( $this->tables()['groups'], $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		DFXPRAY_Log::get_instance()->add( 'group_updated', $id );

		return true;
	}

	/**
	 * Give the group a new random public URL.
	 *
	 * @param int $id Group ID.
	 * @return string The new slug.
	 */
	public function regenerate_slug( int $id ): string {
		global $wpdb;

		$slug = $this->generate_slug();
		$wpdb->update( $this->tables()['groups'], array( 'slug' => $slug ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( 'dfxpray_flush_rewrite', 1 );
		DFXPRAY_Log::get_instance()->add( 'group_slug_regenerated', $id );

		return $slug;
	}

	/**
	 * Archive a group: it disappears for leaders and its form stops working.
	 *
	 * @param int $id Group ID.
	 */
	public function archive( int $id ): void {
		$this->set_status( $id, 'archived' );
	}

	/**
	 * Restore an archived group.
	 *
	 * @param int $id Group ID.
	 */
	public function restore( int $id ): void {
		$this->set_status( $id, 'active' );
	}

	/**
	 * Delete a group and everything that belongs to it.
	 *
	 * @param int $id Group ID.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$t   = $this->tables();
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE group_id = %d', $t['intentions'], $id ) ) ); // phpcs:ignore WordPress.DB

		DFXPRAY_Intentions::get_instance()->delete( $ids );
		$wpdb->delete( $t['group_leaders'], array( 'group_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $t['groups'], array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		DFXPRAY_Log::get_instance()->delete_for_group( $id );
		DFXPRAY_Members::get_instance()->delete_for_group( $id );
		DFXPRAY_Invitations::get_instance()->delete_for_group( $id );
		DFXPRAY_Shares::get_instance()->delete_for_group( $id );
		delete_option( 'dfxpray_digest_' . $id );
		delete_option( 'dfxpray_alerts_' . $id );
		update_option( 'dfxpray_flush_rewrite', 1 );
	}

	/**
	 * Replace the leaders of a group.
	 *
	 * @param int   $group_id Group ID.
	 * @param int[] $user_ids User IDs.
	 */
	public function set_leaders( int $group_id, array $user_ids ): void {
		global $wpdb;

		$table = $this->tables()['group_leaders'];
		$wpdb->delete( $table, array( 'group_id' => $group_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) as $user_id ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'group_id' => $group_id,
					'user_id'  => $user_id,
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		DFXPRAY_Log::get_instance()->add( 'group_leaders_set', $group_id, null, array( 'users' => array_values( $user_ids ) ) );
	}

	/**
	 * Make a user a leader of a group, keeping the others.
	 *
	 * @param int $group_id Group ID.
	 * @param int $user_id  User ID.
	 */
	public function add_leader( int $group_id, int $user_id ): void {
		global $wpdb;

		if ( in_array( $user_id, $this->get_leaders( $group_id ), true ) ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->tables()['group_leaders'],
			array(
				'group_id' => $group_id,
				'user_id'  => $user_id,
			)
		);
	}

	/**
	 * User IDs of the leaders of a group.
	 *
	 * @param int $group_id Group ID.
	 * @return int[]
	 */
	public function get_leaders( int $group_id ): array {
		global $wpdb;

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE group_id = %d ORDER BY user_id', $this->tables()['group_leaders'], $group_id ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Groups a user is assigned to as leader, whatever their status.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public function get_group_ids_for_leader( int $user_id ): array {
		global $wpdb;

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT group_id FROM %i WHERE user_id = %d ORDER BY group_id', $this->tables()['group_leaders'], $user_id ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Setting overrides stored for a group.
	 *
	 * @param int $group_id Group ID.
	 * @return array
	 */
	public function get_settings( int $group_id ): array {
		$group = $this->get( $group_id );

		return $group ? $group['settings'] : array();
	}

	/**
	 * Store the setting overrides of a group. Callers sanitise; see DFXPRAY_Settings.
	 *
	 * @param int   $group_id  Group ID.
	 * @param array $overrides Overrides.
	 */
	public function save_settings( int $group_id, array $overrides ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->tables()['groups'],
			array(
				'settings'   => wp_json_encode( $overrides ),
				'updated_at' => DFXPRAY_Clock::get_instance()->mysql(),
			),
			array( 'id' => $group_id )
		);
	}

	/**
	 * Change the status of a group.
	 *
	 * @param int    $id     Group ID.
	 * @param string $status New status.
	 */
	private function set_status( int $id, string $status ): void {
		global $wpdb;

		$wpdb->update( $this->tables()['groups'], array( 'status' => $status ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( 'dfxpray_flush_rewrite', 1 );
		DFXPRAY_Log::get_instance()->add( 'group_' . $status, $id );
	}

	/**
	 * A fresh, unused public slug: "<base>-<8 random characters>".
	 */
	private function generate_slug(): string {
		$base = DFXPRAY_Settings::get_instance()->get( 'url_base' );

		do {
			$random = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$random .= self::SLUG_ALPHABET[ random_int( 0, strlen( self::SLUG_ALPHABET ) - 1 ) ];
			}
			$slug = $base . '-' . $random;
		} while ( null !== $this->get_by_slug( $slug ) );

		return $slug;
	}

	/**
	 * Cast a database row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		$settings = json_decode( (string) $row['settings'], true );

		return array(
			'id'          => (int) $row['id'],
			'name'        => $row['name'],
			'description' => (string) $row['description'],
			'slug'        => $row['slug'],
			'status'      => $row['status'],
			'settings'    => is_array( $settings ) ? $settings : array(),
			'created_at'  => $row['created_at'],
			'updated_at'  => $row['updated_at'],
		);
	}

	/**
	 * Table names.
	 *
	 * @return array<string, string>
	 */
	private function tables(): array {
		return DFXPRAY_Installer::get_instance()->get_table_names();
	}
}
