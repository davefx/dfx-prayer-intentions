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
		$prefix = (string) DFXPRAY_Settings::get_instance()->get( 'member_prefix', isset( $intention['group_id'] ) ? (int) $intention['group_id'] : null );

		return DFXPRAY_Discretion::get_instance()->describe( $intention, $prefix ) ?? __( 'A private intention', 'dfx-prayer-intentions' );
	}

	/**
	 * A phone number as kept: digits, with a leading "+" when it had one.
	 *
	 * @param string $phone Phone as typed.
	 * @return string|null Clean phone ('' when empty), null when it is not a phone.
	 */
	public function clean_phone( string $phone ): ?string {
		$phone = trim( $phone );
		if ( '' === $phone ) {
			return '';
		}

		if ( ! preg_match( '/^\+?[\d\s().-]{6,}$/', $phone ) ) {
			return null;
		}

		$digits = preg_replace( '/\D/', '', $phone );
		if ( strlen( $digits ) < 6 || strlen( $digits ) > 15 ) {
			return null;
		}

		return ( str_starts_with( $phone, '+' ) ? '+' : '' ) . $digits;
	}

	/**
	 * A WhatsApp link to ask the requester how the intention goes, with the
	 * question already written; '' when there is no phone to call. National
	 * numbers take the default country code.
	 *
	 * @param array $intention Intention.
	 */
	public function ask_link( array $intention ): string {
		$phone = (string) ( $intention['requester_phone'] ?? '' );
		if ( '' === $phone ) {
			$phone = (string) $this->clean_phone( (string) ( $intention['requester_contact'] ?? '' ) );
		}

		if ( '' === $phone ) {
			return '';
		}

		$digits = ltrim( $phone, '+' );
		if ( ! str_starts_with( $phone, '+' ) ) {
			$digits = preg_replace( '/\D/', '', (string) DFXPRAY_Settings::get_instance()->get( 'phone_country_code' ) ) . $digits;
		}

		$first   = static fn( string $given, string $full ): string => '' !== $given ? $given : (string) strtok( $full, ' ' );
		$name    = $first( (string) ( $intention['requester_first_name'] ?? '' ), (string) $intention['requester_name'] );
		$summary = (string) ( $intention['summary'] ?? '' );

		if ( ! empty( $intention['beneficiary_is_requester'] ) ) {
			/* translators: 1: requester's first name, 2: summary of the intention. */
			$text = sprintf( __( 'Hi %1$s, how are you doing (%2$s)?', 'dfx-prayer-intentions' ), $name, $summary );
		} else {
			$text = sprintf(
				/* translators: 1: requester's first name, 2: beneficiary's first name, 3: summary of the intention. */
				__( 'Hi %1$s, how is %2$s doing (%3$s)?', 'dfx-prayer-intentions' ),
				$name,
				$first( (string) ( $intention['beneficiary_first_name'] ?? '' ), (string) $intention['beneficiary_name'] ),
				$summary
			);
		}

		return 'https://wa.me/' . $digits . '?text=' . rawurlencode( $text );
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
	 * @param array $intention     Intention.
	 * @param bool  $priority_mark Whether high priority is marked with "❗ ".
	 */
	public function line( array $intention, bool $priority_mark = true ): string {
		$line = $this->person_with( $intention, $intention['summary'] ) . $this->date_suffix( $intention );

		return ( $priority_mark ? $this->priority_mark( $intention ) : '' ) . $line;
	}

	/**
	 * The date suffix of a line: " _(Monday 28, 9:30)_" for events,
	 * " († 20/09)" for the departed, empty otherwise.
	 *
	 * @param array $intention Intention.
	 */
	public function date_suffix( array $intention ): string {
		if ( empty( $intention['reference_date'] ) ) {
			return '';
		}

		if ( 'event' === $intention['type'] ) {
			return ' _(' . $this->when( $intention['reference_date'], $intention['reference_time'] ) . ')_';
		}

		if ( 'deceased' === $intention['type'] ) {
			return ' († ' . $this->short_date( $intention['reference_date'] ) . ')';
		}

		return '';
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
	 * Replace {date} ("24/09") and {long_date} ("Thursday, September 24,
	 * 2026") in a header or footer.
	 *
	 * @param string $template Template.
	 */
	public function fill( string $template ): string {
		$today = DFXPRAY_Clock::get_instance()->today();

		return str_replace(
			array( '{date}', '{long_date}' ),
			array( $this->short_date( $today ), $this->long_date( $today ) ),
			$template
		);
	}

	/**
	 * Full date with the weekday, capitalised: "Thursday, September 24, 2026".
	 *
	 * @param string $date Y-m-d.
	 */
	public function long_date( string $date ): string {
		/* translators: PHP date format for the full date with the weekday, see https://www.php.net/manual/datetime.format.php */
		$text = wp_date( _x( 'l, F j, Y', 'long date format', 'dfx-prayer-intentions' ), $this->timestamp( $date ) );

		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
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
