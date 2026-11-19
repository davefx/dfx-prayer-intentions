<?php
/**
 * E2E harness: captures outgoing email and HTTP requests in options, so the
 * tests can assert on them. Only ever installed in the E2E site.
 *
 * @package DFXPRAY
 */

// The E2E site is served over plain HTTP: let routines use application passwords.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		$mails   = get_option( 'dfxpray_e2e_mails', array() );
		$mails[] = array(
			'to'      => (array) $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
		);
		update_option( 'dfxpray_e2e_mails', $mails, false );
		return true;
	},
	10,
	2
);

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, array( 'api.telegram.org', 'hooks.example.org', 'challenges.cloudflare.com' ), true ) ) {
			return $pre;
		}

		$requests   = get_option( 'dfxpray_e2e_http', array() );
		$requests[] = array(
			'url'  => $url,
			'body' => $args['body'] ?? '',
		);
		update_option( 'dfxpray_e2e_http', $requests, false );

		return array(
			'headers'  => array(),
			'body'     => '{"ok":true,"success":true}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	},
	10,
	3
);
