<?php
/**
 * Router for PHP's built-in server: real files are served as they are,
 * everything else goes through WordPress (pretty permalinks).
 *
 * @package DFXPRAY
 */

$dfxpray_path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore
$dfxpray_file = $_SERVER['DOCUMENT_ROOT'] . $dfxpray_path; // phpcs:ignore

if ( '/' !== $dfxpray_path && file_exists( $dfxpray_file ) ) {
	return false;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php'; // phpcs:ignore
require $_SERVER['DOCUMENT_ROOT'] . '/index.php'; // phpcs:ignore
