<?php
/**
 * Service accounts: users that only routines use.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A service account leads groups like anyone else, so it has the same
 * permissions, but it is not a person: it gets no notices by email, and its
 * application passwords open only this plugin's REST route (not the rest of
 * WordPress' REST API, nor XML-RPC). Only administrators mark one, from the
 * user's profile.
 */
final class DFXPRAY_Service_Accounts {

	use DFXPRAY_Singleton;

	/**
	 * User meta that marks the account.
	 */
	public const META = 'dfxpray_service_account';

	/**
	 * Hooks: the profile field and the limits on application passwords.
	 */
	public function register_hooks(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'limit_rest' ), 10, 3 );
		add_action( 'wp_authenticate_application_password_errors', array( $this, 'on_app_password' ), 10, 2 );
		add_action( 'show_user_profile', array( $this, 'render_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
	}

	/**
	 * Whether a user is a service account.
	 *
	 * @param int $user_id User.
	 */
	public function is_service_account( int $user_id ): bool {
		return $user_id > 0 && (bool) get_user_meta( $user_id, self::META, true );
	}

	/**
	 * Mark or unmark a user.
	 *
	 * @param int  $user_id User.
	 * @param bool $on      Whether it is a service account.
	 */
	public function set( int $user_id, bool $on ): void {
		if ( $on ) {
			update_user_meta( $user_id, self::META, 1 );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * A service account's application password reaches only this plugin's route.
	 *
	 * @param mixed           $result  Response so far.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function limit_rest( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || null === rest_get_authenticated_app_password() || ! $this->is_service_account( get_current_user_id() ) ) {
			return $result;
		}

		$route = (string) $request->get_route();
		if ( '/' . DFXPRAY_REST::NAMESPACE === $route || str_starts_with( $route, '/' . DFXPRAY_REST::NAMESPACE . '/' ) ) {
			return $result;
		}

		return new WP_Error( 'dfxpray_service_account', __( 'This account only works with DFX Prayer Intentions.', 'dfx-prayer-intentions' ), array( 'status' => 403 ) );
	}

	/**
	 * Core hook: application password accepted for a user.
	 *
	 * @param WP_Error $error Errors so far.
	 * @param WP_User  $user  User.
	 */
	public function on_app_password( $error, $user ): void {
		if ( $error instanceof WP_Error && $user instanceof WP_User ) {
			$this->check_app_password( $error, $user, ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) );
		}
	}

	/**
	 * Refuse a service account's application password outside REST.
	 *
	 * @param WP_Error $error   Errors so far.
	 * @param WP_User  $user    User.
	 * @param bool     $in_rest Whether the request is a REST one.
	 */
	public function check_app_password( WP_Error $error, WP_User $user, bool $in_rest ): WP_Error {
		if ( ! $in_rest && $this->is_service_account( $user->ID ) ) {
			$error->add( 'dfxpray_service_account', __( 'This account only works with DFX Prayer Intentions.', 'dfx-prayer-intentions' ) );
		}

		return $error;
	}

	/**
	 * The field in the user's profile, for administrators.
	 *
	 * @param WP_User $user User being edited.
	 */
	public function render_profile( $user ): void {
		if ( ! $user instanceof WP_User || ! DFXPRAY_Permissions::get_instance()->is_admin() ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'DFX Prayer Intentions', 'dfx-prayer-intentions' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Service account', 'dfx-prayer-intentions' ); ?></th>
				<td>
					<label><input type="checkbox" name="dfxpray_service_account" value="1" <?php checked( $this->is_service_account( $user->ID ) ); ?>> <?php esc_html_e( 'Only a routine uses this account', 'dfx-prayer-intentions' ); ?></label>
					<p class="description"><?php esc_html_e( 'It keeps the permissions of the groups it leads, but gets no email, and its application passwords only work with this plugin.', 'dfx-prayer-intentions' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the field (core has already checked the profile's nonce).
	 *
	 * @param int $user_id User being saved.
	 */
	public function save_profile( $user_id ): void {
		if ( ! DFXPRAY_Permissions::get_instance()->is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by core before this hook.
		$this->set( (int) $user_id, ! empty( $_POST['dfxpray_service_account'] ) );
	}
}
