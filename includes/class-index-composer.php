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
	 * @param int $group_id Group ID.
	 */
	public function compose( int $group_id ): string {
		$settings  = DFXPRAY_Settings::get_instance();
		$formatter = DFXPRAY_Formatter::get_instance();
		$emojis    = (bool) $settings->get( 'index_section_emojis', $group_id );
		$by_type   = array_fill_keys( array_values( self::SECTIONS ), array() );

		foreach ( DFXPRAY_Intentions::get_instance()->listable( $group_id ) as $intention ) {
			$by_type[ $intention['type'] ][] = $intention;
		}

		$sections = array();
		foreach ( self::SECTIONS as $key => $type ) {
			$intentions = $this->sort( $by_type[ $type ] );
			$section    = array(
				'key'   => $key,
				'title' => $this->titles()[ $key ],
				'emoji' => $emojis ? $this->emojis()[ $key ] : '',
				'lines' => array_map( array( $formatter, 'line' ), $intentions ),
			);

			/**
			 * Filters one section of the index. The dynamic part is the
			 * section key: events, deceased, ongoing or thanksgiving.
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
				'header'        => $formatter->fill( (string) $settings->get( 'index_header', $group_id ) ),
				'footer'        => $formatter->fill( (string) $settings->get( 'index_footer', $group_id ) ),
				'sections'      => $sections,
				'empty_message' => __( 'There are no active intentions.', 'dfx-prayer-intentions' ),
			)
		);
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
