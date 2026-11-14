<?php
/**
 * Daily prayer digest.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the two variants of the daily digest: the leaders' one (complete,
 * with links to the panel) and the shareable one (no leaders-only section,
 * discretion applied, WhatsApp syntax).
 */
final class DFXPRAY_Digest_Composer {

	use DFXPRAY_Singleton;

	/**
	 * Compose the digest of a group for the current moment.
	 *
	 * @param int $group_id Group ID.
	 * @return array{leaders:string, shareable:string, is_empty:bool, generated_at:string}
	 */
	public function compose( int $group_id ): array {
		$settings   = DFXPRAY_Settings::get_instance();
		$formatter  = DFXPRAY_Formatter::get_instance();
		$clock      = DFXPRAY_Clock::get_instance();
		$today      = $clock->today();
		$tomorrow   = $clock->local( $today )->modify( '+1 day' )->format( 'Y-m-d' );
		$since      = $clock->now()->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
		$intentions = DFXPRAY_Index_Composer::get_instance()->sort( DFXPRAY_Intentions::get_instance()->listable( $group_id ) );
		$titles     = $this->titles();
		$silent     = $this->silent_events( $intentions, $group_id );
		$loud       = array_filter( $intentions, static fn( $i ) => ! isset( $silent['keys'][ $i['id'] ] ) );

		$shared = array(
			'today'    => $this->day_lines( $loud, $today, DFXPRAY_Intentions::get_instance()->anniversaries( $group_id ), $intentions ),
			'quiet'    => $silent['lines'],
			'news'     => $this->news_lines( $group_id, $intentions, $since ),
			'high'     => array_map(
				static fn( $i ) => substr( $formatter->line( $i ), strlen( $formatter->priority_mark( $i ) ) ),
				array_filter( $intentions, static fn( $i ) => 'high' === $i['priority'] )
			),
			'tomorrow' => $this->day_lines( $loud, $tomorrow, array(), $intentions ),
			'deceased' => $this->recent_deceased_lines( $intentions, $group_id ),
		);

		$sections = array();
		foreach ( $shared as $key => $lines ) {
			$sections[] = $this->filter_section( $key, $titles[ $key ], array_values( $lines ), $group_id );
		}
		$sections = array_values( array_filter( $sections, static fn( $s ) => ! empty( $s['lines'] ) ) );

		$leaders_section = $this->filter_section( 'leaders', $titles['leaders'], $this->leaders_lines( $group_id, $since ), $group_id );

		$is_empty = ! $sections;
		$when     = $settings->get( 'digest_when_empty', $group_id );
		$vars     = array(
			'header'        => $formatter->fill( (string) $settings->get( 'digest_header', $group_id ) ),
			'footer'        => $formatter->fill( (string) $settings->get( 'digest_footer', $group_id ) ),
			'empty_message' => __( 'Nothing new today. Let us keep praying for all our intentions.', 'dfx-prayer-intentions' ),
		);

		$templates = DFXPRAY_Templates::get_instance();

		$shareable = ( $is_empty && 'none' === $when )
			? ''
			: $templates->render(
				'digest.php',
				array_merge(
					$vars,
					array(
						'sections' => $sections,
						'variant'  => 'shareable',
					)
				)
			);

		$leader_sections = $leaders_section['lines'] ? array_merge( $sections, array( $leaders_section ) ) : $sections;
		$leaders         = ( ! $leader_sections && 'none' === $when )
			? ''
			: $this->render_leaders( $vars, $leader_sections, $is_empty );

		return array(
			'leaders'        => $leaders,
			'shareable'      => $shareable,
			'is_empty'       => $is_empty,
			'generated_at'   => $clock->mysql(),
			'covered_alerts' => $silent['keys'],
		);
	}

	/**
	 * Section titles.
	 *
	 * @return array<string, string>
	 */
	public function titles(): array {
		return array(
			'today'    => __( 'Today', 'dfx-prayer-intentions' ),
			'quiet'    => __( 'Without an hour alert', 'dfx-prayer-intentions' ),
			'news'     => __( 'News', 'dfx-prayer-intentions' ),
			'high'     => __( 'High priority', 'dfx-prayer-intentions' ),
			'tomorrow' => __( 'Tomorrow', 'dfx-prayer-intentions' ),
			'deceased' => __( 'Recently departed', 'dfx-prayer-intentions' ),
			'leaders'  => __( 'Leaders only', 'dfx-prayer-intentions' ),
		);
	}

	/**
	 * Render the leaders' variant; when only the leaders' section has
	 * content, the empty message goes before it.
	 *
	 * @param array   $vars     Template variables.
	 * @param array[] $sections Sections.
	 * @param bool    $is_empty Whether the shared sections are empty.
	 */
	private function render_leaders( array $vars, array $sections, bool $is_empty ): string {
		if ( $is_empty && $sections ) {
			$vars['header'] = trim( $vars['header'] . "\n\n" . $vars['empty_message'] );
		}

		return DFXPRAY_Templates::get_instance()->render(
			'digest.php',
			array_merge(
				$vars,
				array(
					'sections' => $sections,
					'variant'  => 'leaders',
				)
			)
		);
	}

	/**
	 * Apply the section filter.
	 *
	 * @param string   $key      Section key.
	 * @param string   $title    Title.
	 * @param string[] $lines    Lines.
	 * @param int      $group_id Group ID.
	 * @return array
	 */
	private function filter_section( string $key, string $title, array $lines, int $group_id ): array {
		/**
		 * Filters one section of the daily digest. The dynamic part is the
		 * section key: today, news, high, tomorrow, deceased or leaders.
		 *
		 * @param array $section  Section: key, title, emoji, lines.
		 * @param int   $group_id Group ID.
		 */
		return apply_filters(
			"dfxpray_digest_section_{$key}",
			array(
				'key'   => $key,
				'title' => $title,
				'emoji' => '',
				'lines' => $lines,
			),
			$group_id
		);
	}

	/**
	 * Events, key dates and anniversaries of a day, ordered by time.
	 *
	 * @param array[] $events       Intentions whose events are listed.
	 * @param string  $date         Y-m-d.
	 * @param array[] $anniversaries Deceased whose anniversary is remembered (closed too); empty to leave them out.
	 * @param array[] $intentions    All listable intentions, for key dates.
	 * @return string[]
	 */
	private function day_lines( array $events, string $date, array $anniversaries, array $intentions ): array {
		$formatter = DFXPRAY_Formatter::get_instance();
		$items     = array();

		foreach ( $events as $intention ) {
			if ( 'event' === $intention['type'] && $date === $intention['reference_date'] ) {
				$items[] = array(
					'time' => (string) $intention['reference_time'],
					'text' => $formatter->priority_mark( $intention ) . $formatter->person_with( $intention, $intention['summary'] ),
				);
			}
		}

		foreach ( $intentions as $intention ) {
			foreach ( $intention['key_dates'] as $key_date ) {
				if ( $date === $key_date['date'] ) {
					$items[] = array(
						'time' => $key_date['time'],
						/* translators: 1: key date label (funeral, appointment…), 2: person. */
						'text' => sprintf( _x( '%1$s: %2$s', 'key date label and person', 'dfx-prayer-intentions' ), $key_date['label'], $formatter->person( $intention ) ),
					);
				}
			}
		}

		foreach ( $anniversaries as $intention ) {
			if ( $intention['reference_date'] && substr( $intention['reference_date'], 5 ) === substr( $date, 5 ) && $intention['reference_date'] < $date ) {
				$items[] = array(
					'time' => '',
					/* translators: %s: person. */
					'text' => sprintf( __( 'Anniversary: %s', 'dfx-prayer-intentions' ), $formatter->person( $intention ) ),
				);
			}
		}

		usort( $items, static fn( $a, $b ) => strcmp( $a['time'], $b['time'] ) );

		return array_map(
			static fn( $item ) => '' === $item['time'] ? $item['text'] : $formatter->time( $item['time'] ) . ' – ' . $item['text'],
			$items
		);
	}

	/**
	 * Timed events before the next digest whose hour alert falls in quiet
	 * hours: they get no alert, so the digest tells the group now.
	 *
	 * @param array[] $intentions Listable intentions.
	 * @param int     $group_id   Group ID.
	 * @return array{lines: string[], keys: array<int, string[]>} Lines, and the alert keys covered per intention.
	 */
	private function silent_events( array $intentions, int $group_id ): array {
		$settings  = DFXPRAY_Settings::get_instance();
		$scheduler = DFXPRAY_Scheduler::get_instance();
		$formatter = DFXPRAY_Formatter::get_instance();
		$now       = DFXPRAY_Clock::get_instance()->now();
		$next      = $now->modify( '+1 day' );
		$quiet     = array( (string) $settings->get( 'quiet_start', $group_id ), (string) $settings->get( 'quiet_end', $group_id ) );
		$found     = array();
		$keys      = array();

		foreach ( $intentions as $intention ) {
			foreach ( $scheduler->alert_slots( $intention, $group_id ) as $slot ) {
				if ( $slot['at'] >= $now && $slot['at'] < $next && $scheduler->is_quiet( $slot['at']->format( 'H:i' ), $quiet[0], $quiet[1] ) ) {
					$keys[ $intention['id'] ][] = $slot['key'];
					$found[ $intention['id'] ]  = $intention;
				}
			}
		}

		uasort( $found, static fn( $a, $b ) => strcmp( $a['reference_date'] . $a['reference_time'], $b['reference_date'] . $b['reference_time'] ) );

		$lines = array();
		foreach ( $found as $intention ) {
			$lines[] = $formatter->priority_mark( $intention ) . $formatter->when( $intention['reference_date'], $intention['reference_time'] ) . ' – ' . $formatter->person_with( $intention, $intention['summary'] );
		}

		return array(
			'lines' => $lines,
			'keys'  => $keys,
		);
	}

	/**
	 * Intentions activated and news updates in the last 24 hours.
	 *
	 * @param int     $group_id   Group ID.
	 * @param array[] $intentions Listable intentions.
	 * @param string  $since      Local MySQL datetime.
	 * @return string[]
	 */
	private function news_lines( int $group_id, array $intentions, string $since ): array {
		$formatter = DFXPRAY_Formatter::get_instance();
		$by_id     = array_column( $intentions, null, 'id' );
		$lines     = array();

		foreach ( $intentions as $intention ) {
			if ( $intention['activated_at'] && $intention['activated_at'] >= $since ) {
				$lines[] = $formatter->line( $intention );
			}
		}

		foreach ( DFXPRAY_Updates::get_instance()->news_since( $group_id, $since ) as $update ) {
			if ( isset( $by_id[ $update['intention_id'] ] ) ) {
				$lines[] = $formatter->person_with( $by_id[ $update['intention_id'] ], $update['body'] );
			}
		}

		return $lines;
	}

	/**
	 * Deaths of the last days.
	 *
	 * @param array[] $intentions Listable intentions.
	 * @param int     $group_id   Group ID.
	 * @return string[]
	 */
	private function recent_deceased_lines( array $intentions, int $group_id ): array {
		$clock = DFXPRAY_Clock::get_instance();
		$today = $clock->today();
		$days  = (int) DFXPRAY_Settings::get_instance()->get( 'recent_deceased_days', $group_id );
		$from  = $clock->local( $today )->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );

		$recent = array_filter(
			$intentions,
			static fn( $i ) => 'deceased' === $i['type'] && $i['reference_date'] >= $from && $i['reference_date'] <= $today
		);

		return array_map( array( DFXPRAY_Formatter::get_instance(), 'line' ), $recent );
	}

	/**
	 * Pending moderation, reviews due and, optionally, recent comments.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $since    Local MySQL datetime.
	 * @return string[]
	 */
	private function leaders_lines( int $group_id, string $since ): array {
		$intentions = DFXPRAY_Intentions::get_instance();
		$counts     = $intentions->counts( $group_id );
		$lines      = array();

		if ( $counts['pending'] ) {
			/* translators: 1: number of pending intentions, 2: link to the panel. */
			$lines[] = sprintf( __( 'Pending moderation: %1$d – %2$s', 'dfx-prayer-intentions' ), $counts['pending'], DFXPRAY_Admin::get_instance()->group_url( $group_id, 'moderation' ) );
		}

		if ( $counts['review'] ) {
			/* translators: 1: number of intentions in review, 2: link to the panel. */
			$lines[] = sprintf( __( 'Reviews due: %1$d – %2$s', 'dfx-prayer-intentions' ), $counts['review'], DFXPRAY_Admin::get_instance()->group_url( $group_id, 'reviews' ) );
		}

		if ( DFXPRAY_Settings::get_instance()->get( 'digest_include_comments', $group_id ) ) {
			$formatter = DFXPRAY_Formatter::get_instance();
			foreach ( $intentions->query( $group_id, array( 'status' => array( 'pending', 'active', 'review' ) ) ) as $intention ) {
				foreach ( DFXPRAY_Comments::get_instance()->for_intention( $intention['id'] ) as $comment ) {
					if ( $comment['created_at'] >= $since ) {
						$lines[] = $formatter->person_with( $intention, $comment['body'] );
					}
				}
			}
		}

		return $lines;
	}
}
