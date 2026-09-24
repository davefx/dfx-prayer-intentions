<?php
/**
 * Stand-alone page of a group's request form.
 *
 * Override it by copying this file to <theme>/dfx-prayer-intentions/form-page.php.
 *
 * Available variables:
 *
 * @var array  $group Group.
 * @var string $form  Rendered form.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $group['name'] . ' – ' . __( 'Prayer request', 'dfx-prayer-intentions' ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="dfxpray-page">
	<main class="dfxpray-page__main">
		<h1 class="dfxpray-page__title"><?php echo esc_html( $group['name'] ); ?></h1>
		<p class="dfxpray-page__intro"><?php esc_html_e( 'Tell us your prayer intention. The group leaders will read it and we will pray for it together.', 'dfx-prayer-intentions' ); ?></p>
		<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by DFXPRAY_Form, escaped there. ?>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
