<?php
/**
 * Plugin Name: Bridge coverage fixture CPT
 * Description: Test-only must-use plugin: a public post type hidden from the REST API.
 */
add_action( 'init', static function () {
	register_post_type( 'bridge_cov_cpt', array( 'public' => true, 'show_in_rest' => false, 'label' => 'Coverage books', 'supports' => array( 'title', 'editor' ) ) );
} );
