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

		<p class="dfxpray-form__consent">
			<label class="dfxpray-form__inline">
				<input type="checkbox" name="dfxpray_consent" value="1" required>
				<span>
					<?php
					if ( $privacy_url ) {
						printf(
							/* translators: %s: link to the privacy policy. */
							esc_html__( 'I agree that the leaders of this group use this information to pray for my intention, as explained in the %s.', 'dfx-prayer-intentions' ),
							'<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'dfx-prayer-intentions' ) . '</a>'
						);
					} else {
						esc_html_e( 'I agree that the leaders of this group use this information to pray for my intention.', 'dfx-prayer-intentions' );
					}
					?>
				</span>
			</label>
		</p>

		<p><button type="submit" class="dfxpray-form__submit"><?php esc_html_e( 'Send my request', 'dfx-prayer-intentions' ); ?></button></p>
	</form>
</div>
