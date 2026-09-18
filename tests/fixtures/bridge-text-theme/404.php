<?php get_header(); ?>
<h1>Page not found</h1>
<p><?php esc_html_e( 'Try a search instead.', 'bridge-text' ); ?></p>
<?php get_search_form(); ?>
<?php get_template_part( 'parts/cta' ); ?>
<?php get_footer(); ?>
