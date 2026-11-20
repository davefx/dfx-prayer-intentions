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
 * same permission checks. Routines reach the same operations through
 * DFXPRAY_REST, with an application password instead of the nonce.
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
	private const WRITES = array( 'save_intention', 'add_update', 'close', 'reopen', 'keep_active', 'approve', 'discard', 'set_priority', 'postpone', 'send_digest_now' );

	/**
	 * Lists the app asks for, by status.
	 */
	private const LISTS = array(
		'current' => DFXPRAY_Intentions::LISTABLE_STATUSES,
		'pending' => array( 'pending' ),
		'review'  => array( 'review' ),
		'closed'  => array( 'closed' ),
		'ask'     => DFXPRAY_Intentions::LISTABLE_STATUSES,
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

		return $this->dispatch( $request );
	}

	/**
	 * Run one operation for the current user, once the caller has checked
	 * how they authenticated (the app's nonce, or an application password).
	 *
	 * @param array $request Unslashed request: op and the operation's fields.
	 * @return array{ok:bool, code:string, message:string, id:?int, data?:array}
	 */
	public function dispatch( array $request ): array {
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
				return $this->index( (int) ( $request['group_id'] ?? 0 ), 'monthly' === ( $request['edition'] ?? '' ) ? 'monthly' : 'daily' );

			case 'list':
				return $this->list_intentions( (int) ( $request['group_id'] ?? 0 ), (string) ( $request['status'] ?? 'current' ), (string) ( $request['q'] ?? '' ) );

			case 'get':
				return $this->intention( (int) ( $request['intention_id'] ?? 0 ) );

			case 'thanksgiving_preview':
				return $this->thanksgiving_preview( (int) ( $request['intention_id'] ?? 0 ), (string) ( $request['summary'] ?? '' ) );

			case 'members':
				return $this->members( (int) ( $request['group_id'] ?? 0 ) );

			case 'digest':
				return $this->digest( (int) ( $request['group_id'] ?? 0 ) );

			case 'preview':
				return $this->preview( (int) ( $request['group_id'] ?? 0 ), (int) ( $request['intention_id'] ?? 0 ), (array) ( $request['intention'] ?? array() ) );

			default:
				return $this->failure( 'unknown', __( 'Unknown action.', 'dfx-prayer-intentions' ) );
		}
	}

	/**
	 * The members of a group in the order of the calendar of their
	 * birthday or anniversary, with a link to write to them by WhatsApp.
	 *
	 * @param int $group_id Group.
	 */
	private function members( int $group_id ): array {
		if ( ! DFXPRAY_Permissions::get_instance()->can_manage_group( $group_id ) ) {
			return $this->forbidden();
		}

		$formatter = DFXPRAY_Formatter::get_instance();
		$members   = array();
		foreach ( DFXPRAY_Members::get_instance()->calendar( $group_id, DFXPRAY_Clock::get_instance()->today() ) as $member ) {
			$number    = $formatter->wa_number( $member['phone'] );
			$members[] = array(
				'id'         => $member['id'],
				'name'       => $member['name'],
				'shown'      => $member['shown'],
				'notes'      => $member['notes'],
				'kind'       => $member['kind'],
				'month'      => $member['month'],
				'day'        => $member['day'],
				'next'       => $member['next'],
				'days'       => $member['days'],
				'years'      => $member['years'],
				'death_date' => $member['death_date'],
				'whatsapp'   => '' !== $number ? 'https://wa.me/' . $number : '',
			);
		}

		return $this->success( array( 'members' => $members ) );
	}

	/**
	 * Today's digest: the message to share, the leaders' version and whether
	 * it has already gone out today.
	 *
	 * @param int $group_id Group.
	 */
	private function digest( int $group_id ): array {
		if ( ! DFXPRAY_Permissions::get_instance()->can_manage_group( $group_id ) ) {
			return $this->forbidden();
		}

		$stored = DFXPRAY_Scheduler::get_instance()->get_stored_digest( $group_id );
		$today  = $stored && DFXPRAY_Clock::get_instance()->today() === $stored['date'];
		$digest = DFXPRAY_Digest_Composer::get_instance()->compose( $group_id );

		return $this->success(
			array(
				'shareable'  => $digest['shareable'],
				'leaders'    => $digest['leaders'],
				'sent_today' => $today,
				'sent_at'    => $today ? (string) $stored['generated_at'] : '',
			)
		);
	}

	/**
	 * The line an intention would show while it is written, for the form.
	 * An edit is scoped to the group of the saved intention.
	 *
	 * @param int   $group_id     Group of a new intention.
	 * @param int   $intention_id Saved intention being edited, or 0.
	 * @param array $data         Fields of the form.
	 */
	private function preview( int $group_id, int $intention_id, array $data ): array {
		$intentions = DFXPRAY_Intentions::get_instance();
		$current    = $intention_id ? $intentions->get( $intention_id ) : null;
		if ( $intention_id && ! $current ) {
			return $this->forbidden();
		}

		$group_id = $current ? (int) $current['group_id'] : $group_id;
		if ( ! DFXPRAY_Permissions::get_instance()->can_manage_group( $group_id ) ) {
			return $this->forbidden();
		}

		$line = $intentions->preview( $group_id, $data, $current );
		if ( is_wp_error( $line ) ) {
			return $this->failure( 'invalid', $line->get_error_message() );
		}

		return $this->success( array( 'line' => $line ) );
	}

	/**
	 * The line the thanksgiving will show, while closing an intention as
	 * answered, scoped to the intention's group.
	 *
	 * @param int    $intention_id Intention being closed.
	 * @param string $summary      Summary of the thanksgiving.
	 */
	private function thanksgiving_preview( int $intention_id, string $summary ): array {
		if ( ! DFXPRAY_Admin_Actions::get_instance()->can_manage_intention( $intention_id ) ) {
			return $this->forbidden();
		}

		$line = DFXPRAY_Intentions::get_instance()->thanksgiving_preview( $intention_id, $summary );
		if ( is_wp_error( $line ) ) {
			return $this->failure( 'invalid', $line->get_error_message() );
		}

		return $this->success( array( 'line' => $line ) );
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
	 * The group's index: the message to share, daily or monthly.
	 *
	 * @param int    $group_id Group.
	 * @param string $edition  'daily' or 'monthly'.
	 */
	private function index( int $group_id, string $edition = 'daily' ): array {
		if ( ! DFXPRAY_Permissions::get_instance()->can_manage_group( $group_id ) ) {
			return $this->forbidden();
		}

		return $this->success( array( 'text' => DFXPRAY_Index_Composer::get_instance()->compose( $group_id, $edition ) ) );
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
			'to_ask'       => 'ask' === $status,
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
				'ends_on'     => $intention['ends_on'],
				'created_at'  => $intention['created_at'],
				'requester'   => $intention['requester_name'],
				'to_ask'      => $intention['to_ask'],
				'ask'         => $formatter->ask_link( $intention ),
			);
		}

		$data = array( 'items' => $items );
		if ( 'ask' === $status ) {
			$data['groups'] = $this->by_requester( $intentions );
		}

		return $this->success( $data );
	}

	/**
	 * Intentions grouped by requester (same name, any case), each group with
	 * one WhatsApp question about all of them.
	 *
	 * @param array[] $intentions Intentions.
	 * @return array[] requester, ids, ask.
	 */
	private function by_requester( array $intentions ): array {
		$groups = array();
		foreach ( $intentions as $intention ) {
			$groups[ mb_strtolower( trim( $intention['requester_name'] ) ) ][] = $intention;
		}

		$formatter = DFXPRAY_Formatter::get_instance();

		return array_values(
			array_map(
				static fn( array $group ): array => array(
					'requester' => $group[0]['requester_name'],
					'ids'       => array_column( $group, 'id' ),
					'ask'       => $formatter->ask_link_for( $group ),
				),
				$groups
			)
		);
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
				'ask'       => DFXPRAY_Formatter::get_instance()->ask_link( $intention ),
				'linked'    => $this->linked( $intention ),
			)
		);
	}

	/**
	 * The intention linked to this one (the thanksgiving of an answered
	 * intention, or the intention a thanksgiving gives thanks for), when it
	 * is in the same group.
	 *
	 * @param array $intention Intention.
	 * @return array{id:int, type:string, status:string, line:string}|null
	 */
	private function linked( array $intention ): ?array {
		$linked = $intention['linked_id'] ? DFXPRAY_Intentions::get_instance()->get( $intention['linked_id'] ) : null;
		if ( ! $linked || $linked['group_id'] !== $intention['group_id'] ) {
			return null;
		}

		return array(
			'id'     => $linked['id'],
			'type'   => $linked['type'],
			'status' => $linked['status'],
			'line'   => DFXPRAY_Formatter::get_instance()->line( $linked ),
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
