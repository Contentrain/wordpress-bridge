<?php
/**
 * BR-23: a synthetic large site for measuring the export, in the throwaway test
 * container only. Rows go in with multi-row INSERTs (wp_insert_post for 45,000
 * records would take longer than the export it prepares for).
 *
 *   php large-site.php <posts> <attachments> <comments>
 *
 * Posts carry ~1.5 KB of HTML with image links into uploads, a category and three
 * tags. Attachments are records with their metadata and three sizes, no files:
 * the export is measured with media_files:false, which copies none.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
global $wpdb;
list( , $posts, $attachments, $comments ) = array_map( 'intval', $argv + array( 0, 5000, 20000, 20000 ) );
$admin = get_user_by( 'login', 'bridge-admin' )->ID;
$base = wp_get_upload_dir()['baseurl'];
$now = gmdate( 'Y-m-d H:i:s' );
$started = microtime( true );

$insert = static function ( $table, $columns, $rows ) use ( $wpdb ) {
	foreach ( array_chunk( $rows, 500 ) as $chunk ) {
		$values = array();
		foreach ( $chunk as $row ) {
			$values[] = '(' . implode( ',', array_map( static function ( $v ) use ( $wpdb ) { return null === $v ? 'NULL' : ( is_int( $v ) ? (string) $v : "'" . esc_sql( $v ) . "'" ); }, $row ) ) . ')';
		}
		$wpdb->query( "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', $values ) ); // phpcs:ignore
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Insert into ' . $table . ' failed: ' . $wpdb->last_error );
		}
	}
};
$post_columns = array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'post_type', 'post_mime_type', 'comment_count' );

// ---- Terms: 20 categories, 200 tags. ----
$categories = array();
$tags = array();
for ( $i = 1; $i <= 20; ++$i ) { $categories[] = (int) wp_insert_term( "Perf category $i", 'category', array( 'slug' => "perf-category-$i" ) )['term_taxonomy_id']; }
for ( $i = 1; $i <= 200; ++$i ) { $tags[] = (int) wp_insert_term( "Perf tag $i", 'post_tag', array( 'slug' => "perf-tag-$i" ) )['term_taxonomy_id']; }

// ---- Posts. ----
$first = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) + 1 FROM {$wpdb->posts}" );
$rows = array();
for ( $i = 0; $i < $posts; ++$i ) {
	$date = gmdate( 'Y-m-d H:i:s', strtotime( '2019-01-01' ) + $i * 3600 * 7 );
	$body = '';
	for ( $p = 0; $p < 6; ++$p ) {
		$body .= "<!-- wp:paragraph -->\n<p>Paragraph $p of post $i. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam.</p>\n<!-- /wp:paragraph -->\n";
	}
	$body .= '<!-- wp:image --><figure class="wp-block-image"><img src="' . $base . '/2026/09/perf-' . ( $i % max( 1, $attachments ) ) . '.jpg" alt="Image ' . $i . '"/></figure><!-- /wp:image -->';
	$rows[] = array( $admin, $date, $date, $body, "Perf post $i", '', 'publish', 'open', 'open', "perf-post-$i", '', '', $date, $date, '', 0, home_url( "/?p=perf-$i" ), 'post', '', 0 );
}
$insert( $wpdb->posts, $post_columns, $rows );
$post_ids = range( $first, $first + $posts - 1 );

// A category and three tags per post.
$rel = array();
foreach ( $post_ids as $n => $id ) {
	$rel[] = array( $id, $categories[ $n % 20 ], 0 );
	foreach ( array( 0, 1, 2 ) as $k ) { $rel[] = array( $id, $tags[ ( $n * 3 + $k ) % 200 ], 0 ); }
}
$insert( $wpdb->term_relationships, array( 'object_id', 'term_taxonomy_id', 'term_order' ), $rel );

// ---- Attachments: records and metadata, no files. ----
$first_attachment = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) + 1 FROM {$wpdb->posts}" );
$rows = array();
for ( $i = 0; $i < $attachments; ++$i ) {
	$rows[] = array( $admin, $now, $now, '', "Perf image $i", '', 'inherit', 'open', 'closed', "perf-$i", '', '', $now, $now, '', $post_ids[ $i % max( 1, $posts ) ] ?? 0, "$base/2026/09/perf-$i.jpg", 'attachment', 'image/jpeg', 0 );
}
$insert( $wpdb->posts, $post_columns, $rows );
$meta = array();
for ( $i = 0; $i < $attachments; ++$i ) {
	$id = $first_attachment + $i;
	$meta[] = array( $id, '_wp_attached_file', "2026/09/perf-$i.jpg" );
	$meta[] = array( $id, '_wp_attachment_metadata', serialize( array( 'width' => 1600, 'height' => 900, 'file' => "2026/09/perf-$i.jpg", 'sizes' => array( 'thumbnail' => array( 'file' => "perf-$i-150x150.jpg", 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' ), 'medium' => array( 'file' => "perf-$i-300x169.jpg", 'width' => 300, 'height' => 169, 'mime-type' => 'image/jpeg' ), 'large' => array( 'file' => "perf-$i-1024x576.jpg", 'width' => 1024, 'height' => 576, 'mime-type' => 'image/jpeg' ) ), 'image_meta' => array() ) ) );
	$meta[] = array( $id, '_wp_attachment_image_alt', "Alt text $i" );
}
$insert( $wpdb->postmeta, array( 'post_id', 'meta_key', 'meta_value' ), $meta );

// ---- Comments, approved, spread over the posts. ----
$rows = array();
for ( $i = 0; $i < $comments; ++$i ) {
	$rows[] = array( $post_ids[ $i % max( 1, $posts ) ], "Reader $i", "reader$i@example.test", '', '192.0.2.1', $now, $now, "Comment $i: a reader's words about the post, long enough to be a real comment.", 0, '1', '', 'comment', 0, 0 );
}
$insert( $wpdb->comments, array( 'comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_author_IP', 'comment_date', 'comment_date_gmt', 'comment_content', 'comment_karma', 'comment_approved', 'comment_agent', 'comment_type', 'comment_parent', 'user_id' ), $rows );
$wpdb->query( "UPDATE {$wpdb->posts} p SET comment_count = (SELECT COUNT(*) FROM {$wpdb->comments} c WHERE c.comment_post_ID = p.ID AND c.comment_approved = '1') WHERE p.post_type = 'post'" ); // phpcs:ignore

wp_update_term_count_now( $categories, 'category' );
wp_update_term_count_now( $tags, 'post_tag' );
wp_cache_flush();
printf( "Large site: %d posts, %d attachments, %d comments, 220 terms in %.1f s\n", $posts, $attachments, $comments, microtime( true ) - $started );
