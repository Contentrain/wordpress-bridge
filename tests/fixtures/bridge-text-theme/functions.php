<?php
// Test fixture: every kind of hardcoded text the B-08 scan has to account for.
add_action( 'after_setup_theme', static function () {
	add_theme_support( 'title-tag' );
	register_nav_menus( array( 'primary' => __( 'Primary menu', 'bridge-text' ) ) );
} );
add_action( 'widgets_init', static function () {
	register_sidebar( array( 'id' => 'footer', 'name' => __( 'Footer widgets', 'bridge-text' ), 'before_title' => '<h3 class="widget-title">', 'after_title' => '</h3>' ) );
} );
add_action( 'customize_register', static function ( $wp_customize ) {
	$wp_customize->add_setting( 'footer_note', array( 'default' => __( 'Made with WordPress', 'bridge-text' ) ) );
	$wp_customize->add_setting( 'cta_label', array( 'default' => __( 'Book now', 'bridge-text' ) ) );
} );
add_action( 'wp_enqueue_scripts', static function () {
	wp_enqueue_script( 'bridge-text', get_template_directory_uri() . '/assets/app.js', array(), '1.0', true );
} );
function bridge_text_label( $label ) {
	// A variable argument: its text exists only at run time.
	return __( $label, 'bridge-text' ); // phpcs:ignore
}
function bridge_text_count( $n ) {
	/* translators: %s: number of guests */
	return sprintf( _n( 'One guest', '%s guests', $n, 'bridge-text' ), $n ) . ' ' . __( '%s', 'bridge-text' );
}
