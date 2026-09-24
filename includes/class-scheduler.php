<?php
/**
 * Scheduled work.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs every five minutes (the "tick": expirations, each group's digest at
 * its time, hour alerts) and once a day (retention).
 *
 * Punctuality depends on cron: with WP-Cron, work only happens when someone
 * visits the site. A real system cron calling wp-cron.php is recommended.
 */
final class DFXPRAY_Scheduler {

	use DFXPRAY_Singleton;

	/**
	 * How late an alert may still go out after its moment, in minutes.
	 */
	private const ALERT_TOLERANCE = 30;

	/**
	 * How many recent alerts are kept for the panel's Copy button.
	 */
	private const RECENT_ALERTS = 20;

	/**
	 * Minutes before quiet hours in which an early alert may go out.
	 */
	private const EARLY_ALERT_WINDOW = 30;

	/**
	 * Hook into cron.
	 */
	public function register_hooks(): void {
		add_action( 'dfxpray_tick', array( $this, 'tick' ) );
		add_action( 'dfxpray_daily', array( $this, 'daily' ) );
	}

	/**
	 * Five-minute work.
	 */
	public function tick(): void {
		DFXPRAY_Intentions::get_instance()->process_due();

		foreach ( DFXPRAY_Groups::get_instance()->all( 'active' ) as $group ) {
			$this->maybe_send_digest( $group['id'] );
			$this->send_hour_alerts( $group['id'] );
		}
	}

	/**
	 * Daily work: delete closed and discarded intentions past retention.
	 *
	 * @return int How many intentions were deleted.
	 */
	public function daily(): int {
		global $wpdb;

		$days   = (int) DFXPRAY_Settings::get_instance()->get( 'retention_days' );
		$cutoff = DFXPRAY_Clock::get_instance()->now()->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
		$table  = DFXPRAY_Installer::get_instance()->get_table_names()['intentions'];

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id FROM %i WHERE status IN ('closed','discarded') AND closed_at IS NOT NULL AND closed_at < %s",
				$table,
				$cutoff
			)
		);

		DFXPRAY_Intentions::get_instance()->delete( $ids );

		return count( $ids );
	}

	/**
	 * Generate, store and send the group's digest once its time has come.
	 *
	 * @param int  $group_id Group ID.
	 * @param bool $force    Send even if already sent today or not yet time.
	 * @return bool Whether a digest was generated.
	 */
	public function maybe_send_digest( int $group_id, bool $force = false ): bool {
		$clock  = DFXPRAY_Clock::get_instance();
		$today  = $clock->today();
		$time   = (string) DFXPRAY_Settings::get_instance()->get( 'digest_time', $group_id );
		$stored = $this->get_stored_digest( $group_id );

		if ( ! $force && ( $clock->now()->format( 'H:i' ) < $time || ( $stored && $today === $stored['date'] ) ) ) {
			return false;
		}

		$digest         = DFXPRAY_Digest_Composer::get_instance()->compose( $group_id );
		$digest['date'] = $today;
		update_option( 'dfxpray_digest_' . $group_id, $digest, false );

		// Silent alerts this digest listed need no early alert.
		$intentions = DFXPRAY_Intentions::get_instance();
		foreach ( $digest['covered_alerts'] as $intention_id => $keys ) {
			$intention = $intentions->get( (int) $intention_id );
			if ( $intention ) {
				$intentions->set_alerts_sent( $intention['id'], array_merge( $intention['alerts_sent'], $keys ) );
			}
		}

		DFXPRAY_Notifier::get_instance()->send_digest( $group_id, $digest );

		return true;
	}

	/**
	 * Last digest generated for a group.
	 *
	 * @param int $group_id Group ID.
	 * @return array|null
	 */
	public function get_stored_digest( int $group_id ): ?array {
		$digest = get_option( 'dfxpray_digest_' . $group_id );

		return is_array( $digest ) ? $digest : null;
	}

	/**
	 * Send the alerts whose moment has come.
	 *
	 * An alert that falls in quiet hours is never sent at its moment. If a
	 * digest already listed it (see DFXPRAY_Digest_Composer), nothing more is
	 * needed; otherwise one early alert goes out in the half hour before quiet
	 * hours begin, so no timed event is ever left without notice.
	 *
	 * @param int $group_id Group ID.
	 */
	public function send_hour_alerts( int $group_id ): void {
		$settings   = DFXPRAY_Settings::get_instance();
		$now        = DFXPRAY_Clock::get_instance()->now();
		$quiet      = array( (string) $settings->get( 'quiet_start', $group_id ), (string) $settings->get( 'quiet_end', $group_id ) );
		$intentions = DFXPRAY_Intentions::get_instance();
		$notifier   = DFXPRAY_Notifier::get_instance();

		foreach ( $intentions->listable( $group_id ) as $intention ) {
			$slots = $this->alert_slots( $intention, $group_id );
			if ( ! $slots ) {
				continue;
			}

			$sent  = $intention['alerts_sent'];
			$early = false;

			foreach ( $slots as $slot ) {
				if ( in_array( $slot['key'], $sent, true ) ) {
					continue;
				}

				if ( $this->is_quiet( $slot['at']->format( 'H:i' ), $quiet[0], $quiet[1] ) ) {
					$quiet_from = $this->quiet_start_of( $slot['at'], $quiet[0], $quiet[1] );

					if ( $now >= $slot['at'] ) {
						$sent[] = $slot['key'];
					} elseif ( $now >= $quiet_from->modify( '-' . self::EARLY_ALERT_WINDOW . ' minutes' ) && $now < $quiet_from ) {
						$sent[] = $slot['key'];
						$early  = true;
					}
					continue;
				}

				if ( $now < $slot['at'] || $now >= $slot['until'] ) {
					continue;
				}

				$sent[] = $slot['key'];
				$text   = $notifier->alert_text( $intention, $slot['moment'] );
				$notifier->send_alert( $intention, $text );
				$this->remember_alert( $group_id, $intention['id'], $text );
			}

			$early_key = 'early|' . $intention['reference_date'] . ' ' . $intention['reference_time'];
			if ( $early && ! in_array( $early_key, $sent, true ) ) {
				$sent[] = $early_key;
				$text   = $notifier->alert_text( $intention, 'early' );
				$notifier->send_alert( $intention, $text );
				$this->remember_alert( $group_id, $intention['id'], $text );
			}

			if ( $sent !== $intention['alerts_sent'] ) {
				$intentions->set_alerts_sent( $intention['id'], $sent );
			}
		}
	}

	/**
	 * The alert moments of a timed event: before it, and optionally at the time.
	 *
	 * @param array $intention Intention.
	 * @param int   $group_id  Group ID.
	 * @return array[] key, moment ('before'|'now'), at, until (DateTimeImmutable).
	 */
	public function alert_slots( array $intention, int $group_id ): array {
		if ( 'event' !== $intention['type'] || ! $intention['reference_date'] || ! $intention['reference_time'] ) {
			return array();
		}

		$settings = DFXPRAY_Settings::get_instance();
		$event    = DFXPRAY_Clock::get_instance()->local( $intention['reference_date'] . ' ' . $intention['reference_time'] );
		$suffix   = '|' . $intention['reference_date'] . ' ' . $intention['reference_time'];

		$slots = array(
			array(
				'key'    => 'before' . $suffix,
				'moment' => 'before',
				'at'     => $event->modify( '-' . (int) $settings->get( 'alert_lead_minutes', $group_id ) . ' minutes' ),
				'until'  => $event,
			),
		);

		if ( $settings->get( 'alert_at_exact_time', $group_id ) ) {
			$slots[] = array(
				'key'    => 'now' . $suffix,
				'moment' => 'now',
				'at'     => $event,
				'until'  => $event->modify( '+' . self::ALERT_TOLERANCE . ' minutes' ),
			);
		}

		return $slots;
	}

	/**
	 * Start of the quiet period that contains a moment.
	 *
	 * @param DateTimeImmutable $at    Moment inside quiet hours.
	 * @param string            $start Quiet start, H:i.
	 * @param string            $end   Quiet end, H:i.
	 */
	public function quiet_start_of( DateTimeImmutable $at, string $start, string $end ): DateTimeImmutable {
		[ $hour, $minute ] = array_map( 'intval', explode( ':', $start ) );
		$from              = $at->setTime( $hour, $minute );

		// A range that wraps midnight started the day before for early-morning moments.
		if ( $start > $end && $at->format( 'H:i' ) < $start ) {
			$from = $from->modify( '-1 day' );
		}

		return $from;
	}

	/**
	 * Alerts sent recently, newest first, for the panel's Copy button.
	 *
	 * @param int $group_id Group ID.
	 * @return array[] time, intention_id, text.
	 */
	public function get_recent_alerts( int $group_id ): array {
		$alerts = get_option( 'dfxpray_alerts_' . $group_id, array() );

		return is_array( $alerts ) ? $alerts : array();
	}

	/**
	 * Whether a time of day falls in quiet hours; the range may wrap midnight.
	 *
	 * @param string $time  H:i.
	 * @param string $start Quiet start, H:i.
	 * @param string $end   Quiet end, H:i.
	 */
	public function is_quiet( string $time, string $start, string $end ): bool {
		if ( $start === $end ) {
			return false;
		}

		if ( $start < $end ) {
			return $time >= $start && $time < $end;
		}

		return $time >= $start || $time < $end;
	}

	/**
	 * Keep an alert for the panel.
	 *
	 * @param int    $group_id     Group ID.
	 * @param int    $intention_id Intention ID.
	 * @param string $text         Text.
	 */
	private function remember_alert( int $group_id, int $intention_id, string $text ): void {
		$alerts = $this->get_recent_alerts( $group_id );

		array_unshift(
			$alerts,
			array(
				'time'         => DFXPRAY_Clock::get_instance()->mysql(),
				'intention_id' => $intention_id,
				'text'         => $text,
			)
		);

		update_option( 'dfxpray_alerts_' . $group_id, array_slice( $alerts, 0, self::RECENT_ALERTS ), false );
	}
}
