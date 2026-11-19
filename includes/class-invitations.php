<?php
/**
 * Invitations to lead a group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A leader of a group (or an administrator) invites someone by name and
 * email to lead that group. The invitee gets a link, valid for seven days, to
 * a page on wp-login.php where they accept it with a button: a GET never
 * accepts, so email scanners cannot. On acceptance they become a leader at
 * once (with an account and the leader role if they had none) and the site's
 * administrator is told. Only the hash of the token is stored.
 */
final class DFXPRAY_Invitations {

	use DFXPRAY_Singleton;

	/**
	 * Days an invitation stays valid, and days an accepted one is kept.
	 */
	public const DAYS = 7;

	/**
	 * The wp-login.php action of the accept page.
	 */
	public const LOGIN_ACTION = 'dfxpray_invitation';

	/**
	 * Nonce action of the accept form.
	 */
	private const NONCE = 'dfxpray_accept_invitation';

	/**
	 * Hook the accept page into wp-login.php.
	 */
	public function register_hooks(): void {
		add_action( 'login_form_' . self::LOGIN_ACTION, array( $this, 'handle_login_action' ) );
	}

	/**
	 * Invite someone to lead a group, or send a pending invitation to the
	 * same email again with a new link and a new expiry.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $name     Name of the person invited.
	 * @param string $email    Their email.
	 * @return int|true|WP_Error Invitation ID; true when the person already
	 *                           leads the group (nothing is stored).
	 */
	public function invite( int $group_id, string $name, string $email ) {
		global $wpdb;

		$group = DFXPRAY_Groups::get_instance()->get( $group_id );
		if ( ! $group || 'active' !== $group['status'] ) {
			return new WP_Error( 'dfxpray_invitation_group', __( 'Invitations can only be sent for an active group.', 'dfx-prayer-intentions' ) );
		}

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'dfxpray_invitation_name', __( 'Write the name of the person you invite.', 'dfx-prayer-intentions' ) );
		}

		$email = strtolower( sanitize_email( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'dfxpray_invitation_email', __( 'The email address is not valid.', 'dfx-prayer-intentions' ) );
		}

		// Whoever invites never learns whether the email has an account, nor
		// whether it already leads the group: the answer is the same. Only the
		// person invited is told, by email, that nothing changes.
		$user = get_user_by( 'email', $email );
		if ( $user && in_array( $user->ID, DFXPRAY_Groups::get_instance()->get_leaders( $group_id ), true ) ) {
			$this->send_already_leader( $user, $group );
			return true;
		}

		$clock = DFXPRAY_Clock::get_instance();
		$token = wp_generate_password( 32, false );
		$data  = array(
			'name'       => $name,
			'token_hash' => $this->hash( $token ),
			'invited_by' => get_current_user_id(),
			'created_at' => $clock->mysql(),
			'expires_at' => $clock->now()->modify( '+' . self::DAYS . ' days' )->format( 'Y-m-d H:i:s' ),
		);

		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE group_id = %d AND email = %s AND accepted_at IS NULL ORDER BY id DESC LIMIT 1', $this->table(), $group_id, $email ) ); // phpcs:ignore WordPress.DB

		if ( $existing ) {
			$wpdb->update( $this->table(), $data, array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = $existing;
		} else {
			$data['group_id'] = $group_id;
			$data['email']    = $email;
			$wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $wpdb->insert_id;
		}

		$this->send_invitation( $this->get( $id ), $group, $token );
		DFXPRAY_Log::get_instance()->add( 'invitation_sent', $group_id, null, array( 'invitation' => $id ) );

		return $id;
	}

	/**
	 * Get an invitation.
	 *
	 * @param int $id Invitation ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Pending, unexpired invitations of a group, oldest first.
	 *
	 * @param int $group_id Group ID.
	 * @return array[]
	 */
	public function for_group( int $group_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE group_id = %d AND accepted_at IS NULL AND expires_at > %s ORDER BY created_at ASC, id ASC', $this->table(), $group_id, DFXPRAY_Clock::get_instance()->mysql() ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Withdraw a pending invitation: it is deleted and its link stops working.
	 *
	 * @param int $id Invitation ID.
	 * @return true|WP_Error
	 */
	public function withdraw( int $id ) {
		global $wpdb;

		$invitation = $this->get( $id );
		if ( ! $invitation || null !== $invitation['accepted_at'] ) {
			return new WP_Error( 'dfxpray_invitation_not_found', __( 'This invitation is no longer pending.', 'dfx-prayer-intentions' ) );
		}

		$wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		DFXPRAY_Log::get_instance()->add( 'invitation_withdrawn', $invitation['group_id'], null, array( 'invitation' => $id ) );

		return true;
	}

	/**
	 * The invitation a token opens, if it can still be accepted: pending,
	 * unexpired and for an active group.
	 *
	 * @param string $token Token from the link.
	 * @return array|null
	 */
	public function find_valid( string $token ): ?array {
		global $wpdb;

		if ( '' === $token ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s AND accepted_at IS NULL AND expires_at > %s', $this->table(), $this->hash( $token ), DFXPRAY_Clock::get_instance()->mysql() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}

		$invitation = $this->hydrate( $row );
		$group      = DFXPRAY_Groups::get_instance()->get( $invitation['group_id'] );

		return $group && 'active' === $group['status'] ? $invitation : null;
	}

	/**
	 * Accept an invitation: the person becomes a leader of the group, with a
	 * new account if they had none.
	 *
	 * @param string $token Token from the link.
	 * @return array{user_id:int, created:bool, redirect:string}|WP_Error
	 */
	public function accept( string $token ) {
		global $wpdb;

		$invitation = $this->find_valid( $token );
		$invalid    = new WP_Error( 'dfxpray_invitation_invalid', $this->invalid_message() );
		if ( ! $invitation ) {
			return $invalid;
		}

		// Claim it first, so that the same link cannot be used twice at once.
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET accepted_at = %s WHERE id = %d AND accepted_at IS NULL', $this->table(), DFXPRAY_Clock::get_instance()->mysql(), $invitation['id'] ) ); // phpcs:ignore WordPress.DB
		if ( 1 !== (int) $claimed ) {
			return $invalid;
		}

		$user    = get_user_by( 'email', $invitation['email'] );
		$created = false;

		if ( ! $user ) {
			$user_id = $this->create_user( $invitation );
			if ( is_wp_error( $user_id ) ) {
				$wpdb->update( $this->table(), array( 'accepted_at' => null ), array( 'id' => $invitation['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $user_id;
			}
			$user    = get_user_by( 'id', $user_id );
			$created = true;
		} elseif ( ! DFXPRAY_Permissions::get_instance()->can_access_panel( $user->ID ) ) {
			$user->add_role( DFXPRAY_Installer::ROLE );
		}

		DFXPRAY_Groups::get_instance()->add_leader( $invitation['group_id'], $user->ID );
		$wpdb->update( $this->table(), array( 'accepted_user_id' => $user->ID ), array( 'id' => $invitation['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		DFXPRAY_Log::get_instance()->add(
			'invitation_accepted',
			$invitation['group_id'],
			null,
			array(
				'invitation' => $invitation['id'],
				'invited_by' => $invitation['invited_by'],
			),
			$user->ID
		);
		$this->notify_admin( $invitation, $user );

		return array(
			'user_id'  => $user->ID,
			'created'  => $created,
			'redirect' => $this->redirect_after_accepting( $user, $created, $invitation['group_id'] ),
		);
	}

	/**
	 * Daily: delete expired invitations, and accepted ones after seven days.
	 *
	 * @return int How many were deleted.
	 */
	public function cleanup(): int {
		global $wpdb;

		$now = DFXPRAY_Clock::get_instance()->now();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'DELETE FROM %i WHERE ( accepted_at IS NULL AND expires_at <= %s ) OR ( accepted_at IS NOT NULL AND accepted_at < %s )',
				$this->table(),
				$now->format( 'Y-m-d H:i:s' ),
				$now->modify( '-' . self::DAYS . ' days' )->format( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Delete every invitation of a group.
	 *
	 * @param int $group_id Group ID.
	 */
	public function delete_for_group( int $group_id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'group_id' => $group_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Invitations sent to an email, for the privacy tools.
	 *
	 * @param string $email Email.
	 * @return array[]
	 */
	public function find_by_email( string $email ): array {
		global $wpdb;

		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return array();
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id', $this->table(), $email ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Delete the invitations sent to an email.
	 *
	 * @param string $email Email.
	 * @return int How many were deleted.
	 */
	public function erase_by_email( string $email ): int {
		global $wpdb;

		$found = $this->find_by_email( $email );
		foreach ( $found as $invitation ) {
			$wpdb->delete( $this->table(), array( 'id' => $invitation['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return count( $found );
	}

	/**
	 * The accept page, on wp-login.php. A GET only shows the invitation; the
	 * button posts the token with a nonce, and only that accepts it.
	 */
	public function handle_login_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token is the credential; acceptance checks a nonce below.
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		$error = '';

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( ! wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '', self::NONCE ) ) {
				$error = __( 'The link has expired. Please try again.', 'dfx-prayer-intentions' );
			} else {
				$result = $this->accept( $token );
				if ( ! is_wp_error( $result ) ) {
					wp_safe_redirect( $result['redirect'] );
					exit;
				}
				$error = $result->get_error_message();
			}
		}

		login_header( __( 'Invitation to lead a prayer group', 'dfx-prayer-intentions' ) );
		echo $this->page_body( $token, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page_body().
		login_footer();
		exit;
	}

	/**
	 * Body of the accept page: the invitation and its button, or why it
	 * cannot be accepted.
	 *
	 * @param string $token Token from the link.
	 * @param string $error Error to show above it.
	 */
	public function page_body( string $token, string $error = '' ): string {
		$invitation = $this->find_valid( $token );
		$html       = '' !== $error ? '<div id="login_error" class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>' : '';

		if ( ! $invitation ) {
			return ( $error !== $this->invalid_message() ? $html : '' ) . '<p class="message dfxpray-invitation-invalid">' . esc_html( $this->invalid_message() ) . '</p>';
		}

		$group = DFXPRAY_Groups::get_instance()->get( $invitation['group_id'] );

		$html .= '<div class="message dfxpray-invitation">';
		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: who invites, 2: group name, 3: site name. */
				__( '%1$s invites you to be a leader of the prayer group “%2$s” on %3$s.', 'dfx-prayer-intentions' ),
				$this->inviter_name( $invitation ),
				$group['name'],
				$this->site_name()
			)
		) . '</p>';
		$html .= '<p>' . esc_html( $this->confidentiality_sentence() ) . '</p>';
		$html .= '</div>';
		$html .= '<form method="post" action="' . esc_url( $this->page_url() ) . '" class="dfxpray-invitation-accept">';
		$html .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		$html .= wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		$html .= '<p class="submit"><button type="submit" class="button button-primary button-large">' . esc_html__( 'Accept the invitation', 'dfx-prayer-intentions' ) . '</button></p>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * The link in the email.
	 *
	 * @param string $token Token.
	 */
	public function accept_url( string $token ): string {
		return add_query_arg( 'token', rawurlencode( $token ), $this->page_url() );
	}

	/**
	 * Address of the accept page, without the token.
	 */
	private function page_url(): string {
		return site_url( 'wp-login.php?action=' . self::LOGIN_ACTION, 'login_post' );
	}

	/**
	 * Mail the invitation. The token only travels in the link.
	 *
	 * @param array  $invitation Invitation.
	 * @param array  $group      Group.
	 * @param string $token      Token.
	 */
	private function send_invitation( array $invitation, array $group, string $token ): void {
		$body = implode(
			"\n\n",
			array(
				sprintf(
					/* translators: 1: name of the person invited, 2: who invites, 3: group name, 4: site name, 5: site address. */
					__( 'Hello, %1$s: %2$s invites you to be a leader of the prayer group “%3$s” on %4$s (%5$s).', 'dfx-prayer-intentions' ),
					$invitation['name'],
					$this->inviter_name( $invitation ),
					$group['name'],
					$this->site_name(),
					home_url( '/' )
				),
				$this->confidentiality_sentence(),
				__( 'To accept, open this link:', 'dfx-prayer-intentions' ) . "\n" . $this->accept_url( $token ),
				/* translators: %s: date. */
				sprintf( __( 'The invitation expires on %s.', 'dfx-prayer-intentions' ), $this->expiry_date( $invitation ) ),
				__( 'If you did not expect this invitation, ignore this email.', 'dfx-prayer-intentions' ),
			)
		);

		/* translators: %s: group name. */
		wp_mail( $invitation['email'], sprintf( __( 'Invitation to lead %s', 'dfx-prayer-intentions' ), $group['name'] ), $body );
	}

	/**
	 * Tell someone invited to a group they already lead that nothing changes.
	 * Service accounts get no email.
	 *
	 * @param WP_User $user  Leader.
	 * @param array   $group Group.
	 */
	private function send_already_leader( WP_User $user, array $group ): void {
		if ( DFXPRAY_Service_Accounts::get_instance()->is_service_account( $user->ID ) ) {
			return;
		}

		$inviter = wp_get_current_user();
		$body    = implode(
			"\n\n",
			array(
				sprintf(
					/* translators: 1: who invites, 2: group name, 3: site name. */
					__( '%1$s invited you to be a leader of the prayer group “%2$s” on %3$s, but you are already a leader of it. Nothing changes.', 'dfx-prayer-intentions' ),
					$inviter->exists() ? $inviter->display_name : $this->site_name(),
					$group['name'],
					$this->site_name()
				),
				__( 'If you did not expect this invitation, ignore this email.', 'dfx-prayer-intentions' ),
			)
		);

		/* translators: %s: group name. */
		wp_mail( $user->user_email, sprintf( __( 'Invitation to lead %s', 'dfx-prayer-intentions' ), $group['name'] ), $body );
	}

	/**
	 * Tell the site's administrator about a new leader.
	 *
	 * @param array   $invitation Invitation.
	 * @param WP_User $user       New leader.
	 */
	private function notify_admin( array $invitation, WP_User $user ): void {
		$group = DFXPRAY_Groups::get_instance()->get( $invitation['group_id'] );
		$body  = implode(
			"\n\n",
			array(
				sprintf(
					/* translators: 1: name of the new leader, 2: their email, 3: who invited them, 4: group name, 5: site name. */
					__( '%1$s (%2$s) accepted the invitation of %3$s and is now a leader of the prayer group “%4$s” on %5$s.', 'dfx-prayer-intentions' ),
					$invitation['name'],
					$user->user_email,
					$this->inviter_name( $invitation ),
					$group['name'],
					$this->site_name()
				),
				/* translators: %s: address of the groups page. */
				sprintf( __( 'Only administrators can remove leaders, in %s', 'dfx-prayer-intentions' ), DFXPRAY_Admin::get_instance()->url( 'dfxpray' ) ),
			)
		);

		/* translators: %s: group name. */
		wp_mail( (string) get_option( 'admin_email' ), sprintf( __( 'New leader in %s', 'dfx-prayer-intentions' ), $group['name'] ), $body );
	}

	/**
	 * Create the account of someone who accepted an invitation.
	 *
	 * @param array $invitation Invitation.
	 * @return int|WP_Error User ID.
	 */
	private function create_user( array $invitation ) {
		$base  = substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) strstr( $invitation['email'], '@', true ) ) ), 0, 50 );
		$base  = '' !== $base ? $base : 'leader';
		$login = $base;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . ( $i++ );
		}

		$parts = preg_split( '/\s+/', trim( $invitation['name'] ), 2 );

		return wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $invitation['email'],
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $invitation['name'],
				'nickname'     => $invitation['name'],
				'first_name'   => $parts[0] ?? '',
				'last_name'    => $parts[1] ?? '',
				'role'         => DFXPRAY_Installer::ROLE,
			)
		);
	}

	/**
	 * Where someone goes after accepting: a new account chooses its password;
	 * an existing one goes to the group, through the login if needed.
	 *
	 * @param WP_User $user     User.
	 * @param bool    $created  Whether the account was just created.
	 * @param int     $group_id Group ID.
	 */
	private function redirect_after_accepting( WP_User $user, bool $created, int $group_id ): string {
		$group_url = DFXPRAY_Admin::get_instance()->group_url( $group_id );

		if ( $created ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				return network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
			}
		}

		return get_current_user_id() === $user->ID ? $group_url : wp_login_url( $group_url );
	}

	/**
	 * The sentence about what leaders see and their duty of confidentiality.
	 */
	private function confidentiality_sentence(): string {
		return __( 'Leaders see very sensitive information: illnesses, deaths, family situations. If you accept, you owe confidentiality about everything you read, also when you stop being a leader.', 'dfx-prayer-intentions' );
	}

	/**
	 * Message for a link that cannot be accepted.
	 */
	private function invalid_message(): string {
		return __( 'This invitation is not valid or has expired. Ask whoever invited you for a new one.', 'dfx-prayer-intentions' );
	}

	/**
	 * Name of who sent an invitation; the site's when unknown.
	 *
	 * @param array $invitation Invitation.
	 */
	private function inviter_name( array $invitation ): string {
		$user = $invitation['invited_by'] ? get_userdata( $invitation['invited_by'] ) : null;

		return $user ? $user->display_name : $this->site_name();
	}

	/**
	 * Expiry date of an invitation, in words.
	 *
	 * @param array $invitation Invitation.
	 */
	public function expiry_date( array $invitation ): string {
		return DFXPRAY_Formatter::get_instance()->long_date( substr( $invitation['expires_at'], 0, 10 ) );
	}

	/**
	 * Site name, as plain text.
	 */
	private function site_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Hash of a token, as stored.
	 *
	 * @param string $token Token.
	 */
	private function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Cast a database row. The token hash stays out.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'               => (int) $row['id'],
			'group_id'         => (int) $row['group_id'],
			'name'             => $row['name'],
			'email'            => $row['email'],
			'invited_by'       => (int) $row['invited_by'],
			'created_at'       => $row['created_at'],
			'expires_at'       => $row['expires_at'],
			'accepted_at'      => $row['accepted_at'],
			'accepted_user_id' => null === $row['accepted_user_id'] ? null : (int) $row['accepted_user_id'],
		);
	}

	/**
	 * Table name.
	 */
	private function table(): string {
		return DFXPRAY_Installer::get_instance()->get_table_names()['invitations'];
	}
}
