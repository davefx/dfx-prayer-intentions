<?php
/**
 * Setting fields, shared by the group and global screens.
 *
 * @var array    $values Resolved values.
 * @var string[] $keys   Keys to show, in order, grouped by section.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_fields = DFXPRAY_Admin::get_instance()->setting_fields();

foreach ( $keys as $dfxpray_section => $dfxpray_section_keys ) :
	?>
	<h2><?php echo esc_html( $dfxpray_section ); ?></h2>
	<table class="form-table" role="presentation">
		<?php foreach ( $dfxpray_section_keys as $dfxpray_key ) : ?>
			<?php
			$dfxpray_field = $dfxpray_fields[ $dfxpray_key ];
			$dfxpray_id    = 'dfxpray-setting-' . $dfxpray_key;
			$dfxpray_name  = 'settings[' . $dfxpray_key . ']';
			$dfxpray_value = $values[ $dfxpray_key ];
			?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $dfxpray_id ); ?>"><?php echo esc_html( $dfxpray_field['label'] ); ?></label></th>
				<td>
					<?php
					switch ( $dfxpray_field['type'] ) {
						case 'checkbox':
							echo '<input type="hidden" name="' . esc_attr( $dfxpray_name ) . '" value="0">';
							echo '<input type="checkbox" id="' . esc_attr( $dfxpray_id ) . '" name="' . esc_attr( $dfxpray_name ) . '" value="1" ' . checked( (bool) $dfxpray_value, true, false ) . '>';
							break;
						case 'textarea':
							echo '<textarea id="' . esc_attr( $dfxpray_id ) . '" name="' . esc_attr( $dfxpray_name ) . '" rows="3" class="large-text">' . esc_textarea( (string) $dfxpray_value ) . '</textarea>';
							break;
						case 'select':
							echo '<select id="' . esc_attr( $dfxpray_id ) . '" name="' . esc_attr( $dfxpray_name ) . '">';
							foreach ( $dfxpray_field['options'] as $dfxpray_option => $dfxpray_label ) {
								echo '<option value="' . esc_attr( $dfxpray_option ) . '" ' . selected( $dfxpray_value, $dfxpray_option, false ) . '>' . esc_html( $dfxpray_label ) . '</option>';
							}
							echo '</select>';
							break;
						default:
							$dfxpray_class = 'number' === $dfxpray_field['type'] ? 'small-text' : ( 'time' === $dfxpray_field['type'] ? '' : 'regular-text' );
							echo '<input type="' . esc_attr( $dfxpray_field['type'] ) . '" id="' . esc_attr( $dfxpray_id ) . '" name="' . esc_attr( $dfxpray_name ) . '" value="' . esc_attr( (string) $dfxpray_value ) . '" class="' . esc_attr( $dfxpray_class ) . '"' . ( 'number' === $dfxpray_field['type'] ? ' min="0"' : '' ) . ( 'password' === $dfxpray_field['type'] ? ' autocomplete="off"' : '' ) . '>';
					}
					if ( ! empty( $dfxpray_field['help'] ) ) {
						echo '<p class="description">' . esc_html( $dfxpray_field['help'] ) . '</p>';
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endforeach; ?>
