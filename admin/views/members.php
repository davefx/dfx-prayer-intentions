<?php
/**
 * The brothers of a group: how they are called, phone and birthday.
 *
 * @var int $group_id Active group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin   = DFXPRAY_Admin::get_instance();
$dfxpray_members = DFXPRAY_Members::get_instance()->for_group( $group_id );
$dfxpray_months  = array();
for ( $dfxpray_m = 1; $dfxpray_m <= 12; $dfxpray_m++ ) {
	$dfxpray_months[ $dfxpray_m ] = wp_date( 'F', (int) gmmktime( 12, 0, 0, $dfxpray_m, 1, 2001 ), new DateTimeZone( 'UTC' ) );
}

/**
 * The fields of a brother, empty for a new one.
 *
 * @param array $member  Member (or defaults).
 * @param array $months  Month names.
 * @param string $suffix Unique suffix for the IDs.
 */
$dfxpray_fields = static function ( array $member, array $months, string $suffix ): void {
	?>
	<p>
		<label for="dfxpray-member-name-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Full name', 'dfx-prayer-intentions' ); ?></label><br>
		<input type="text" class="regular-text" id="dfxpray-member-name-<?php echo esc_attr( $suffix ); ?>" name="member[name]" value="<?php echo esc_attr( $member['name'] ); ?>" required>
	</p>
	<p>
		<label for="dfxpray-member-called-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'How he is called', 'dfx-prayer-intentions' ); ?></label><br>
		<input type="text" class="regular-text" id="dfxpray-member-called-<?php echo esc_attr( $suffix ); ?>" name="member[called]" value="<?php echo esc_attr( $member['called'] ); ?>" placeholder="<?php esc_attr_e( 'How we call them: Toni, Javi Cavallé…', 'dfx-prayer-intentions' ); ?>">
	</p>
	<p>
		<label for="dfxpray-member-phone-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Phone (WhatsApp)', 'dfx-prayer-intentions' ); ?></label><br>
		<input type="tel" id="dfxpray-member-phone-<?php echo esc_attr( $suffix ); ?>" name="member[phone]" value="<?php echo esc_attr( $member['phone'] ); ?>">
	</p>
	<fieldset>
		<legend><?php esc_html_e( 'Birthday', 'dfx-prayer-intentions' ); ?></legend>
		<input type="number" min="1" max="31" name="member[birth_day]" value="<?php echo esc_attr( (string) $member['birth_day'] ); ?>" aria-label="<?php esc_attr_e( 'Day of birth', 'dfx-prayer-intentions' ); ?>" style="width:5em">
		<select name="member[birth_month]" aria-label="<?php esc_attr_e( 'Month of birth', 'dfx-prayer-intentions' ); ?>">
			<option value=""><?php esc_html_e( '— Month —', 'dfx-prayer-intentions' ); ?></option>
			<?php foreach ( $months as $dfxpray_number => $dfxpray_name ) : ?>
				<option value="<?php echo esc_attr( (string) $dfxpray_number ); ?>" <?php selected( $member['birth_month'], $dfxpray_number ); ?>><?php echo esc_html( $dfxpray_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="number" min="1900" max="2100" name="member[birth_year]" value="<?php echo esc_attr( (string) $member['birth_year'] ); ?>" placeholder="<?php esc_attr_e( 'Year (optional)', 'dfx-prayer-intentions' ); ?>" aria-label="<?php esc_attr_e( 'Year of birth', 'dfx-prayer-intentions' ); ?>" style="width:9em">
	</fieldset>
	<p>
		<label for="dfxpray-member-death-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Date of death', 'dfx-prayer-intentions' ); ?></label><br>
		<input type="date" id="dfxpray-member-death-<?php echo esc_attr( $suffix ); ?>" name="member[death_date]" value="<?php echo esc_attr( (string) ( $member['death_date'] ?? '' ) ); ?>" max="<?php echo esc_attr( DFXPRAY_Clock::get_instance()->today() ); ?>">
		<br><small><?php esc_html_e( 'He is then no longer mentioned on his birthday, and we pray especially for him every year on the anniversary of his death.', 'dfx-prayer-intentions' ); ?></small>
	</p>
	<p>
		<label for="dfxpray-member-notes-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Notes', 'dfx-prayer-intentions' ); ?></label><br>
		<textarea class="large-text" rows="2" id="dfxpray-member-notes-<?php echo esc_attr( $suffix ); ?>" name="member[notes]" placeholder="<?php esc_attr_e( 'Internal: XI Retiro - Mesa 1…', 'dfx-prayer-intentions' ); ?>"><?php echo esc_textarea( (string) ( $member['notes'] ?? '' ) ); ?></textarea>
	</p>
	<?php
};
?>
<p class="description"><?php esc_html_e( 'The members of the group. On their birthday they open “Especially” in the daily message. Only the leaders see this list.', 'dfx-prayer-intentions' ); ?></p>

<?php if ( $dfxpray_members ) : ?>
	<table class="widefat striped dfxpray-table dfxpray-members">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Member', 'dfx-prayer-intentions' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Phone', 'dfx-prayer-intentions' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Birthday', 'dfx-prayer-intentions' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'dfx-prayer-intentions' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $dfxpray_members as $dfxpray_member ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $dfxpray_member['name'] ); ?></strong>
						<?php if ( '' !== $dfxpray_member['called'] ) : ?>
							<br><small><?php echo esc_html( $dfxpray_member['called'] ); ?></small>
						<?php endif; ?>
						<?php if ( '' !== $dfxpray_member['notes'] ) : ?>
							<br><small class="dfxpray-member-notes"><?php echo nl2br( esc_html( $dfxpray_member['notes'] ) ); ?></small>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $dfxpray_member['phone'] ); ?></td>
					<td>
						<?php
						if ( $dfxpray_member['birth_day'] ) {
							echo esc_html( $dfxpray_member['birth_day'] . ' ' . $dfxpray_months[ $dfxpray_member['birth_month'] ] . ( $dfxpray_member['birth_year'] ? ' ' . $dfxpray_member['birth_year'] : '' ) );
						}
						if ( $dfxpray_member['death_date'] ) {
							echo '<br><small>† ' . esc_html( mysql2date( get_option( 'date_format' ), $dfxpray_member['death_date'] ) ) . '</small>';
						}
						?>
					</td>
					<td>
						<details class="dfxpray-edit-member">
							<summary><?php esc_html_e( 'Edit', 'dfx-prayer-intentions' ); ?></summary>
							<?php $dfxpray_admin->form_open( 'save_member', array( 'member_id' => $dfxpray_member['id'] ) ); ?>
								<?php $dfxpray_fields( $dfxpray_member, $dfxpray_months, (string) $dfxpray_member['id'] ); ?>
								<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'dfx-prayer-intentions' ); ?></button></p>
							</form>
							<?php $dfxpray_admin->form_open( 'delete_member', array( 'member_id' => $dfxpray_member['id'] ) ); ?>
								<p><button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'dfx-prayer-intentions' ); ?></button></p>
							</form>
						</details>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php else : ?>
	<p class="dfxpray-empty"><?php esc_html_e( 'No members yet.', 'dfx-prayer-intentions' ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( 'Add a member', 'dfx-prayer-intentions' ); ?></h2>
<?php $dfxpray_admin->form_open( 'save_member', array( 'group_id' => $group_id ), 'dfxpray-add-member' ); ?>
	<?php
	$dfxpray_fields(
		array(
			'name'        => '',
			'called'      => '',
			'phone'       => '',
			'birth_day'   => null,
			'birth_month' => null,
			'birth_year'  => null,
			'death_date'  => null,
			'notes'       => '',
		),
		$dfxpray_months,
		'new'
	);
	?>
	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add member', 'dfx-prayer-intentions' ); ?></button></p>
</form>
