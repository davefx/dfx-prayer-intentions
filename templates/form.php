<?php
/**
 * Public request form.
 *
 * Override it by copying this file to <theme>/dfx-prayer-intentions/form.php.
 * Keep the field names: the handler relies on them.
 *
 * Available variables:
 *
 * @var array  $group       Group.
 * @var array  $draft       Fields typed before an error.
 * @var string $error       Error message, if any.
 * @var string $action_url  Where the form posts.
 * @var string $return_url  Where to come back.
 * @var string $nonce       Nonce.
 * @var array  $types       Type choices.
 * @var array  $levels      Discretion levels.
 * @var string $privacy_url Privacy policy URL.
 * @var string $site_key    Turnstile site key, if configured.
 * @var string $controller  Data controller (the site's name when not set).
 * @var string $contact     Contact for data protection, if any.
 * @var string $provider    Who provides the service as data processor, if any.
 * @var int    $min_age     Minimum age to send a request.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_value = static fn( string $field, string $fallback = '' ): string => (string) ( $draft[ $field ] ?? $fallback );
$dfxpray_type  = $dfxpray_value( 'type' );
$dfxpray_level = $dfxpray_value( 'discretion', 'first_name' );
$dfxpray_self  = ! empty( $draft['beneficiary_is_requester'] );
?>
<div class="dfxpray-form" id="dfxpray-form">
	<?php if ( '' !== $error ) : ?>
		<p class="dfxpray-form__error" role="alert"><?php echo esc_html( $error ); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $action_url ); ?>" novalidate>
		<input type="hidden" name="action" value="<?php echo esc_attr( DFXPRAY_Form::ACTION ); ?>">
		<input type="hidden" name="dfxpray_group" value="<?php echo esc_attr( (string) $group['id'] ); ?>">
		<input type="hidden" name="dfxpray_nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<input type="hidden" name="dfxpray_return" value="<?php echo esc_url( $return_url ); ?>">

		<div class="dfxpray-form__trap" aria-hidden="true">
			<label for="dfxpray-website"><?php esc_html_e( 'Leave this field empty', 'dfx-prayer-intentions' ); ?></label>
			<input type="text" id="dfxpray-website" name="dfxpray_website" value="" tabindex="-1" autocomplete="off">
		</div>

		<fieldset class="dfxpray-form__step">
			<legend><?php esc_html_e( 'About you', 'dfx-prayer-intentions' ); ?></legend>
			<p>
				<label for="dfxpray-requester-name"><?php esc_html_e( 'Your name', 'dfx-prayer-intentions' ); ?></label>
				<input type="text" id="dfxpray-requester-name" name="dfxpray[requester_name]" value="<?php echo esc_attr( $dfxpray_value( 'requester_name' ) ); ?>" required autocomplete="given-name">
			</p>
			<p>
				<label for="dfxpray-requester-contact"><?php esc_html_e( 'A way to contact you (optional)', 'dfx-prayer-intentions' ); ?></label>
				<input type="text" id="dfxpray-requester-contact" name="dfxpray[requester_contact]" value="<?php echo esc_attr( $dfxpray_value( 'requester_contact' ) ); ?>" autocomplete="email">
				<small><?php esc_html_e( 'Email or phone. Only the group leaders will see it.', 'dfx-prayer-intentions' ); ?></small>
			</p>
		</fieldset>

		<fieldset class="dfxpray-form__step">
			<legend><?php esc_html_e( 'Who are we praying for?', 'dfx-prayer-intentions' ); ?></legend>
			<p>
				<label class="dfxpray-form__inline">
					<input type="checkbox" name="dfxpray[beneficiary_is_requester]" value="1" data-dfxpray-self <?php checked( $dfxpray_self ); ?>>
					<?php esc_html_e( 'For me', 'dfx-prayer-intentions' ); ?>
				</label>
			</p>
			<div data-dfxpray-other>
				<p>
					<label for="dfxpray-beneficiary-name"><?php esc_html_e( 'Their name', 'dfx-prayer-intentions' ); ?></label>
					<input type="text" id="dfxpray-beneficiary-name" name="dfxpray[beneficiary_name]" value="<?php echo esc_attr( $dfxpray_value( 'beneficiary_name' ) ); ?>">
				</p>
				<p>
					<label for="dfxpray-relationship"><?php esc_html_e( 'Their relationship to you', 'dfx-prayer-intentions' ); ?></label>
					<input type="text" id="dfxpray-relationship" name="dfxpray[relationship]" value="<?php echo esc_attr( $dfxpray_value( 'relationship' ) ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'e.g. mother of, friend of', 'dfx-prayer-intentions' ); ?>">
				</p>
			</div>
		</fieldset>

		<fieldset class="dfxpray-form__step">
			<legend><?php esc_html_e( 'What is it about?', 'dfx-prayer-intentions' ); ?></legend>
			<?php foreach ( $types as $dfxpray_key => $dfxpray_choice ) : ?>
				<label class="dfxpray-form__choice">
					<input type="radio" name="dfxpray[type]" value="<?php echo esc_attr( $dfxpray_key ); ?>" data-needs-date="<?php echo $dfxpray_choice['needs_date'] ? '1' : '0'; ?>" data-has-time="<?php echo $dfxpray_choice['has_time'] ? '1' : '0'; ?>" data-date-label="<?php echo esc_attr( $dfxpray_choice['date_label'] ); ?>" <?php checked( $dfxpray_type, $dfxpray_key ); ?> required>
					<span><strong><?php echo esc_html( $dfxpray_choice['label'] ); ?></strong> <small><?php echo esc_html( $dfxpray_choice['hint'] ); ?></small></span>
				</label>
			<?php endforeach; ?>
			<div data-dfxpray-date>
				<p>
					<label for="dfxpray-reference-date" data-dfxpray-date-label><?php esc_html_e( 'Date', 'dfx-prayer-intentions' ); ?></label>
					<input type="date" id="dfxpray-reference-date" name="dfxpray[reference_date]" value="<?php echo esc_attr( $dfxpray_value( 'reference_date' ) ); ?>">
				</p>
				<p data-dfxpray-time>
					<label for="dfxpray-reference-time"><?php esc_html_e( 'Time (optional)', 'dfx-prayer-intentions' ); ?></label>
					<input type="time" id="dfxpray-reference-time" name="dfxpray[reference_time]" value="<?php echo esc_attr( $dfxpray_value( 'reference_time' ) ); ?>">
					<small><?php esc_html_e( 'If you know what time it is, we will pray at that moment.', 'dfx-prayer-intentions' ); ?></small>
				</p>
			</div>
		</fieldset>

		<fieldset class="dfxpray-form__step">
			<legend><label for="dfxpray-body"><?php esc_html_e( 'Your intention', 'dfx-prayer-intentions' ); ?></label></legend>
			<textarea id="dfxpray-body" name="dfxpray[body]" rows="5" required><?php echo esc_textarea( $dfxpray_value( 'body' ) ); ?></textarea>
			<small><?php esc_html_e( 'There is no need to include diagnoses or surnames: the leaders will write a short, discreet summary for the group.', 'dfx-prayer-intentions' ); ?></small>
		</fieldset>

		<fieldset class="dfxpray-form__step">
			<legend><?php esc_html_e( 'How should the names appear to the group?', 'dfx-prayer-intentions' ); ?></legend>
			<?php foreach ( $levels as $dfxpray_key => $dfxpray_label ) : ?>
				<label class="dfxpray-form__choice">
					<input type="radio" name="dfxpray[discretion]" value="<?php echo esc_attr( $dfxpray_key ); ?>"<?php echo $dfxpray_level === $dfxpray_key ? ' checked' : ''; ?>>
					<span><?php echo esc_html( $dfxpray_label ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<?php if ( '' !== $site_key ) : ?>
			<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $site_key ); ?>"></div>
		<?php endif; ?>

		<?php
		$dfxpray_policy = $privacy_url
			? '<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'dfx-prayer-intentions' ) . '</a>'
			: esc_html__( 'privacy policy', 'dfx-prayer-intentions' );
		?>
		<div class="dfxpray-form__privacy">
			<p><strong><?php esc_html_e( 'Data protection', 'dfx-prayer-intentions' ); ?></strong></p>
			<ul>
				<li>
					<strong><?php esc_html_e( 'Controller:', 'dfx-prayer-intentions' ); ?></strong>
					<?php echo esc_html( '' !== $contact ? $controller . ' (' . $contact . ').' : $controller . '.' ); ?>
				</li>
				<?php if ( '' !== $provider ) : ?>
					<li>
						<strong><?php esc_html_e( 'Service:', 'dfx-prayer-intentions' ); ?></strong>
						<?php
						/* translators: 1: provider of the service, 2: data controller. */
						echo esc_html( sprintf( __( '%1$s provides this tool free of charge to %2$s as data processor, and only processes your data on its behalf.', 'dfx-prayer-intentions' ), $provider, $controller ) );
						?>
					</li>
				<?php endif; ?>
				<li>
					<strong><?php esc_html_e( 'Purpose:', 'dfx-prayer-intentions' ); ?></strong>
					<?php
					/* translators: %s: group name. */
					echo esc_html( sprintf( __( 'to pray for your intention in the group “%s” and, if you leave a contact, to ask you how it goes.', 'dfx-prayer-intentions' ), $group['name'] ) );
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Legal basis:', 'dfx-prayer-intentions' ); ?></strong>
					<?php esc_html_e( 'your explicit consent (arts. 6.1.a and 9.2.a GDPR).', 'dfx-prayer-intentions' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Recipients:', 'dfx-prayer-intentions' ); ?></strong>
					<?php esc_html_e( 'only the leaders of the group read your request. The group receives a short summary, with the names as you choose, through its messaging channel (for example WhatsApp or Telegram). It is not given to anyone else.', 'dfx-prayer-intentions' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Rights:', 'dfx-prayer-intentions' ); ?></strong>
					<?php
					printf(
						/* translators: %s: link to the privacy policy. */
						esc_html__( 'you can access, rectify or erase your data, restrict or object to its processing, withdraw your consent at any time and complain to the supervisory authority. More in the %s.', 'dfx-prayer-intentions' ),
						$dfxpray_policy // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					);
					?>
				</li>
			</ul>
		</div>

		<p class="dfxpray-form__consent">
			<label class="dfxpray-form__inline">
				<input type="checkbox" name="dfxpray_consent" value="1" required>
				<span>
					<?php
					printf(
						/* translators: %s: link to the privacy policy. */
						esc_html__( 'I give my explicit consent for the leaders of this group to process the data of this request, including any about health or beliefs, and to share a discreet summary with the group, as explained in the %s.', 'dfx-prayer-intentions' ),
						$dfxpray_policy // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					);
					?>
				</span>
			</label>
			<label class="dfxpray-form__inline">
				<input type="checkbox" name="dfxpray_others" value="1" required>
				<span><?php esc_html_e( 'If I ask for someone else, I have their permission or believe in good faith that they would give it, and I do not include details they would not want known.', 'dfx-prayer-intentions' ); ?></span>
			</label>
			<label class="dfxpray-form__inline">
				<input type="checkbox" name="dfxpray_age" value="1" required>
				<span>
					<?php
					/* translators: %d: minimum age. */
					echo esc_html( sprintf( __( 'I am at least %d years old.', 'dfx-prayer-intentions' ), $min_age ) );
					?>
				</span>
			</label>
		</p>

		<p><button type="submit" class="dfxpray-form__submit"><?php esc_html_e( 'Send my request', 'dfx-prayer-intentions' ); ?></button></p>
	</form>
</div>
