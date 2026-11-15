<?php
/**
 * Global and per-group settings.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves settings in three layers: built-in defaults, the administrator's
 * global values, and each group's overrides. Leaders may override deadlines,
 * templates, channels and schedules; a handful of keys (retention, Turnstile,
 * URL base, rate limit) are global only.
 */
final class DFXPRAY_Settings {

	use DFXPRAY_Singleton;

	/**
	 * Option holding the global values.
	 */
	public const OPTION = 'dfxpray_settings';

	/**
	 * Index length (characters) above which leaders get a warning.
	 */
	public const INDEX_LENGTH_WARNING = 4000;

	/**
	 * Keys only the administrator may set.
	 */
	private const GLOBAL_ONLY = array(
		'retention_days',
		'url_base',
		'turnstile_site_key',
		'turnstile_secret',
		'rate_limit_per_hour',
		'delete_data_on_uninstall',
		'phone_country_code',
	);

	/**
	 * Keys holding a time of day.
	 */
	private const TIME_KEYS = array( 'digest_time', 'quiet_start', 'quiet_end' );

	/**
	 * Keys holding multi-line text.
	 */
	private const TEXT_KEYS = array( 'confirmation_message', 'index_header', 'index_footer', 'digest_header', 'digest_footer' );

	/**
	 * Allowed values for enumerated keys.
	 */
	private const ENUMS = array(
		'digest_when_empty' => array( 'short', 'none' ),
		'index_layout'      => array( 'by_type', 'proposals' ),
	);

	/**
	 * Minimum value for integer keys; 1 unless listed.
	 */
	private const INT_MINIMUMS = array(
		'alert_lead_minutes' => 0,
	);

	/**
	 * Resolved settings, keyed by group ID (0 for global).
	 *
	 * @var array<int, array>
	 */
	private array $cache = array();

	/**
	 * Built-in defaults.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array(
			// Global only.
			'retention_days'           => 90,
			'url_base'                 => sanitize_title( _x( 'prayer-requests', 'Base of the public form URL', 'dfx-prayer-intentions' ) ),
			'turnstile_site_key'       => '',
			'turnstile_secret'         => '',
			'rate_limit_per_hour'      => 5,
			'delete_data_on_uninstall' => true,
			'phone_country_code'       => '34',

			// Deadlines.
			'event_grace_days'         => 2,
			'deceased_days'            => 30,
			'daily_deceased_days'      => 7,
			'deceased_auto_close'      => true,
			'ongoing_review_days'      => 30,
			'thanksgiving_days'        => 7,
			'keep_active_days'         => 30,
			'recent_deceased_days'     => 3,

			// Templates.
			'confirmation_message'     => __( 'Thank you. We have received your request and will pray for it.', 'dfx-prayer-intentions' ),
			/* translators: {date} is replaced with the day and month. */
			'index_header'             => __( '🙏 *Active intentions* – {date}', 'dfx-prayer-intentions' ),
			'index_footer'             => '',
			/* translators: {long_date} is replaced with the full date. */
			'monthly_header'           => __( "*Prayer intentions of the month*\n_{long_date}_", 'dfx-prayer-intentions' ),
			'index_section_emojis'     => false,
			'index_layout'             => 'by_type',
			'member_prefix'            => '',
			/* translators: {date} is replaced with the day and month. */
			'digest_header'            => __( '🙏 *Prayer digest* – {date}', 'dfx-prayer-intentions' ),
			'digest_footer'            => '',
			'digest_include_comments'  => false,

			// Schedules.
			'digest_time'              => '07:00',
			'digest_when_empty'        => 'short',
			'alert_lead_minutes'       => 15,
			'alert_at_exact_time'      => false,
			'quiet_start'              => '23:00',
			'quiet_end'                => '07:00',

			// Channels.
			'channels'                 => array(
				'email'    => array(
					'digest'      => true,
					'alerts'      => false,
					'new_request' => true,
					'recipients'  => '',
				),
				'telegram' => array(
					'digest'      => false,
					'alerts'      => false,
					'new_request' => false,
					'token'       => '',
					'chat_id'     => '',
					'variant'     => 'shareable',
				),
				'webhook'  => array(
					'digest'      => false,
					'alerts'      => false,
					'new_request' => false,
					'url'         => '',
					'secret'      => '',
					'variant'     => 'shareable',
				),
			),
		);
	}

	/**
	 * Keys a group's leaders may override.
	 *
	 * @return string[]
	 */
	public function group_keys(): array {
		return array_values( array_diff( array_keys( $this->defaults() ), self::GLOBAL_ONLY ) );
	}

	/**
	 * Resolve one setting.
	 *
	 * @param string   $key      Setting key.
	 * @param int|null $group_id Group whose overrides apply, or null for the global value.
	 * @return mixed
	 */
	public function get( string $key, ?int $group_id = null ) {
		return $this->all( $group_id )[ $key ] ?? null;
	}

	/**
	 * Resolve every setting.
	 *
	 * @param int|null $group_id Group whose overrides apply, or null for global values.
	 * @return array
	 */
	public function all( ?int $group_id = null ): array {
		$cache_key = (int) $group_id;

		if ( ! isset( $this->cache[ $cache_key ] ) ) {
			$global = get_option( self::OPTION, array() );
			$values = $this->merge( $this->defaults(), is_array( $global ) ? $global : array() );

			if ( $group_id ) {
				$values = $this->merge( $values, DFXPRAY_Groups::get_instance()->get_settings( $group_id ) );
			}

			$this->cache[ $cache_key ] = $values;
		}

		return $this->cache[ $cache_key ];
	}

	/**
	 * Save global values (administrator).
	 *
	 * @param array $input Raw input; unknown keys are ignored.
	 */
	public function save_global( array $input ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		update_option( self::OPTION, $this->merge( $stored, $this->sanitize( $input, array_keys( $this->defaults() ) ) ) );
		$this->flush_cache();
	}

	/**
	 * Save a group's overrides (leaders).
	 *
	 * @param int   $group_id Group ID.
	 * @param array $input    Raw input; global-only and unknown keys are ignored.
	 */
	public function save_group( int $group_id, array $input ): void {
		$groups = DFXPRAY_Groups::get_instance();
		$stored = $groups->get_settings( $group_id );

		$groups->save_settings( $group_id, $this->merge( $stored, $this->sanitize( $input, $this->group_keys() ) ) );
		$this->flush_cache();
	}

	/**
	 * Drop a group's overrides so it follows the global values again.
	 *
	 * @param int      $group_id Group ID.
	 * @param string[] $keys     Keys to reset; empty for all.
	 */
	public function reset_group( int $group_id, array $keys = array() ): void {
		$groups = DFXPRAY_Groups::get_instance();
		$stored = $keys ? array_diff_key( $groups->get_settings( $group_id ), array_flip( $keys ) ) : array();

		$groups->save_settings( $group_id, $stored );
		$this->flush_cache();
	}

	/**
	 * Forget resolved values.
	 */
	public function flush_cache(): void {
		$this->cache = array();
	}

	/**
	 * Sanitise raw input to the type of each default.
	 *
	 * @param array    $input   Raw input.
	 * @param string[] $allowed Keys accepted.
	 * @return array
	 */
	public function sanitize( array $input, array $allowed ): array {
		$defaults = $this->defaults();
		$clean    = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			if ( 'channels' === $key ) {
				$clean[ $key ] = $this->sanitize_channels( (array) $input[ $key ], $defaults['channels'] );
				continue;
			}

			$clean[ $key ] = $this->sanitize_value( $key, $input[ $key ], $defaults[ $key ] );
		}

		return $clean;
	}

	/**
	 * Sanitise one scalar value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Raw value.
	 * @param mixed  $fallback Default, which gives the type.
	 * @return mixed
	 */
	private function sanitize_value( string $key, $value, $fallback ) {
		if ( is_bool( $fallback ) ) {
			return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}

		if ( is_int( $fallback ) ) {
			return max( self::INT_MINIMUMS[ $key ] ?? 1, (int) $value );
		}

		$value = is_scalar( $value ) ? (string) $value : '';

		if ( in_array( $key, self::TIME_KEYS, true ) ) {
			return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $fallback;
		}

		if ( isset( self::ENUMS[ $key ] ) ) {
			return in_array( $value, self::ENUMS[ $key ], true ) ? $value : $fallback;
		}

		if ( 'url_base' === $key ) {
			$value = sanitize_title( $value );
			return '' === $value ? $fallback : $value;
		}

		if ( in_array( $key, self::TEXT_KEYS, true ) ) {
			return sanitize_textarea_field( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Sanitise the nested channel settings.
	 *
	 * @param array $input    Raw input, keyed by channel.
	 * @param array $defaults Channel defaults.
	 * @return array
	 */
	private function sanitize_channels( array $input, array $defaults ): array {
		$clean = array();

		foreach ( $defaults as $channel => $channel_defaults ) {
			if ( ! isset( $input[ $channel ] ) || ! is_array( $input[ $channel ] ) ) {
				continue;
			}

			foreach ( $channel_defaults as $key => $default ) {
				if ( ! array_key_exists( $key, $input[ $channel ] ) ) {
					continue;
				}

				$value = $input[ $channel ][ $key ];

				if ( is_bool( $default ) ) {
					$value = (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				} elseif ( 'variant' === $key ) {
					$value = in_array( $value, array( 'shareable', 'leaders' ), true ) ? $value : 'shareable';
				} elseif ( 'url' === $key ) {
					$value = esc_url_raw( (string) $value, array( 'https', 'http' ) );
				} elseif ( 'recipients' === $key ) {
					$emails = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $value ) ) );
					$value  = implode( ', ', $emails );
				} else {
					$value = sanitize_text_field( (string) $value );
				}

				$clean[ $channel ][ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Merge two setting arrays; channels merge one level deeper.
	 *
	 * @param array $base     Base values.
	 * @param array $override Overriding values.
	 * @return array
	 */
	private function merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( 'channels' === $key && is_array( $value ) ) {
				foreach ( $value as $channel => $channel_values ) {
					$base['channels'][ $channel ] = array_merge( $base['channels'][ $channel ] ?? array(), (array) $channel_values );
				}
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}
}
