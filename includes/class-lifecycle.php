<?php
/**
 * Lifecycle rules: types, deadlines and allowed state changes.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The temporal type decides how long an intention stays active and when it
 * asks for attention. When its review date arrives, an active intention moves
 * to "review" and keeps appearing in the index until a leader decides.
 */
final class DFXPRAY_Lifecycle {

	use DFXPRAY_Singleton;

	/**
	 * Allowed transitions, from the specification's state diagram.
	 */
	private const TRANSITIONS = array(
		'pending' => array( 'active', 'discarded' ),
		'active'  => array( 'review', 'closed' ),
		'review'  => array( 'active', 'closed' ),
	);

	/**
	 * Temporal types.
	 *
	 * @return array<string, string> Type => label.
	 */
	public function types(): array {
		return array(
			'event'        => __( 'One-off event', 'dfx-prayer-intentions' ),
			'deceased'     => __( 'Deceased', 'dfx-prayer-intentions' ),
			'ongoing'      => __( 'Ongoing situation', 'dfx-prayer-intentions' ),
			'thanksgiving' => __( 'Thanksgiving', 'dfx-prayer-intentions' ),
		);
	}

	/**
	 * States.
	 *
	 * @return array<string, string> State => label.
	 */
	public function statuses(): array {
		return array(
			'pending'   => __( 'Pending', 'dfx-prayer-intentions' ),
			'active'    => __( 'Active', 'dfx-prayer-intentions' ),
			'review'    => __( 'In review', 'dfx-prayer-intentions' ),
			'closed'    => __( 'Closed', 'dfx-prayer-intentions' ),
			'discarded' => __( 'Discarded', 'dfx-prayer-intentions' ),
		);
	}

	/**
	 * Reasons for closing.
	 *
	 * @return array<string, string> Reason => label.
	 */
	public function close_reasons(): array {
		return array(
			'answered'  => __( 'Answered', 'dfx-prayer-intentions' ),
			'expired'   => __( 'Expired', 'dfx-prayer-intentions' ),
			'withdrawn' => __( 'Withdrawn by the requester', 'dfx-prayer-intentions' ),
		);
	}

	/**
	 * Whether a type exists.
	 *
	 * @param string $type Type.
	 */
	public function is_valid_type( string $type ): bool {
		return array_key_exists( $type, $this->types() );
	}

	/**
	 * Whether the type needs a reference date.
	 *
	 * @param string $type Type.
	 */
	public function requires_reference_date( string $type ): bool {
		return in_array( $type, array( 'event', 'deceased' ), true );
	}

	/**
	 * Whether the type accepts a time of day.
	 *
	 * @param string $type Type.
	 */
	public function accepts_time( string $type ): bool {
		return 'event' === $type;
	}

	/**
	 * Whether closing as answered may create a linked thanksgiving.
	 *
	 * @param string $type Type.
	 */
	public function can_spawn_thanksgiving( string $type ): bool {
		return in_array( $type, array( 'event', 'ongoing' ), true );
	}

	/**
	 * Whether the state diagram allows a change.
	 *
	 * @param string $from Current state.
	 * @param string $to   New state.
	 */
	public function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	/**
	 * Review date for an intention being activated today.
	 *
	 * @param string      $type      Type.
	 * @param string|null $reference Reference date (Y-m-d) or null.
	 * @param int|null    $group_id  Group whose deadlines apply.
	 * @return string Y-m-d.
	 */
	public function initial_review_date( string $type, ?string $reference, ?int $group_id = null ): string {
		$settings = DFXPRAY_Settings::get_instance();
		$clock    = DFXPRAY_Clock::get_instance();
		$today    = $clock->local( $clock->today() );
		$base     = $reference ? $clock->local( $reference ) : $today;

		switch ( $type ) {
			case 'event':
				$date = $base->modify( '+' . $settings->get( 'event_grace_days', $group_id ) . ' days' );
				break;
			case 'deceased':
				$date = $base->modify( '+' . $settings->get( 'deceased_days', $group_id ) . ' days' );
				break;
			case 'thanksgiving':
				$date = $base->modify( '+' . $settings->get( 'thanksgiving_days', $group_id ) . ' days' );
				break;
			default:
				$date = $today->modify( '+' . $settings->get( 'ongoing_review_days', $group_id ) . ' days' );
		}

		return $date->format( 'Y-m-d' );
	}

	/**
	 * Review date after a leader says the intention is still current.
	 *
	 * @param string   $type     Type.
	 * @param int|null $group_id Group whose deadlines apply.
	 * @return string Y-m-d.
	 */
	public function keep_active_review_date( string $type, ?int $group_id = null ): string {
		$settings = DFXPRAY_Settings::get_instance();
		$clock    = DFXPRAY_Clock::get_instance();
		$days     = 'ongoing' === $type ? $settings->get( 'ongoing_review_days', $group_id ) : $settings->get( 'keep_active_days', $group_id );

		return $clock->local( $clock->today() )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}
}
