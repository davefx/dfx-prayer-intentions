<?php
/**
 * Groups: a card per group the user may manage, with its counters and links
 * to its tabs; below, for administrators, group management (create, edit,
 * assign leaders, archive, delete).
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin = DFXPRAY_Admin::get_instance();
$dfxpray_ids   = DFXPRAY_Permissions::get_instance()->group_ids_for_user();
$dfxpray_label = array(
	'pending'  => __( 'Pending', 'dfx-prayer-intentions' ),
	'review'   => __( 'In review', 'dfx-prayer-intentions' ),
	'today'    => __( 'Events today', 'dfx-prayer-intentions' ),
	'tomorrow' => __( 'Events tomorrow', 'dfx-prayer-intentions' ),
);
$dfxpray_link  = array(
	'pending'  => 'moderation',
	'review'   => 'reviews',
	'today'    => 'intentions',
	'tomorrow' => 'intentions',
);

if ( ! $dfxpray_ids ) {
	echo '<div class="notice notice-info inline"><p>' . esc_html__( 'You are not assigned to any group yet. Ask the site administrator.', 'dfx-prayer-intentions' ) . '</p></div>';
}
?>
<div class="dfxpray-cards">
	<?php foreach ( $dfxpray_ids as $dfxpray_id ) : ?>
		<?php
		$dfxpray_g      = DFXPRAY_Groups::get_instance()->get( $dfxpray_id );
		$dfxpray_counts = DFXPRAY_Intentions::get_instance()->counts( $dfxpray_id );
		?>
		<section class="dfxpray-card dfxpray-group-card">
			<h2><a href="<?php echo esc_url( $dfxpray_admin->group_url( $dfxpray_id ) ); ?>"><?php echo esc_html( $dfxpray_g['name'] ); ?></a></h2>
			<ul class="dfxpray-counters">
				<?php foreach ( $dfxpray_counts as $dfxpray_key => $dfxpray_value ) : ?>
					<li>
						<a href="<?php echo esc_url( $dfxpray_admin->group_url( $dfxpray_id, $dfxpray_link[ $dfxpray_key ] ) ); ?>">
							<span class="dfxpray-count" data-count="<?php echo esc_attr( $dfxpray_key ); ?>"><?php echo esc_html( number_format_i18n( $dfxpray_value ) ); ?></span>
							<span class="dfxpray-count-label"><?php echo esc_html( $dfxpray_label[ $dfxpray_key ] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a class="button" href="<?php echo esc_url( $dfxpray_admin->group_url( $dfxpray_id, 'digest' ) ); ?>"><?php esc_html_e( 'Daily digest', 'dfx-prayer-intentions' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dfxpray_admin->group_url( $dfxpray_id, 'intentions', array( 'view' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add intention', 'dfx-prayer-intentions' ); ?></a>
			</p>
		</section>
	<?php endforeach; ?>
</div>
<?php
if ( ! DFXPRAY_Permissions::get_instance()->is_admin() ) {
	return;
}
?>
<h2 class="dfxpray-section-title"><?php esc_html_e( 'Manage groups', 'dfx-prayer-intentions' ); ?></h2>
<?php
$dfxpray_groups     = DFXPRAY_Groups::get_instance();
$dfxpray_candidates = get_users(
	array(
		'capability' => DFXPRAY_Installer::CAPABILITY,
		'orderby'    => 'display_name',
	)
);

$dfxpray_group_form = static function ( ?array $group ) use ( $dfxpray_admin, $dfxpray_groups, $dfxpray_candidates ): void {
	$leaders = $group ? $dfxpray_groups->get_leaders( $group['id'] ) : array();
	$dfxpray_admin->form_open( 'save_group', array( 'group_id' => $group['id'] ?? 0 ), 'dfxpray-group-form' );
	?>
		<p><label><?php esc_html_e( 'Name', 'dfx-prayer-intentions' ); ?><br><input type="text" class="regular-text" name="name" value="<?php echo esc_attr( $group['name'] ?? '' ); ?>" required></label></p>
		<p><label><?php esc_html_e( 'Internal description', 'dfx-prayer-intentions' ); ?><br><textarea name="description" rows="2" class="large-text"><?php echo esc_textarea( $group['description'] ?? '' ); ?></textarea></label></p>
		<fieldset>
			<legend><?php esc_html_e( 'Leaders', 'dfx-prayer-intentions' ); ?></legend>
			<?php if ( ! $dfxpray_candidates ) : ?>
				<p class="description"><?php esc_html_e( 'No users can lead groups yet. Give someone the “Prayer leader” role first.', 'dfx-prayer-intentions' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $dfxpray_candidates as $user ) : ?>
				<label class="dfxpray-leader"><input type="checkbox" name="leaders[]" value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php checked( in_array( $user->ID, $leaders, true ) ); ?>> <?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<?php $privacy = DFXPRAY_Settings::get_instance()->all( $group['id'] ?? null ); ?>
		<fieldset>
			<legend><?php esc_html_e( 'Data protection', 'dfx-prayer-intentions' ); ?></legend>
			<p><label><?php esc_html_e( 'Data controller', 'dfx-prayer-intentions' ); ?><br><input type="text" class="regular-text" name="privacy[privacy_controller]" value="<?php echo esc_attr( $group ? $privacy['privacy_controller'] : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. the parish', 'dfx-prayer-intentions' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Contact for data protection', 'dfx-prayer-intentions' ); ?><br><input type="text" class="regular-text" name="privacy[privacy_contact]" value="<?php echo esc_attr( $group ? $privacy['privacy_contact'] : '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Own privacy policy (URL, optional)', 'dfx-prayer-intentions' ); ?><br><input type="url" class="regular-text" name="privacy[privacy_policy_url]" value="<?php echo esc_attr( $group ? $privacy['privacy_policy_url'] : '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Processing agreement accepted on', 'dfx-prayer-intentions' ); ?><br><input type="date" name="privacy[dpa_accepted_on]" value="<?php echo esc_attr( $group ? $privacy['dpa_accepted_on'] : '' ); ?>"></label></p>
		</fieldset>
		<p><button type="submit" class="button button-primary"><?php echo $group ? esc_html__( 'Save group', 'dfx-prayer-intentions' ) : esc_html__( 'Create group', 'dfx-prayer-intentions' ); ?></button></p>
	</form>
	<?php
};
?>
<p class="description"><?php esc_html_e( 'Each group is independent: its own leaders, form, index and digest. No data crosses from one group to another.', 'dfx-prayer-intentions' ); ?></p>

<?php foreach ( $dfxpray_groups->all() as $dfxpray_group ) : ?>
	<section class="dfxpray-card dfxpray-group dfxpray-group--<?php echo esc_attr( $dfxpray_group['status'] ); ?>">
		<h2>
			<?php echo esc_html( $dfxpray_group['name'] ); ?>
			<?php if ( 'archived' === $dfxpray_group['status'] ) : ?>
				<span class="dfxpray-status dfxpray-status--closed"><?php esc_html_e( 'Archived', 'dfx-prayer-intentions' ); ?></span>
			<?php endif; ?>
		</h2>
		<p><code><?php echo esc_html( home_url( '/' . $dfxpray_group['slug'] . '/' ) ); ?></code></p>
		<?php $dfxpray_group_form( $dfxpray_group ); ?>
		<details class="dfxpray-agreement">
			<summary><?php esc_html_e( 'Data processing agreement of this group', 'dfx-prayer-intentions' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Copy it, have it accepted or signed by the controller of the group, and record the date above.', 'dfx-prayer-intentions' ); ?></p>
			<?php
			$dfxpray_admin->view(
				'partials/copy-box',
				array(
					'id'    => 'dfxpray-agreement-' . $dfxpray_group['id'],
					'label' => __( 'Data processing agreement of this group', 'dfx-prayer-intentions' ),
					'text'  => DFXPRAY_Privacy::get_instance()->processing_agreement( $dfxpray_group['id'] ),
					'rows'  => 16,
				)
			);
			?>
		</details>
		<div class="dfxpray-inline-forms">
			<?php if ( 'active' === $dfxpray_group['status'] ) : ?>
				<?php $dfxpray_admin->form_open( 'archive_group', array( 'group_id' => $dfxpray_group['id'] ) ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Archive', 'dfx-prayer-intentions' ); ?></button>
				</form>
			<?php else : ?>
				<?php $dfxpray_admin->form_open( 'restore_group', array( 'group_id' => $dfxpray_group['id'] ) ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Restore', 'dfx-prayer-intentions' ); ?></button>
				</form>
			<?php endif; ?>
			<?php $dfxpray_admin->form_open( 'delete_group', array( 'group_id' => $dfxpray_group['id'] ), 'dfxpray-confirm' ); ?>
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete group and all its intentions', 'dfx-prayer-intentions' ); ?></button>
			</form>
		</div>
	</section>
<?php endforeach; ?>

<section class="dfxpray-card">
	<h2><?php esc_html_e( 'New group', 'dfx-prayer-intentions' ); ?></h2>
	<?php $dfxpray_group_form( null ); ?>
</section>
