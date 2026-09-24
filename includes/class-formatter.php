<?php
/**
 * Formatting of dates, times, people and index lines.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared by the index, the digest and the hour alerts, so that one intention
 * always reads the same wherever it appears.
 */
final class DFXPRAY_Formatter {

	use DFXPRAY_Singleton;

	/**
	 * Day and month, e.g. "24/09".
	 *
	 * @param string $date Y-m-d.
	 */
	public function short_date( string $date ): string {
		/* translators: PHP date format for day and month in the index, see https://www.php.net/manual/datetime.format.php */
		return wp_date( _x( 'd/m', 'short date format', 'dfx-prayer-intentions' ), $this->timestamp( $date ) );
	}

	/**
	 * Time of day, e.g. "9:30".
	 *
	 * @param string $time H:i.
	 */
	public function time( string $time ): string {
		/* translators: PHP time format for event times, see https://www.php.net/manual/datetime.format.php */
		return wp_date( _x( 'G:i', 'event time format', 'dfx-prayer-intentions' ), $this->timestamp( DFXPRAY_Clock::get_instance()->today() . ' ' . $time ) );
	}

	/**
	 * When an event happens, relative to today: "today", "tomorrow",
	 * "Monday 28" within the week, "15/10" otherwise; time appended.
	 *
	 * @param string      $date Y-m-d.
	 * @param string|null $time H:i or null.
	 */
	public function when( string $date, ?string $time = null ): string {
		$clock = DFXPRAY_Clock::get_instance();
		$today = $clock->local( $clock->today() );
		$day   = $clock->local( $date );
		$diff  = (int) $today->diff( $day )->format( '%r%a' );

		if ( 0 === $diff ) {
			$label = __( 'today', 'dfx-prayer-intentions' );
		} elseif ( 1 === $diff ) {
			$label = __( 'tomorrow', 'dfx-prayer-intentions' );
		} elseif ( $diff > 1 && $diff < 7 ) {
			/* translators: PHP date format for a day within the next week, e.g. "Monday 28". */
			$label = wp_date( _x( 'l j', 'weekday and day format', 'dfx-prayer-intentions' ), $day->getTimestamp() );
		} else {
			$label = $this->short_date( $date );
		}

		return null === $time || '' === $time ? $label : $label . ', ' . $this->time( $time );
	}

	/**
	 * The people part of a line, with discretion applied.
	 *
	 * @param array $intention Intention.
	 */
	public function person( array $intention ): string {
		return DFXPRAY_Discretion::get_instance()->describe( $intention ) ?? __( 'A private intention', 'dfx-prayer-intentions' );
	}

	/**
	 * "Person: text", the building block of every line.
	 *
	 * @param array  $intention Intention.
	 * @param string $text      Text after the person.
	 */
	public function person_with( array $intention, string $text ): string {
		$person = $this->person( $intention );

		if ( '' === $text ) {
			return $person;
		}

		/* translators: 1: person (or "A private intention"), 2: summary or update. */
		return sprintf( __( '%1$s: %2$s', 'dfx-prayer-intentions' ), $person, $text );
	}

	/**
	 * A full index line (without bullet): priority mark, person, summary and
	 * the date suffix of events and the departed.
	 *
	 * @param array $intention Intention.
	 */
	public function line( array $intention ): string {
		$line = $this->person_with( $intention, $intention['summary'] );

		if ( 'event' === $intention['type'] && $intention['reference_date'] ) {
			$line .= ' _(' . $this->when( $intention['reference_date'], $intention['reference_time'] ) . ')_';
		} elseif ( 'deceased' === $intention['type'] && $intention['reference_date'] ) {
			$line .= ' († ' . $this->short_date( $intention['reference_date'] ) . ')';
		}

		return $this->priority_mark( $intention ) . $line;
	}

	/**
	 * "❗ " for high priority.
	 *
	 * @param array $intention Intention.
	 */
	public function priority_mark( array $intention ): string {
		return 'high' === $intention['priority'] ? '❗ ' : '';
	}

	/**
	 * Replace {date} in a header or footer.
	 *
	 * @param string $template Template.
	 */
	public function fill( string $template ): string {
		return str_replace( '{date}', $this->short_date( DFXPRAY_Clock::get_instance()->today() ), $template );
	}

	/**
	 * Timestamp of a local date(time).
	 *
	 * @param string $local Local date or datetime.
	 */
	private function timestamp( string $local ): int {
		return DFXPRAY_Clock::get_instance()->local( $local )->getTimestamp();
	}
}
