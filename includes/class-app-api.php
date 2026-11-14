<?php
/**
 * Private API of the leaders' app.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers the leaders' app through admin-ajax, for logged-in users only.
 *
 * It is not a REST route: intentions stay out of WordPress' public surfaces.
 * Every request carries the app's nonce; reads are scoped to the groups the
 * user may manage, and writes go through the panel's own actions, with the
 * same permission checks.
 */
final class DFXPRAY_App_API {

	use DFXPRAY_Singleton;

	/**
	 * The admin-ajax action.
	 */
	public const AJAX_ACTION = 'dfxpray_app';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'dfxpray_app';

	/**
	 * Panel actions the app may run.
	 */
	private const WRITES = array( 'save_intention', 'add_update', 'close', 'reopen', 'keep_active', 'approve', 'discard', 'set_priority', 'postpone' );

	/**
	 * Lists the app asks for, by status.
	 */
	private const LISTS = array(
		'current' => DFXPRAY_Intentions::LISTABLE_STATUSES,
		'pending' => array( 'pending' ),
		'review'  => array( 'review' ),
		'closed'  => array( 'closed' ),
	);

	/**
	 * Hook into admin-ajax, for logged-in users only (no nopriv handler).
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
	}

	/**
	 * Handler for admin-ajax.
	 */
	public function handle_ajax(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in respond().
		$request = wp_unslash( $_POST );
		$result  = $this->respond( is_array( $request ) ? $request : array() );

		nocache_headers();
		wp_send_json( $result, $result['ok'] ? 200 : ( 'nonce' === $result['code'] || 'forbidden' === $result['code'] ? 403 : 400 ) );
	}

	/**
	 * Answer one request.
	 *
	 * @param array $request Unslashed request: op, _nonce and the operation's fields.
	 * @return array{ok:bool, code:string, message:string, id:?int, data?:array}
	 */
	public function respond( array $request ): array {
		if ( ! wp_verify_nonce( (string) ( $request['_nonce'] ?? '' ), self::NONCE ) ) {
			return $this->failure( 'nonce', __( 'The session has expired. Reload the app.', 'dfx-prayer-intentions' ) );
		}

		if ( ! DFXPRAY_Permissions::get_instance()->can_access_panel() ) {
			return $this->forbidden();
		}

		$op = sanitize_key( (string) ( $request['op'] ?? '' ) );

		if ( in_array( $op, self::WRITES, true ) ) {
			return DFXPRAY_Admin_Actions::get_instance()->run( $op, $request );
		}

		switch ( $op ) {
			case 'bootstrap':
				return $this->success( array( 'groups' => $this->groups() ) );

			case 'index':
				return $this->index( (int) ( $request['group_id'] ?? 0 ) );

			case 'list':
				return $this->list_intentions( (int) ( $request['group_id'] ?? 0 ), (string) ( $request['status'] ?? 'current' ), (string) ( $request['q'] ?? '' ) );

			case 'get':
				return $this->intention( (int) ( $request['intention_id'] ?? 0 ) );

			default:
				return $this->failure( 'unknown', __( 'Unknown action.', 'dfx-prayer-intentions' ) );
		}
	}

	/**
	 * The groups the user may manage.
	 *
	 * @return array[]
	 */
	private function groups(): array {
		$groups = array();

		foreach ( DFXPRAY_Permissions::get_instance()->group_ids_for_user() as $group_id ) {
			$group = DFXPRAY_Groups::get_instance()->get( $group_id );
			if ( $group && 'active' === $group['status'] ) {
				$groups[] = array(
					'id'   => (int) $group['id'],
					'name' => $group['name'],
				);
			}
		}

		return $groups;
	}

	/**
	 * The group's index: the message to share.
	 *
	 * @param int $group_id Group.
	 */
	private function index( int $group_id ): array {
		if ( ! DFXPRAY_Permissions::get_instance()->can_manage_group( $group_id ) ) {
			return $this->forbidden();
		}

		return $this->success( array( 'text' => DFXPRAY_Index_Composer::get_instance()->compose( $group_id ) ) );
	}

	/**
	 * Intentions of a group in one state, optionally searched.
	 *
	 * @param int    $group_id Group.
	 * @param string $status   current, pending, review or closed.
	 * @param string $search   Text to look for.
	 */
	private function list_intentions( int $group_id, string $status, string $search ): array {
		if ( ! DFXPRAY_Permissions::get_instance()->can_manage_group( $group_id ) ) {
			return $this->forbidden();
		}

		$args = array(
			'status'       => self::LISTS[ $status ] ?? self::LISTS['current'],
			'urgent_first' => true,
		);
		if ( '' !== trim( $search ) ) {
			$args['search'] = sanitize_text_field( $search );
		}

		$formatter  = DFXPRAY_Formatter::get_instance();
		$intentions = DFXPRAY_Intentions::get_instance()->query( $group_id, $args );
		$items      = array();

		foreach ( $intentions as $intention ) {
			$items[] = array(
				'id'          => $intention['id'],
				'line'        => $formatter->line( $intention ),
				'status'      => $intention['status'],
				'type'        => $intention['type'],
				'priority'    => $intention['priority'],
				'next_review' => $intention['next_review'],
				'created_at'  => $intention['created_at'],
				'requester'   => $intention['requester_name'],
			);
		}

		return $this->success( array( 'items' => $items ) );
	}

	/**
	 * One intention, with its updates.
	 *
	 * @param int $id Intention ID.
	 */
	private function intention( int $id ): array {
		if ( ! DFXPRAY_Admin_Actions::get_instance()->can_manage_intention( $id ) ) {
			return $this->forbidden();
		}

		$intention = DFXPRAY_Intentions::get_instance()->get( $id );
		$updates   = array();

		foreach ( DFXPRAY_Updates::get_instance()->for_intention( $id ) as $update ) {
			$author    = get_userdata( (int) $update['author_id'] );
			$updates[] = array(
				'body'       => $update['body'],
				'is_news'    => (bool) $update['is_news'],
				'created_at' => $update['created_at'],
				'author'     => $author ? $author->display_name : '',
			);
		}

		return $this->success(
			array(
				'intention' => $intention,
				'line'      => DFXPRAY_Formatter::get_instance()->line( $intention ),
				'updates'   => $updates,
			)
		);
	}

	/**
	 * A successful answer.
	 *
	 * @param array $data Data.
	 */
	private function success( array $data ): array {
		return array(
			'ok'      => true,
			'code'    => 'ok',
			'message' => '',
			'id'      => null,
			'data'    => $data,
		);
	}

	/**
	 * Refused for lack of permission.
	 */
	private function forbidden(): array {
		return $this->failure( 'forbidden', __( 'You are not allowed to do that.', 'dfx-prayer-intentions' ) );
	}

	/**
	 * A failed answer.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private function failure( string $code, string $message ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'id'      => null,
		);
	}
}
