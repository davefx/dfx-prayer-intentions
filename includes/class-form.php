<?php
/**
 * Public request form.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the form (shortcode, block and the group's own page share this
 * renderer) and handles submissions sent to admin-post.php. Every request
 * enters as pending in its group.
 */
final class DFXPRAY_Form {

	use DFXPRAY_Singleton;

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'dfxpray_submit';

	/**
	 * Minutes a draft is kept to refill the form after an error.
	 */
	private const DRAFT_MINUTES = 15;

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_shortcode( 'dfxpray_form', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'process_request' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'process_request' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register the Gutenberg block.
	 */
	public function register_block(): void {
		register_block_type(
			DFXPRAY_PLUGIN_DIR . 'blocks/form',
			array( 'render_callback' => array( $this, 'render_block' ) )
		);

		if ( is_admin() ) {
			$groups = array_map(
				static fn( $g ) => array(
					'value' => $g['id'],
					'label' => $g['name'],
				),
				DFXPRAY_Groups::get_instance()->all( 'active' )
			);
			wp_add_inline_script( 'dfxpray-form-editor-script', 'window.dfxprayGroups = ' . wp_json_encode( $groups ) . ';', 'before' );
		}
	}

	/**
	 * Register the front-end assets.
	 */
	public function register_assets(): void {
		wp_register_style( 'dfxpray-form', DFXPRAY_PLUGIN_URL . 'assets/css/form.css', array(), DFXPRAY_VERSION );
		wp_register_script( 'dfxpray-form', DFXPRAY_PLUGIN_URL . 'assets/js/form.js', array(), DFXPRAY_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * Shortcode [dfxpray_form group="ID"]. With a single group, no attribute is needed.
	 *
	 * @param array|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'group' => 0 ), (array) $atts, 'dfxpray_form' );

		return $this->render( $this->resolve_group( (int) $atts['group'] ) );
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 */
	public function render_block( $attributes ): string {
		return $this->render( $this->resolve_group( (int) ( $attributes['groupId'] ?? 0 ) ) );
	}

	/**
	 * Render the form of a group, or the confirmation after sending.
	 *
	 * @param int $group_id Group ID.
	 */
	public function render( int $group_id ): string {
		$group = DFXPRAY_Groups::get_instance()->get( $group_id );

		if ( ! $group || 'active' !== $group['status'] ) {
			return '<div class="dfxpray-form dfxpray-form--unavailable"><p>' . esc_html__( 'This form is not available.', 'dfx-prayer-intentions' ) . '</p></div>';
		}

		$this->enqueue_assets();
		DFXPRAY_Public_Page::get_instance()->prevent_caching();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only flags set by our own redirect.
		if ( isset( $_GET['dfxpray_sent'] ) && (int) $_GET['dfxpray_sent'] === $group_id ) {
			$message = (string) DFXPRAY_Settings::get_instance()->get( 'confirmation_message', $group_id );
			return '<div class="dfxpray-form dfxpray-form--sent" id="dfxpray-form" role="status"><p>' . nl2br( esc_html( $message ) ) . '</p></div>';
		}

		$draft = array();
		$error = '';
		if ( isset( $_GET['dfxpray_error'] ) ) {
			$draft = isset( $_GET['dfxpray_draft'] ) ? $this->get_draft( sanitize_key( wp_unslash( $_GET['dfxpray_draft'] ) ) ) : array();
			$error = $draft['_error'] ?? $this->error_message( sanitize_key( wp_unslash( $_GET['dfxpray_error'] ) ) );
		}
		// phpcs:enable

		$site_key = (string) DFXPRAY_Settings::get_instance()->get( 'turnstile_site_key' );
		if ( '' !== $site_key ) {
			// Turnstile only works loaded from Cloudflare; documented under "External services" in readme.txt.
			wp_enqueue_script( 'dfxpray-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion, PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
		}

		return DFXPRAY_Templates::get_instance()->render(
			'form.php',
			array(
				'group'       => $group,
				'draft'       => $draft,
				'error'       => $error,
				'action_url'  => admin_url( 'admin-post.php' ),
				'return_url'  => $this->current_url(),
				'nonce'       => wp_create_nonce( 'dfxpray_submit_' . $group_id ),
				'types'       => $this->type_choices(),
				'levels'      => DFXPRAY_Discretion::get_instance()->levels(),
				'privacy_url' => get_privacy_policy_url(),
				'site_key'    => $site_key,
			)
		);
	}

	/**
	 * Handler for admin-post: process and redirect back.
	 */
	public function process_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle().
		$post   = wp_unslash( $_POST );
		$result = $this->handle( is_array( $post ) ? $post : array(), $this->client_ip() );
		$return = $this->safe_return_url( (string) ( $post['dfxpray_return'] ?? '' ) );

		if ( $result['ok'] ) {
			$url = add_query_arg( 'dfxpray_sent', (int) ( $post['dfxpray_group'] ?? 0 ), $return );
		} else {
			$fields           = (array) ( $post['dfxpray'] ?? array() );
			$fields['_error'] = $result['message'];
			$url              = add_query_arg(
				array(
					'dfxpray_error' => $result['code'],
					'dfxpray_draft' => $this->store_draft( $fields ),
				),
				$return
			);
		}

		wp_safe_redirect( $url . '#dfxpray-form' );
		exit;
	}

	/**
	 * Validate and store a submission.
	 *
	 * @param array  $post Raw, unslashed POST data.
	 * @param string $ip   Client IP address.
	 * @return array{ok:bool, code:string, message:string, id:?int}
	 */
	public function handle( array $post, string $ip ): array {
		$group_id = (int) ( $post['dfxpray_group'] ?? 0 );
		$group    = DFXPRAY_Groups::get_instance()->get( $group_id );

		if ( ! $group || 'active' !== $group['status'] ) {
			return $this->failure( 'unavailable' );
		}

		if ( ! wp_verify_nonce( (string) ( $post['dfxpray_nonce'] ?? '' ), 'dfxpray_submit_' . $group_id ) ) {
			return $this->failure( 'expired' );
		}

		// Bots fill every field. They are told it went well and nothing is stored.
		if ( '' !== trim( (string) ( $post['dfxpray_website'] ?? '' ) ) ) {
			return array(
				'ok'      => true,
				'code'    => 'ok',
				'message' => '',
				'id'      => null,
			);
		}

		if ( ! $this->within_rate_limit( $ip ) ) {
			return $this->failure( 'rate_limited' );
		}

		if ( ! $this->verify_turnstile( (string) ( $post['cf-turnstile-response'] ?? '' ), $ip ) ) {
			return $this->failure( 'captcha' );
		}

		if ( empty( $post['dfxpray_consent'] ) ) {
			return $this->failure( 'consent' );
		}

		$id = DFXPRAY_Intentions::get_instance()->create( $group_id, (array) ( $post['dfxpray'] ?? array() ), 'form' );

		if ( is_wp_error( $id ) ) {
			return array(
				'ok'      => false,
				'code'    => $id->get_error_code(),
				'message' => $id->get_error_message(),
				'id'      => null,
			);
		}

		return array(
			'ok'      => true,
			'code'    => 'ok',
			'message' => '',
			'id'      => $id,
		);
	}

	/**
	 * Keep the typed fields for a few minutes to refill the form after an error.
	 *
	 * @param array $fields Raw fields.
	 * @return string Draft key.
	 */
	public function store_draft( array $fields ): string {
		$key   = strtolower( wp_generate_password( 20, false ) );
		$clean = array();

		foreach ( $fields as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$clean[ sanitize_key( $name ) ] = 'body' === $name ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
			}
		}

		set_transient( 'dfxpray_draft_' . $key, $clean, self::DRAFT_MINUTES * MINUTE_IN_SECONDS );

		return $key;
	}

	/**
	 * Read a draft, once.
	 *
	 * @param string $key Draft key.
	 * @return array
	 */
	private function get_draft( string $key ): array {
		$draft = get_transient( 'dfxpray_draft_' . $key );
		delete_transient( 'dfxpray_draft_' . $key );

		return is_array( $draft ) ? $draft : array();
	}

	/**
	 * A redirect target on this site; the home page otherwise.
	 *
	 * @param string $url Requested URL.
	 */
	public function safe_return_url( string $url ): string {
		$url = remove_query_arg( array( 'dfxpray_sent', 'dfxpray_error', 'dfxpray_draft' ), $url );

		return wp_validate_redirect( $url, home_url( '/' ) );
	}

	/**
	 * Group to use when none is given: the only one, if there is only one.
	 *
	 * @param int $group_id Requested group.
	 */
	private function resolve_group( int $group_id ): int {
		if ( $group_id ) {
			return $group_id;
		}

		$active = DFXPRAY_Groups::get_instance()->all( 'active' );

		return 1 === count( $active ) ? $active[0]['id'] : 0;
	}

	/**
	 * Types in plain language, with the date question each one needs.
	 *
	 * @return array<string, array{label:string, hint:string, date_label:string, needs_date:bool, has_time:bool}>
	 */
	private function type_choices(): array {
		$lifecycle = DFXPRAY_Lifecycle::get_instance();

		$choices = array(
			'event'        => array(
				'label'      => __( 'a moment in particular', 'dfx-prayer-intentions' ),
				'hint'       => __( 'an operation, an interview, an exam…', 'dfx-prayer-intentions' ),
				'date_label' => __( 'When is it?', 'dfx-prayer-intentions' ),
			),
			'deceased'     => array(
				'label'      => __( 'a death', 'dfx-prayer-intentions' ),
				'hint'       => __( 'for the eternal rest of someone who has died', 'dfx-prayer-intentions' ),
				'date_label' => __( 'When did they pass away?', 'dfx-prayer-intentions' ),
			),
			'ongoing'      => array(
				'label'      => __( 'a situation that goes on', 'dfx-prayer-intentions' ),
				'hint'       => __( 'an illness, family, work, money…', 'dfx-prayer-intentions' ),
				'date_label' => '',
			),
			'thanksgiving' => array(
				'label'      => __( 'giving thanks', 'dfx-prayer-intentions' ),
				'hint'       => __( 'for a grace received', 'dfx-prayer-intentions' ),
				'date_label' => '',
			),
		);

		foreach ( $choices as $type => $choice ) {
			$choices[ $type ]['needs_date'] = $lifecycle->requires_reference_date( $type );
			$choices[ $type ]['has_time']   = $lifecycle->accepts_time( $type );
		}

		return $choices;
	}

	/**
	 * Count this submission against the IP's hourly limit.
	 *
	 * @param string $ip Client IP.
	 */
	private function within_rate_limit( string $ip ): bool {
		$limit = (int) DFXPRAY_Settings::get_instance()->get( 'rate_limit_per_hour' );
		$key   = 'dfxpray_rl_' . md5( wp_salt( 'nonce' ) . $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Verify Cloudflare Turnstile, when configured.
	 *
	 * @param string $token Token sent by the widget.
	 * @param string $ip    Client IP.
	 */
	private function verify_turnstile( string $token, string $ip ): bool {
		$settings = DFXPRAY_Settings::get_instance();
		$secret   = (string) $settings->get( 'turnstile_secret' );

		if ( '' === $secret || '' === (string) $settings->get( 'turnstile_site_key' ) ) {
			return true;
		}

		if ( '' === $token ) {
			return false;
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A server-side API call, not offloaded content.
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return ! empty( $body['success'] );
	}

	/**
	 * A failure result.
	 *
	 * @param string $code Code.
	 * @return array
	 */
	private function failure( string $code ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $this->error_message( $code ),
			'id'      => null,
		);
	}

	/**
	 * Message for a form-level error.
	 *
	 * @param string $code Code.
	 */
	private function error_message( string $code ): string {
		$messages = array(
			'unavailable'  => __( 'This form is not available.', 'dfx-prayer-intentions' ),
			'expired'      => __( 'The form expired. Please send it again.', 'dfx-prayer-intentions' ),
			'rate_limited' => __( 'Too many requests from your connection. Please try again later.', 'dfx-prayer-intentions' ),
			'captcha'      => __( 'We could not verify that you are a person. Please try again.', 'dfx-prayer-intentions' ),
			'consent'      => __( 'Please accept the privacy policy to send your request.', 'dfx-prayer-intentions' ),
		);

		return $messages[ $code ] ?? __( 'Please check the form and try again.', 'dfx-prayer-intentions' );
	}

	/**
	 * Client IP. Proxies can be handled with the dfxpray_client_ip filter.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used for rate limiting and Turnstile.
		 *
		 * @param string $ip IP address.
		 */
		return (string) apply_filters( 'dfxpray_client_ip', $ip );
	}

	/**
	 * URL of the current request.
	 */
	private function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return home_url( $uri );
	}

	/**
	 * Enqueue CSS and JS.
	 */
	private function enqueue_assets(): void {
		if ( ! wp_style_is( 'dfxpray-form', 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( 'dfxpray-form' );
		wp_enqueue_script( 'dfxpray-form' );
	}
}
