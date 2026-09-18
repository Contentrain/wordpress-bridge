<?php
/**
 * B-04 fixture: Yoast SEO metadata, redirects from every supported source and a
 * custom permalink structure. Executed inside the throwaway test container.
 * Writes the ids and addresses it created to /tmp/bridge-seo/fixture.json.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
if ( ! defined( 'WPSEO_VERSION' ) || ! defined( 'REDIRECTION_VERSION' ) ) {
	throw new RuntimeException( 'FAIL: Yoast SEO and Redirection must be active for this fixture.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
$run = substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
$f = array( 'run' => $run, 'posts' => array(), 'terms' => array() );

// ---- Site-wide Yoast settings, and integration secrets that must never leave. ----
$titles = get_option( 'wpseo_titles' );
$titles['separator'] = 'sc-pipe';
$titles['title-post'] = '%%title%% %%sep%% %%sitename%% Journal';
$titles['metadesc-page'] = 'Page about %%title%%';
update_option( 'wpseo_titles', $titles );
$social = get_option( 'wpseo_social' );
$social['twitter_site'] = 'contentrainio';
$social['facebook_site'] = 'https://www.facebook.com/contentrain';
update_option( 'wpseo_social', $social );
$general = get_option( 'wpseo' );
$general['googleverify'] = 'bridge-google-verify-code';
$general['semrush_tokens'] = array( 'access_token' => 'ghp_' . str_repeat( 'S', 36 ), 'refresh_token' => 'never-export-semrush' );
$general['wincher_tokens'] = array( 'access_token' => 'never-export-wincher' );
$general['myyoast-oauth'] = array( 'config' => array( 'secret' => 'never-export-myyoast' ) );
update_option( 'wpseo', $general );
$stored = get_option( 'wpseo' );
if ( false === strpos( wp_json_encode( $stored ), 'never-export-semrush' ) ) {
	// Yoast's option validation may drop what we plant; a canary that never landed tests nothing.
	$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $general ) ), array( 'option_name' => 'wpseo' ) );
	wp_cache_delete( 'wpseo', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
}
if ( false === strpos( wp_json_encode( get_option( 'wpseo' ) ), 'never-export-semrush' ) ) {
	throw new RuntimeException( 'FAIL: the Yoast token canary did not land in the database.' );
}

// ---- Content with every per-record SEO field. meta_input lands before save_post, so Yoast indexes it. ----
$make = static function ( $key, $title, $meta = array(), $args = array() ) use ( $admin, $run, &$f ) {
	$id = wp_insert_post( $args + array( 'post_type' => 'post', 'post_title' => $title, 'post_name' => sanitize_title( $title ) . '-' . $run, 'post_content' => '<p>' . str_repeat( $title . ' body text. ', 20 ) . '</p>', 'post_status' => 'publish', 'post_author' => $admin->ID, 'meta_input' => $meta ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'FAIL: fixture ' . $key . ': ' . $id->get_error_message() );
	}
	$f['posts'][ $key ] = $id;
	return $id;
};
$cat = wp_insert_term( 'SEO Topic ' . $run, 'category', array( 'slug' => 'seo-topic-' . $run ) );
$f['terms']['topic'] = (int) $cat['term_id'];
$a = $make( 'custom_title', 'SEO custom title', array( '_yoast_wpseo_title' => 'Custom %%title%% %%sep%% %%sitename%%', '_yoast_wpseo_metadesc' => 'A description written by hand, with "quotes" & ampersands.', '_yoast_wpseo_focuskw' => 'custom title keyword' ), array( 'post_category' => array( $f['terms']['topic'] ) ) );
$make( 'noindex', 'SEO noindex', array( '_yoast_wpseo_meta-robots-noindex' => '1', '_yoast_wpseo_meta-robots-nofollow' => '1', '_yoast_wpseo_metadesc' => 'Hidden from search' ) );
$make( 'canonical', 'SEO canonical page', array( '_yoast_wpseo_canonical' => 'https://example.org/the-original/', '_yoast_wpseo_meta-robots-adv' => 'noimageindex,noarchive' ), array( 'post_type' => 'page' ) );
$make( 'open_graph', 'SEO open graph', array( '_yoast_wpseo_opengraph-title' => 'Shared title', '_yoast_wpseo_opengraph-description' => 'Shared description', '_yoast_wpseo_opengraph-image' => 'https://example.org/share.png' ) );
$make( 'twitter', 'SEO twitter', array( '_yoast_wpseo_twitter-title' => 'Tweet title', '_yoast_wpseo_twitter-description' => 'Tweet description', '_yoast_wpseo_twitter-image' => 'https://example.org/tweet.png' ) );
$make( 'faq_page', 'SEO FAQ page', array( '_yoast_wpseo_schema_page_type' => 'FAQPage', '_yoast_wpseo_metadesc' => 'Questions and answers' ), array( 'post_type' => 'page' ) );
$make( 'news_article', 'SEO news article', array( '_yoast_wpseo_schema_article_type' => 'NewsArticle' ) );
$make( 'defaults', 'SEO defaults only' );
$renamed = $make( 'renamed', 'SEO renamed' );
wp_update_post( array( 'ID' => $renamed, 'post_name' => 'seo-renamed-new-' . $run ) );
$f['old_slug'] = 'seo-renamed-' . $run;
$front = $make( 'front', 'SEO Home ' . $run, array(), array( 'post_type' => 'page' ) );
$blog = $make( 'blog', 'SEO Journal ' . $run, array(), array( 'post_type' => 'page' ) );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $front );
update_option( 'page_for_posts', $blog );

// Term SEO lives in one Yoast option, not term meta.
$tax_meta = (array) get_option( 'wpseo_taxonomy_meta', array() );
$tax_meta['category'][ $f['terms']['topic'] ] = array( 'wpseo_title' => 'Topic %%term_title%% archive', 'wpseo_desc' => 'Everything filed under this topic', 'wpseo_noindex' => 'default' );
update_option( 'wpseo_taxonomy_meta', $tax_meta );

// Data a deactivated Rank Math and AIOSEO left behind: read, never served.
update_post_meta( $a, 'rank_math_title', '%title% %sep% %sitename%' );
update_post_meta( $a, 'rank_math_description', 'Rank Math description' );
update_post_meta( $a, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
update_post_meta( $a, 'rank_math_focus_keyword', 'rank math keyword' );
update_post_meta( $a, 'rank_math_schema_BlogPosting', array( '@type' => 'BlogPosting', 'headline' => '%seo_title%' ) );
$aioseo = $wpdb->prefix . 'aioseo_posts';
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$aioseo} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_id bigint(20) unsigned NOT NULL, title text, description text, keyphrases longtext, canonical_url text, og_title text, og_description text, og_object_type varchar(64) DEFAULT 'default', twitter_title text, twitter_card varchar(64) DEFAULT 'default', robots_default tinyint(1) NOT NULL DEFAULT 1, robots_noindex tinyint(1) NOT NULL DEFAULT 0, robots_nofollow tinyint(1) NOT NULL DEFAULT 0, schema longtext, schema_type varchar(20) DEFAULT 'default', created datetime NOT NULL, updated datetime NOT NULL, PRIMARY KEY (id))" );
$wpdb->insert( $aioseo, array( 'post_id' => $a, 'title' => '#post_title #separator_sa #site_title', 'description' => 'AIOSEO description', 'keyphrases' => wp_json_encode( array( 'focus' => array( 'keyphrase' => 'aioseo keyword' ) ) ), 'robots_default' => 0, 'robots_noindex' => 1, 'schema' => wp_json_encode( array( 'graphs' => array( array( 'graphName' => 'FAQPage' ) ) ) ), 'schema_type' => 'WebPage', 'created' => current_time( 'mysql', true ), 'updated' => current_time( 'mysql', true ) ) );

// ---- Redirects: eight in Redirection, covering served, disabled, gone and conditional. ----
$path = static function ( $id ) { return wp_make_link_relative( get_permalink( $id ) ); };
$red = static function ( $args ) {
	$item = Red_Item::create( $args + array( 'group_id' => 1, 'match_type' => 'url', 'action_type' => 'url', 'action_code' => 301 ) );
	if ( is_wp_error( $item ) ) {
		throw new RuntimeException( 'FAIL: redirection fixture: ' . $item->get_error_message() );
	}
	return $item;
};
$r = array();
$r['plain'] = $red( array( 'url' => '/seo-old-a-' . $run, 'action_data' => array( 'url' => $path( $a ) ) ) )->get_id();
$r['external'] = $red( array( 'url' => '/seo-old-b-' . $run, 'action_data' => array( 'url' => 'https://example.org/b' ), 'action_code' => 302 ) )->get_id();
$r['temporary'] = $red( array( 'url' => '/seo-old-c-' . $run, 'action_data' => array( 'url' => $path( $f['posts']['canonical'] ) ), 'action_code' => 307 ) )->get_id();
$r['absolute'] = $red( array( 'url' => '/seo-old-d-' . $run, 'action_data' => array( 'url' => get_permalink( $f['posts']['faq_page'] ) ), 'action_code' => 308 ) )->get_id();
$r['regex'] = $red( array( 'url' => '^/seo-legacy-' . $run . '/(.*)$', 'regex' => true, 'action_data' => array( 'url' => '/blog/$1' ) ) )->get_id();
$disabled = $red( array( 'url' => '/seo-old-disabled-' . $run, 'action_data' => array( 'url' => '/' ) ) );
$disabled->disable();
$r['disabled'] = $disabled->get_id();
$r['gone'] = $red( array( 'url' => '/seo-gone-' . $run, 'action_type' => 'error', 'action_code' => 410, 'action_data' => array( 'url' => '' ) ) )->get_id();
$r['login'] = $red( array( 'url' => '/seo-members-' . $run, 'match_type' => 'login', 'action_data' => array( 'logged_in' => '/in/', 'logged_out' => '/out/' ) ) )->get_id();
$f['redirection'] = $r;

// Yoast Premium's redirect option, left by an uninstalled Premium.
update_option( 'wpseo-premium-redirects-base', array(
	array( 'origin' => 'yoast-old-' . $run, 'url' => 'yoast-new/', 'type' => 301, 'format' => 'plain' ),
	array( 'origin' => '^yoast-regex-' . $run . '/(.*)', 'url' => '/blog/$1', 'type' => 302, 'format' => 'regex' ),
	array( 'origin' => 'yoast-gone-' . $run, 'url' => '', 'type' => 410, 'format' => 'plain' ),
) );
// Rank Math's redirection table (its real columns), with a two-pattern rule.
$rm = $wpdb->prefix . 'rank_math_redirections';
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$rm} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, sources text NOT NULL, url_to text NOT NULL, header_code smallint(4) unsigned NOT NULL, hits bigint(20) unsigned NOT NULL DEFAULT 0, status varchar(25) NOT NULL DEFAULT 'active', created datetime NOT NULL, updated datetime NOT NULL, last_accessed datetime NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (id))" );
$wpdb->insert( $rm, array( 'sources' => serialize( array( array( 'pattern' => 'rm-old-' . $run, 'comparison' => 'exact' ), array( 'pattern' => 'rm-start-' . $run, 'comparison' => 'start' ) ) ), 'url_to' => home_url( '/rm-new/' ), 'header_code' => 301, 'status' => 'active', 'created' => current_time( 'mysql', true ), 'updated' => current_time( 'mysql', true ) ) );
$wpdb->insert( $rm, array( 'sources' => serialize( array( array( 'pattern' => 'rm-off-' . $run, 'comparison' => 'exact' ) ) ), 'url_to' => '/', 'header_code' => 302, 'status' => 'inactive', 'created' => current_time( 'mysql', true ), 'updated' => current_time( 'mysql', true ) ) );
// Safe Redirect Manager: a rule is a `redirect_rule` post.
foreach ( array( 'publish' => '/srm-old-', 'draft' => '/srm-draft-' ) as $status => $from ) {
	wp_insert_post( array( 'post_type' => 'redirect_rule', 'post_status' => $status, 'post_title' => 'SRM ' . $status, 'meta_input' => array( '_redirect_rule_from' => $from . $run, '_redirect_rule_to' => '/srm-new/', '_redirect_rule_status_code' => 301, '_redirect_rule_from_regex' => 0 ) ) );
}

$f['paths'] = array();
foreach ( $f['posts'] as $key => $id ) {
	$f['paths'][ $key ] = $path( $id );
}
$f['paths']['topic'] = wp_make_link_relative( get_term_link( $f['terms']['topic'] ) );
wp_mkdir_p( '/tmp/bridge-seo' );
file_put_contents( '/tmp/bridge-seo/fixture.json', wp_json_encode( $f, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
echo 'Fixture ' . $run . ': ' . count( $f['posts'] ) . " posts/pages, 8 Redirection rules, 3 Yoast Premium, 3 Rank Math, 2 SRM\n";
