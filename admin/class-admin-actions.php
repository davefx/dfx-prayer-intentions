<?php
/**
 * Panel actions controller.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every change made from the panel comes through here: nonce first, then the
 * permission for the group the target belongs to, then the service call.
 */
final class DFXPRAY_Admin_Actions {

	use DFXPRAY_Singleton;

	/**
	 * The admin-post action carrying every panel form.
	 */
	public const HOOK = 'dfxpray_action';

	/**
	 * Actions: method and the scope used for the permission check.
	 *
	 * Scopes: intention (intention_id), comment (comment_id), group (group_id),
	 * invitation (invitation_id: the invitation's own group), admin
	 * (administrator only).
	 */
	private const ACTIONS = array(
		'approve'                   => 'intention',
		'discard'                   => 'intention',
		'merge'                     => 'intention',
		'save_intention'            => 'intention_or_group',
		'add_comment'               => 'intention',
		'edit_comment'              => 'comment',
		'delete_comment'            => 'comment',
		'convert_comment'           => 'comment',
		'add_update'                => 'intention',
		'set_priority'              => 'intention',
		'postpone'                  => 'intention',
		'ack_sent'                  => 'intention',
		'transfer'                  => 'intention',
		'keep_active'               => 'intention',
		'close'                     => 'intention',
		'reopen'                    => 'intention',
		'save_member'               => 'member_or_group',
		'delete_member'             => 'member_or_group',
		'save_group_settings'       => 'group',
		'regenerate_slug'           => 'group',
		'test_channel'              => 'group',
		'send_digest_now'           => 'group',
		'mark_shared'               => 'group',
		'invite_leader'             => 'group',
		'withdraw_invitation'       => 'invitation',
		'save_group'                => 'admin',
		'archive_group'             => 'admin',
		'restore_group'             => 'admin',
		'delete_group'              => 'admin',
		'save_global_settings'      => 'admin',
		'privacy_erase'             => 'admin',
		'admin_invite_leader'       => 'admin',
		'admin_withdraw_invitation' => 'admin',
		'ack_confidentiality'       => 'self',
	);

	/**
	 * Hook into admin-post.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::HOOK, array( $this, 'process_request' ) );
	}

	/**
	 * Handler for admin-post: run the action, keep a notice and go back.
	 */
	public function process_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle().
		$request = wp_unslash( $_POST );
		$request = is_array( $request ) ? $request : array();
		$action  = sanitize_key( (string) ( $request['dfxpray_action'] ?? '' ) );
		$result  = $this->handle( $action, $request );

		DFXPRAY_Admin::get_instance()->add_notice( $result['ok'] ? 'success' : 'error', $result['message'] );

		$return = (string) ( $request['dfxpray_return'] ?? wp_get_referer() );
		if ( $result['ok'] && 'save_intention' === $action && empty( $request['intention_id'] ) && ! empty( $result['id'] ) ) {
			$return = DFXPRAY_Admin::get_instance()->group_url(
				(int) $request['group_id'],
				'intentions',
				array(
					'view' => 'edit',
					'id'   => $result['id'],
				)
			);
		}
		if ( $result['ok'] && 'transfer' === $action ) {
			$return = DFXPRAY_Admin::get_instance()->group_url(
				(int) $request['group_id'],
				'intentions',
				array(
					'view' => 'edit',
					'id'   => (int) $request['intention_id'],
				)
			);
		}
		// Closing with a thanksgiving opens the new thanksgiving, to edit it.
		if ( $result['ok'] && 'close' === $action && ! empty( $result['id'] ) ) {
			$thanks = DFXPRAY_Intentions::get_instance()->get( (int) $result['id'] );
			$return = DFXPRAY_Admin::get_instance()->group_url(
				(int) $thanks['group_id'],
				'intentions',
				array(
					'view' => 'edit',
					'id'   => (int) $result['id'],
				)
			);
		}
		if ( $result['ok'] && 'delete_group' === $action ) {
			$return = DFXPRAY_Admin::get_instance()->url( 'dfxpray' );
		}

		wp_safe_redirect( wp_validate_redirect( $return, DFXPRAY_Admin::get_instance()->url( 'dfxpray' ) ) );
		exit;
	}

	/**
	 * Run an action.
	 *
	 * @param string $action  Action name.
	 * @param array  $request Unslashed request data, including _wpnonce.
	 * @return array{ok:bool, code:string, message:string, id:?int}
	 */
	public function handle( string $action, array $request ): array {
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return $this->failure( 'unknown', __( 'Unknown action.', 'dfx-prayer-intentions' ) );
		}

		if ( ! wp_verify_nonce( (string) ( $request['_wpnonce'] ?? '' ), 'dfxpray_' . $action ) ) {
			return $this->failure( 'nonce', __( 'The link has expired. Please try again.', 'dfx-prayer-intentions' ) );
		}

		return $this->run( $action, $request );
	}

	/**
	 * Run an action whose request the caller has already authenticated
	 * (a nonce of its own, such as the app's). Permissions are checked here,
	 * exactly as for the panel.
	 *
	 * @param string $action  Action name.
	 * @param array  $request Unslashed request data.
	 * @return array{ok:bool, code:string, message:string, id:?int}
	 */
	public function run( string $action, array $request ): array {
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return $this->failure( 'unknown', __( 'Unknown action.', 'dfx-prayer-intentions' ) );
		}

		if ( ! $this->is_allowed( self::ACTIONS[ $action ], $request ) ) {
			return $this->failure( 'forbidden', __( 'You are not allowed to do that.', 'dfx-prayer-intentions' ) );
		}

		$result = $this->{'do_' . $action}( $request );

		if ( is_wp_error( $result ) ) {
			return $this->failure( (string) $result->get_error_code(), $result->get_error_message() );
		}

		return array(
			'ok'      => true,
			'code'    => 'ok',
			'message' => $this->result_message( $action, $request, $result ),
			'id'      => is_int( $result ) ? $result : null,
		);
	}

	/**
	 * Permission check for the action's scope.
	 *
	 * @param string $scope   Scope.
	 * @param array  $request Request.
	 */
	private function is_allowed( string $scope, array $request ): bool {
		$permissions = DFXPRAY_Permissions::get_instance();

		if ( ! $permissions->can_access_panel() ) {
			return false;
		}

		switch ( $scope ) {
			case 'self':
				return true;

			case 'admin':
				return $permissions->is_admin();

			case 'group':
				return $permissions->can_manage_group( (int) ( $request['group_id'] ?? 0 ) );

			case 'intention_or_group':
				if ( empty( $request['intention_id'] ) ) {
					return $permissions->can_manage_group( (int) ( $request['group_id'] ?? 0 ) );
				}
				return $this->can_manage_intention( (int) $request['intention_id'] );

			case 'member_or_group':
				if ( empty( $request['member_id'] ) ) {
					return $permissions->can_manage_group( (int) ( $request['group_id'] ?? 0 ) );
				}
				$member = DFXPRAY_Members::get_instance()->get( (int) $request['member_id'] );
				return $member && $permissions->can_manage_group( $member['group_id'] );

			case 'invitation':
				$invitation = DFXPRAY_Invitations::get_instance()->get( (int) ( $request['invitation_id'] ?? 0 ) );
				return $invitation && $permissions->can_manage_group( $invitation['group_id'] );

			case 'comment':
				$comment = DFXPRAY_Comments::get_instance()->get( (int) ( $request['comment_id'] ?? 0 ) );
				return $comment && $this->can_manage_intention( $comment['intention_id'] );

			default:
				if ( ! $this->can_manage_intention( (int) ( $request['intention_id'] ?? 0 ) ) ) {
					return false;
				}
				// Merging reaches a second intention, which must be manageable too.
				return ! isset( $request['target_id'] ) || $this->can_manage_intention( (int) $request['target_id'] );
		}
	}

	/**
	 * Whether the current user manages the intention's group.
	 *
	 * @param int $id Intention ID.
	 */
	public function can_manage_intention( int $id ): bool {
		$intention = DFXPRAY_Intentions::get_instance()->get( $id );

		return $intention && DFXPRAY_Permissions::get_instance()->can_manage_group( $intention['group_id'] );
	}

	/**
	 * Approve.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_approve( array $r ) {
		$data = array_intersect_key( $r, array_flip( array( 'summary', 'discretion', 'priority', 'requester_display_name', 'beneficiary_display_name' ) ) );

		return DFXPRAY_Intentions::get_instance()->approve( (int) $r['intention_id'], $data );
	}

	/**
	 * Discard.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_discard( array $r ) {
		return DFXPRAY_Intentions::get_instance()->discard( (int) $r['intention_id'], (string) ( $r['reason'] ?? 'spam' ) );
	}

	/**
	 * Merge a duplicate.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_merge( array $r ) {
		return DFXPRAY_Intentions::get_instance()->merge( (int) $r['intention_id'], (int) ( $r['target_id'] ?? 0 ) );
	}

	/**
	 * Create (manual) or edit an intention.
	 *
	 * @param array $r Request.
	 * @return true|int|WP_Error
	 */
	private function do_save_intention( array $r ) {
		$data = (array) ( $r['intention'] ?? array() );

		if ( isset( $r['key_dates'] ) && is_array( $r['key_dates'] ) ) {
			$data['key_dates'] = $this->key_dates_from_columns( $r['key_dates'] );
		}

		foreach ( array( 'beneficiary_is_requester', 'anniversary', 'requester_is_member', 'beneficiary_is_member', 'to_ask' ) as $flag ) {
			if ( isset( $r['intention'] ) && ! isset( $data[ $flag ] ) && isset( $r[ 'has_' . $flag ] ) ) {
				$data[ $flag ] = false;
			}
		}

		$intentions = DFXPRAY_Intentions::get_instance();

		if ( empty( $r['intention_id'] ) ) {
			return $intentions->create( (int) $r['group_id'], $data, 'manual' );
		}

		return $intentions->update( (int) $r['intention_id'], $data );
	}

	/**
	 * Add or edit a brother of the group. When editing, the group is always
	 * the member's own, whatever the request says.
	 *
	 * @param array $r Request.
	 * @return int|true|WP_Error
	 */
	private function do_save_member( array $r ) {
		$data    = (array) ( $r['member'] ?? array() );
		$members = DFXPRAY_Members::get_instance();

		if ( empty( $r['member_id'] ) ) {
			return $members->create( (int) $r['group_id'], $data );
		}

		return $members->update( (int) $r['member_id'], $data );
	}

	/**
	 * Delete a brother of the group.
	 *
	 * @param array $r Request.
	 * @return true
	 */
	private function do_delete_member( array $r ): bool {
		DFXPRAY_Members::get_instance()->delete( (int) $r['member_id'] );

		return true;
	}

	/**
	 * Add a comment.
	 *
	 * @param array $r Request.
	 * @return int|WP_Error
	 */
	private function do_add_comment( array $r ) {
		return DFXPRAY_Comments::get_instance()->add( (int) $r['intention_id'], (string) ( $r['body'] ?? '' ), (string) ( $r['type'] ?? '' ) );
	}

	/**
	 * Edit a comment.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_edit_comment( array $r ) {
		return DFXPRAY_Comments::get_instance()->update( (int) $r['comment_id'], (string) ( $r['body'] ?? '' ), (string) ( $r['type'] ?? '' ) );
	}

	/**
	 * Delete a comment.
	 *
	 * @param array $r Request.
	 * @return true
	 */
	private function do_delete_comment( array $r ): bool {
		DFXPRAY_Comments::get_instance()->delete( (int) $r['comment_id'] );

		return true;
	}

	/**
	 * Turn a comment into an update.
	 *
	 * @param array $r Request.
	 * @return int|WP_Error
	 */
	private function do_convert_comment( array $r ) {
		return DFXPRAY_Comments::get_instance()->convert_to_update( (int) $r['comment_id'], ! empty( $r['is_news'] ) );
	}

	/**
	 * Add an update.
	 *
	 * @param array $r Request.
	 * @return int|WP_Error
	 */
	private function do_add_update( array $r ) {
		return DFXPRAY_Updates::get_instance()->add( (int) $r['intention_id'], (string) ( $r['body'] ?? '' ), ! empty( $r['is_news'] ) );
	}

	/**
	 * Set priority.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_set_priority( array $r ) {
		return DFXPRAY_Intentions::get_instance()->set_priority( (int) $r['intention_id'], (string) ( $r['priority'] ?? '' ) );
	}

	/**
	 * Postpone the review.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_postpone( array $r ) {
		return DFXPRAY_Intentions::get_instance()->postpone( (int) $r['intention_id'], (string) ( $r['date'] ?? '' ) );
	}

	/**
	 * Mark the acknowledgement as sent.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_ack_sent( array $r ) {
		return DFXPRAY_Intentions::get_instance()->mark_ack_sent( (int) $r['intention_id'] );
	}

	/**
	 * Move to another group.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_transfer( array $r ) {
		return DFXPRAY_Intentions::get_instance()->transfer( (int) $r['intention_id'], (int) ( $r['group_id'] ?? 0 ) );
	}

	/**
	 * Still current.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_keep_active( array $r ) {
		return DFXPRAY_Intentions::get_instance()->keep_active( (int) $r['intention_id'] );
	}

	/**
	 * Close.
	 *
	 * @param array $r Request.
	 * @return true|int|WP_Error
	 */
	private function do_close( array $r ) {
		return DFXPRAY_Intentions::get_instance()->close(
			(int) $r['intention_id'],
			(string) ( $r['reason'] ?? '' ),
			! empty( $r['thanksgiving'] ),
			(string) ( $r['thanksgiving_summary'] ?? '' )
		);
	}

	/**
	 * Reopen a closed intention.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_reopen( array $r ) {
		return DFXPRAY_Intentions::get_instance()->reopen( (int) $r['intention_id'] );
	}

	/**
	 * Save or reset the group's settings.
	 *
	 * @param array $r Request.
	 * @return true
	 */
	private function do_save_group_settings( array $r ): bool {
		$settings = DFXPRAY_Settings::get_instance();

		if ( ! empty( $r['reset'] ) ) {
			$settings->reset_group( (int) $r['group_id'] );
		} else {
			$settings->save_group( (int) $r['group_id'], (array) ( $r['settings'] ?? array() ) );
		}

		DFXPRAY_Log::get_instance()->add( 'group_settings_saved', (int) $r['group_id'] );

		return true;
	}

	/**
	 * New public URL.
	 *
	 * @param array $r Request.
	 * @return true
	 */
	private function do_regenerate_slug( array $r ): bool {
		DFXPRAY_Groups::get_instance()->regenerate_slug( (int) $r['group_id'] );

		return true;
	}

	/**
	 * Send a test through a channel.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_test_channel( array $r ) {
		return DFXPRAY_Channels::get_instance()->send_test( (int) $r['group_id'], sanitize_key( (string) ( $r['channel'] ?? '' ) ) );
	}

	/**
	 * Generate and send the digest now.
	 *
	 * @param array $r Request.
	 * @return true
	 */
	private function do_send_digest_now( array $r ): bool {
		DFXPRAY_Scheduler::get_instance()->maybe_send_digest( (int) $r['group_id'], true );

		return true;
	}

	/**
	 * Mark today's message as sent to the group, or undo it.
	 *
	 * @param array $r Request.
	 */
	private function do_mark_shared( array $r ): bool {
		$shares = DFXPRAY_Shares::get_instance();
		if ( ! empty( $r['undo'] ) ) {
			$shares->undo( (int) $r['group_id'] );
		} else {
			$shares->mark( (int) $r['group_id'] );
		}

		return true;
	}

	/**
	 * Invite someone to lead the group.
	 *
	 * @param array $r Request.
	 * @return int|WP_Error
	 */
	private function do_invite_leader( array $r ) {
		return DFXPRAY_Invitations::get_instance()->invite( (int) $r['group_id'], (string) ( $r['name'] ?? '' ), (string) ( $r['email'] ?? '' ) );
	}

	/**
	 * Withdraw a pending invitation. Its group is its own, never the request's.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_withdraw_invitation( array $r ) {
		return DFXPRAY_Invitations::get_instance()->withdraw( (int) $r['invitation_id'] );
	}

	/**
	 * Invite someone to lead any group, from Manage groups.
	 *
	 * @param array $r Request.
	 * @return int|true|WP_Error
	 */
	private function do_admin_invite_leader( array $r ) {
		return $this->do_invite_leader( $r );
	}

	/**
	 * Withdraw a pending invitation of any group, from Manage groups.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_admin_withdraw_invitation( array $r ) {
		return $this->do_withdraw_invitation( $r );
	}

	/**
	 * Create or edit a group, with its leaders.
	 *
	 * @param array $r Request.
	 * @return true|int|WP_Error
	 */
	private function do_save_group( array $r ) {
		$groups = DFXPRAY_Groups::get_instance();
		$id     = (int) ( $r['group_id'] ?? 0 );
		$data   = array(
			'name'        => (string) ( $r['name'] ?? '' ),
			'description' => (string) ( $r['description'] ?? '' ),
		);

		if ( $id ) {
			if ( ! $groups->get( $id ) ) {
				return new WP_Error( 'dfxpray_not_found', __( 'The group does not exist.', 'dfx-prayer-intentions' ) );
			}
			$result = $groups->update( $id, $data );
		} else {
			$result = $groups->create( $data );
			$id     = is_int( $result ) ? $result : 0;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$groups->set_leaders( $id, array_map( 'intval', (array) ( $r['leaders'] ?? array() ) ) );

		if ( isset( $r['privacy'] ) ) {
			DFXPRAY_Settings::get_instance()->save_group_privacy( $id, (array) $r['privacy'] );
		}

		return $id;
	}

	/**
	 * Archive a group.
	 *
	 * @param array $r Request.
	 * @return true
	 */
	private function do_archive_group( array $r ): bool {
		DFXPRAY_Groups::get_instance()->archive( (int) $r['group_id'] );

		return true;
	}

	/**
	 * Restore a group.
	 *
	 * @param array $r Request.
	 * @return true
	 */
	private function do_restore_group( array $r ): bool {
		DFXPRAY_Groups::get_instance()->restore( (int) $r['group_id'] );

		return true;
	}

	/**
	 * Delete a group and all its data.
	 *
	 * @param array $r Request.
	 * @return true
	 */
	private function do_delete_group( array $r ): bool {
		DFXPRAY_Groups::get_instance()->delete( (int) $r['group_id'] );

		return true;
	}

	/**
	 * Erase everything kept about a contact (email or phone): their requests
	 * and their member records.
	 *
	 * @param array $r Request.
	 * @return true|WP_Error
	 */
	private function do_privacy_erase( array $r ) {
		$contact = sanitize_text_field( (string) ( $r['contact'] ?? '' ) );
		if ( '' === trim( $contact ) ) {
			return new WP_Error( 'dfxpray_privacy_contact', __( 'Write an email or a phone.', 'dfx-prayer-intentions' ) );
		}

		$privacy = DFXPRAY_Privacy::get_instance();
		do {
			$result = $privacy->erase( $contact, 1 );
		} while ( ! $result['done'] );

		DFXPRAY_Log::get_instance()->add( 'privacy_erased' );

		return true;
	}

	/**
	 * The current user acknowledges their duty of confidentiality.
	 *
	 * @return true
	 */
	private function do_ack_confidentiality(): bool {
		update_user_meta( get_current_user_id(), DFXPRAY_Admin::CONFIDENTIALITY_META, DFXPRAY_Clock::get_instance()->mysql() );

		return true;
	}

	/**
	 * Save global settings.
	 *
	 * @param array $r Request.
	 * @return true
	 */
	private function do_save_global_settings( array $r ): bool {
		DFXPRAY_Settings::get_instance()->save_global( (array) ( $r['settings'] ?? array() ) );

		if ( isset( $r['panel_roles'] ) || isset( $r['has_panel_roles'] ) ) {
			DFXPRAY_Permissions::get_instance()->set_panel_roles( array_map( 'sanitize_key', (array) ( $r['panel_roles'] ?? array() ) ) );
		}

		DFXPRAY_Log::get_instance()->add( 'global_settings_saved' );

		return true;
	}

	/**
	 * Key dates arrive as three parallel columns from the form.
	 *
	 * @param array $columns date[], time[], label[].
	 * @return array[]
	 */
	private function key_dates_from_columns( array $columns ): array {
		$dates = array();

		foreach ( (array) ( $columns['date'] ?? array() ) as $i => $date ) {
			$dates[] = array(
				'date'  => (string) $date,
				'time'  => (string) ( $columns['time'][ $i ] ?? '' ),
				'label' => (string) ( $columns['label'][ $i ] ?? '' ),
			);
		}

		return $dates;
	}

	/**
	 * A failure result.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private function failure( string $code, string $message ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'id'      => null,
		);
	}

	/**
	 * Message after a successful action, for the request it answered.
	 *
	 * @param string $action  Action.
	 * @param array  $request Request.
	 * @param mixed  $result  What the action returned.
	 */
	private function result_message( string $action, array $request, $result ): string {
		if ( 'close' === $action && is_int( $result ) ) {
			return __( 'Intention closed. This is its thanksgiving: you can edit it here.', 'dfx-prayer-intentions' );
		}
		if ( 'mark_shared' === $action && ! empty( $request['undo'] ) ) {
			return __( 'Today’s message is no longer marked as sent.', 'dfx-prayer-intentions' );
		}

		return $this->success_message( $action );
	}

	/**
	 * Message after a successful action.
	 *
	 * @param string $action Action.
	 */
	private function success_message( string $action ): string {
		$messages = array(
			'approve'                   => __( 'Intention approved.', 'dfx-prayer-intentions' ),
			'discard'                   => __( 'Intention discarded.', 'dfx-prayer-intentions' ),
			'merge'                     => __( 'Duplicate merged.', 'dfx-prayer-intentions' ),
			'save_intention'            => __( 'Intention saved.', 'dfx-prayer-intentions' ),
			'add_comment'               => __( 'Comment added.', 'dfx-prayer-intentions' ),
			'edit_comment'              => __( 'Comment updated.', 'dfx-prayer-intentions' ),
			'delete_comment'            => __( 'Comment deleted.', 'dfx-prayer-intentions' ),
			'convert_comment'           => __( 'Comment turned into an update.', 'dfx-prayer-intentions' ),
			'add_update'                => __( 'Update added.', 'dfx-prayer-intentions' ),
			'set_priority'              => __( 'Priority changed.', 'dfx-prayer-intentions' ),
			'postpone'                  => __( 'Review postponed.', 'dfx-prayer-intentions' ),
			'ack_sent'                  => __( 'Acknowledgement marked as sent.', 'dfx-prayer-intentions' ),
			'transfer'                  => __( 'Intention moved to the other group.', 'dfx-prayer-intentions' ),
			'keep_active'               => __( 'Intention kept active.', 'dfx-prayer-intentions' ),
			'close'                     => __( 'Intention closed.', 'dfx-prayer-intentions' ),
			'reopen'                    => __( 'Intention reopened.', 'dfx-prayer-intentions' ),
			'save_member'               => __( 'Member saved.', 'dfx-prayer-intentions' ),
			'delete_member'             => __( 'Member deleted.', 'dfx-prayer-intentions' ),
			'save_group_settings'       => __( 'Settings saved.', 'dfx-prayer-intentions' ),
			'regenerate_slug'           => __( 'The form has a new address. The old one no longer works.', 'dfx-prayer-intentions' ),
			'test_channel'              => __( 'Test message sent.', 'dfx-prayer-intentions' ),
			'send_digest_now'           => __( 'Digest generated and sent.', 'dfx-prayer-intentions' ),
			'mark_shared'               => __( 'Today’s message is marked as sent to the group.', 'dfx-prayer-intentions' ),
			'invite_leader'             => __( 'Invitation sent.', 'dfx-prayer-intentions' ),
			'withdraw_invitation'       => __( 'Invitation withdrawn.', 'dfx-prayer-intentions' ),
			'save_group'                => __( 'Group saved.', 'dfx-prayer-intentions' ),
			'archive_group'             => __( 'Group archived.', 'dfx-prayer-intentions' ),
			'restore_group'             => __( 'Group restored.', 'dfx-prayer-intentions' ),
			'delete_group'              => __( 'Group deleted.', 'dfx-prayer-intentions' ),
			'save_global_settings'      => __( 'Settings saved.', 'dfx-prayer-intentions' ),
			'privacy_erase'             => __( 'Personal data erased.', 'dfx-prayer-intentions' ),
			'admin_invite_leader'       => __( 'Invitation sent.', 'dfx-prayer-intentions' ),
			'admin_withdraw_invitation' => __( 'Invitation withdrawn.', 'dfx-prayer-intentions' ),
			'ack_confidentiality'       => __( 'Thank you.', 'dfx-prayer-intentions' ),
		);

		return $messages[ $action ] ?? __( 'Done.', 'dfx-prayer-intentions' );
	}
}
