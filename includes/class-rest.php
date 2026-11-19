<?php
/**
 * The routines' REST route.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One REST route for routines (scripts that keep a group up to date), with
 * the same operations and permissions as the leaders' app.
 *
 * It answers only to users authenticated with an application password: never
 * anonymously, and never to a browser session, so a page cannot call it with
 * the leader's cookies. The password stays with the routine's script.
 */
final class DFXPRAY_REST {

	use DFXPRAY_Singleton;

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'dfxpray/v1';

	/**
	 * Register the route when the REST server starts.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * POST /dfxpray/v1/<op>, with the operation's fields as a JSON body.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/(?P<op>[a-z_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_call' ),
			)
		);
	}

	/**
	 * Only application passwords of users who reach the panel.
	 *
	 * @return true|WP_Error
	 */
	public function can_call() {
		if ( ! is_user_logged_in() || null === rest_get_authenticated_app_password() ) {
			return new WP_Error( 'dfxpray_app_password_required', __( 'This route only answers to application passwords.', 'dfx-prayer-intentions' ), array( 'status' => 401 ) );
		}

		if ( ! DFXPRAY_Permissions::get_instance()->can_access_panel() ) {
			return new WP_Error( 'dfxpray_forbidden', __( 'You are not allowed to do that.', 'dfx-prayer-intentions' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Run the operation as the app would.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();

		$result = DFXPRAY_App_API::get_instance()->dispatch( array_merge( (array) $params, array( 'op' => (string) $request['op'] ) ) );

		$status = 200;
		if ( ! $result['ok'] ) {
			$status = 'forbidden' === $result['code'] ? 403 : 400;
		}

		$response = new WP_REST_Response( $result, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
