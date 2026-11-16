<?php
/**
 * Index of active intentions, ready to paste into WhatsApp.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds each group's index of active and in-review intentions. Only the
 * summary written by a leader is ever published, never the full text.
 */
final class DFXPRAY_Index_Composer {

	use DFXPRAY_Singleton;

	/**
	 * Section keys, in order, mapped to the temporal type they hold.
	 */
	private const SECTIONS = array(
		'events'       => 'event',
		'deceased'     => 'deceased',
		'ongoing'      => 'ongoing',
		'thanksgiving' => 'thanksgiving',
	);

	/**
	 * Compose the index of a group.
	 *
	 * The daily edition leaves out the intentions kept for the monthly list;
	 * the monthly one has them all, by topic.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $edition  'daily' or 'monthly'.
	 */
	public function compose( int $group_id, string $edition = 'daily' ): string {
		$settings  = DFXPRAY_Settings::get_instance();
		$formatter = DFXPRAY_Formatter::get_instance();
		$emojis    = (bool) $settings->get( 'index_section_emojis', $group_id );
		$listable  = DFXPRAY_Intentions::get_instance()->listable( $group_id );
		$monthly   = 'monthly' === $edition;

		if ( $monthly ) {
			$raw = $this->monthly_sections( $listable, $group_id );
		} else {
			$daily = array_values( array_filter( $listable, static fn( $i ) => 'monthly' !== $i['scope'] ) );
			$raw   = 'proposals' === $settings->get( 'index_layout', $group_id ) ? $this->proposal_sections( $daily, $group_id ) : $this->type_sections( $daily );
		}

		$sections = array();
		foreach ( $raw as $key => $lines ) {
			$section = array(
				'key'   => $key,
				'title' => $this->titles()[ $key ],
				'emoji' => $emojis ? $this->emojis()[ $key ] : '',
				'lines' => $lines,
			);

			/**
			 * Filters one section of the index. The dynamic part is the
			 * section key: events, deceased, ongoing or thanksgiving; with
			 * the "prayer proposals" layout, urgent, others, thanks or
			 * departed.
			 *
			 * @param array $section  Section: key, title, emoji, lines.
			 * @param int   $group_id Group ID.
			 */
			$section = apply_filters( "dfxpray_index_section_{$key}", $section, $group_id );

			if ( ! empty( $section['lines'] ) ) {
				$sections[] = $section;
			}
		}

		/**
		 * Filters every section of the index before rendering.
		 *
		 * @param array[] $sections Sections.
		 * @param int     $group_id Group ID.
		 */
		$sections = apply_filters( 'dfxpray_index_sections', $sections, $group_id );

		return DFXPRAY_Templates::get_instance()->render(
			'index.php',
			array(
				'header'        => $formatter->fill( (string) $settings->get( $monthly ? 'monthly_header' : 'index_header', $group_id ) ),
				'footer'        => $formatter->fill( (string) $settings->get( 'index_footer', $group_id ) ),
				'sections'      => $sections,
				'empty_message' => __( 'There are no active intentions.', 'dfx-prayer-intentions' ),
			)
		);
	}

	/**
	 * Lines of the layout by type: one section per kind of intention.
	 *
	 * @param array[] $intentions Listable intentions.
	 * @return array<string, string[]> Section key => lines.
	 */
	private function type_sections( array $intentions ): array {
		$formatter = DFXPRAY_Formatter::get_instance();
		$by_type   = array_fill_keys( array_values( self::SECTIONS ), array() );

		foreach ( $intentions as $intention ) {
			$by_type[ $intention['type'] ][] = $intention;
		}

		$sections = array();
		foreach ( self::SECTIONS as $key => $type ) {
			$sections[ $key ] = array_map( array( $formatter, 'line' ), $this->sort( $by_type[ $type ] ) );
		}

		return $sections;
	}

	/**
	 * Lines of the "prayer proposals" layout, the daily message: the news of
	 * the day before (also the departed) and the urgent ones (high
	 * priority), the people whose health we pray for (only their
	 * names, our brothers first), the rest, current affairs, the thanksgivings
	 * of the last day, and the departed of the last week. Other intentions sharing a summary
	 * go in one line: "Problemas de salud: Marina, …; Irene, …".
	 *
	 * @param array[] $intentions Listable intentions.
	 * @param int     $group_id   Group ID.
	 * @return array<string, string[]> Section key => lines.
	 */
	private function proposal_sections( array $intentions, int $group_id ): array {
		$formatter = DFXPRAY_Formatter::get_instance();
		$since     = (int) DFXPRAY_Settings::get_instance()->get( 'daily_news_days', $group_id ) > 0 ? $this->departed_since( 'daily_news_days', $group_id ) : '9999-12-31';
		$is_news   = static fn( $i ) => 'thanksgiving' !== $i['type'] && substr( (string) $i['created_at'], 0, 10 ) >= $since;
		$news      = $this->sort( array_values( array_filter( $intentions, $is_news ) ) );
		$news      = array_merge(
			array_filter( $news, static fn( $i ) => 'deceased' !== $i['type'] ),
			array_filter( $news, static fn( $i ) => 'deceased' === $i['type'] )
		);
		$buckets   = $this->buckets( array_values( array_filter( $intentions, static fn( $i ) => ! $is_news( $i ) ) ), $this->departed_since( 'daily_deceased_days', $group_id ) );
		$thanks    = $this->departed_since( 'daily_thanks_days', $group_id );
		$thanked   = array_values( array_filter( $buckets['thanks'], static fn( $i ) => ( $i['reference_date'] ?? substr( (string) $i['created_at'], 0, 10 ) ) >= $thanks ) );

		return array(
			'urgent'   => array_merge( $this->grouped_lines( $news ), $this->birthday_lines( $group_id ), $this->grouped_lines( $buckets['urgent'] ) ),
			'health'   => array_map( array( $formatter, 'person' ), $buckets['health'] ),
			'others'   => $this->grouped_lines( array_merge( $buckets['general'], $buckets['studies'], $buckets['economic'] ) ),
			'current'  => $this->grouped_lines( $buckets['current'] ),
			'thanks'   => $this->grouped_lines( $thanked ),
			'departed' => array_map( static fn( $intention ) => $formatter->line( $intention, false ), $buckets['departed'] ),
		);
	}

	/**
	 * Lines of the monthly list, read at the monthly adoration: every
	 * intention, by topic, with the thanks and the departed of the month
	 * (thanks already closed included) and a standing line for those who
	 * study or look for work.
	 *
	 * @param array[] $intentions Listable intentions.
	 * @param int     $group_id   Group ID.
	 * @return array<string, string[]> Section key => lines.
	 */
	private function monthly_sections( array $intentions, int $group_id ): array {
		$formatter = DFXPRAY_Formatter::get_instance();
		$since     = $this->departed_since( 'monthly_thanks_days', $group_id );
		$closed    = DFXPRAY_Intentions::get_instance()->query(
			$group_id,
			array(
				'status' => 'closed',
				'type'   => 'thanksgiving',
			)
		);
		$closed    = array_filter( $closed, static fn( $i ) => ( $i['reference_date'] ?? substr( (string) $i['created_at'], 0, 10 ) ) >= $since );
		$buckets   = $this->buckets( array_merge( $intentions, $closed ), $this->departed_since( 'deceased_days', $group_id ) );
		$lines     = static fn( array $items ): array => array_map( static fn( $intention ) => $formatter->line( $intention, false ), $items );

		return array(
			'urgent'   => $lines( $buckets['urgent'] ),
			'sick'     => $lines( $buckets['health'] ),
			'studies'  => array_merge( $lines( $buckets['studies'] ), array( __( 'For those who study, sit exams or look for work', 'dfx-prayer-intentions' ) ) ),
			'economic' => $lines( $buckets['economic'] ),
			'general'  => $lines( $buckets['general'] ),
			'current'  => $lines( $buckets['current'] ),
			'thanks'   => $lines( $buckets['thanks'] ),
			'departed' => $lines( $buckets['departed'] ),
		);
	}

	/**
	 * The members who celebrate their birthday today, all in one line, and
	 * those who died on this day of an earlier year, unless the group
	 * turned it off: "N.H. Ramón García and N.H. Teo: on their birthday",
	 * "N.H. Juan: on the anniversary of his death († 2024)". Namesakes
	 * carry their surname.
	 *
	 * @param int $group_id Group ID.
	 * @return string[]
	 */
	private function birthday_lines( int $group_id ): array {
		$settings = DFXPRAY_Settings::get_instance();
		if ( ! $settings->get( 'daily_birthdays', $group_id ) ) {
			return array();
		}

		$prefix  = trim( (string) $settings->get( 'member_prefix', $group_id ) );
		$members = DFXPRAY_Members::get_instance();
		$today   = DFXPRAY_Clock::get_instance()->today();
		$short   = $members->short_names( $group_id );
		$name    = static fn( array $member ): string => trim( $prefix . ' ' . $short[ $member['id'] ] );
		$lines   = array();

		$birthdays = array_map( $name, $members->birthdays_on( $group_id, $today ) );
		if ( 1 === count( $birthdays ) ) {
			/* translators: %s: the member, as he is called ("N.H. Ramón"). */
			$lines[] = sprintf( __( '%s: on his birthday', 'dfx-prayer-intentions' ), $birthdays[0] );
		} elseif ( $birthdays ) {
			$last = array_pop( $birthdays );
			/* translators: 1: members, separated by commas ("N.H. Ramón, N.H. Teo"), 2: the last member ("N.H. Luis"). */
			$lines[] = sprintf( __( '%1$s and %2$s: on their birthday', 'dfx-prayer-intentions' ), implode( ', ', $birthdays ), $last );
		}

		foreach ( $members->death_anniversaries_on( $group_id, $today ) as $member ) {
			/* translators: 1: the member, as he is called ("N.H. Juan"), 2: year of his death. */
			$lines[] = sprintf( __( '%1$s: on the anniversary of his death († %2$s)', 'dfx-prayer-intentions' ), $name( $member ), substr( $member['death_date'], 0, 4 ) );
		}

		return $lines;
	}

	/**
	 * First day whose departed (or thanksgivings) still appear, after a
	 * deadline setting.
	 *
	 * @param string $setting  A setting with a number of days (deceased_days, daily_thanks_days…).
	 * @param int    $group_id Group ID.
	 */
	private function departed_since( string $setting, int $group_id ): string {
		$clock = DFXPRAY_Clock::get_instance();

		return $clock->local( $clock->today() )->modify( '-' . DFXPRAY_Settings::get_instance()->get( $setting, $group_id ) . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Sort intentions into the buckets of the messages: urgent ones, then
	 * by topic, thanksgivings, and the departed since a day.
	 *
	 * @param array[] $intentions Intentions.
	 * @param string  $since      Y-m-d: older departed are left out.
	 * @return array<string, array[]>
	 */
	private function buckets( array $intentions, string $since ): array {
		$buckets = array_fill_keys( array( 'urgent', 'health', 'studies', 'economic', 'general', 'current', 'thanks', 'departed' ), array() );

		foreach ( $this->sort( $intentions ) as $intention ) {
			if ( 'deceased' === $intention['type'] ) {
				if ( (string) $intention['reference_date'] >= $since ) {
					$buckets['departed'][] = $intention;
				}
			} elseif ( 'thanksgiving' === $intention['type'] ) {
				$buckets['thanks'][] = $intention;
			} elseif ( 'high' === $intention['priority'] ) {
				$buckets['urgent'][] = $intention;
			} else {
				$buckets[ '' === $intention['topic'] ? 'general' : $intention['topic'] ][] = $intention;
			}
		}

		// Our brothers first, then everyone else, each in their order.
		$buckets['health'] = array_merge(
			array_values( array_filter( $buckets['health'], static fn( $i ) => $i['beneficiary_is_member'] ) ),
			array_values( array_filter( $buckets['health'], static fn( $i ) => ! $i['beneficiary_is_member'] ) )
		);

		return $buckets;
	}

	/**
	 * Lines without priority mark, joining intentions that share a summary
	 * (and have no date) into one line, where the first of them was.
	 *
	 * @param array[] $intentions Sorted intentions.
	 * @return string[]
	 */
	private function grouped_lines( array $intentions ): array {
		$formatter = DFXPRAY_Formatter::get_instance();
		$groups    = array();

		foreach ( $intentions as $intention ) {
			$key              = '' === $formatter->date_suffix( $intention ) ? mb_strtolower( trim( $intention['summary'] ) ) : 'id:' . $intention['id'];
			$groups[ $key ][] = $intention;
		}

		$lines = array();
		foreach ( $groups as $group ) {
			if ( 1 === count( $group ) ) {
				$lines[] = $formatter->line( $group[0], false );
				continue;
			}

			$summary = trim( $group[0]['summary'] );
			$lines[] = mb_strtoupper( mb_substr( $summary, 0, 1 ) ) . mb_substr( $summary, 1 ) . ': ' . implode( '; ', array_map( array( $formatter, 'person' ), $group ) );
		}

		return $lines;
	}

	/**
	 * Whether the text is too long to paste comfortably.
	 *
	 * @param string $text Text.
	 */
	public function is_too_long( string $text ): bool {
		return mb_strlen( $text ) > DFXPRAY_Settings::INDEX_LENGTH_WARNING;
	}

	/**
	 * Section titles.
	 *
	 * @return array<string, string>
	 */
	public function titles(): array {
		return array(
			'events'       => __( 'Upcoming events', 'dfx-prayer-intentions' ),
			'deceased'     => __( 'For our departed', 'dfx-prayer-intentions' ),
			'ongoing'      => __( 'The sick and difficult situations', 'dfx-prayer-intentions' ),
			'thanksgiving' => __( 'Thanksgiving', 'dfx-prayer-intentions' ),
			'urgent'       => __( 'Especially', 'dfx-prayer-intentions' ),
			'others'       => __( 'Also for', 'dfx-prayer-intentions' ),
			'thanks'       => __( 'We give thanks', 'dfx-prayer-intentions' ),
			'departed'     => __( 'For our departed', 'dfx-prayer-intentions' ),
			'health'       => __( 'For the health of', 'dfx-prayer-intentions' ),
			'current'      => _x( 'We also pray for', 'heading of the current affairs section', 'dfx-prayer-intentions' ),
			'sick'         => __( 'For the sick', 'dfx-prayer-intentions' ),
			'studies'      => __( 'Studies, exams and job interviews', 'dfx-prayer-intentions' ),
			'economic'     => __( 'Economic or legal problems', 'dfx-prayer-intentions' ),
			'general'      => __( 'Other intentions', 'dfx-prayer-intentions' ),
		);
	}

	/**
	 * Section emojis.
	 *
	 * @return array<string, string>
	 */
	private function emojis(): array {
		return array(
			'events'       => '📅',
			'deceased'     => '🕯️',
			'ongoing'      => '🤲',
			'thanksgiving' => '🙌',
			'urgent'       => '❗',
			'others'       => '🤲',
			'thanks'       => '🙌',
			'departed'     => '🕯️',
			'health'       => '🤲',
			'current'      => '🌍',
			'sick'         => '🤲',
			'studies'      => '📚',
			'economic'     => '⚖️',
			'general'      => '🙏',
		);
	}

	/**
	 * High priority first, then by reference date (activation date when none).
	 *
	 * @param array[] $intentions Intentions.
	 * @return array[]
	 */
	public function sort( array $intentions ): array {
		usort(
			$intentions,
			static function ( $a, $b ) {
				$priority = ( 'high' === $b['priority'] ) <=> ( 'high' === $a['priority'] );
				if ( 0 !== $priority ) {
					return $priority;
				}

				$date_a = ( $a['reference_date'] ?? substr( (string) $a['activated_at'], 0, 10 ) ) . ' ' . ( $a['reference_time'] ?? '' );
				$date_b = ( $b['reference_date'] ?? substr( (string) $b['activated_at'], 0, 10 ) ) . ' ' . ( $b['reference_time'] ?? '' );

				return strcmp( $date_a, $date_b ) ?: $a['id'] <=> $b['id'];
			}
		);

		return $intentions;
	}
}
