<?php
/**
 * Groups (administrators): create, edit, assign leaders, archive, delete.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin      = DFXPRAY_Admin::get_instance();
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
