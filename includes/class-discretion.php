<?php
/**
 * Discretion levels and visible names.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides how a person's name may appear outside the leaders' panel.
 *
 * The level is chosen by the requester and applies to both requester and
 * beneficiary. Leaders may make it stricter, never more open.
 */
final class DFXPRAY_Discretion {

	use DFXPRAY_Singleton;

	/**
	 * Words skipped when building initials.
	 */
	private const PARTICLES = array( 'de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'i', 'da', 'das', 'do', 'dos', 'di', 'van', 'von', 'der', 'den', 'du', 'le', 'of', 'the' );

	/**
	 * Levels, from the most open to the most discreet.
	 *
	 * @return array<string, string> Level => label.
	 */
	public function levels(): array {
		return array(
			'full_name'  => __( 'Full name', 'dfx-prayer-intentions' ),
			'first_name' => __( 'First name', 'dfx-prayer-intentions' ),
			'initials'   => __( 'Initials', 'dfx-prayer-intentions' ),
			'anonymous'  => __( 'Anonymous', 'dfx-prayer-intentions' ),
		);
	}

	/**
	 * Whether a level exists.
	 *
	 * @param string $level Level.
	 */
	public function is_valid( string $level ): bool {
		return array_key_exists( $level, $this->levels() );
	}

	/**
	 * Whether the level lets a (possibly edited) name appear.
	 *
	 * @param string $level Level.
	 */
	public function shows_name( string $level ): bool {
		return in_array( $level, array( 'full_name', 'first_name' ), true );
	}

	/**
	 * Apply a requested level, refusing anything more open than the current one.
	 *
	 * @param string $current   Current level.
	 * @param string $requested Requested level.
	 * @return string|WP_Error The new level.
	 */
	public function harden( string $current, string $requested ) {
		if ( ! $this->is_valid( $requested ) ) {
			return new WP_Error( 'dfxpray_discretion_invalid', __( 'Unknown discretion level.', 'dfx-prayer-intentions' ) );
		}

		$order = array_keys( $this->levels() );

		if ( array_search( $requested, $order, true ) < array_search( $current, $order, true ) ) {
			return new WP_Error( 'dfxpray_discretion_relaxed', __( 'The discretion level can be made stricter, never more open.', 'dfx-prayer-intentions' ) );
		}

		return $requested;
	}

	/**
	 * How a name appears at a level.
	 *
	 * @param string $name         Original name.
	 * @param string $level        Level.
	 * @param string $visible_name Leader-edited visible name; used only for name levels.
	 * @param string $first_name   First name set by a leader when it is compound
	 *                             ("Juan Tomás"); empty means the first word.
	 * @return string|null Null when nothing about the person may appear.
	 */
	public function format_name( string $name, string $level, string $visible_name = '', string $first_name = '' ): ?string {
		$visible_name = trim( $visible_name );
		$tokens       = $this->tokens( $name );

		switch ( $level ) {
			case 'full_name':
				return '' !== $visible_name ? $visible_name : implode( ' ', $tokens );

			case 'first_name':
				return '' !== $visible_name ? $visible_name : implode( ' ', array_slice( $tokens, 0, $this->first_name_length( $name, $first_name ) ) );

			case 'initials':
				$initials = array();
				foreach ( $tokens as $token ) {
					if ( in_array( mb_strtolower( $token ), self::PARTICLES, true ) ) {
						continue;
					}
					$initials[] = mb_strtoupper( mb_substr( $token, 0, 1 ) ) . '.';
				}
				return implode( ' ', $initials );

			default:
				return null;
		}
	}

	/**
	 * Check that a visible name reveals no more than the level allows.
	 *
	 * With "first name", no word of the original name after its first name
	 * (the first word, or the compound one a leader set) may appear in the
	 * visible name.
	 *
	 * @param string $name         Original name.
	 * @param string $level        Level.
	 * @param string $visible_name Proposed visible name.
	 * @param string $first_name   First name set by a leader, if any.
	 * @return true|WP_Error
	 */
	public function validate_visible_name( string $name, string $level, string $visible_name, string $first_name = '' ) {
		if ( '' === trim( $visible_name ) || 'first_name' !== $level ) {
			return true;
		}

		$tokens   = $this->tokens( $name );
		$surnames = $this->normalized_words( implode( ' ', array_slice( $tokens, $this->first_name_length( $name, $first_name ) ) ) );
		$surnames = array_diff( $surnames, self::PARTICLES );
		$found    = array_intersect( $this->normalized_words( $visible_name ), $surnames );

		if ( $found ) {
			return new WP_Error( 'dfxpray_visible_name_reveals', __( 'With “first name” discretion, the visible name cannot include surnames.', 'dfx-prayer-intentions' ) );
		}

		return true;
	}

	/**
	 * Check a first name set by a leader: it must be the first words of the
	 * name. Nothing is guessed; the leader decides how many words it has.
	 *
	 * @param string $name       Original name.
	 * @param string $first_name Proposed first name; empty means the first word.
	 * @return true|WP_Error
	 */
	public function validate_first_name( string $name, string $first_name ) {
		if ( '' === trim( $first_name ) || $this->prefix_length( $name, $first_name ) > 0 ) {
			return true;
		}

		return new WP_Error( 'dfxpray_first_name_invalid', __( 'The first name must be the first words of the name.', 'dfx-prayer-intentions' ) );
	}

	/**
	 * The people part of an index line: "Carmen, madre de Luis".
	 *
	 * @param array $intention Intention fields.
	 * @return string|null Null when anonymous.
	 */
	public function describe( array $intention ): ?string {
		$level     = $intention['discretion'];
		$requester = $this->format_name( $intention['requester_name'], $level, $intention['requester_display_name'] ?? '', $intention['requester_first_name'] ?? '' );

		if ( null === $requester ) {
			return null;
		}

		if ( ! empty( $intention['beneficiary_is_requester'] ) ) {
			return $requester;
		}

		$beneficiary  = (string) $this->format_name( $intention['beneficiary_name'], $level, $intention['beneficiary_display_name'] ?? '', $intention['beneficiary_first_name'] ?? '' );
		$relationship = trim( (string) ( $intention['relationship'] ?? '' ) );

		if ( '' === $relationship ) {
			return $beneficiary;
		}

		return $beneficiary . ', ' . $relationship . ' ' . $requester;
	}

	/**
	 * How many words of the name make up its first name: those of the first
	 * name a leader set, or just the first one.
	 *
	 * @param string $name       Original name.
	 * @param string $first_name First name set by a leader, if any.
	 */
	private function first_name_length( string $name, string $first_name ): int {
		return max( $this->prefix_length( $name, $first_name ), min( 1, count( $this->tokens( $name ) ) ) );
	}

	/**
	 * How many leading words of the name spell the given first name, ignoring
	 * case and accents; 0 when they do not.
	 *
	 * @param string $name       Original name.
	 * @param string $first_name First name.
	 */
	private function prefix_length( string $name, string $first_name ): int {
		$wanted = $this->normalized_words( $first_name );
		$tokens = $this->tokens( $name );

		if ( ! $wanted ) {
			return 0;
		}

		for ( $length = 1, $count = count( $tokens ); $length <= $count; $length++ ) {
			if ( $this->normalized_words( implode( ' ', array_slice( $tokens, 0, $length ) ) ) === $wanted ) {
				return $length;
			}
		}

		return 0;
	}

	/**
	 * Words of a name, whitespace collapsed.
	 *
	 * @param string $name Name.
	 * @return string[]
	 */
	private function tokens( string $name ): array {
		return array_values( array_filter( preg_split( '/\s+/u', trim( $name ) ) ) );
	}

	/**
	 * Lower-case, accent-free words, for comparisons.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function normalized_words( string $text ): array {
		$text = mb_strtolower( remove_accents( $text ) );

		return array_values( array_filter( preg_split( '/[^\p{L}\p{N}]+/u', $text ) ) );
	}
}
