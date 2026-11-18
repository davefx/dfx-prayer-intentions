<?php
/**
 * Leaders' panel.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu and screens. The menu holds "Groups", "Global settings" (for
 * administrators) and one entry per group the user may manage; each group's
 * page carries its tasks as tabs. The group always comes from the URL, and
 * every query goes through DFXPRAY_Permissions.
 */
final class DFXPRAY_Admin {

	use DFXPRAY_Singleton;

	/**
	 * Slug prefix of a group's page: dfxpray-group-<id>.
	 */
	private const GROUP_PAGE = 'dfxpray-group-';

	/**
	 * User meta marking that a leader acknowledged their duty of confidentiality.
	 */
	public const CONFIDENTIALITY_META = 'dfxpray_confidentiality_ack';

	/**
	 * Tabs of a group's page, mapped to their views, in display order.
	 */
	private const TABS = array(
		'moderation' => 'moderation',
		'intentions' => 'intentions',
		'reviews'    => 'reviews',
		'digest'     => 'digest',
		'monthly'    => 'monthly',
		'members'    => 'members',
		'settings'   => 'settings',
		'log'        => 'log',
	);

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
		add_action( 'load-index.php', array( $this, 'maybe_redirect_dashboard' ) );
		add_action( 'admin_menu', array( $this, 'hide_dashboard' ), 999 );
		add_action( 'wp_roles_init', array( $this, 'translate_role_name' ) );
		add_action( 'init', array( $this, 'translate_role_name' ), 20 );
		DFXPRAY_Admin_Actions::get_instance()->register_hooks();
	}

	/**
	 * Menu: Groups, Global settings, then one entry per group.
	 */
	public function register_menu(): void {
		$cap         = DFXPRAY_Installer::CAPABILITY;
		$callback    = array( $this, 'render_current' );
		$permissions = DFXPRAY_Permissions::get_instance();
		$groups      = DFXPRAY_Groups::get_instance();
		$intentions  = DFXPRAY_Intentions::get_instance();
		$group_ids   = $permissions->group_ids_for_user();
		$pending     = array();

		foreach ( $group_ids as $group_id ) {
			$pending[ $group_id ] = $intentions->counts( $group_id )['pending'];
		}

		add_menu_page( __( 'Prayer intentions', 'dfx-prayer-intentions' ), __( 'Prayer intentions', 'dfx-prayer-intentions' ) . $this->bubble( array_sum( $pending ) ), $cap, 'dfxpray', $callback, 'dashicons-heart', 26 );
		add_submenu_page( 'dfxpray', __( 'Groups', 'dfx-prayer-intentions' ), __( 'Groups', 'dfx-prayer-intentions' ), $cap, 'dfxpray', $callback );

		if ( $permissions->is_admin() ) {
			add_submenu_page( 'dfxpray', __( 'Global settings', 'dfx-prayer-intentions' ), __( 'Global settings', 'dfx-prayer-intentions' ), 'manage_options', 'dfxpray-global', $callback );
		}

		foreach ( $group_ids as $group_id ) {
			$name = $groups->get( $group_id )['name'];
			add_submenu_page( 'dfxpray', $name, esc_html( $name ) . $this->bubble( $pending[ $group_id ] ), $cap, self::GROUP_PAGE . $group_id, $callback );
		}
	}

	/**
	 * Tab titles of a group's page.
	 *
	 * @return array<string, string>
	 */
	public function tab_titles(): array {
		return array(
			'moderation' => __( 'Moderation', 'dfx-prayer-intentions' ),
			'intentions' => __( 'Intentions', 'dfx-prayer-intentions' ),
			'reviews'    => __( 'Reviews', 'dfx-prayer-intentions' ),
			'digest'     => __( 'Daily digest', 'dfx-prayer-intentions' ),
			'monthly'    => __( 'Monthly digest', 'dfx-prayer-intentions' ),
			'members'    => __( 'Members', 'dfx-prayer-intentions' ),
			'settings'   => __( 'Group settings', 'dfx-prayer-intentions' ),
			'log'        => __( 'Activity', 'dfx-prayer-intentions' ),
		);
	}

	/**
	 * Menu callback.
	 */
	public function render_current(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$this->render( sanitize_key( wp_unslash( $_GET['page'] ?? 'dfxpray' ) ) );
	}

	/**
	 * Render a screen and the DFX footer.
	 *
	 * @param string $page Page slug.
	 */
	public function render( string $page ): void {
		echo '<div class="wrap dfxpray-wrap">';

		if ( str_starts_with( $page, self::GROUP_PAGE ) ) {
			$this->render_group_page( (int) substr( $page, strlen( self::GROUP_PAGE ) ) );
		} else {
			$this->render_page( $page );
		}

		echo '</div>';
		$this->render_footer();
	}

	/**
	 * The DFX footer shown on every screen of the panel, as in the other DFX plugins.
	 */
	public function render_footer(): void {
		echo '<div class="dfxpray-plugin-footer"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: 1: plugin name, 2: link to the author. */
				__( 'Prayer intentions management features provided via %1$s plugin by %2$s. A.M.D.G.', 'dfx-prayer-intentions' ),
				'<strong>DFX Prayer Intentions</strong>',
				sprintf( '<a href="%s">David Marín Carreño</a>', esc_url( __( 'https://davefx.com/en/wordpress-plugins/', 'dfx-prayer-intentions' ) ) )
			)
		);
		echo '</p></div>';
	}

	/**
	 * The groups page or the global settings.
	 *
	 * @param string $page Page slug.
	 */
	private function render_page( string $page ): void {
		$permissions = DFXPRAY_Permissions::get_instance();
		$pages       = array(
			'dfxpray'        => array( __( 'Groups', 'dfx-prayer-intentions' ), 'groups', false ),
			'dfxpray-global' => array( __( 'Global settings', 'dfx-prayer-intentions' ), 'global', true ),
		);

		echo '<h1>' . esc_html( $pages[ $page ][0] ?? __( 'Prayer intentions', 'dfx-prayer-intentions' ) ) . '</h1>';

		if ( ! isset( $pages[ $page ] ) || ! $permissions->can_access_panel() || ( $pages[ $page ][2] && ! $permissions->is_admin() ) ) {
			$this->forbidden();
			return;
		}

		$this->render_notices();
		$this->view( $pages[ $page ][1] );
	}

	/**
	 * A group's page: its name, the tabs, and the chosen tab.
	 *
	 * @param int $group_id Group ID.
	 */
	private function render_group_page( int $group_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$tab   = sanitize_key( wp_unslash( $_GET['tab'] ?? 'moderation' ) );
		$group = DFXPRAY_Groups::get_instance()->get( $group_id );

		if ( ! $group || ! isset( self::TABS[ $tab ] ) || ! DFXPRAY_Permissions::get_instance()->can_manage_group( $group_id ) ) {
			echo '<h1>' . esc_html__( 'Prayer intentions', 'dfx-prayer-intentions' ) . '</h1>';
			$this->forbidden();
			return;
		}

		echo '<h1>' . esc_html( $group['name'] ) . '</h1>';
		$this->render_notices();
		$this->confidentiality_notice();
		$this->agreement_notice( $group_id );

		$counts = DFXPRAY_Intentions::get_instance()->counts( $group_id );
		$badges = array(
			'moderation' => $counts['pending'],
			'reviews'    => $counts['review'],
		);

		echo '<nav class="nav-tab-wrapper dfxpray-tabs" aria-label="' . esc_attr__( 'Group sections', 'dfx-prayer-intentions' ) . '">';
		foreach ( $this->tab_titles() as $key => $title ) {
			$class = 'nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' );
			$count = empty( $badges[ $key ] ) ? '' : ' <span class="dfxpray-tab-count">' . esc_html( number_format_i18n( $badges[ $key ] ) ) . '</span>';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $this->group_url( $group_id, $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $title ) . $count . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $count is escaped above.
		}
		echo '</nav>';

		$this->view(
			self::TABS[ $tab ],
			array(
				'group_id' => $group_id,
				'group'    => $group,
				'tab'      => $tab,
			)
		);
	}

	/**
	 * The duty of confidentiality, until the leader acknowledges it.
	 */
	private function confidentiality_notice(): void {
		if ( get_user_meta( get_current_user_id(), self::CONFIDENTIALITY_META, true ) ) {
			return;
		}

		echo '<div class="notice notice-info inline dfxpray-confidentiality"><p>' . esc_html__( 'As a leader of a group you will learn very sensitive data: illnesses, deaths, family situations. You have a duty of confidentiality: do not share anything outside the panel, except the discreet summary for the group, and keep this duty also when you stop being a leader.', 'dfx-prayer-intentions' ) . '</p>';
		$this->form_open( 'ack_confidentiality' );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Understood', 'dfx-prayer-intentions' ) . '</button></p></form></div>';
	}

	/**
	 * A group without its controller or an accepted processing agreement.
	 *
	 * @param int $group_id Group ID.
	 */
	private function agreement_notice( int $group_id ): void {
		if ( ! DFXPRAY_Privacy::get_instance()->agreement_pending( $group_id ) ) {
			return;
		}

		echo '<div class="notice notice-info inline dfxpray-agreement-pending"><p>' . esc_html__( 'Data protection: this group still lacks its data controller or the accepted processing agreement. The administrator of the site records them in the groups page.', 'dfx-prayer-intentions' ) . '</p></div>';
	}

	/**
	 * Menu badge.
	 *
	 * @param int $count Count.
	 */
	private function bubble( int $count ): string {
		return $count ? ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . number_format_i18n( $count ) . '</span></span>' : '';
	}

	/**
	 * Include a view from admin/views.
	 *
	 * @param string $name View name.
	 * @param array  $vars Variables.
	 */
	public function view( string $name, array $vars = array() ): void {
		$file = DFXPRAY_PLUGIN_DIR . 'admin/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}

		( static function ( string $dfxpray_file, array $dfxpray_vars ) {
			extract( $dfxpray_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $dfxpray_file;
		} )( $file, $vars );
	}

	/**
	 * Refusal message.
	 */
	public function forbidden(): void {
		echo '<div class="notice notice-error inline"><p>' . esc_html__( 'You are not allowed to see this.', 'dfx-prayer-intentions' ) . '</p></div>';
	}

	/**
	 * URL of a tab of a group's page.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $tab      Tab.
	 * @param array  $args     Extra arguments.
	 */
	public function group_url( int $group_id, string $tab = 'moderation', array $args = array() ): string {
		return $this->url( self::GROUP_PAGE . $group_id, array_merge( array( 'tab' => $tab ), $args ) );
	}

	/**
	 * Where a panel user starts: their group's page when they have only one,
	 * the groups page otherwise.
	 *
	 * @param int|null $user_id User ID; defaults to the current user.
	 */
	public function landing_url( ?int $user_id = null ): string {
		$groups = DFXPRAY_Permissions::get_instance()->group_ids_for_user( $user_id );

		return 1 === count( $groups ) ? admin_url( 'admin.php?page=' . self::GROUP_PAGE . $groups[0] ) : admin_url( 'admin.php?page=dfxpray' );
	}

	/**
	 * Admin URL of a screen.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra arguments.
	 */
	public function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Opening of a panel form: action, nonce and return address.
	 *
	 * @param string $action Action name.
	 * @param array  $hidden Extra hidden fields.
	 * @param string $css_class CSS class.
	 */
	public function form_open( string $action, array $hidden = array(), string $css_class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( trim( 'dfxpray-action ' . $css_class ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( DFXPRAY_Admin_Actions::HOOK ) . '">';
		echo '<input type="hidden" name="dfxpray_action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'dfxpray_' . $action ) ) . '">';
		echo '<input type="hidden" name="dfxpray_return" value="' . esc_url( $this->current_url() ) . '">';

		foreach ( $hidden as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
	}

	/**
	 * Keep a notice for the next screen.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message.
	 */
	public function add_notice( string $type, string $message ): void {
		$key     = 'dfxpray_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Show and forget the pending notices.
	 */
	private function render_notices(): void {
		$key     = 'dfxpray_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		delete_transient( $key );

		foreach ( is_array( $notices ) ? $notices : array() as $notice ) {
			$class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	/**
	 * Admin CSS and JS, on the plugin screens only.
	 *
	 * @param string $hook Screen hook.
	 */
	public function enqueue_assets( $hook ): void {
		if ( false === strpos( (string) $hook, 'dfxpray' ) ) {
			return;
		}

		wp_enqueue_style( 'dfxpray-admin', DFXPRAY_PLUGIN_URL . 'assets/css/admin.css', array(), DFXPRAY_VERSION );
		wp_enqueue_script( 'dfxpray-admin', DFXPRAY_PLUGIN_URL . 'assets/js/admin.js', array(), DFXPRAY_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'dfxpray-admin',
			'dfxprayAdmin',
			array(
				'copied'        => __( 'Copied!', 'dfx-prayer-intentions' ),
				'copy'          => __( 'Copy', 'dfx-prayer-intentions' ),
				'confirmDelete' => __( 'This cannot be undone. Continue?', 'dfx-prayer-intentions' ),
			)
		);
	}

	/**
	 * Leaders land on the panel after logging in, unless they came from the
	 * app, which they go back to.
	 *
	 * @param string           $redirect_to Destination.
	 * @param string           $requested   Requested destination.
	 * @param WP_User|WP_Error $user        User.
	 * @return string
	 */
	public function login_redirect( $redirect_to, $requested, $user ) {
		$app = DFXPRAY_App::get_instance()->url();
		if ( is_string( $requested ) && '' !== $requested && str_starts_with( $requested, $app ) ) {
			return $requested;
		}

		if ( $user instanceof WP_User && DFXPRAY_Permissions::get_instance()->is_panel_only_user( $user->ID ) ) {
			return $this->landing_url( $user->ID );
		}

		return $redirect_to;
	}

	/**
	 * Where the dashboard should send the current user instead, if anywhere.
	 *
	 * @return string|null Panel URL for leaders; null for everyone else.
	 */
	public function dashboard_redirect(): ?string {
		return DFXPRAY_Permissions::get_instance()->is_panel_only_user() ? $this->landing_url() : null;
	}

	/**
	 * Leaders have nothing to do on the dashboard: send them to the panel.
	 */
	public function maybe_redirect_dashboard(): void {
		$url = $this->dashboard_redirect();

		if ( $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Remove the dashboard from the leaders' menu.
	 */
	public function hide_dashboard(): void {
		if ( DFXPRAY_Permissions::get_instance()->is_panel_only_user() ) {
			remove_menu_page( 'index.php' );
		}
	}

	/**
	 * Show the leader role name in the site language.
	 *
	 * Roles are first built in wp-settings.php, before translations may load,
	 * so the name is translated on init and again whenever roles are rebuilt.
	 *
	 * @param WP_Roles|null $roles Roles; the global ones when called on init.
	 */
	public function translate_role_name( $roles = null ): void {
		if ( ! did_action( 'init' ) ) {
			return;
		}

		$roles = $roles instanceof WP_Roles ? $roles : wp_roles();
		if ( isset( $roles->role_names[ DFXPRAY_Installer::ROLE ] ) ) {
			$roles->role_names[ DFXPRAY_Installer::ROLE ] = __( 'Prayer leader', 'dfx-prayer-intentions' );
		}
	}

	/**
	 * Definitions of the setting fields.
	 *
	 * @return array<string, array{type:string, label:string, help?:string, options?:array}>
	 */
	public function setting_fields(): array {
		return array(
			'event_grace_days'         => array(
				'type'  => 'number',
				'label' => __( 'One-off events: days after the date', 'dfx-prayer-intentions' ),
				'help'  => __( 'After that, the intention goes to review.', 'dfx-prayer-intentions' ),
			),
			'deceased_days'            => array(
				'type'  => 'number',
				'label' => __( 'Deceased: days after the death', 'dfx-prayer-intentions' ),
			),
			'daily_deceased_days'      => array(
				'type'  => 'number',
				'label' => __( 'Daily message: departed during (days)', 'dfx-prayer-intentions' ),
				'help'  => __( 'The monthly list keeps them for the whole deceased deadline.', 'dfx-prayer-intentions' ),
			),
			'deceased_auto_close'      => array(
				'type'  => 'checkbox',
				'label' => __( 'Close the deceased when that time is over', 'dfx-prayer-intentions' ),
				'help'  => __( 'Instead of waiting in review. Those whose anniversary is remembered are kept.', 'dfx-prayer-intentions' ),
			),
			'ongoing_review_days'      => array(
				'type'  => 'number',
				'label' => __( 'Ongoing situations: review every (days)', 'dfx-prayer-intentions' ),
			),
			'thanksgiving_days'        => array(
				'type'  => 'number',
				'label' => __( 'Thanksgiving: days', 'dfx-prayer-intentions' ),
			),
			'daily_news_days'          => array(
				'type'  => 'number',
				'label' => __( 'Daily message: news go first during (days)', 'dfx-prayer-intentions' ),
				'help'  => __( 'What reached the list (registered or approved, however old the request) or was reopened the day before (and that morning), and what got an update marked as news, goes in “Especially”. 0: never.', 'dfx-prayer-intentions' ),
			),
			'daily_birthdays'          => array(
				'type'  => 'checkbox',
				'label' => __( 'Daily message: pray for the members on their birthday', 'dfx-prayer-intentions' ),
				'help'  => __( 'They open “Especially”, and so do the members who have died, on the anniversary of their death. The dates are kept in the Members tab.', 'dfx-prayer-intentions' ),
			),
			'daily_thanks_days'        => array(
				'type'  => 'number',
				'label' => __( 'Daily message: thanksgivings during (days)', 'dfx-prayer-intentions' ),
				'help'  => __( 'How many daily messages carry it, from the day of the good news, or from the day it reaches the list if later: 1 means a single message. The monthly list keeps them.', 'dfx-prayer-intentions' ),
			),
			'monthly_thanks_days'      => array(
				'type'  => 'number',
				'label' => __( 'Monthly list: thanksgivings of the last (days)', 'dfx-prayer-intentions' ),
				'help'  => __( 'Also those already closed, to give thanks again at the monthly adoration.', 'dfx-prayer-intentions' ),
			),
			'thanksgiving_auto_close'  => array(
				'type'  => 'checkbox',
				'label' => __( 'Close thanksgivings when their days are over', 'dfx-prayer-intentions' ),
			),
			'keep_active_days'         => array(
				'type'  => 'number',
				'label' => __( '“Still current”: days until the next review', 'dfx-prayer-intentions' ),
			),
			'confirmation_message'     => array(
				'type'  => 'textarea',
				'label' => __( 'Message after sending the form', 'dfx-prayer-intentions' ),
			),
			'index_header'             => array(
				'type'  => 'textarea',
				'label' => __( 'Index header', 'dfx-prayer-intentions' ),
				'help'  => __( '{date} is replaced with the date, and {long_date} with the date and weekday in full.', 'dfx-prayer-intentions' ),
			),
			'monthly_header'           => array(
				'type'  => 'textarea',
				'label' => __( 'Monthly list header', 'dfx-prayer-intentions' ),
				'help'  => __( 'Placeholders: {date}, {long_date}.', 'dfx-prayer-intentions' ),
			),
			'index_footer'             => array(
				'type'  => 'textarea',
				'label' => __( 'Index footer', 'dfx-prayer-intentions' ),
				'help'  => __( 'For example, a short prayer.', 'dfx-prayer-intentions' ),
			),
			'index_section_emojis'     => array(
				'type'  => 'checkbox',
				'label' => __( 'Emojis in the index sections', 'dfx-prayer-intentions' ),
			),
			'index_layout'             => array(
				'type'    => 'select',
				'label'   => __( 'Index layout', 'dfx-prayer-intentions' ),
				'options' => array(
					'by_type'   => __( 'By kind: events, the departed, ongoing situations, thanksgiving', 'dfx-prayer-intentions' ),
					'proposals' => __( 'Prayer proposals: especially, also for, we give thanks, the recently departed', 'dfx-prayer-intentions' ),
				),
			),
			'member_prefix'            => array(
				'type'  => 'text',
				'label' => __( 'Prefix for members of the group', 'dfx-prayer-intentions' ),
				'help'  => __( 'Written before the name of those marked as members, e.g. “N.H.”. Empty: no prefix.', 'dfx-prayer-intentions' ),
			),
			'digest_include_comments'  => array(
				'type'  => 'checkbox',
				'label' => __( 'Include recent internal comments in the leaders’ digest', 'dfx-prayer-intentions' ),
			),
			'digest_phones'            => array(
				'type'  => 'checkbox',
				'label' => __( 'Include the requesters’ phones in the leaders’ digest', 'dfx-prayer-intentions' ),
			),
			'notice_full_text'         => array(
				'type'  => 'checkbox',
				'label' => __( 'Include the full text of new requests in the email notice', 'dfx-prayer-intentions' ),
				'help'  => __( 'Only by email, never in other channels. Without it, leaders read the text in the panel.', 'dfx-prayer-intentions' ),
			),
			'digest_time'              => array(
				'type'  => 'time',
				'label' => __( 'Daily digest at', 'dfx-prayer-intentions' ),
			),
			'digest_when_empty'        => array(
				'type'    => 'select',
				'label'   => __( 'When there is nothing new', 'dfx-prayer-intentions' ),
				'options' => array(
					'short' => __( 'Send a short version', 'dfx-prayer-intentions' ),
					'none'  => __( 'Send nothing', 'dfx-prayer-intentions' ),
				),
			),
			'alert_lead_minutes'       => array(
				'type'  => 'number',
				'label' => __( 'Hour alerts: minutes before', 'dfx-prayer-intentions' ),
			),
			'alert_at_exact_time'      => array(
				'type'  => 'checkbox',
				'label' => __( 'Second alert at the exact time', 'dfx-prayer-intentions' ),
			),
			'quiet_start'              => array(
				'type'  => 'time',
				'label' => __( 'Quiet hours from', 'dfx-prayer-intentions' ),
				'help'  => __( 'Alerts are never sent in quiet hours. The digest before them lists those events; if none did, an early alert goes out just before quiet hours begin.', 'dfx-prayer-intentions' ),
			),
			'quiet_end'                => array(
				'type'  => 'time',
				'label' => __( 'Quiet hours until', 'dfx-prayer-intentions' ),
			),
			'retention_days'           => array(
				'type'  => 'number',
				'label' => __( 'Delete closed and discarded intentions after (days)', 'dfx-prayer-intentions' ),
				'help'  => __( 'Including their updates, comments and history.', 'dfx-prayer-intentions' ),
			),
			'phone_country_code'       => array(
				'type'  => 'text',
				'label' => __( 'Country code for phone numbers', 'dfx-prayer-intentions' ),
				'help'  => __( 'Added to numbers written without one, for the WhatsApp links. 34 is Spain.', 'dfx-prayer-intentions' ),
			),
			'url_base'                 => array(
				'type'  => 'text',
				'label' => __( 'Beginning of the form addresses', 'dfx-prayer-intentions' ),
				'help'  => __( 'Applies to new addresses. A random part is always added.', 'dfx-prayer-intentions' ),
			),
			'rate_limit_per_hour'      => array(
				'type'  => 'number',
				'label' => __( 'Maximum requests per hour from one connection', 'dfx-prayer-intentions' ),
			),
			'turnstile_site_key'       => array(
				'type'  => 'text',
				'label' => __( 'Cloudflare Turnstile site key', 'dfx-prayer-intentions' ),
				'help'  => __( 'Optional. Leave both keys empty to disable it.', 'dfx-prayer-intentions' ),
			),
			'turnstile_secret'         => array(
				'type'  => 'password',
				'label' => __( 'Cloudflare Turnstile secret key', 'dfx-prayer-intentions' ),
			),
			'delete_data_on_uninstall' => array(
				'type'  => 'checkbox',
				'label' => __( 'Delete all data when the plugin is uninstalled', 'dfx-prayer-intentions' ),
			),
			'privacy_controller'       => array(
				'type'  => 'text',
				'label' => __( 'Default data controller', 'dfx-prayer-intentions' ),
				'help'  => __( 'Each group sets its own in the groups page; this one is for groups that have none. Empty: the name of the site.', 'dfx-prayer-intentions' ),
			),
			'privacy_contact'          => array(
				'type'  => 'text',
				'label' => __( 'Default contact for data protection', 'dfx-prayer-intentions' ),
				'help'  => __( 'An email or address where people can exercise their rights.', 'dfx-prayer-intentions' ),
			),
			'provider_name'            => array(
				'type'  => 'text',
				'label' => __( 'Provider of the service (data processor)', 'dfx-prayer-intentions' ),
				'help'  => __( 'Who runs this site for the parishes and groups: a person, a company or an association. Empty: the site itself is the controller.', 'dfx-prayer-intentions' ),
			),
			'provider_tax_id'          => array(
				'type'  => 'text',
				'label' => __( 'Tax ID of the provider', 'dfx-prayer-intentions' ),
			),
			'provider_address'         => array(
				'type'  => 'text',
				'label' => __( 'Address of the provider', 'dfx-prayer-intentions' ),
			),
			'provider_email'           => array(
				'type'  => 'text',
				'label' => __( 'Email of the provider for data protection', 'dfx-prayer-intentions' ),
			),
			'subprocessors'            => array(
				'type'  => 'textarea',
				'label' => __( 'Subprocessors', 'dfx-prayer-intentions' ),
				'help'  => __( 'One per line: who and for what, and where, e.g. “Hosting: Hetzner (Germany)”.', 'dfx-prayer-intentions' ),
			),
			'consent_min_age'          => array(
				'type'  => 'number',
				'label' => __( 'Minimum age to send requests', 'dfx-prayer-intentions' ),
				'help'  => __( 'Age to consent on one’s own: 14 in Spain; 13 to 16 elsewhere in the EU.', 'dfx-prayer-intentions' ),
			),
		);
	}

	/**
	 * Setting keys by section, for the group or the global screen.
	 *
	 * @param string $scope 'group' or 'global'.
	 * @return array<string, string[]>
	 */
	public function setting_sections( string $scope ): array {
		$sections = array(
			__( 'Deadlines', 'dfx-prayer-intentions' ) => array( 'event_grace_days', 'deceased_days', 'daily_deceased_days', 'daily_news_days', 'daily_birthdays', 'deceased_auto_close', 'ongoing_review_days', 'thanksgiving_days', 'daily_thanks_days', 'monthly_thanks_days', 'thanksgiving_auto_close', 'keep_active_days' ),
			__( 'Texts', 'dfx-prayer-intentions' )     => array( 'confirmation_message', 'index_header', 'monthly_header', 'index_footer', 'index_section_emojis', 'index_layout', 'member_prefix', 'digest_include_comments' ),
			__( 'Schedules', 'dfx-prayer-intentions' ) => array( 'digest_time', 'digest_when_empty', 'alert_lead_minutes', 'alert_at_exact_time', 'quiet_start', 'quiet_end' ),
			__( 'Personal data in messages', 'dfx-prayer-intentions' ) => array( 'notice_full_text', 'digest_phones' ),
		);

		if ( 'global' === $scope ) {
			$sections = array_merge(
				array(
					__( 'Data and protection', 'dfx-prayer-intentions' ) => array( 'provider_name', 'provider_tax_id', 'provider_address', 'provider_email', 'subprocessors', 'privacy_controller', 'privacy_contact', 'consent_min_age', 'retention_days', 'phone_country_code', 'url_base', 'rate_limit_per_hour', 'turnstile_site_key', 'turnstile_secret', 'delete_data_on_uninstall' ),
				),
				$sections
			);
		}

		return $sections;
	}

	/**
	 * Human description of a log entry.
	 *
	 * @param array $entry Log entry.
	 */
	public function describe_log_entry( array $entry ): string {
		$labels = array(
			'intention_submitted'       => __( 'Request received from the form', 'dfx-prayer-intentions' ),
			'intention_created'         => __( 'Intention added', 'dfx-prayer-intentions' ),
			'intention_approved'        => __( 'Approved', 'dfx-prayer-intentions' ),
			'intention_discarded'       => __( 'Discarded', 'dfx-prayer-intentions' ),
			'intention_merged'          => __( 'Duplicate merged into it', 'dfx-prayer-intentions' ),
			'intention_updated'         => __( 'Edited', 'dfx-prayer-intentions' ),
			'visible_name_changed'      => __( 'Description changed', 'dfx-prayer-intentions' ),
			'intention_due'             => __( 'Review date reached', 'dfx-prayer-intentions' ),
			'intention_kept_active'     => __( 'Kept active', 'dfx-prayer-intentions' ),
			'intention_reopened'        => __( 'Reopened', 'dfx-prayer-intentions' ),
			'intention_closed'          => __( 'Closed', 'dfx-prayer-intentions' ),
			'intention_ack_sent'        => __( 'Acknowledgement sent', 'dfx-prayer-intentions' ),
			'intention_transferred_out' => __( 'Moved to another group', 'dfx-prayer-intentions' ),
			'intention_transferred_in'  => __( 'Received from another group', 'dfx-prayer-intentions' ),
			'update_added'              => __( 'Update added', 'dfx-prayer-intentions' ),
			'comment_added'             => __( 'Comment added', 'dfx-prayer-intentions' ),
			'comment_edited'            => __( 'Comment edited', 'dfx-prayer-intentions' ),
			'comment_deleted'           => __( 'Comment deleted', 'dfx-prayer-intentions' ),
			'comment_converted'         => __( 'Comment turned into an update', 'dfx-prayer-intentions' ),
			'channel_sent'              => __( 'Delivered', 'dfx-prayer-intentions' ),
			'channel_failed'            => __( 'Delivery failed', 'dfx-prayer-intentions' ),
			'group_created'             => __( 'Group created', 'dfx-prayer-intentions' ),
			'group_updated'             => __( 'Group edited', 'dfx-prayer-intentions' ),
			'group_archived'            => __( 'Group archived', 'dfx-prayer-intentions' ),
			'group_active'              => __( 'Group restored', 'dfx-prayer-intentions' ),
			'group_leaders_set'         => __( 'Leaders changed', 'dfx-prayer-intentions' ),
			'group_slug_regenerated'    => __( 'New form address', 'dfx-prayer-intentions' ),
			'group_settings_saved'      => __( 'Settings saved', 'dfx-prayer-intentions' ),
			'global_settings_saved'     => __( 'Global settings saved', 'dfx-prayer-intentions' ),
			'privacy_erased'            => __( 'Personal data erased on request', 'dfx-prayer-intentions' ),
		);

		$text    = $labels[ $entry['action'] ] ?? $entry['action'];
		$details = $entry['details'];

		if ( isset( $details['channel'] ) ) {
			$text .= ' · ' . $details['channel'] . ' · ' . ( $details['kind'] ?? '' );
		}
		if ( isset( $details['error'] ) ) {
			$text .= ': ' . $details['error'];
		}
		if ( isset( $details['reason'] ) ) {
			$reasons = DFXPRAY_Lifecycle::get_instance()->close_reasons() + array(
				'spam'      => __( 'Spam', 'dfx-prayer-intentions' ),
				'duplicate' => __( 'Duplicate', 'dfx-prayer-intentions' ),
			);
			$text   .= ' · ' . ( $reasons[ $details['reason'] ] ?? $details['reason'] );
		}
		if ( 'visible_name_changed' === $entry['action'] ) {
			$text .= ': “' . $details['old'] . '” → “' . $details['new'] . '”';
		}

		return $text;
	}

	/**
	 * Current admin URL.
	 */
	private function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $uri || false === strpos( $uri, 'page=' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
			$args = array_map( 'sanitize_text_field', wp_unslash( $_GET ) );
			return add_query_arg( $args, admin_url( 'admin.php' ) );
		}

		return ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . $uri;
	}
}
