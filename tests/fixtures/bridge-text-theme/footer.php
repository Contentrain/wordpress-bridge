</main>
<footer class="site-footer">
	<h2>Opening hours</h2>
	<?php dynamic_sidebar( 'footer' ); ?>
	<p class="note"><?php echo esc_html( get_theme_mod( 'footer_note', __( 'Made with WordPress', 'bridge-text' ) ) ); ?></p>
	<p>Follow us</p>
	<p>Güncel içerik → Истории</p>
	<p><?php esc_html_e( 'All rights reserved.', 'bridge-text' ); ?> 2026</p>
	<a href="https://example.org/privacy">https://example.org/privacy</a>
</footer>
<?php wp_footer(); ?>
</body>
</html>
