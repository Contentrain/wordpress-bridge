<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><?php wp_head(); ?></head>
<body <?php body_class(); ?>>
<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', 'bridge-text' ); ?></a>
<header class="site-header">
	<p class="site-title"><?php bloginfo( 'name' ); ?></p>
	<nav aria-label="Main navigation"><?php wp_nav_menu( array( 'theme_location' => 'primary', 'fallback_cb' => false ) ); ?></nav>
	<button class="menu-toggle">Menu</button>
	<a class="cta" href="#book"><?php echo esc_html( get_theme_mod( 'cta_label', __( 'Book now', 'bridge-text' ) ) ); ?></a>
	<form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<label for="s">Search the menu</label>
		<input id="s" name="s" type="search" placeholder="Type a dish">
		<input type="submit" value="Find">
	</form>
	<h2>Opening hours</h2>
</header>
<main id="main">
