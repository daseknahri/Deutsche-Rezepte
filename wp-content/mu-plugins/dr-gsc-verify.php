<?php
/**
 * Plugin Name: DR GSC Verification
 * Description: Serves the Google Search Console HTML verification file at /google<token>.html.
 *              The token is public-by-design (Google fetches this URL and reads the content);
 *              it ties the GSC property https://deutsche-rezepte.getemoji.site/ to the owner's
 *              Google account. Keep the file even after verification succeeds.
 * Version: 1.0.0
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) { return; }

add_action( 'init', function () {
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) { return; }
	$uri = strtok( (string) $_SERVER['REQUEST_URI'], '?' );
	if ( '/googlec475efa985256bc9.html' !== $uri ) { return; }
	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex' );
	echo 'google-site-verification: googlec475efa985256bc9.html';
	exit;
}, 0 );
