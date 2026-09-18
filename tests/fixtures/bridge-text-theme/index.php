<?php get_header(); ?>
<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
	<article <?php post_class(); ?>>
		<h1 class="entry-title"><?php the_title(); ?></h1>
		<div class="entry-content"><?php the_content(); ?></div>
		<a class="more" href="<?php the_permalink(); ?>">Read more</a>
		<p class="meta"><?php echo esc_html_x( 'Post', 'noun', 'bridge-text' ); ?> · <?php echo esc_html_x( 'Post', 'verb', 'bridge-text' ); ?></p>
	</article>
<?php endwhile; else : ?>
	<p class="empty"><?php esc_html_e( 'Nothing matched your search.', 'bridge-text' ); ?></p>
<?php endif; ?>
<form class="newsletter"><button type="submit">Read more</button></form>
<?php echo '<p class="notice">Closed on Mondays</p>'; ?>
<?php get_footer(); ?>
