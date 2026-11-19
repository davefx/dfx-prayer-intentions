<?php
/**
 * Figures of every group, for administrators.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts per group (leaders, members, intentions by state, the last thirty
 * days) and their totals. Figures only: no names, no summaries, no text, so
 * administrators can follow every group without seeing data of the groups
 * they do not lead.
 */
final class DFXPRAY_Statistics {

	use DFXPRAY_Singleton;

	/**
	 * Days that "recently" covers.
	 */
	public const DAYS = 30;

	/**
	 * Every group, archived ones too, ordered by name, and the totals.
	 *
	 * @return array{groups: array<int, array>, totals: array<string, int>}
	 */
	public function for_all_groups(): array {
		global $wpdb;

		$tables = DFXPRAY_Installer::get_instance()->get_table_names();
		$since  = DFXPRAY_Clock::get_instance()->now()->modify( '-' . self::DAYS . ' days' )->format( 'Y-m-d H:i:s' );

		$intentions = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT group_id,
					SUM(status = 'pending') AS pending,
					SUM(status IN ('active','review')) AS listed,
					SUM(created_at >= %s) AS received_30,
					SUM(status = 'closed' AND closed_at >= %s) AS closed_30,
					SUM(status = 'closed' AND close_reason = 'answered') AS answered,
					COUNT(*) AS total
				FROM %i GROUP BY group_id",
				$since,
				$since,
				$tables['intentions']
			),
			OBJECT_K
		);
		$leaders    = $wpdb->get_results( $wpdb->prepare( 'SELECT group_id, COUNT(*) AS n FROM %i GROUP BY group_id', $tables['group_leaders'] ), OBJECT_K ); // phpcs:ignore WordPress.DB
		$members    = $wpdb->get_results( $wpdb->prepare( 'SELECT group_id, COUNT(*) AS n FROM %i WHERE death_date IS NULL GROUP BY group_id', $tables['members'] ), OBJECT_K ); // phpcs:ignore WordPress.DB
		$distinct   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM %i', $tables['group_leaders'] ) ); // phpcs:ignore WordPress.DB

		$keys   = array( 'pending', 'listed', 'received_30', 'closed_30', 'answered', 'total' );
		$totals = array_fill_keys( array_merge( array( 'leaders', 'members' ), $keys ), 0 );
		$groups = array();

		foreach ( DFXPRAY_Groups::get_instance()->all() as $group ) {
			$id  = (int) $group['id'];
			$row = array(
				'name'    => $group['name'],
				'status'  => $group['status'],
				'leaders' => (int) ( $leaders[ $id ]->n ?? 0 ),
				'members' => (int) ( $members[ $id ]->n ?? 0 ),
			);
			foreach ( $keys as $key ) {
				$row[ $key ] = (int) ( $intentions[ $id ]->$key ?? 0 );
			}

			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + $row[ $key ];
			}
			$groups[ $id ] = $row;
		}

		// Someone who leads two groups is one leader.
		$totals['leaders'] = $distinct;

		return array(
			'groups' => $groups,
			'totals' => $totals,
		);
	}
}
