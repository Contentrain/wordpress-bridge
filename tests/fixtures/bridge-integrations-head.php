<?php
/**
 * Plugin Name: Bridge integrations fixture
 * Description: Test-only must-use plugin: a tracking script in the page head, as a theme or header plugin would print it.
 */
add_action( 'wp_head', static function () {
	echo '<script async src="https://static.hotjar.com/c/hotjar-3100.js?sv=6"></script>' . "\n";
} );
