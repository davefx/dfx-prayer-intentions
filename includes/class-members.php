<?php
/**
 * The brothers of a group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The people of a group, kept by its leaders: how they are called, their
 * phone and their birthday, so the daily message can pray for whoever
 * celebrates it. They never leave the panel and the app.
 */
final class DFXPRAY_Members {

	use DFXPRAY_Singleton;

	/**
	 * Longest name.
	 */
	public const NAME_MAX_LENGTH = 191;

	/**
	 * Add a member to a group.
	 *
	 * @param int   $group_id Group ID.
	 * @param array $data     name, called, phone, birth_day, birth_month, birth_year, death_date.
	 * @return int|WP_Error Member ID.
	 */
	public function create( int $group_id, array $data ) {
		global $wpdb;

		$record = $this->build_record( $data, null );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$now = DFXPRAY_Clock::get_instance()->mysql();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array_merge(
				$record,
				array(
					'group_id'   => $group_id,
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Edit a member; only the given fields change.
	 *
	 * @param int   $id   Member ID.
	 * @param array $data Fields.
	 * @return true|WP_Error
	 */
	public function update( int $id, array $data ) {
		global $wpdb;

		$current = $this->get( $id );
		if ( ! $current ) {
			return new WP_Error( 'dfxpray_member_not_found', __( 'The member does not exist.', 'dfx-prayer-intentions' ) );
		}

		$record = $this->build_record( $data, $current );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$record['updated_at'] = DFXPRAY_Clock::get_instance()->mysql();
		$wpdb->update( $this->table(), $record, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return true;
	}

	/**
	 * Delete a member.
	 *
	 * @param int $id Member ID.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete every member of a group.
	 *
	 * @param int $group_id Group ID.
	 */
	public function delete_for_group( int $group_id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'group_id' => $group_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One member.
	 *
	 * @param int $id Member ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * The members of a group, by name.
	 *
	 * @param int $group_id Group ID.
	 * @return array[]
	 */
	public function for_group( int $group_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE group_id = %d ORDER BY name, id', $this->table(), $group_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * The members of a group whose birthday is a day. Those born on
	 * 29 February celebrate on the 28th in common years.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $date     Y-m-d.
	 * @return array[]
	 */
	public function birthdays_on( int $group_id, string $date ): array {
		return array_values(
			array_filter(
				$this->for_group( $group_id ),
				fn( array $member ): bool => null === $member['death_date'] && null !== $member['birth_day'] && $this->falls_on( (int) $member['birth_month'], (int) $member['birth_day'], $date )
			)
		);
	}

	/**
	 * The members of a group who died on that day of an earlier year, to be
	 * remembered every year. 29 February falls on the 28th in common years.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $date     Y-m-d.
	 * @return array[]
	 */
	public function death_anniversaries_on( int $group_id, string $date ): array {
		return array_values(
			array_filter(
				$this->for_group( $group_id ),
				fn( array $member ): bool => null !== $member['death_date'] && $member['death_date'] < $date
					&& $this->falls_on( (int) substr( $member['death_date'], 5, 2 ), (int) substr( $member['death_date'], 8, 2 ), $date )
			)
		);
	}

	/**
	 * Whether a day of the year (month, day) is celebrated on a date.
	 *
	 * @param int    $month Month.
	 * @param int    $day   Day.
	 * @param string $date  Y-m-d.
	 */
	private function falls_on( int $month, int $day, string $date ): bool {
		$on_month = (int) substr( $date, 5, 2 );
		$on_day   = (int) substr( $date, 8, 2 );

		if ( $month === $on_month && $day === $on_day ) {
			return true;
		}

		return 2 === $month && 29 === $day && 2 === $on_month && 28 === $on_day && ! checkdate( 2, 29, (int) substr( $date, 0, 4 ) );
	}

	/**
	 * Clean and check the fields of a member.
	 *
	 * @param array      $data    Raw data.
	 * @param array|null $current Current member, when editing.
	 * @return array|WP_Error Columns.
	 */
	private function build_record( array $data, ?array $current ) {
		$r = $current ?? array(
			'name'        => '',
			'called'      => '',
			'phone'       => '',
			'birth_day'   => null,
			'birth_month' => null,
			'birth_year'  => null,
			'death_date'  => null,
		);

		foreach ( array( 'name', 'called' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$r[ $field ] = mb_substr( trim( sanitize_text_field( (string) $data[ $field ] ) ), 0, self::NAME_MAX_LENGTH );
			}
		}

		if ( array_key_exists( 'phone', $data ) ) {
			$phone = DFXPRAY_Formatter::get_instance()->clean_phone( (string) $data['phone'] );
			if ( null === $phone ) {
				return new WP_Error( 'dfxpray_phone_invalid', __( 'The phone number is not valid.', 'dfx-prayer-intentions' ) );
			}
			$r['phone'] = $phone;
		}

		foreach ( array( 'birth_day', 'birth_month', 'birth_year' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$value       = trim( (string) $data[ $field ] );
				$r[ $field ] = '' === $value ? null : (int) $value;
			}
		}

		if ( array_key_exists( 'death_date', $data ) ) {
			$value           = trim( (string) $data['death_date'] );
			$r['death_date'] = '' === $value ? null : $value;
		}

		$death = $r['death_date'];
		if ( null !== $death && ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $death, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $death > DFXPRAY_Clock::get_instance()->today() ) ) {
			return new WP_Error( 'dfxpray_member_death_invalid', __( 'The date of death must be a valid day, today or earlier.', 'dfx-prayer-intentions' ) );
		}

		if ( '' === $r['name'] ) {
			return new WP_Error( 'dfxpray_member_name_required', __( 'Write the name of the member.', 'dfx-prayer-intentions' ) );
		}

		$day   = $r['birth_day'];
		$month = $r['birth_month'];
		if ( ( null === $day ) !== ( null === $month ) || ( null !== $day && ! checkdate( (int) $month, (int) $day, $r['birth_year'] ?? 2000 ) ) ) {
			return new WP_Error( 'dfxpray_member_birthday_invalid', __( 'The birthday is not a valid day.', 'dfx-prayer-intentions' ) );
		}

		return array(
			'name'        => $r['name'],
			'called'      => $r['called'],
			'phone'       => $r['phone'],
			'birth_day'   => $day,
			'birth_month' => $month,
			'birth_year'  => $r['birth_year'],
			'death_date'  => $death,
		);
	}

	/**
	 * Database row to member.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		$int = static fn( $value ) => null === $value ? null : (int) $value;

		return array(
			'id'          => (int) $row['id'],
			'group_id'    => (int) $row['group_id'],
			'name'        => $row['name'],
			'called'      => $row['called'],
			'phone'       => $row['phone'],
			'birth_day'   => $int( $row['birth_day'] ),
			'birth_month' => $int( $row['birth_month'] ),
			'birth_year'  => $int( $row['birth_year'] ),
			'death_date'  => $row['death_date'],
			'created_at'  => $row['created_at'],
			'updated_at'  => $row['updated_at'],
		);
	}

	/**
	 * Members table.
	 */
	private function table(): string {
		return DFXPRAY_Installer::get_instance()->get_table_names()['members'];
	}
}
