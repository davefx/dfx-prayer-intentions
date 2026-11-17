<?php
/**
 * Daily and monthly prayer digests.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the two variants of the daily and monthly digests. The shareable
 * one is the message of the leaders' app as it is; the leaders' one is that
 * same message saying who asks for each intention, followed by a private
 * block (today's appointments, events without an hour alert, what waits in
 * the panel, what to ask and the news of the last day).
 */
final class DFXPRAY_Digest_Composer {

	use DFXPRAY_Singleton;

	/**
	 * Line that separates the message from the leaders' block.
	 */
	private const RULE = '────────────';

	/**
	 * Days ahead whose reviews make an intention one "to ask" about.
	 */
	private const ASK_DAYS = 2;

	/**
	 * Compose the daily digest of a group for the current moment.
	 *
	 * @param int $group_id Group ID.
	 * @return array{leaders:string, shareable:string, is_empty:bool, generated_at:string, covered_alerts:array}
	 */
	public function compose( int $group_id ): array {
		$clock      = DFXPRAY_Clock::get_instance();
		$since      = $clock->now()->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
		$intentions = DFXPRAY_Index_Composer::get_instance()->sort( DFXPRAY_Intentions::get_instance()->listable( $group_id ) );
		$silent     = $this->silent_events( $intentions, $group_id );
		$loud       = array_filter( $intentions, static fn( $i ) => ! isset( $silent['keys'][ $i['id'] ] ) );

		$leaders_only = array(
			'today' => $this->day_lines( $loud, $clock->today(), $intentions ),
			'quiet' => $silent['lines'],
			'panel' => $this->panel_lines( $group_id ),
			'ask'   => $this->ask_lines( $intentions ),
			'news'  => $this->news_lines( $group_id, $intentions, $since ),
		);

		return array_merge(
			$this->variants( $group_id, 'daily', $leaders_only ),
			array(
				'generated_at'   => $clock->mysql(),
				'covered_alerts' => $silent['keys'],
			)
		);
	}

	/**
	 * Compose the monthly digest of a group: the monthly list, and for the
	 * leaders what waits in the panel and what to ask.
	 *
	 * @param int $group_id Group ID.
	 * @return array{leaders:string, shareable:string, is_empty:bool}
	 */
	public function compose_monthly( int $group_id ): array {
		$intentions = DFXPRAY_Intentions::get_instance()->listable( $group_id );

		return $this->variants(
			$group_id,
			'monthly',
			array(
				'panel' => $this->panel_lines( $group_id ),
				'ask'   => $this->ask_lines( DFXPRAY_Index_Composer::get_instance()->sort( $intentions ) ),
			)
		);
	}

	/**
	 * Both variants of a digest from an edition of the index and the lines
	 * of the leaders' block.
	 *
	 * @param int                     $group_id Group ID.
	 * @param string                  $edition  'daily' or 'monthly'.
	 * @param array<string, string[]> $leaders_only Leaders' sections: key => lines.
	 * @return array{leaders:string, shareable:string, is_empty:bool}
	 */
	private function variants( int $group_id, string $edition, array $leaders_only ): array {
		$index    = DFXPRAY_Index_Composer::get_instance();
		$is_empty = ! $index->sections( $group_id, $edition );
		$silence  = $is_empty && 'none' === DFXPRAY_Settings::get_instance()->get( 'digest_when_empty', $group_id );
		$titles   = $this->titles();

		$sections = array();
		foreach ( $leaders_only as $key => $lines ) {
			$sections[] = $this->filter_section( $key, $titles[ $key ], array_values( $lines ), $group_id );
		}
		$sections = array_values( array_filter( $sections, static fn( $s ) => ! empty( $s['lines'] ) ) );

		$leaders = $silence && ! $sections ? '' : $index->compose( $group_id, $edition, true );
		if ( $sections ) {
			$leaders .= "\n\n" . DFXPRAY_Templates::get_instance()->render(
				'digest.php',
				array(
					'header'        => self::RULE . "\n*🔒 " . $titles['leaders'] . '*',
					'footer'        => '',
					'sections'      => $sections,
					'empty_message' => '',
					'variant'       => 'leaders',
				)
			);
		}

		return array(
			'leaders'   => $leaders,
			'shareable' => $silence ? '' : $index->compose( $group_id, $edition ),
			'is_empty'  => $is_empty,
		);
	}

	/**
	 * Section titles of the leaders' block.
	 *
	 * @return array<string, string>
	 */
	public function titles(): array {
		return array(
			'today'   => __( 'Today', 'dfx-prayer-intentions' ),
			'quiet'   => __( 'Without an hour alert', 'dfx-prayer-intentions' ),
			'panel'   => __( 'Pending in the panel', 'dfx-prayer-intentions' ),
			'ask'     => _x( 'To ask', 'section of the leaders’ digest', 'dfx-prayer-intentions' ),
			'news'    => __( 'News of the last 24 hours', 'dfx-prayer-intentions' ),
			'leaders' => __( 'Leaders only', 'dfx-prayer-intentions' ),
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
		 * Filters one section of the leaders' block of a digest. The dynamic
		 * part is the section key: today, quiet, panel, ask or news.
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
	 * Whether an intention closes by itself at its review (the departed and
	 * the thanksgivings, when the group closes them automatically), so there
	 * is nothing to ask about it.
	 *
	 * @param array $intention Intention.
	 */
	private function closes_by_itself( array $intention ): bool {
		$setting = array(
			'deceased'     => 'deceased_auto_close',
			'thanksgiving' => 'thanksgiving_auto_close',
		)[ $intention['type'] ] ?? '';

		return '' !== $setting && (bool) DFXPRAY_Settings::get_instance()->get( $setting, (int) $intention['group_id'] );
	}

	/**
	 * Intentions to ask the requester about: those marked so, and the active
	 * ones whose review comes in the next days (except those that close by
	 * themselves), with whom to ask.
	 *
	 * @param array[] $intentions Listable intentions, sorted.
	 * @return string[]
	 */
	private function ask_lines( array $intentions ): array {
		$formatter = DFXPRAY_Formatter::get_instance();
		$clock     = DFXPRAY_Clock::get_instance();
		$until     = $clock->local( $clock->today() )->modify( '+' . self::ASK_DAYS . ' days' )->format( 'Y-m-d' );
		$lines     = array();

		foreach ( $intentions as $intention ) {
			$review = (string) $intention['next_review'];
			$soon   = 'active' === $intention['status'] && '' !== $review && $review <= $until && ! $this->closes_by_itself( $intention );
			if ( ! $intention['to_ask'] && ! $soon ) {
				continue;
			}

			$phone = $formatter->requester_phone( $intention );
			$who   = trim( (string) $intention['requester_name'] ) . ( '' !== $phone ? ' (' . $phone . ')' : '' );

			$ask = $soon
				/* translators: 1: who to ask, with their phone, 2: review date. */
				? sprintf( __( 'ask %1$s, review on %2$s', 'dfx-prayer-intentions' ), $who, $formatter->short_date( $review ) )
				/* translators: %s: who to ask, with their phone. */
				: sprintf( __( 'ask %s', 'dfx-prayer-intentions' ), $who );

			$lines[] = $formatter->line( $intention, false ) . ' – ' . $ask;
		}

		return $lines;
	}

	/**
	 * Events and key dates of a day, ordered by time.
	 *
	 * @param array[] $events     Intentions whose events are listed.
	 * @param string  $date       Y-m-d.
	 * @param array[] $intentions All listable intentions, for key dates.
	 * @return string[]
	 */
	private function day_lines( array $events, string $date, array $intentions ): array {
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
	 * Intentions activated, news updates and, optionally, internal comments
	 * of the last 24 hours.
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

		if ( DFXPRAY_Settings::get_instance()->get( 'digest_include_comments', $group_id ) ) {
			foreach ( DFXPRAY_Intentions::get_instance()->query( $group_id, array( 'status' => array( 'pending', 'active', 'review' ) ) ) as $intention ) {
				foreach ( DFXPRAY_Comments::get_instance()->for_intention( $intention['id'] ) as $comment ) {
					if ( $comment['created_at'] >= $since ) {
						$lines[] = $formatter->person_with( $intention, $comment['body'] );
					}
				}
			}
		}

		return $lines;
	}

	/**
	 * What waits in the panel: pending moderation and reviews due, with links.
	 *
	 * @param int $group_id Group ID.
	 * @return string[]
	 */
	private function panel_lines( int $group_id ): array {
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

		return $lines;
	}
}
