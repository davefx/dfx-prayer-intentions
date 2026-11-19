<?php
/**
 * Group settings: public URL, deadlines, templates, schedules and channels.
 *
 * @var int   $group_id Active group.
 * @var array $group    Active group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin       = DFXPRAY_Admin::get_instance();
$dfxpray_settings    = DFXPRAY_Settings::get_instance();
$dfxpray_values      = $dfxpray_settings->all( $group_id );
$dfxpray_channels    = $dfxpray_values['channels'];
$dfxpray_url         = home_url( '/' . $group['slug'] . '/' );
$dfxpray_variants    = array(
	'shareable' => __( 'Shareable version (discretion applied)', 'dfx-prayer-intentions' ),
	'leaders'   => __( 'Leaders’ version (only for a private chat of leaders)', 'dfx-prayer-intentions' ),
);
$dfxpray_flags       = array(
	'digest'      => __( 'Daily digest', 'dfx-prayer-intentions' ),
	'alerts'      => __( 'Hour alerts', 'dfx-prayer-intentions' ),
	'new_request' => __( 'New requests (without the text, except by email)', 'dfx-prayer-intentions' ),
);
$dfxpray_invitations = DFXPRAY_Invitations::get_instance();
$dfxpray_accounts    = DFXPRAY_Service_Accounts::get_instance();
?>
<?php
// Leaders see only the names of their co-leaders; administrators, their emails too.
$dfxpray_is_admin = DFXPRAY_Permissions::get_instance()->is_admin();
?>
<section class="dfxpray-leaders">
	<h2><?php esc_html_e( 'Leaders', 'dfx-prayer-intentions' ); ?></h2>
	<ul class="dfxpray-leader-list">
		<?php foreach ( DFXPRAY_Groups::get_instance()->get_leaders( $group_id ) as $dfxpray_leader_id ) : ?>
			<?php
			$dfxpray_leader = get_userdata( $dfxpray_leader_id );
			if ( ! $dfxpray_leader ) {
				continue;
			}
			?>
			<li><?php echo esc_html( $dfxpray_leader->display_name . ( $dfxpray_is_admin ? ' (' . $dfxpray_leader->user_email . ')' : '' ) ); ?><?php echo $dfxpray_accounts->is_service_account( $dfxpray_leader->ID ) ? ' · ' . esc_html__( 'service account', 'dfx-prayer-intentions' ) : ''; ?></li>
		<?php endforeach; ?>
	</ul>
	<p class="description"><?php esc_html_e( 'Only the administrator of the site can remove leaders.', 'dfx-prayer-intentions' ); ?></p>

	<?php $dfxpray_pending = $dfxpray_invitations->for_group( $group_id ); ?>
	<?php if ( $dfxpray_pending ) : ?>
		<h3><?php esc_html_e( 'Pending invitations', 'dfx-prayer-intentions' ); ?></h3>
		<ul class="dfxpray-invitation-list">
			<?php foreach ( $dfxpray_pending as $dfxpray_invitation ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: name, 2: email, 3: date. */
							__( '%1$s (%2$s) · expires on %3$s', 'dfx-prayer-intentions' ),
							$dfxpray_invitation['name'],
							$dfxpray_invitation['email'],
							$dfxpray_invitations->expiry_date( $dfxpray_invitation )
						)
					);
					?>
					<?php $dfxpray_admin->form_open( 'withdraw_invitation', array( 'invitation_id' => $dfxpray_invitation['id'] ), 'dfxpray-inline-form' ); ?>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Withdraw', 'dfx-prayer-intentions' ); ?></button>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Invite a leader', 'dfx-prayer-intentions' ); ?></h3>
	<p class="description"><?php esc_html_e( 'The person receives an email with a link to accept, valid for 7 days. On accepting, they become a leader of this group at once, with an account on this site if they had none.', 'dfx-prayer-intentions' ); ?></p>
	<?php $dfxpray_admin->form_open( 'invite_leader', array( 'group_id' => $group_id ), 'dfxpray-invite-leader' ); ?>
		<p>
			<label><?php esc_html_e( 'Name', 'dfx-prayer-intentions' ); ?><br><input type="text" class="regular-text" name="name" required></label><br>
			<label><?php esc_html_e( 'Email', 'dfx-prayer-intentions' ); ?><br><input type="email" class="regular-text" name="email" required></label>
		</p>
		<p><button type="submit" class="button"><?php esc_html_e( 'Send invitation', 'dfx-prayer-intentions' ); ?></button></p>
	</form>
</section>

<h2><?php esc_html_e( 'Public form', 'dfx-prayer-intentions' ); ?></h2>
<p>
	<?php esc_html_e( 'Share this address only with the people who should send requests. It is not indexed by search engines.', 'dfx-prayer-intentions' ); ?><br>
	<a href="<?php echo esc_url( $dfxpray_url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $dfxpray_url ); ?></code></a>
</p>
<p class="description">
	<?php
	/* translators: %s: shortcode. */
	printf( esc_html__( 'You can also place the form on any page with the “Prayer request form” block or the shortcode %s.', 'dfx-prayer-intentions' ), '<code>[dfxpray_form group="' . esc_html( (string) $group_id ) . '"]</code>' );
	?>
</p>
<?php $dfxpray_admin->form_open( 'regenerate_slug', array( 'group_id' => $group_id ), 'dfxpray-confirm' ); ?>
	<p><button type="submit" class="button"><?php esc_html_e( 'Generate a new address', 'dfx-prayer-intentions' ); ?></button>
	<span class="description"><?php esc_html_e( 'The current address will stop working.', 'dfx-prayer-intentions' ); ?></span></p>
</form>

<?php $dfxpray_admin->form_open( 'save_group_settings', array( 'group_id' => $group_id ), 'dfxpray-settings-form' ); ?>
	<?php
	$dfxpray_admin->view(
		'partials/settings-fields',
		array(
			'values' => $dfxpray_values,
			'keys'   => $dfxpray_admin->setting_sections( 'group' ),
		)
	);
	?>

	<h2><?php esc_html_e( 'Channels', 'dfx-prayer-intentions' ); ?></h2>
	<p class="description"><?php esc_html_e( 'The panel always shows the index and the digest with a Copy button for WhatsApp. These channels deliver them automatically.', 'dfx-prayer-intentions' ); ?></p>
	<?php foreach ( DFXPRAY_Channels::get_instance()->all() as $dfxpray_id => $dfxpray_channel ) : ?>
		<?php
		if ( ! isset( $dfxpray_channels[ $dfxpray_id ] ) ) {
			continue;
		}
		$dfxpray_c    = $dfxpray_channels[ $dfxpray_id ];
		$dfxpray_base = 'settings[channels][' . $dfxpray_id . ']';
		?>
		<fieldset class="dfxpray-card dfxpray-channel">
			<legend><strong><?php echo esc_html( $dfxpray_channel->label() ); ?></strong></legend>
			<p>
				<?php foreach ( $dfxpray_flags as $dfxpray_flag => $dfxpray_label ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $dfxpray_base . '[' . $dfxpray_flag . ']' ); ?>" value="0">
					<label><input type="checkbox" name="<?php echo esc_attr( $dfxpray_base . '[' . $dfxpray_flag . ']' ); ?>" value="1" <?php checked( ! empty( $dfxpray_c[ $dfxpray_flag ] ) ); ?>> <?php echo esc_html( $dfxpray_label ); ?></label><br>
				<?php endforeach; ?>
			</p>
			<?php if ( 'email' === $dfxpray_id ) : ?>
				<p>
					<label><?php esc_html_e( 'Recipients', 'dfx-prayer-intentions' ); ?><br>
					<input type="text" class="large-text" name="<?php echo esc_attr( $dfxpray_base . '[recipients]' ); ?>" value="<?php echo esc_attr( $dfxpray_c['recipients'] ); ?>" placeholder="<?php esc_attr_e( 'Empty: every leader of the group', 'dfx-prayer-intentions' ); ?>"></label>
				</p>
			<?php elseif ( 'telegram' === $dfxpray_id ) : ?>
				<p>
					<label><?php esc_html_e( 'Bot token', 'dfx-prayer-intentions' ); ?><br><input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $dfxpray_base . '[token]' ); ?>" value="<?php echo esc_attr( $dfxpray_c['token'] ); ?>"></label><br>
					<label><?php esc_html_e( 'Chat ID', 'dfx-prayer-intentions' ); ?><br><input type="text" class="regular-text" name="<?php echo esc_attr( $dfxpray_base . '[chat_id]' ); ?>" value="<?php echo esc_attr( $dfxpray_c['chat_id'] ); ?>"></label>
				</p>
			<?php elseif ( 'webhook' === $dfxpray_id ) : ?>
				<p>
					<label><?php esc_html_e( 'URL', 'dfx-prayer-intentions' ); ?><br><input type="url" class="large-text" name="<?php echo esc_attr( $dfxpray_base . '[url]' ); ?>" value="<?php echo esc_attr( $dfxpray_c['url'] ); ?>"></label><br>
					<label><?php esc_html_e( 'Signing secret', 'dfx-prayer-intentions' ); ?><br><input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $dfxpray_base . '[secret]' ); ?>" value="<?php echo esc_attr( $dfxpray_c['secret'] ); ?>"></label>
					<br><span class="description"><?php esc_html_e( 'Requests carry an X-DFXPray-Signature header: sha256= followed by the HMAC-SHA256 of the body.', 'dfx-prayer-intentions' ); ?></span>
				</p>
			<?php endif; ?>
			<?php if ( isset( $dfxpray_c['variant'] ) ) : ?>
				<p>
					<label><?php esc_html_e( 'Version sent', 'dfx-prayer-intentions' ); ?><br>
						<select name="<?php echo esc_attr( $dfxpray_base . '[variant]' ); ?>">
							<?php foreach ( $dfxpray_variants as $dfxpray_key => $dfxpray_label ) : ?>
								<option value="<?php echo esc_attr( $dfxpray_key ); ?>" <?php selected( $dfxpray_c['variant'], $dfxpray_key ); ?>><?php echo esc_html( $dfxpray_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
			<?php endif; ?>
		</fieldset>
	<?php endforeach; ?>

	<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'dfx-prayer-intentions' ); ?></button></p>
</form>

<h2><?php esc_html_e( 'Test the channels', 'dfx-prayer-intentions' ); ?></h2>
<p class="dfxpray-inline-forms">
	<?php foreach ( DFXPRAY_Channels::get_instance()->all() as $dfxpray_id => $dfxpray_channel ) : ?>
		<?php
		$dfxpray_admin->form_open(
			'test_channel',
			array(
				'group_id' => $group_id,
				'channel'  => $dfxpray_id,
			)
		);
		?>
			<button type="submit" class="button">
				<?php
				/* translators: %s: channel name. */
				printf( esc_html__( 'Send a test by %s', 'dfx-prayer-intentions' ), esc_html( $dfxpray_channel->label() ) );
				?>
			</button>
		</form>
	<?php endforeach; ?>
</p>

<?php
$dfxpray_admin->form_open(
	'save_group_settings',
	array(
		'group_id' => $group_id,
		'reset'    => 1,
	),
	'dfxpray-confirm'
);
?>
	<p><button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Reset this group to the global settings', 'dfx-prayer-intentions' ); ?></button></p>
</form>
