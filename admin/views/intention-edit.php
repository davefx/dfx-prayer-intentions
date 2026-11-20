<?php
/**
 * Intention detail: every field, updates, comments and quick actions.
 *
 * @var int $group_id Active group.
 * @var int $id       Intention ID, 0 for a new one.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin      = DFXPRAY_Admin::get_instance();
$dfxpray_lifecycle  = DFXPRAY_Lifecycle::get_instance();
$dfxpray_discretion = DFXPRAY_Discretion::get_instance();
$dfxpray_perms      = DFXPRAY_Permissions::get_instance();
$dfxpray_intention  = $id ? DFXPRAY_Intentions::get_instance()->get( $id ) : null;

if ( $id && ( ! $dfxpray_intention || ! $dfxpray_perms->can_manage_group( $dfxpray_intention['group_id'] ) ) ) {
	$dfxpray_admin->forbidden();
	return;
}

$dfxpray_is_new = null === $dfxpray_intention;
$dfxpray_v      = $dfxpray_intention ?? array(
	'requester_name'           => '',
	'requester_contact'        => '',
	'beneficiary_name'         => '',
	'beneficiary_is_requester' => false,
	'relationship'             => '',
	'body'                     => '',
	'summary'                  => '',
	'type'                     => 'ongoing',
	'reference_date'           => null,
	'reference_time'           => null,
	'key_dates'                => array(),
	'anniversary'              => false,
	'discretion'               => 'first_name',
	'requester_display_name'   => '',
	'beneficiary_display_name' => '',
	'requester_first_name'     => '',
	'beneficiary_first_name'   => '',
	'requester_is_member'      => true,
	'beneficiary_is_member'    => false,
	'priority'                 => 'normal',
	'next_review'              => null,
	'ends_on'                  => null,
	'scope'                    => 'daily',
	'topic'                    => '',
	'requester_phone'          => '',
	'status'                   => '',
);
$dfxpray_levels = $dfxpray_discretion->levels();
$dfxpray_from   = (int) array_search( $dfxpray_v['discretion'], array_keys( $dfxpray_levels ), true );
$dfxpray_open   = in_array( $dfxpray_v['status'], array( 'active', 'review' ), true );
$dfxpray_others = array_diff( $dfxpray_perms->group_ids_for_user(), array( $dfxpray_intention['group_id'] ?? $group_id ) );
?>
<p><a href="<?php echo esc_url( $dfxpray_admin->group_url( $group_id, 'intentions' ) ); ?>">&larr; <?php esc_html_e( 'Back to the list', 'dfx-prayer-intentions' ); ?></a></p>

<div class="dfxpray-columns">
	<div class="dfxpray-main">
		<?php if ( ! $dfxpray_is_new ) : ?>
			<p>
				<span class="dfxpray-status dfxpray-status--<?php echo esc_attr( $dfxpray_v['status'] ); ?>"><?php echo esc_html( $dfxpray_lifecycle->statuses()[ $dfxpray_v['status'] ] ?? '' ); ?></span>
				<?php if ( $dfxpray_intention['close_reason'] ) : ?>
					· <?php echo esc_html( $dfxpray_lifecycle->close_reasons()[ $dfxpray_intention['close_reason'] ] ?? $dfxpray_intention['close_reason'] ); ?>
				<?php endif; ?>
				· <?php echo 'form' === $dfxpray_intention['source'] ? esc_html__( 'From the form', 'dfx-prayer-intentions' ) : esc_html__( 'Added by a leader', 'dfx-prayer-intentions' ); ?>
				<?php if ( $dfxpray_intention['ack_sent'] ) : ?>
					· <?php esc_html_e( 'Acknowledgement sent', 'dfx-prayer-intentions' ); ?>
				<?php endif; ?>
			</p>
			<?php
			$dfxpray_linked = $dfxpray_intention['linked_id'] ? DFXPRAY_Intentions::get_instance()->get( $dfxpray_intention['linked_id'] ) : null;
			$dfxpray_link   = null;
			if ( $dfxpray_linked && $dfxpray_linked['group_id'] === $dfxpray_intention['group_id'] ) {
				if ( 'thanksgiving' === $dfxpray_intention['type'] && 'thanksgiving' !== $dfxpray_linked['type'] ) {
					$dfxpray_link = __( 'Gives thanks for:', 'dfx-prayer-intentions' );
				} elseif ( 'thanksgiving' === $dfxpray_linked['type'] && 'thanksgiving' !== $dfxpray_intention['type'] ) {
					$dfxpray_link = __( 'Its thanksgiving:', 'dfx-prayer-intentions' );
				}
			}
			?>
			<?php if ( $dfxpray_link ) : ?>
				<?php
				$dfxpray_link_url = $dfxpray_admin->group_url(
					$dfxpray_linked['group_id'],
					'intentions',
					array(
						'view' => 'edit',
						'id'   => $dfxpray_linked['id'],
					)
				);
				?>
				<p class="dfxpray-linked">
					<?php echo esc_html( $dfxpray_link ); ?>
					<a href="<?php echo esc_url( $dfxpray_link_url ); ?>"><?php echo esc_html( DFXPRAY_Formatter::get_instance()->line( $dfxpray_linked ) ); ?></a>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		$dfxpray_admin->form_open(
			'save_intention',
			array(
				'intention_id'                 => $id,
				'group_id'                     => $group_id,
				'has_beneficiary_is_requester' => 1,
				'has_anniversary'              => 1,
				'has_requester_is_member'      => 1,
				'has_beneficiary_is_member'    => 1,
				'has_to_ask'                   => 1,
			),
			'dfxpray-intention-form dfxpray-steps'
		);
		?>
			<?php $dfxpray_ask = $dfxpray_is_new ? '' : DFXPRAY_Formatter::get_instance()->ask_link( $dfxpray_v ); ?>
			<fieldset class="dfxpray-step">
				<legend><span class="dfxpray-step-number">1</span> <?php esc_html_e( 'Who asks', 'dfx-prayer-intentions' ); ?></legend>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dfxpray-requester-name"><?php esc_html_e( 'Full name', 'dfx-prayer-intentions' ); ?></label></th>
						<td><input type="text" class="regular-text" id="dfxpray-requester-name" name="intention[requester_name]" value="<?php echo esc_attr( $dfxpray_v['requester_name'] ); ?>" required></td>
					</tr>
					<tr data-dfxpray-called="requester">
						<th scope="row"><label for="dfxpray-requester-called"><?php esc_html_e( 'How we call them', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="dfxpray-requester-called" name="intention[requester_first_name]" value="<?php echo esc_attr( $dfxpray_v['requester_first_name'] ); ?>" aria-label="<?php esc_attr_e( 'How the requester is called', 'dfx-prayer-intentions' ); ?>">
							<p class="description"><?php esc_html_e( 'A nickname, a compound name or a surname to tell brothers apart: Toni, Juan Tomás, Javi Cavallé. Empty: the first word, as shown in grey.', 'dfx-prayer-intentions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dfxpray-requester-phone"><?php esc_html_e( 'WhatsApp', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<input type="tel" class="regular-text" id="dfxpray-requester-phone" name="intention[requester_phone]" value="<?php echo esc_attr( $dfxpray_v['requester_phone'] ); ?>" aria-label="<?php esc_attr_e( 'Phone of the requester', 'dfx-prayer-intentions' ); ?>">
							<p class="description"><?php esc_html_e( 'To ask them how it goes.', 'dfx-prayer-intentions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'One of our group', 'dfx-prayer-intentions' ); ?></th>
						<td><label><input type="checkbox" name="intention[requester_is_member]" value="1" aria-label="<?php esc_attr_e( 'The requester is a member of the group', 'dfx-prayer-intentions' ); ?>" <?php checked( $dfxpray_v['requester_is_member'] ); ?>> <?php esc_html_e( 'One of our group', 'dfx-prayer-intentions' ); ?></label></td>
					</tr>
					<?php if ( '' !== $dfxpray_v['requester_contact'] ) : ?>
						<tr>
							<th scope="row"><label for="dfxpray-requester-contact"><?php esc_html_e( 'Contact left in the form:', 'dfx-prayer-intentions' ); ?></label></th>
							<td><input type="text" class="regular-text" id="dfxpray-requester-contact" name="intention[requester_contact]" value="<?php echo esc_attr( $dfxpray_v['requester_contact'] ); ?>"></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="dfxpray-asked-on"><?php esc_html_e( 'When they asked', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<input type="date" id="dfxpray-asked-on" name="intention[asked_on]" value="<?php echo esc_attr( $dfxpray_is_new ? '' : substr( (string) $dfxpray_v['created_at'], 0, 10 ) ); ?>" max="<?php echo esc_attr( DFXPRAY_Clock::get_instance()->today() ); ?>">
							<p class="description"><?php esc_html_e( 'Empty: today.', 'dfx-prayer-intentions' ); ?></p>
						</td>
					</tr>
					<?php if ( ! $dfxpray_is_new && null !== $dfxpray_v['activated_at'] ) : ?>
						<tr>
							<th scope="row"><label for="dfxpray-listed-on"><?php esc_html_e( 'When it reached the list', 'dfx-prayer-intentions' ); ?></label></th>
							<td>
								<input type="date" id="dfxpray-listed-on" name="intention[listed_on]" value="<?php echo esc_attr( substr( (string) $dfxpray_v['activated_at'], 0, 10 ) ); ?>" max="<?php echo esc_attr( DFXPRAY_Clock::get_instance()->today() ); ?>">
								<p class="description"><?php esc_html_e( 'While it is new, it goes in “Especially”. Move this day back to take it out sooner.', 'dfx-prayer-intentions' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</fieldset>

			<fieldset class="dfxpray-step">
				<legend><span class="dfxpray-step-number">2</span> <?php esc_html_e( 'Who we pray for', 'dfx-prayer-intentions' ); ?></legend>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'For themselves', 'dfx-prayer-intentions' ); ?></th>
						<td><label><input type="checkbox" id="dfxpray-for-themselves" name="intention[beneficiary_is_requester]" value="1" <?php checked( $dfxpray_v['beneficiary_is_requester'] ); ?>> <?php esc_html_e( 'For themselves', 'dfx-prayer-intentions' ); ?></label></td>
					</tr>
					<tr data-dfxpray-beneficiary>
						<th scope="row"><label for="dfxpray-beneficiary-name"><?php esc_html_e( 'Name', 'dfx-prayer-intentions' ); ?></label></th>
						<td><input type="text" class="regular-text" id="dfxpray-beneficiary-name" name="intention[beneficiary_name]" value="<?php echo esc_attr( $dfxpray_v['beneficiary_name'] ); ?>"></td>
					</tr>
					<tr data-dfxpray-beneficiary data-dfxpray-called="beneficiary">
						<th scope="row"><label for="dfxpray-beneficiary-called"><?php esc_html_e( 'How we call them', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="dfxpray-beneficiary-called" name="intention[beneficiary_first_name]" value="<?php echo esc_attr( $dfxpray_v['beneficiary_first_name'] ); ?>" aria-label="<?php esc_attr_e( 'How the beneficiary is called', 'dfx-prayer-intentions' ); ?>">
						</td>
					</tr>
					<tr data-dfxpray-beneficiary>
						<th scope="row"><label for="dfxpray-relationship"><?php esc_html_e( 'What they are to the requester', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="dfxpray-relationship" name="intention[relationship]" value="<?php echo esc_attr( $dfxpray_v['relationship'] ); ?>" maxlength="100">
							<p class="description"><?php esc_html_e( '“mother of”, “friend of”… It goes before the requester’s name: “Carmen, mother of Luis”.', 'dfx-prayer-intentions' ); ?></p>
						</td>
					</tr>
					<tr data-dfxpray-beneficiary>
						<th scope="row"><?php esc_html_e( 'One of our group', 'dfx-prayer-intentions' ); ?></th>
						<td><label><input type="checkbox" name="intention[beneficiary_is_member]" value="1" aria-label="<?php esc_attr_e( 'The beneficiary is a member of the group', 'dfx-prayer-intentions' ); ?>" <?php checked( $dfxpray_v['beneficiary_is_member'] ); ?>> <?php esc_html_e( 'One of our group', 'dfx-prayer-intentions' ); ?></label></td>
					</tr>
				</table>
			</fieldset>

			<fieldset class="dfxpray-step">
				<legend><span class="dfxpray-step-number">3</span> <?php esc_html_e( 'What is happening', 'dfx-prayer-intentions' ); ?></legend>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dfxpray-type"><?php esc_html_e( 'Kind', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<select id="dfxpray-type" name="intention[type]">
								<?php foreach ( $dfxpray_lifecycle->kind_choices() as $dfxpray_key => $dfxpray_label ) : ?>
									<option value="<?php echo esc_attr( $dfxpray_key ); ?>" <?php selected( $dfxpray_v['type'], $dfxpray_key ); ?>><?php echo esc_html( $dfxpray_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" data-dfxpray-kinds="event"><?php esc_html_e( 'An operation, an exam, a retreat…', 'dfx-prayer-intentions' ); ?></p>
						</td>
					</tr>
					<tr data-dfxpray-kinds="event deceased thanksgiving">
						<th scope="row"><label for="dfxpray-reference-date" data-dfxpray-date-label data-date="<?php esc_attr_e( 'Date', 'dfx-prayer-intentions' ); ?>" data-death="<?php esc_attr_e( 'Date of death', 'dfx-prayer-intentions' ); ?>"><?php echo esc_html( 'deceased' === $dfxpray_v['type'] ? __( 'Date of death', 'dfx-prayer-intentions' ) : __( 'Date', 'dfx-prayer-intentions' ) ); ?></label></th>
						<td><input type="date" id="dfxpray-reference-date" name="intention[reference_date]" value="<?php echo esc_attr( (string) $dfxpray_v['reference_date'] ); ?>"></td>
					</tr>
					<tr data-dfxpray-kinds="event">
						<th scope="row"><label for="dfxpray-reference-time"><?php esc_html_e( 'Time', 'dfx-prayer-intentions' ); ?></label></th>
						<td><input type="time" id="dfxpray-reference-time" name="intention[reference_time]" value="<?php echo esc_attr( (string) $dfxpray_v['reference_time'] ); ?>"></td>
					</tr>
					<tr data-dfxpray-kinds="deceased">
						<th scope="row"><?php esc_html_e( 'Anniversary', 'dfx-prayer-intentions' ); ?></th>
						<td><label><input type="checkbox" name="intention[anniversary]" value="1" <?php checked( $dfxpray_v['anniversary'] ); ?>> <?php esc_html_e( 'Remember it every year', 'dfx-prayer-intentions' ); ?></label></td>
					</tr>
					<tr data-dfxpray-kinds="event deceased">
						<th scope="row"><?php esc_html_e( 'Key dates', 'dfx-prayer-intentions' ); ?></th>
						<td>
							<div class="dfxpray-key-dates" data-dfxpray-rows>
								<?php
								foreach ( array_merge(
									$dfxpray_v['key_dates'],
									array(
										array(
											'date'  => '',
											'time'  => '',
											'label' => '',
										),
									)
								) as $dfxpray_kd ) :
									?>
									<p class="dfxpray-key-date" data-dfxpray-row>
										<input type="date" name="key_dates[date][]" value="<?php echo esc_attr( $dfxpray_kd['date'] ); ?>" aria-label="<?php esc_attr_e( 'Date', 'dfx-prayer-intentions' ); ?>">
										<input type="time" name="key_dates[time][]" value="<?php echo esc_attr( $dfxpray_kd['time'] ); ?>" aria-label="<?php esc_attr_e( 'Time', 'dfx-prayer-intentions' ); ?>">
										<input type="text" name="key_dates[label][]" value="<?php echo esc_attr( $dfxpray_kd['label'] ); ?>" placeholder="<?php esc_attr_e( 'Funeral, month’s mind Mass, appointment…', 'dfx-prayer-intentions' ); ?>" aria-label="<?php esc_attr_e( 'Label', 'dfx-prayer-intentions' ); ?>">
									</p>
								<?php endforeach; ?>
							</div>
							<button type="button" class="button-link" data-dfxpray-add-row><?php esc_html_e( 'Add another date', 'dfx-prayer-intentions' ); ?></button>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dfxpray-body"><?php esc_html_e( 'What they told us', 'dfx-prayer-intentions' ); ?></label></th>
						<td><textarea id="dfxpray-body" class="large-text" rows="5" name="intention[body]" required><?php echo esc_textarea( $dfxpray_v['body'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Never published. Only leaders read it.', 'dfx-prayer-intentions' ); ?></p></td>
					</tr>
				</table>
			</fieldset>

			<fieldset class="dfxpray-step">
				<legend><span class="dfxpray-step-number">4</span> <?php esc_html_e( 'How it appears in the message', 'dfx-prayer-intentions' ); ?></legend>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dfxpray-summary"><?php esc_html_e( 'Summary', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="dfxpray-summary" name="intention[summary]" value="<?php echo esc_attr( $dfxpray_v['summary'] ); ?>" maxlength="100" <?php echo $dfxpray_is_new || 'pending' !== $dfxpray_v['status'] ? 'required' : ''; ?>>
							<p class="description"><?php esc_html_e( 'What follows the name in the message.', 'dfx-prayer-intentions' ); ?></p>
						</td>
					</tr>
					<?php if ( ! $dfxpray_is_new ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'How it appears', 'dfx-prayer-intentions' ); ?></th>
							<td><p class="dfxpray-preview"><code><?php echo esc_html( DFXPRAY_Formatter::get_instance()->line( $dfxpray_intention ) ); ?></code></p></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="dfxpray-scope"><?php esc_html_e( 'Message', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<select id="dfxpray-scope" name="intention[scope]">
								<?php foreach ( DFXPRAY_Intentions::get_instance()->scope_labels() as $dfxpray_key => $dfxpray_label ) : ?>
									<option value="<?php echo esc_attr( $dfxpray_key ); ?>" <?php selected( $dfxpray_v['scope'], $dfxpray_key ); ?>><?php echo esc_html( $dfxpray_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<select id="dfxpray-topic" name="intention[topic]" aria-label="<?php esc_attr_e( 'Topic', 'dfx-prayer-intentions' ); ?>">
								<?php foreach ( DFXPRAY_Intentions::get_instance()->topic_labels() as $dfxpray_key => $dfxpray_label ) : ?>
									<option value="<?php echo esc_attr( $dfxpray_key ); ?>" <?php selected( $dfxpray_v['topic'], $dfxpray_key ); ?>><?php echo esc_html( $dfxpray_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The monthly list, read at the monthly adoration, has every intention by topic; the daily message leaves out those kept for the monthly one.', 'dfx-prayer-intentions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dfxpray-priority"><?php esc_html_e( 'Priority', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<select id="dfxpray-priority" name="intention[priority]">
								<option value="normal" <?php selected( $dfxpray_v['priority'], 'normal' ); ?>><?php esc_html_e( 'Normal', 'dfx-prayer-intentions' ); ?></option>
								<option value="high" <?php selected( $dfxpray_v['priority'], 'high' ); ?>><?php esc_html_e( 'Urgent: it opens “Especially”', 'dfx-prayer-intentions' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<details class="dfxpray-more">
					<summary><?php esc_html_e( 'Discretion and names', 'dfx-prayer-intentions' ); ?></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dfxpray-discretion"><?php esc_html_e( 'Discretion', 'dfx-prayer-intentions' ); ?></label></th>
							<td>
								<select id="dfxpray-discretion" name="intention[discretion]">
									<?php foreach ( array_slice( $dfxpray_levels, $dfxpray_is_new ? 0 : $dfxpray_from, null, true ) as $dfxpray_key => $dfxpray_label ) : ?>
										<option value="<?php echo esc_attr( $dfxpray_key ); ?>" <?php selected( $dfxpray_v['discretion'], $dfxpray_key ); ?>><?php echo esc_html( $dfxpray_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( ! $dfxpray_is_new ) : ?>
									<p class="description"><?php esc_html_e( 'It can be made stricter than the requester chose, never more open.', 'dfx-prayer-intentions' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Description instead of the name', 'dfx-prayer-intentions' ); ?></th>
							<td>
								<input type="text" class="regular-text" name="intention[requester_display_name]" value="<?php echo esc_attr( $dfxpray_v['requester_display_name'] ); ?>" placeholder="<?php esc_attr_e( 'Requester, as described', 'dfx-prayer-intentions' ); ?>" aria-label="<?php esc_attr_e( 'Description of the requester instead of the name', 'dfx-prayer-intentions' ); ?>">
								<input type="text" class="regular-text" name="intention[beneficiary_display_name]" value="<?php echo esc_attr( $dfxpray_v['beneficiary_display_name'] ); ?>" placeholder="<?php esc_attr_e( 'Beneficiary, as described', 'dfx-prayer-intentions' ); ?>" aria-label="<?php esc_attr_e( 'Description of the beneficiary instead of the name', 'dfx-prayer-intentions' ); ?>">
								<p class="description"><?php esc_html_e( 'Only for discreet cases: it replaces the whole description of the person and nothing is added to it, not even the prefix. Example: “Bro. John’s daughter”. It cannot reveal more than the discretion level allows.', 'dfx-prayer-intentions' ); ?></p>
							</td>
						</tr>
					</table>
				</details>
			</fieldset>

			<fieldset class="dfxpray-step">
				<legend><span class="dfxpray-step-number">5</span> <?php esc_html_e( 'Follow-up', 'dfx-prayer-intentions' ); ?></legend>
				<table class="form-table" role="presentation">
					<?php if ( ! $dfxpray_is_new && $dfxpray_open ) : ?>
						<tr>
							<th scope="row"><label for="dfxpray-next-review"><?php esc_html_e( 'Look at it again on', 'dfx-prayer-intentions' ); ?></label></th>
							<td><input type="date" id="dfxpray-next-review" name="intention[next_review]" value="<?php echo esc_attr( (string) $dfxpray_v['next_review'] ); ?>"></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="dfxpray-ends-on"><?php esc_html_e( 'Or pray for it only until', 'dfx-prayer-intentions' ); ?></label></th>
						<td>
							<input type="date" id="dfxpray-ends-on" name="intention[ends_on]" value="<?php echo esc_attr( (string) $dfxpray_v['ends_on'] ); ?>">
							<p class="description"><?php esc_html_e( 'The day after, it leaves the messages and closes by itself.', 'dfx-prayer-intentions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pending to ask', 'dfx-prayer-intentions' ); ?></th>
						<td>
							<label><input type="checkbox" name="intention[to_ask]" value="1" <?php checked( ! empty( $dfxpray_v['to_ask'] ) ); ?>> <?php esc_html_e( 'Pending to ask', 'dfx-prayer-intentions' ); ?></label>
							<?php if ( '' !== $dfxpray_ask ) : ?>
								<a class="button dfxpray-ask" href="<?php echo esc_url( $dfxpray_ask ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ask by WhatsApp how it goes', 'dfx-prayer-intentions' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</fieldset>
			<p><button type="submit" class="button button-primary"><?php echo $dfxpray_is_new ? esc_html__( 'Add intention', 'dfx-prayer-intentions' ) : esc_html__( 'Save changes', 'dfx-prayer-intentions' ); ?></button></p>
		</form>

		<?php if ( ! $dfxpray_is_new ) : ?>
			<h2><?php esc_html_e( 'Updates', 'dfx-prayer-intentions' ); ?></h2>
			<?php $dfxpray_admin->form_open( 'add_update', array( 'intention_id' => $id ), 'dfxpray-stacked' ); ?>
				<textarea name="body" rows="2" class="large-text" required aria-label="<?php esc_attr_e( 'New update', 'dfx-prayer-intentions' ); ?>" placeholder="<?php esc_attr_e( 'The operation went well, still in hospital…', 'dfx-prayer-intentions' ); ?>"></textarea>
				<label><input type="checkbox" name="is_news" value="1" checked> <?php esc_html_e( 'Show as news in the daily digest', 'dfx-prayer-intentions' ); ?></label>
				<button type="submit" class="button"><?php esc_html_e( 'Add update', 'dfx-prayer-intentions' ); ?></button>
			</form>
			<ul class="dfxpray-timeline">
				<?php foreach ( DFXPRAY_Updates::get_instance()->for_intention( $id ) as $dfxpray_update ) : ?>
					<li>
						<span class="dfxpray-meta"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dfxpray_update['created_at'] ) . ' · ' . ( get_userdata( $dfxpray_update['author_id'] )->display_name ?? '—' ) ); ?><?php echo $dfxpray_update['is_news'] ? ' · ' . esc_html__( 'news', 'dfx-prayer-intentions' ) : ''; ?></span>
						<?php echo nl2br( esc_html( $dfxpray_update['body'] ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h2><?php esc_html_e( 'Internal comments', 'dfx-prayer-intentions' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Only leaders see these. They never appear in the index or the shareable digest.', 'dfx-prayer-intentions' ); ?></p>
			<?php $dfxpray_types = DFXPRAY_Comments::get_instance()->types(); ?>
			<?php $dfxpray_admin->form_open( 'add_comment', array( 'intention_id' => $id ), 'dfxpray-stacked' ); ?>
				<textarea name="body" rows="2" class="large-text" required aria-label="<?php esc_attr_e( 'New comment', 'dfx-prayer-intentions' ); ?>"></textarea>
				<select name="type" aria-label="<?php esc_attr_e( 'Type', 'dfx-prayer-intentions' ); ?>">
					<option value=""><?php esc_html_e( '— Type —', 'dfx-prayer-intentions' ); ?></option>
					<?php foreach ( $dfxpray_types as $dfxpray_key => $dfxpray_label ) : ?>
						<option value="<?php echo esc_attr( $dfxpray_key ); ?>"><?php echo esc_html( $dfxpray_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Add comment', 'dfx-prayer-intentions' ); ?></button>
			</form>
			<ul class="dfxpray-timeline">
				<?php foreach ( DFXPRAY_Comments::get_instance()->for_intention( $id ) as $dfxpray_comment ) : ?>
					<li>
						<span class="dfxpray-meta">
							<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dfxpray_comment['created_at'] ) . ' · ' . ( get_userdata( $dfxpray_comment['author_id'] )->display_name ?? '—' ) ); ?>
							<?php echo '' !== $dfxpray_comment['type'] ? ' · ' . esc_html( $dfxpray_types[ $dfxpray_comment['type'] ] ?? '' ) : ''; ?>
							<?php if ( $dfxpray_comment['edited_by'] ) : ?>
								· <em>
								<?php
								printf(
									/* translators: 1: user, 2: date. */
									esc_html__( 'edited by %1$s on %2$s', 'dfx-prayer-intentions' ),
									esc_html( get_userdata( $dfxpray_comment['edited_by'] )->display_name ?? '—' ),
									esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dfxpray_comment['edited_at'] ) )
								);
								?>
								</em>
							<?php endif; ?>
						</span>
						<?php echo nl2br( esc_html( $dfxpray_comment['body'] ) ); ?>
						<details>
							<summary><?php esc_html_e( 'Edit', 'dfx-prayer-intentions' ); ?></summary>
							<?php $dfxpray_admin->form_open( 'edit_comment', array( 'comment_id' => $dfxpray_comment['id'] ), 'dfxpray-stacked' ); ?>
								<textarea name="body" rows="2" class="large-text" aria-label="<?php esc_attr_e( 'Comment', 'dfx-prayer-intentions' ); ?>"><?php echo esc_textarea( $dfxpray_comment['body'] ); ?></textarea>
								<select name="type" aria-label="<?php esc_attr_e( 'Type', 'dfx-prayer-intentions' ); ?>">
									<option value=""><?php esc_html_e( '— Type —', 'dfx-prayer-intentions' ); ?></option>
									<?php foreach ( $dfxpray_types as $dfxpray_key => $dfxpray_label ) : ?>
										<option value="<?php echo esc_attr( $dfxpray_key ); ?>" <?php selected( $dfxpray_comment['type'], $dfxpray_key ); ?>><?php echo esc_html( $dfxpray_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button"><?php esc_html_e( 'Save', 'dfx-prayer-intentions' ); ?></button>
							</form>
						</details>
						<div class="dfxpray-inline-forms">
							<?php
							$dfxpray_admin->form_open(
								'convert_comment',
								array(
									'comment_id' => $dfxpray_comment['id'],
									'is_news'    => 1,
								)
							);
							?>
								<button type="submit" class="button-link"><?php esc_html_e( 'Turn into an update', 'dfx-prayer-intentions' ); ?></button>
							</form>
							<?php $dfxpray_admin->form_open( 'delete_comment', array( 'comment_id' => $dfxpray_comment['id'] ), 'dfxpray-confirm' ); ?>
								<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'dfx-prayer-intentions' ); ?></button>
							</form>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>

			<h2><?php esc_html_e( 'History', 'dfx-prayer-intentions' ); ?></h2>
			<?php $dfxpray_admin->view( 'partials/log-table', array( 'entries' => DFXPRAY_Log::get_instance()->for_intention( $id ) ) ); ?>
		<?php endif; ?>
	</div>

	<?php if ( ! $dfxpray_is_new && 'closed' === $dfxpray_v['status'] ) : ?>
		<aside class="dfxpray-side">
			<div class="dfxpray-card">
				<h2><?php esc_html_e( 'Quick actions', 'dfx-prayer-intentions' ); ?></h2>
				<?php $dfxpray_admin->form_open( 'reopen', array( 'intention_id' => $id ) ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Reopen', 'dfx-prayer-intentions' ); ?></button>
					<p class="description"><?php esc_html_e( 'If it was closed by mistake: it becomes active again and keeps its history.', 'dfx-prayer-intentions' ); ?></p>
				</form>
			</div>
		</aside>
	<?php endif; ?>

	<?php if ( ! $dfxpray_is_new && $dfxpray_open ) : ?>
		<aside class="dfxpray-side">
			<div class="dfxpray-card">
				<h2><?php esc_html_e( 'Quick actions', 'dfx-prayer-intentions' ); ?></h2>

				<?php
				$dfxpray_admin->form_open(
					'set_priority',
					array(
						'intention_id' => $id,
						'priority'     => 'high' === $dfxpray_intention['priority'] ? 'normal' : 'high',
					)
				);
				?>
					<button type="submit" class="button"><?php echo 'high' === $dfxpray_intention['priority'] ? esc_html__( 'Back to normal priority', 'dfx-prayer-intentions' ) : esc_html__( 'Mark as high priority', 'dfx-prayer-intentions' ); ?></button>
				</form>

				<?php if ( 'review' === $dfxpray_intention['status'] ) : ?>
					<?php $dfxpray_admin->form_open( 'keep_active', array( 'intention_id' => $id ) ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Still current', 'dfx-prayer-intentions' ); ?></button>
					</form>
				<?php endif; ?>

				<?php $dfxpray_admin->form_open( 'postpone', array( 'intention_id' => $id ) ); ?>
					<label><?php esc_html_e( 'Postpone review to', 'dfx-prayer-intentions' ); ?> <input type="date" name="date" required></label>
					<button type="submit" class="button"><?php esc_html_e( 'Postpone', 'dfx-prayer-intentions' ); ?></button>
				</form>

				<?php if ( ! $dfxpray_intention['ack_sent'] ) : ?>
					<?php $dfxpray_admin->form_open( 'ack_sent', array( 'intention_id' => $id ) ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Acknowledgement sent', 'dfx-prayer-intentions' ); ?></button>
					</form>
				<?php endif; ?>

				<?php if ( $dfxpray_others ) : ?>
					<?php $dfxpray_admin->form_open( 'transfer', array( 'intention_id' => $id ) ); ?>
						<label><?php esc_html_e( 'Move to', 'dfx-prayer-intentions' ); ?>
							<select name="group_id">
								<?php foreach ( $dfxpray_others as $dfxpray_other ) : ?>
									<option value="<?php echo esc_attr( (string) $dfxpray_other ); ?>"><?php echo esc_html( DFXPRAY_Groups::get_instance()->get( $dfxpray_other )['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="submit" class="button"><?php esc_html_e( 'Move', 'dfx-prayer-intentions' ); ?></button>
					</form>
				<?php endif; ?>

				<?php $dfxpray_admin->view( 'partials/close-form', array( 'intention' => $dfxpray_intention ) ); ?>
			</div>
		</aside>
	<?php endif; ?>
</div>
