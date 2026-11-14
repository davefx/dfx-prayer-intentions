<?php
/**
 * Intentions service.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, edits and moves intentions through their lifecycle. Every change
 * is logged with its author and date.
 */
final class DFXPRAY_Intentions {

	use DFXPRAY_Singleton;

	/**
	 * Fields a requester may fill in through the public form.
	 */
	private const FORM_FIELDS = array(
		'requester_name',
		'requester_contact',
		'beneficiary_name',
		'beneficiary_is_requester',
		'relationship',
		'type',
		'reference_date',
		'reference_time',
		'body',
		'discretion',
	);

	/**
	 * Extra fields only leaders may set.
	 */
	private const LEADER_FIELDS = array(
		'summary',
		'key_dates',
		'anniversary',
		'priority',
		'requester_display_name',
		'beneficiary_display_name',
		'requester_first_name',
		'beneficiary_first_name',
		'next_review',
	);

	/**
	 * Maximum length of the index summary.
	 */
	public const SUMMARY_MAX_LENGTH = 100;

	/**
	 * Statuses that appear in the index.
	 */
	public const LISTABLE_STATUSES = array( 'active', 'review' );

	/**
	 * Create an intention.
	 *
	 * @param int    $group_id Group.
	 * @param array  $data     Raw data.
	 * @param string $source   'form' (enters as pending) or 'manual' (leader, enters as active).
	 * @return int|WP_Error Intention ID.
	 */
	public function create( int $group_id, array $data, string $source = 'form' ) {
		global $wpdb;

		$manual = 'manual' === $source;
		$record = $this->build_record( $data, null, $manual );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( $manual && '' === $record['summary'] ) {
			return $this->summary_required_error();
		}

		$now    = DFXPRAY_Clock::get_instance()->mysql();
		$record = array_merge(
			$record,
			array(
				'group_id'   => $group_id,
				'source'     => $manual ? 'manual' : 'form',
				'status'     => $manual ? 'active' : 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		if ( $manual ) {
			$record['activated_at'] = $now;
			$record['next_review']  = $record['next_review'] ?? DFXPRAY_Lifecycle::get_instance()->initial_review_date( $record['type'], $record['reference_date'], $group_id );
		}

		$wpdb->insert( $this->tables()['intentions'], $this->to_row( $record ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$id = (int) $wpdb->insert_id;

		DFXPRAY_Log::get_instance()->add( $manual ? 'intention_created' : 'intention_submitted', $group_id, $id );

		/**
		 * Fires after an intention is created.
		 *
		 * @param int    $id       Intention ID.
		 * @param int    $group_id Group ID.
		 * @param string $source   'form' or 'manual'.
		 */
		do_action( 'dfxpray_intention_created', $id, $group_id, $record['source'] );

		return $id;
	}

	/**
	 * Get an intention.
	 *
	 * @param int $id Intention ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->tables()['intentions'], $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Edit an intention (leaders).
	 *
	 * @param int   $id   Intention ID.
	 * @param array $data Raw data; only the given fields change.
	 * @return true|WP_Error
	 */
	public function update( int $id, array $data ) {
		$current = $this->get( $id );
		if ( ! $current ) {
			return $this->not_found_error();
		}

		$record = $this->build_record( $data, $current, true );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( 'pending' !== $current['status'] && '' === $record['summary'] ) {
			return $this->summary_required_error();
		}

		$dates_changed = $record['type'] !== $current['type'] || $record['reference_date'] !== $current['reference_date'];
		if ( $dates_changed && in_array( $current['status'], self::LISTABLE_STATUSES, true ) && ! array_key_exists( 'next_review', $data ) ) {
			$record['next_review'] = DFXPRAY_Lifecycle::get_instance()->initial_review_date( $record['type'], $record['reference_date'], $current['group_id'] );
		}

		$changed = array();
		foreach ( $record as $field => $value ) {
			if ( $value !== $current[ $field ] ) {
				$changed[] = $field;
			}
		}

		if ( ! $changed ) {
			return true;
		}

		$this->write( $id, $record );

		$log = DFXPRAY_Log::get_instance();
		foreach ( array( 'requester', 'beneficiary' ) as $person ) {
			$field = $person . '_display_name';
			if ( in_array( $field, $changed, true ) ) {
				$log->add(
					'visible_name_changed',
					$current['group_id'],
					$id,
					array(
						'person' => $person,
						'old'    => $current[ $field ],
						'new'    => $record[ $field ],
					)
				);
			}
		}

		$other = array_diff( $changed, array( 'requester_display_name', 'beneficiary_display_name' ) );
		if ( $other ) {
			$log->add( 'intention_updated', $current['group_id'], $id, array( 'fields' => array_values( $other ) ) );
		}

		return true;
	}

	/**
	 * Approve a pending intention. A summary is required at this point.
	 *
	 * @param int   $id   Intention ID.
	 * @param array $data Edits made while approving (summary, discretion…).
	 * @return true|WP_Error
	 */
	public function approve( int $id, array $data = array() ) {
		$current = $this->get( $id );
		if ( ! $current ) {
			return $this->not_found_error();
		}

		if ( ! DFXPRAY_Lifecycle::get_instance()->can_transition( $current['status'], 'active' ) || 'pending' !== $current['status'] ) {
			return $this->transition_error();
		}

		$record = $this->build_record( $data, $current, true );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( '' === $record['summary'] ) {
			return $this->summary_required_error();
		}

		$record['status']       = 'active';
		$record['activated_at'] = DFXPRAY_Clock::get_instance()->mysql();
		$record['next_review']  = array_key_exists( 'next_review', $data ) && $record['next_review']
			? $record['next_review']
			: DFXPRAY_Lifecycle::get_instance()->initial_review_date( $record['type'], $record['reference_date'], $current['group_id'] );

		$this->write( $id, $record );
		DFXPRAY_Log::get_instance()->add( 'intention_approved', $current['group_id'], $id );

		/**
		 * Fires after a pending intention is approved.
		 *
		 * @param int $id Intention ID.
		 */
		do_action( 'dfxpray_intention_approved', $id );

		return true;
	}

	/**
	 * Discard a pending intention (spam or duplicate).
	 *
	 * @param int    $id     Intention ID.
	 * @param string $reason 'spam' or 'duplicate'.
	 * @return true|WP_Error
	 */
	public function discard( int $id, string $reason = 'spam' ) {
		$current = $this->get( $id );
		if ( ! $current ) {
			return $this->not_found_error();
		}

		if ( ! DFXPRAY_Lifecycle::get_instance()->can_transition( $current['status'], 'discarded' ) ) {
			return $this->transition_error();
		}

		$reason = in_array( $reason, array( 'spam', 'duplicate' ), true ) ? $reason : 'spam';

		$this->write(
			$id,
			array(
				'status'       => 'discarded',
				'close_reason' => $reason,
				'closed_at'    => DFXPRAY_Clock::get_instance()->mysql(),
			)
		);
		DFXPRAY_Log::get_instance()->add( 'intention_discarded', $current['group_id'], $id, array( 'reason' => $reason ) );

		return true;
	}

	/**
	 * Merge a pending duplicate into an existing intention of the same group.
	 *
	 * The duplicate's requester and text are kept as an internal comment on the
	 * existing intention, and the duplicate is discarded.
	 *
	 * @param int $id        Pending duplicate.
	 * @param int $target_id Existing intention.
	 * @return true|WP_Error
	 */
	public function merge( int $id, int $target_id ) {
		$duplicate = $this->get( $id );
		$target    = $this->get( $target_id );

		if ( ! $duplicate || ! $target || $id === $target_id ) {
			return $this->not_found_error();
		}

		if ( $duplicate['group_id'] !== $target['group_id'] ) {
			return new WP_Error( 'dfxpray_merge_groups', __( 'Only intentions of the same group can be merged.', 'dfx-prayer-intentions' ) );
		}

		$discarded = $this->discard( $id, 'duplicate' );
		if ( is_wp_error( $discarded ) ) {
			return $discarded;
		}

		$this->write( $id, array( 'linked_id' => $target_id ) );

		$contact = '' !== $duplicate['requester_contact'] ? ' (' . $duplicate['requester_contact'] . ')' : '';
		DFXPRAY_Comments::get_instance()->add(
			$target_id,
			sprintf(
				/* translators: 1: requester name and contact, 2: request text. */
				__( "Merged duplicate request from %1\$s:\n%2\$s", 'dfx-prayer-intentions' ),
				$duplicate['requester_name'] . $contact,
				$duplicate['body']
			),
			'note'
		);
		DFXPRAY_Log::get_instance()->add( 'intention_merged', $target['group_id'], $target_id, array( 'duplicate' => $id ) );

		return true;
	}

	/**
	 * Move every active intention whose review date has arrived to "review".
	 *
	 * @return int How many moved.
	 */
	public function process_due(): int {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, group_id FROM %i WHERE status = 'active' AND next_review IS NOT NULL AND next_review <= %s",
				$this->tables()['intentions'],
				DFXPRAY_Clock::get_instance()->today()
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$this->write( (int) $row['id'], array( 'status' => 'review' ) );
			DFXPRAY_Log::get_instance()->add( 'intention_due', (int) $row['group_id'], (int) $row['id'], array(), 0 );
		}

		return count( $rows );
	}

	/**
	 * "Still current": back to active with a new review date.
	 *
	 * @param int $id Intention ID.
	 * @return true|WP_Error
	 */
	public function keep_active( int $id ) {
		$current = $this->get( $id );
		if ( ! $current ) {
			return $this->not_found_error();
		}

		if ( 'review' !== $current['status'] && 'active' !== $current['status'] ) {
			return $this->transition_error();
		}

		$this->write(
			$id,
			array(
				'status'      => 'active',
				'next_review' => DFXPRAY_Lifecycle::get_instance()->keep_active_review_date( $current['type'], $current['group_id'] ),
			)
		);
		DFXPRAY_Log::get_instance()->add( 'intention_kept_active', $current['group_id'], $id );

		return true;
	}

	/**
	 * Close an intention.
	 *
	 * @param int    $id                   Intention ID.
	 * @param string $reason               'answered', 'expired' or 'withdrawn'.
	 * @param bool   $spawn_thanksgiving   Create a linked thanksgiving (answered events and ongoing only).
	 * @param string $thanksgiving_summary Summary for the thanksgiving; defaults to the original one.
	 * @return true|int|WP_Error True, or the new thanksgiving's ID.
	 */
	public function close( int $id, string $reason, bool $spawn_thanksgiving = false, string $thanksgiving_summary = '' ) {
		$current   = $this->get( $id );
		$lifecycle = DFXPRAY_Lifecycle::get_instance();

		if ( ! $current ) {
			return $this->not_found_error();
		}

		if ( ! array_key_exists( $reason, $lifecycle->close_reasons() ) ) {
			return new WP_Error( 'dfxpray_close_reason', __( 'Choose why the intention is being closed.', 'dfx-prayer-intentions' ) );
		}

		if ( ! $lifecycle->can_transition( $current['status'], 'closed' ) ) {
			return $this->transition_error();
		}

		$this->write(
			$id,
			array(
				'status'       => 'closed',
				'close_reason' => $reason,
				'closed_at'    => DFXPRAY_Clock::get_instance()->mysql(),
			)
		);
		DFXPRAY_Log::get_instance()->add( 'intention_closed', $current['group_id'], $id, array( 'reason' => $reason ) );

		if ( ! $spawn_thanksgiving || 'answered' !== $reason || ! $lifecycle->can_spawn_thanksgiving( $current['type'] ) ) {
			return true;
		}

		$summary = sanitize_text_field( $thanksgiving_summary );
		$new_id  = $this->create(
			$current['group_id'],
			array(
				'requester_name'           => $current['requester_name'],
				'requester_contact'        => $current['requester_contact'],
				'beneficiary_name'         => $current['beneficiary_name'],
				'beneficiary_is_requester' => $current['beneficiary_is_requester'],
				'relationship'             => $current['relationship'],
				'requester_display_name'   => $current['requester_display_name'],
				'beneficiary_display_name' => $current['beneficiary_display_name'],
				'requester_first_name'     => $current['requester_first_name'],
				'beneficiary_first_name'   => $current['beneficiary_first_name'],
				'body'                     => $current['body'],
				'discretion'               => $current['discretion'],
				'type'                     => 'thanksgiving',
				'reference_date'           => DFXPRAY_Clock::get_instance()->today(),
				'summary'                  => '' !== $summary ? $summary : $current['summary'],
			),
			'manual'
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$this->write( $new_id, array( 'linked_id' => $id ) );
		$this->write( $id, array( 'linked_id' => $new_id ) );

		return $new_id;
	}

	/**
	 * Set the priority.
	 *
	 * @param int    $id       Intention ID.
	 * @param string $priority 'normal' or 'high'.
	 * @return true|WP_Error
	 */
	public function set_priority( int $id, string $priority ) {
		if ( ! in_array( $priority, array( 'normal', 'high' ), true ) ) {
			return new WP_Error( 'dfxpray_priority_invalid', __( 'Unknown priority.', 'dfx-prayer-intentions' ) );
		}

		return $this->update( $id, array( 'priority' => $priority ) );
	}

	/**
	 * Postpone the review to a future date.
	 *
	 * @param int    $id   Intention ID.
	 * @param string $date New review date (Y-m-d), after today.
	 * @return true|WP_Error
	 */
	public function postpone( int $id, string $date ) {
		if ( ! $this->is_valid_date( $date ) || $date <= DFXPRAY_Clock::get_instance()->today() ) {
			return new WP_Error( 'dfxpray_postpone_date', __( 'Choose a future date.', 'dfx-prayer-intentions' ) );
		}

		return $this->update( $id, array( 'next_review' => $date ) );
	}

	/**
	 * Mark that a leader acknowledged the request to the requester.
	 *
	 * @param int $id Intention ID.
	 * @return true|WP_Error
	 */
	public function mark_ack_sent( int $id ) {
		$current = $this->get( $id );
		if ( ! $current ) {
			return $this->not_found_error();
		}

		if ( $current['ack_sent'] ) {
			return true;
		}

		$this->write( $id, array( 'ack_sent' => true ) );
		DFXPRAY_Comments::get_instance()->add( $id, __( 'Acknowledgement sent to the requester.', 'dfx-prayer-intentions' ), 'contact' );
		DFXPRAY_Log::get_instance()->add( 'intention_ack_sent', $current['group_id'], $id );

		return true;
	}

	/**
	 * Move an intention to another group. The acting user must lead both.
	 *
	 * @param int      $id       Intention ID.
	 * @param int      $group_id Destination group.
	 * @param int|null $user_id  Acting user; defaults to the current user.
	 * @return true|WP_Error
	 */
	public function transfer( int $id, int $group_id, ?int $user_id = null ) {
		$current     = $this->get( $id );
		$permissions = DFXPRAY_Permissions::get_instance();

		if ( ! $current ) {
			return $this->not_found_error();
		}

		if ( $current['group_id'] === $group_id ) {
			return true;
		}

		if ( ! $permissions->can_manage_group( $current['group_id'], $user_id ) || ! $permissions->can_manage_group( $group_id, $user_id ) ) {
			return new WP_Error( 'dfxpray_transfer_forbidden', __( 'You can only move intentions between groups you lead.', 'dfx-prayer-intentions' ) );
		}

		$this->write( $id, array( 'group_id' => $group_id ) );

		$log = DFXPRAY_Log::get_instance();
		$log->add( 'intention_transferred_out', $current['group_id'], $id, array( 'to' => $group_id ), $user_id );
		$log->add( 'intention_transferred_in', $group_id, $id, array( 'from' => $current['group_id'] ), $user_id );

		return true;
	}

	/**
	 * List a group's intentions.
	 *
	 * @param int   $group_id Group.
	 * @param array $args     status (string|string[]), type, priority, search, date_from, date_to, limit.
	 * @return array[]
	 */
	public function query( int $group_id, array $args = array() ): array {
		global $wpdb;

		$where  = array( 'group_id = %d' );
		$values = array( $this->tables()['intentions'], $group_id );

		if ( ! empty( $args['status'] ) ) {
			$statuses = (array) $args['status'];
			$where[]  = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$values   = array_merge( $values, $statuses );
		}

		foreach ( array( 'type', 'priority' ) as $field ) {
			if ( ! empty( $args[ $field ] ) ) {
				$where[]  = "{$field} = %s";
				$values[] = $args[ $field ];
			}
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(requester_name LIKE %s OR beneficiary_name LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'reference_date >= %s';
			$values[] = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'reference_date <= %s';
			$values[] = $args['date_to'];
		}

		$limit    = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 500;
		$values[] = $limit;

		$sql  = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql only holds placeholders.

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Intentions that appear in the index (active and in review).
	 *
	 * @param int $group_id Group.
	 * @return array[]
	 */
	public function listable( int $group_id ): array {
		return $this->query( $group_id, array( 'status' => self::LISTABLE_STATUSES ) );
	}

	/**
	 * Home counters for a group.
	 *
	 * @param int $group_id Group.
	 * @return array{pending:int, review:int, today:int, tomorrow:int}
	 */
	public function counts( int $group_id ): array {
		global $wpdb;

		$clock    = DFXPRAY_Clock::get_instance();
		$today    = $clock->today();
		$tomorrow = $clock->local( $today )->modify( '+1 day' )->format( 'Y-m-d' );
		$table    = $this->tables()['intentions'];

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT
					SUM(status = 'pending') AS pending,
					SUM(status = 'review') AS review,
					SUM(type = 'event' AND status IN ('active','review') AND reference_date = %s) AS today,
					SUM(type = 'event' AND status IN ('active','review') AND reference_date = %s) AS tomorrow
				FROM %i WHERE group_id = %d",
				$today,
				$tomorrow,
				$table,
				$group_id
			),
			ARRAY_A
		);

		return array(
			'pending'  => (int) $row['pending'],
			'review'   => (int) $row['review'],
			'today'    => (int) $row['today'],
			'tomorrow' => (int) $row['tomorrow'],
		);
	}

	/**
	 * Remember which hour alerts went out for an intention.
	 *
	 * @param int      $id   Intention ID.
	 * @param string[] $keys Alert keys.
	 */
	public function set_alerts_sent( int $id, array $keys ): void {
		$this->write( $id, array( 'alerts_sent' => array_values( array_unique( $keys ) ) ) );
	}

	/**
	 * Delete intentions with their updates, comments and log entries.
	 *
	 * @param int[] $ids Intention IDs.
	 */
	public function delete( array $ids ): void {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$t  = $this->tables();

		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE intention_id IN ({$in})", $t['updates'], ...$ids ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE intention_id IN ({$in})", $t['comments'], ...$ids ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$in})", $t['intentions'], ...$ids ) ); // phpcs:ignore WordPress.DB
		DFXPRAY_Log::get_instance()->delete_for_intentions( $ids );
	}

	/**
	 * Build and validate the full record from raw data over the current state.
	 *
	 * @param array      $data    Raw data.
	 * @param array|null $current Current intention, or null when creating.
	 * @param bool       $leader  Whether leader-only fields are accepted.
	 * @return array|WP_Error
	 */
	private function build_record( array $data, ?array $current, bool $leader ) {
		$allowed = $leader ? array_merge( self::FORM_FIELDS, self::LEADER_FIELDS ) : self::FORM_FIELDS;
		$data    = array_intersect_key( $data, array_flip( $allowed ) );
		$r       = $current ?? array(
			'requester_name'           => '',
			'requester_contact'        => '',
			'beneficiary_name'         => '',
			'beneficiary_is_requester' => false,
			'relationship'             => '',
			'body'                     => '',
			'summary'                  => '',
			'type'                     => '',
			'reference_date'           => null,
			'reference_time'           => null,
			'key_dates'                => array(),
			'anniversary'              => false,
			'discretion'               => 'first_name',
			'requester_display_name'   => '',
			'beneficiary_display_name' => '',
			'requester_first_name'     => '',
			'beneficiary_first_name'   => '',
			'priority'                 => 'normal',
			'next_review'              => null,
		);

		$texts = array( 'requester_name', 'requester_contact', 'beneficiary_name', 'relationship', 'summary', 'requester_display_name', 'beneficiary_display_name', 'requester_first_name', 'beneficiary_first_name' );
		foreach ( $texts as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$r[ $field ] = trim( sanitize_text_field( (string) $data[ $field ] ) );
			}
		}

		foreach ( array( 'beneficiary_is_requester', 'anniversary' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$r[ $field ] = (bool) filter_var( $data[ $field ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		if ( array_key_exists( 'body', $data ) ) {
			$r['body'] = trim( sanitize_textarea_field( (string) $data['body'] ) );
		}

		if ( array_key_exists( 'type', $data ) ) {
			$r['type'] = sanitize_key( (string) $data['type'] );
		}

		if ( array_key_exists( 'priority', $data ) ) {
			$r['priority'] = sanitize_key( (string) $data['priority'] );
		}

		foreach ( array( 'reference_date', 'reference_time', 'next_review' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$value       = trim( (string) $data[ $field ] );
				$r[ $field ] = '' === $value ? null : $value;
			}
		}

		if ( array_key_exists( 'key_dates', $data ) ) {
			$r['key_dates'] = $this->sanitize_key_dates( (array) $data['key_dates'] );
		}

		$discretion = DFXPRAY_Discretion::get_instance();
		$lifecycle  = DFXPRAY_Lifecycle::get_instance();

		if ( array_key_exists( 'discretion', $data ) ) {
			$requested = sanitize_key( (string) $data['discretion'] );
			$level     = $current ? $discretion->harden( $current['discretion'], $requested ) : ( $discretion->is_valid( $requested ) ? $requested : new WP_Error( 'dfxpray_discretion_invalid', __( 'Unknown discretion level.', 'dfx-prayer-intentions' ) ) );
			if ( is_wp_error( $level ) ) {
				return $level;
			}
			$r['discretion'] = $level;
		}

		if ( $r['beneficiary_is_requester'] ) {
			$r['beneficiary_name']       = $r['requester_name'];
			$r['beneficiary_first_name'] = $r['requester_first_name'];
			$r['relationship']           = '';
		}

		$r['requester_contact'] = mb_substr( $r['requester_contact'], 0, 191 );
		$r['relationship']      = mb_substr( $r['relationship'], 0, 100 );

		if ( '' === $r['requester_name'] ) {
			return new WP_Error( 'dfxpray_requester_required', __( 'Please tell us your name.', 'dfx-prayer-intentions' ) );
		}

		if ( '' === $r['beneficiary_name'] ) {
			return new WP_Error( 'dfxpray_beneficiary_required', __( 'Please tell us who we should pray for.', 'dfx-prayer-intentions' ) );
		}

		if ( '' === $r['body'] ) {
			return new WP_Error( 'dfxpray_body_required', __( 'Please write your intention.', 'dfx-prayer-intentions' ) );
		}

		if ( ! $lifecycle->is_valid_type( $r['type'] ) ) {
			return new WP_Error( 'dfxpray_type_invalid', __( 'Please choose the kind of intention.', 'dfx-prayer-intentions' ) );
		}

		if ( null === $r['reference_date'] && $lifecycle->requires_reference_date( $r['type'] ) ) {
			return new WP_Error( 'dfxpray_date_required', __( 'Please tell us the date.', 'dfx-prayer-intentions' ) );
		}

		if ( null !== $r['reference_date'] && ! $this->is_valid_date( $r['reference_date'] ) ) {
			return new WP_Error( 'dfxpray_date_invalid', __( 'The date is not valid.', 'dfx-prayer-intentions' ) );
		}

		if ( ! $lifecycle->accepts_time( $r['type'] ) ) {
			$r['reference_time'] = null;
		}

		if ( null !== $r['reference_time'] ) {
			if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $r['reference_time'] ) ) {
				return new WP_Error( 'dfxpray_time_invalid', __( 'The time is not valid.', 'dfx-prayer-intentions' ) );
			}
			$r['reference_time'] = substr( $r['reference_time'], 0, 5 );
		}

		if ( 'deceased' !== $r['type'] ) {
			$r['anniversary'] = false;
		}

		if ( mb_strlen( $r['summary'] ) > self::SUMMARY_MAX_LENGTH ) {
			return new WP_Error(
				'dfxpray_summary_too_long',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'The summary cannot be longer than %d characters.', 'dfx-prayer-intentions' ), self::SUMMARY_MAX_LENGTH )
			);
		}

		if ( ! in_array( $r['priority'], array( 'normal', 'high' ), true ) ) {
			return new WP_Error( 'dfxpray_priority_invalid', __( 'Unknown priority.', 'dfx-prayer-intentions' ) );
		}

		if ( null !== $r['next_review'] && ! $this->is_valid_date( $r['next_review'] ) ) {
			return new WP_Error( 'dfxpray_date_invalid', __( 'The date is not valid.', 'dfx-prayer-intentions' ) );
		}

		if ( ! $discretion->shows_name( $r['discretion'] ) ) {
			$r['requester_display_name']   = '';
			$r['beneficiary_display_name'] = '';
		}

		foreach ( array( 'requester', 'beneficiary' ) as $person ) {
			$valid = $discretion->validate_first_name( $r[ $person . '_name' ], $r[ $person . '_first_name' ] );
			if ( ! is_wp_error( $valid ) ) {
				$valid = $discretion->validate_visible_name( $r[ $person . '_name' ], $r['discretion'], $r[ $person . '_display_name' ], $r[ $person . '_first_name' ] );
			}
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		return array_intersect_key( $r, array_flip( array_merge( self::FORM_FIELDS, self::LEADER_FIELDS ) ) );
	}

	/**
	 * Clean a list of key dates; invalid ones are dropped, the rest sorted.
	 *
	 * @param array $dates Raw list of {date, time, label}.
	 * @return array[]
	 */
	private function sanitize_key_dates( array $dates ): array {
		$clean = array();

		foreach ( $dates as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$date  = trim( (string) ( $item['date'] ?? '' ) );
			$time  = trim( (string) ( $item['time'] ?? '' ) );
			$label = trim( sanitize_text_field( (string) ( $item['label'] ?? '' ) ) );

			if ( '' === $label || ! $this->is_valid_date( $date ) ) {
				continue;
			}

			$clean[] = array(
				'date'  => $date,
				'time'  => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '',
				'label' => mb_substr( $label, 0, 100 ),
			);
		}

		usort( $clean, static fn( $a, $b ) => strcmp( $a['date'] . $a['time'], $b['date'] . $b['time'] ) );

		return $clean;
	}

	/**
	 * Whether a string is a real Y-m-d date.
	 *
	 * @param string $date Date.
	 */
	private function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Write fields to the database.
	 *
	 * @param int   $id     Intention ID.
	 * @param array $fields Hydrated fields.
	 */
	private function write( int $id, array $fields ): void {
		global $wpdb;

		$fields['updated_at'] = DFXPRAY_Clock::get_instance()->mysql();
		$wpdb->update( $this->tables()['intentions'], $this->to_row( $fields ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Hydrated fields to database columns.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private function to_row( array $fields ): array {
		foreach ( array( 'key_dates', 'alerts_sent' ) as $json ) {
			if ( array_key_exists( $json, $fields ) ) {
				$fields[ $json ] = wp_json_encode( array_values( (array) $fields[ $json ] ) );
			}
		}

		foreach ( array( 'beneficiary_is_requester', 'anniversary', 'ack_sent' ) as $bool ) {
			if ( array_key_exists( $bool, $fields ) ) {
				$fields[ $bool ] = $fields[ $bool ] ? 1 : 0;
			}
		}

		return $fields;
	}

	/**
	 * Database row to hydrated fields.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		$key_dates = json_decode( (string) $row['key_dates'], true );
		$alerts    = json_decode( (string) $row['alerts_sent'], true );

		return array(
			'id'                       => (int) $row['id'],
			'group_id'                 => (int) $row['group_id'],
			'requester_name'           => $row['requester_name'],
			'requester_contact'        => $row['requester_contact'],
			'beneficiary_name'         => $row['beneficiary_name'],
			'beneficiary_is_requester' => (bool) $row['beneficiary_is_requester'],
			'relationship'             => $row['relationship'],
			'body'                     => $row['body'],
			'summary'                  => $row['summary'],
			'type'                     => $row['type'],
			'reference_date'           => $row['reference_date'],
			'reference_time'           => null === $row['reference_time'] ? null : substr( $row['reference_time'], 0, 5 ),
			'key_dates'                => is_array( $key_dates ) ? $key_dates : array(),
			'anniversary'              => (bool) $row['anniversary'],
			'discretion'               => $row['discretion'],
			'requester_display_name'   => $row['requester_display_name'],
			'beneficiary_display_name' => $row['beneficiary_display_name'],
			'requester_first_name'     => $row['requester_first_name'],
			'beneficiary_first_name'   => $row['beneficiary_first_name'],
			'priority'                 => $row['priority'],
			'status'                   => $row['status'],
			'close_reason'             => $row['close_reason'],
			'next_review'              => $row['next_review'],
			'ack_sent'                 => (bool) $row['ack_sent'],
			'source'                   => $row['source'],
			'linked_id'                => null === $row['linked_id'] ? null : (int) $row['linked_id'],
			'alerts_sent'              => is_array( $alerts ) ? $alerts : array(),
			'created_at'               => $row['created_at'],
			'updated_at'               => $row['updated_at'],
			'activated_at'             => $row['activated_at'],
			'closed_at'                => $row['closed_at'],
		);
	}

	/**
	 * Error for a missing intention.
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error( 'dfxpray_not_found', __( 'The intention does not exist.', 'dfx-prayer-intentions' ) );
	}

	/**
	 * Error for a forbidden state change.
	 */
	private function transition_error(): WP_Error {
		return new WP_Error( 'dfxpray_transition', __( 'That change is not possible in the intention’s current state.', 'dfx-prayer-intentions' ) );
	}

	/**
	 * Error for a missing summary.
	 */
	private function summary_required_error(): WP_Error {
		return new WP_Error( 'dfxpray_summary_required', __( 'Write the summary for the index before activating the intention.', 'dfx-prayer-intentions' ) );
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
