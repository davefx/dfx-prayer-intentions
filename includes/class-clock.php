<?php
/**
 * Clock.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single source of "now" for the plugin, always in the site timezone.
 *
 * Every date calculation goes through here so that tests (unit and E2E) can
 * freeze time. E2E freezes it through the dfxpray_frozen_now option, which is
 * only honoured when DFXPRAY_ALLOW_FROZEN_CLOCK is defined as true.
 */
final class DFXPRAY_Clock {

	use DFXPRAY_Singleton;

	/**
	 * Frozen moment, or null for real time.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $frozen = null;

	/**
	 * Current moment in the site timezone.
	 */
	public function now(): DateTimeImmutable {
		if ( null !== $this->frozen ) {
			return $this->frozen->setTimezone( wp_timezone() );
		}

		if ( defined( 'DFXPRAY_ALLOW_FROZEN_CLOCK' ) && DFXPRAY_ALLOW_FROZEN_CLOCK ) {
			$option = get_option( 'dfxpray_frozen_now' );
			if ( is_string( $option ) && '' !== $option ) {
				return new DateTimeImmutable( $option, wp_timezone() );
			}
		}

		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * Today's date (Y-m-d) in the site timezone.
	 */
	public function today(): string {
		return $this->now()->format( 'Y-m-d' );
	}

	/**
	 * Current local time in MySQL DATETIME format.
	 */
	public function mysql(): string {
		return $this->now()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Build a local date from a Y-m-d string (midnight, site timezone).
	 *
	 * @param string $date Date as Y-m-d, optionally followed by a time.
	 */
	public function local( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date, wp_timezone() );
	}

	/**
	 * Freeze the clock.
	 *
	 * @param DateTimeImmutable $moment Moment to freeze at.
	 */
	public function freeze( DateTimeImmutable $moment ): void {
		$this->frozen = $moment;
	}

	/**
	 * Return to real time.
	 */
	public function reset(): void {
		$this->frozen = null;
	}
}
