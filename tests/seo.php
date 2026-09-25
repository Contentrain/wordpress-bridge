<?php
/**
 * B-04 acceptance: what Bridge exports about SEO, redirects and routing is what
 * the site actually serves. Every extracted head value is compared with the page
 * WordPress renders, and every exported redirect is requested over HTTP.
 * Run after tests/seo-fixture.php, with Yoast SEO and Redirection active.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Files;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Redirects;
use Contentrain\Bridge\Seo;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
require __DIR__ . '/seo-head.php';
/** The exported block, reduced to what a head carries. */
function expected( $entry ) {
	$robots = $entry['robots'] ?? array();
	$og = $entry['open_graph'] ?? array();
	$twitter = $entry['twitter'] ?? array();
	ksort( $og );
	ksort( $twitter );
	return array_filter( array(
		'title'       => $entry['title'] ?? null,
		'description' => $entry['description'] ?? null,
		'canonical'   => $entry['canonical'] ?? null,
		// What the page carried; directive order carries no meaning to a crawler, so compared as a set.
		'robots'      => ( static function ( $list ) { sort( $list ); return $list ?: null; } )( $entry['robots_served'] ?? array() ),
		'open_graph'  => $og,
		'twitter'     => $twitter,
		'types'       => $entry['schema']['types'] ?? array(),
	), static function ( $v ) { return null !== $v; } );
}
function relative( $url ) {
	$home = wp_parse_url( home_url( '/' ) );
	$target = wp_parse_url( (string) $url );
	return is_array( $target ) && isset( $target['host'] ) && $target['host'] === $home['host'] && ( $target['port'] ?? null ) === ( $home['port'] ?? null ) ? wp_make_link_relative( $url ) : $url;
}

$fixture = json_decode( file_get_contents( '/tmp/bridge-seo/fixture.json' ), true );
$run = $fixture['run'];
$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
register_post_type( 'bridge_seo_book', array( 'public' => true, 'label' => 'Books', 'rewrite' => array( 'slug' => 'library' ), 'has_archive' => 'library' ) );

// ---- Export through the real job pipeline. ----
$previous = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
$summary = Jobs::create( array( 'types' => array( 'post', 'page', 'attachment' ), 'private' => false, 'scan_sources' => false ) );
for ( $i = 0; $i < 800 && 'review' !== $summary['phase']; ++$i ) { $summary = Jobs::step( $summary['id'], $summary['step'] ); }
$summary = Jobs::review( $summary['id'], array(), true );
for ( $i = 0; $i < 800 && 'ready' !== $summary['phase']; ++$i ) { $summary = Jobs::step( $summary['id'], $summary['step'] ); }
check( 'ready' === $summary['phase'], 'export with SEO, redirects and routing reaches ready' );
$job = Jobs::read( $summary['id'] );
$out = Files::dir( $job['id'] ) . '/output';
$read = static function ( $path ) use ( $out ) { return json_decode( Files::read( $out, $path ), true ); };
foreach ( array( 'bridge/seo.json', 'bridge/seo-entries.json', 'bridge/redirects.json', 'bridge/routing.json' ) as $path ) {
	check( isset( $job['files'][ $path ] ), $path . ' is written and hashed in the manifest' );
}
$seo = $read( 'bridge/seo.json' );
$entries = $read( 'bridge/seo-entries.json' );
$redirects = $read( 'bridge/redirects.json' );
$routing = $read( 'bridge/routing.json' );
$raw_posts = $read( 'bridge/raw-posts.json' );

// ---- Providers and site settings. ----
check( 'present' === $seo['status'] && 'yoast' === $seo['serving'], 'Yoast is detected as the SEO plugin the site serves' );
check( 'active' === $seo['providers']['yoast']['status'] && WPSEO_VERSION === $seo['providers']['yoast']['version'], 'the provider carries its version' );
check( 'inactive-with-data' === $seo['providers']['rank_math']['status'] && 'inactive-with-data' === $seo['providers']['aioseo']['status'], 'data left by deactivated Rank Math and AIOSEO is detected, not ignored' );
$settings = $seo['settings']['yoast'];
check( '|' === $settings['separator'], 'the title separator is resolved (sc-pipe -> |)' );
check( '%%title%% %%sep%% %%sitename%% Journal' === $settings['title_templates']['post'] && 'Page about %%title%%' === $settings['description_templates']['page'], 'title and description templates are keyed by content type' );
check( 'contentrainio' === $settings['social']['twitter_site'] && 'bridge-google-verify-code' === $settings['verification']['googleverify'], 'social defaults and verification codes are kept' );
$settings_json = wp_json_encode( $seo );
check( false === strpos( $settings_json, 'never-export' ) && false === strpos( $settings_json, 'ghp_' ) && ! isset( $settings['raw']['wpseo']['semrush_tokens'] ), 'Yoast integration tokens (SEMrush, Wincher, MyYoast) are not exported' );

// ---- Per-record: zero loss against the exported posts, and against the site. ----
$post_keys = array_filter( array_keys( $entries ), static function ( $k ) { return 0 === strpos( $k, 'post:' ); } );
check( count( $post_keys ) === count( $raw_posts ), 'every exported post and page has an SEO entry (' . count( $post_keys ) . ' of ' . count( $raw_posts ) . ')' );
$compared = 0;
$artifacts = '/tmp/bridge-seo';
wp_mkdir_p( $artifacts . '/captured' );
$pages = array();
foreach ( $fixture['posts'] as $key => $id ) {
	$entry = $entries[ 'post:' . $id ]['yoast'] ?? null;
	check( $entry && true === $entry['resolved'], $key . ': exported with the values Yoast renders' );
	$page = fetch( $fixture['paths'][ $key ] );
	check( 200 === $page['status'], $key . ': the page answers 200 at ' . $fixture['paths'][ $key ] );
	$served = head( $page['html'] );
	$want = expected( $entry );
	foreach ( $want as $field => $value ) {
		if ( ( $served[ $field ] ?? null ) !== $value ) {
			echo "  $field exported: " . wp_json_encode( $value ) . "\n  $field served:   " . wp_json_encode( $served[ $field ] ?? null ) . "\n";
		}
	}
	$missing = array_diff_key( array_filter( $served ), $want );
	check( $want == array_intersect_key( $served, $want ) && ! $missing, $key . ': every head value the site serves is exported, and equal (' . implode( ',', array_keys( $want ) ) . ')' );
	++$compared;
	file_put_contents( $artifacts . '/captured/' . $key . '.html', $page['html'] );
	$pages[] = array( 'key' => $key, 'entry' => 'post:' . $id, 'url' => $fixture['paths'][ $key ] );
}
$yoast = static function ( $key ) use ( $entries, $fixture ) { return $entries[ 'post:' . $fixture['posts'][ $key ] ]['yoast']; };
check( 'custom title keyword' === $yoast( 'custom_title' )['focus_keyword'] && 'Custom %%title%% %%sep%% %%sitename%%' === $yoast( 'custom_title' )['stored']['title'], 'the focus keyword and the stored template travel with the rendered value' );
check( 'noindex' === $yoast( 'noindex' )['robots']['index'] && 'nofollow' === $yoast( 'noindex' )['robots']['follow'], 'robots directives are structured' );
check( 'https://example.org/the-original/' === $yoast( 'canonical' )['canonical'], 'a canonical override is kept' );
check( in_array( 'FAQPage', $yoast( 'faq_page' )['schema']['types'], true ) && in_array( 'NewsArticle', $yoast( 'news_article' )['schema']['types'], true ), 'schema page and article type overrides reach the JSON-LD types' );
check( isset( $yoast( 'defaults' )['schema']['graph']['@graph'] ), 'the JSON-LD graph itself is exported, not only its types' );
// Term archive: Yoast keeps term SEO in an option.
$term_entry = $entries[ 'term:category:' . $fixture['terms']['topic'] ]['yoast'] ?? null;
check( $term_entry && 'Everything filed under this topic' === $term_entry['description'] && 'Topic %%term_title%% archive' === $term_entry['stored']['wpseo_title'], 'term SEO is exported from wpseo_taxonomy_meta' );
$page = fetch( $fixture['paths']['topic'] );
$served = head( $page['html'] );
$want = expected( $term_entry );
check( 200 === $page['status'] && $want == array_intersect_key( $served, $want ), 'the term archive head equals its exported entry' );
file_put_contents( $artifacts . '/captured/topic.html', $page['html'] );
$pages[] = array( 'key' => 'topic', 'entry' => 'term:category:' . $fixture['terms']['topic'], 'url' => $fixture['paths']['topic'] );
// Stored data of the deactivated plugins, unresolved and saying so.
$rm = $entries[ 'post:' . $fixture['posts']['custom_title'] ]['rank_math'];
check( false === $rm['resolved'] && '%title% %sep% %sitename%' === $rm['title'] && 'noindex' === $rm['robots']['index'] && array( 'BlogPosting' ) === $rm['schema']['types'] && 'rank math keyword' === $rm['focus_keyword'], 'Rank Math stored meta is normalized, marked unresolved' );
$aio = $entries[ 'post:' . $fixture['posts']['custom_title'] ]['aioseo'];
check( false === $aio['resolved'] && 'AIOSEO description' === $aio['description'] && 'noindex' === $aio['robots']['index'] && 'aioseo keyword' === $aio['focus_keyword'] && array( 'FAQPage', 'WebPage' ) === $aio['schema']['types'], 'AIOSEO table row is normalized, marked unresolved' );

// ---- BR-16: templates rendered to text, for the providers not running. ----
$site = get_bloginfo( 'name' );
$post_of = static function ( $key, $provider ) use ( $entries, $fixture ) { return $entries[ 'post:' . $fixture['posts'][ $key ] ][ $provider ] ?? null; };
check( 'inactive-with-data' === $seo['providers']['seopress']['status'] && 4 === count( $seo['providers'] ), 'SEOPress data is detected, a fourth provider' );
check( '·' === $seo['settings']['seopress']['separator'] && 'Page: %%post_title%% %%sep%% %%sitetitle%%' === $seo['settings']['seopress']['title_templates']['page'], 'SEOPress settings: separator and templates keyed by content type' );
check( ! isset( $post_of( 'custom_title', 'yoast' )['rendered'] ), 'Yoast is running: its resolved values stand, nothing re-rendered' );
$rm = $post_of( 'custom_title', 'rank_math' );
check( 'SEO custom title - ' . $site === $rm['rendered']['title'] && 'post' === $rm['template_source']['title'] && 'bridge' === $rm['rendered_by'] && array() === $rm['unresolved'], 'Rank Math: %title% %sep% %sitename% renders to "' . $rm['rendered']['title'] . '"' );
check( array( 'index' => 'noindex', 'follow' => 'nofollow' ) == $rm['rendered']['robots'] && 'post' === $rm['template_source']['robots'], 'Rank Math: robots from the record: ' . wp_json_encode( $rm['rendered']['robots'] ) . ' from ' . $rm['template_source']['robots'] . ' (blog_public ' . get_option( 'blog_public' ) . ')' );
check( get_permalink( $fixture['posts']['custom_title'] ) === $rm['rendered']['canonical'], 'Rank Math: canonical is the source address: ' . $rm['rendered']['canonical'] . ' / ' . get_permalink( $fixture['posts']['custom_title'] ) );
check( $rm['rendered']['title'] === $rm['rendered']['schema']['graph'][0]['headline'] && ! isset( $rm['rendered']['schema']['graph'][0]['metadata'] ), 'Rank Math: %seo_title% inside the stored schema node renders too' );
check( ! isset( $rm['schema']['graph'] ) && array( 'BlogPosting' ) === $rm['schema']['types'], 'Rank Math not running: the top-level schema has types only, never a graph with template tokens' );
$rm_default = $post_of( 'defaults', 'rank_math' );
check( 'SEO defaults only - ' . $site === $rm_default['rendered']['title'] && 'default' === $rm_default['template_source']['title'] && 0 === strpos( $rm_default['rendered']['description'], 'SEO defaults only body text.' ) && array( 'index' => 'index', 'follow' => 'follow' ) == $rm_default['rendered']['robots'], 'Rank Math: a post with nothing of its own gets the plugin\'s default title, excerpt and robots' );
$unresolved = $post_of( 'unresolved', 'rank_math' );
check( 'SEO unresolved - ' . $site === $unresolved['rendered']['title'] && array( '%unknownvar%' ) === $unresolved['unresolved'] && 'Subtitle from a field' === $unresolved['rendered']['description'], 'an unknown variable is left out and named; a custom field variable reads the field' );
$aio = $post_of( 'custom_title', 'aioseo' );
check( 'SEO custom title - ' . $site === $aio['rendered']['title'] && 'AIOSEO description' === $aio['rendered']['description'] && 'noindex' === $aio['rendered']['robots']['index'] && 'post' === $aio['template_source']['robots'], 'AIOSEO: #post_title #separator_sa #site_title renders, robots from its row' );
$sp = $post_of( 'custom_title', 'seopress' );
check( 'SEO custom title · ' . $site === $sp['rendered']['title'] && 'SEOPress description' === $sp['rendered']['description'] && 'noindex' === $sp['rendered']['robots']['index'] && 'post' === $sp['template_source']['robots'], 'SEOPress: its own title and robots, rendered with its separator' );
$sp_page = $post_of( 'canonical', 'seopress' );
check( 'Page: SEO canonical page · ' . $site === $sp_page['rendered']['title'] && 'post_type' === $sp_page['template_source']['title'], 'SEOPress: a page with no title of its own uses the page template' );
$topic = $entries[ 'term:category:' . $fixture['terms']['topic'] ];
$term_name = get_term( $fixture['terms']['topic'] )->name;
check( 'Topic ' . $term_name . ' - ' . $site === $topic['rank_math']['rendered']['title'] && 'post' === $topic['rank_math']['template_source']['title'], 'term: Rank Math term meta renders' );
check( 'Topic ' . $term_name === $topic['seopress']['rendered']['title'] && 'noindex' === $topic['seopress']['rendered']['robots']['index'] && 'post_type' === $topic['seopress']['template_source']['robots'], 'term: the SEOPress taxonomy template and its noindex apply' );
check( 'All about ' . $term_name . ' - ' . $site === $topic['aioseo']['rendered']['title'] && 'post' === $topic['aioseo']['template_source']['title'] && 'AIOSEO term description' === $topic['aioseo']['rendered']['description'] && 'noindex' === $topic['aioseo']['rendered']['robots']['index'] && 'post' === $topic['aioseo']['template_source']['robots'], 'term: AIOSEO Pro\'s aioseo_terms row is the term\'s own title, description and robots' );
$dirty = array();
foreach ( $entries as $key => $entry ) {
	foreach ( $entry as $provider => $block ) {
		foreach ( array( 'title', 'description' ) as $field ) {
			$text = $block['rendered'][ $field ] ?? '';
			if ( preg_match( '/%%?[a-z_]+(\([^)]*\))?%%?|#(post_title|site_title|separator_sa|tagline|taxonomy_title)/', $text ) ) {
				$dirty[] = "$key/$provider/$field: $text";
			}
		}
	}
}
check( ! $dirty, 'no rendered title or description carries a template variable' . ( $dirty ? ': ' . implode( '; ', array_slice( $dirty, 0, 3 ) ) : '' ) );

// ---- Redirects: accounting, then the live site. ----
global $wpdb;
$by_source = array();
foreach ( array_merge( $redirects['redirects'], $redirects['excluded'] ) as $rule ) {
	$by_source[ $rule['source'] ] = ( $by_source[ $rule['source'] ] ?? 0 ) + 1;
}
$db = array(
	'redirection'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}redirection_items" ),
	'yoast_premium'         => count( get_option( 'wpseo-premium-redirects-base' ) ),
	'rank_math'             => array_sum( array_map( static function ( $s ) { return count( unserialize( $s ) ); }, $wpdb->get_col( "SELECT sources FROM {$wpdb->prefix}rank_math_redirections" ) ) ),
	'safe_redirect_manager' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'redirect_rule' AND post_status <> 'auto-draft'" ),
	'wordpress'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_old_slug' AND p.post_status = 'publish'" ),
	'simple_301_redirects'  => count( get_option( '301_redirects' ) ),
	// Counted apart from the reader: every Redirect*/RewriteRule line outside WordPress's own block.
	'htaccess'              => count( preg_grep( '/^\s*(Redirect|RedirectPermanent|RedirectTemp|RedirectMatch|RewriteRule)\s/i', explode( "\n", preg_replace( '/# BEGIN WordPress.*?# END WordPress/s', '', (string) file_get_contents( ABSPATH . '.htaccess' ) ) ) ) ),
);
$sources_key = array( 'wordpress' => 'wordpress_old_slug' );
foreach ( $db as $source => $count ) {
	check( $count > 0 && $count === ( $by_source[ $source ] ?? 0 ) && $count === $redirects['sources'][ $sources_key[ $source ] ?? $source ]['rules'], "zero loss: $source holds $count rules; every one is exported or excluded with a reason" );
}
$rule = static function ( $id ) use ( $redirects ) {
	foreach ( array_merge( $redirects['redirects'], $redirects['excluded'] ) as $r ) { if ( $id === $r['id'] ) { return $r; } }
	return null;
};
$f = $fixture['redirection'];
$served_rules = array( 'plain', 'external', 'temporary', 'absolute' );
foreach ( $served_rules as $name ) {
	$r = $rule( 'redirection:' . $f[ $name ] );
	check( $r && ! isset( $r['reason'] ), "redirection $name is listed as served" );
	$live = fetch( $r['from'] );
	check( $live['status'] === $r['status'] && relative( $live['location'] ) === $r['to'], "redirection $name: the site answers {$live['status']} -> {$live['location']}, as exported ({$r['status']} -> {$r['to']})" );
}
check( '/' === substr( $rule( 'redirection:' . $f['absolute'] )['to'], 0, 1 ), 'a same-host absolute target is normalized to a root-relative path' );
check( 'https://example.org/b' === $rule( 'redirection:' . $f['external'] )['to'], 'an external target stays absolute' );
$regex = $rule( 'redirection:' . $f['regex'] );
$live = fetch( '/seo-legacy-' . $run . '/some/deep' );
check( true === $regex['regex'] && ! isset( $regex['reason'] ) && 301 === $live['status'] && '/blog/some/deep' === relative( $live['location'] ), 'a regex rule is exported as a pattern, and the site applies it' );
check( 'disabled' === $rule( 'redirection:' . $f['disabled'] )['reason'] && 404 === fetch( '/seo-old-disabled-' . $run )['status'], 'a disabled rule is excluded, and the site does not serve it' );
$gone = $rule( 'redirection:' . $f['gone'] );
check( ! isset( $gone['reason'] ) && 410 === $gone['status'] && '' === $gone['to'] && 410 === fetch( '/seo-gone-' . $run )['status'], 'a 410 rule is served as 410 with no target, as the site answers' );
$flags = $rule( 'redirection:' . $f['flags'] );
$live = fetch( '/SEO-Flags-' . $run . '?utm=x' );
check( 'ignore' === $flags['query'] && true === $flags['case_insensitive'] && 'ignore' === $flags['trailing_slash'] && 301 === $live['status'] && $flags['to'] === relative( $live['location'] ), 'Redirection\'s matching flags are exported, and the site matches as they say (other case, no slash, a query dropped)' );
// A rule without flags of its own: whatever Redirection applies to it here is what was exported.
$plain = $rule( 'redirection:' . $f['plain'] );
$upper = fetch( strtoupper( $plain['from'] ) );
$slash = fetch( $plain['from'] . '/' );
check( $plain['case_insensitive'] === ( 301 === $upper['status'] ) && ( 'ignore' === $plain['trailing_slash'] ) === ( 301 === $slash['status'] ), 'a rule without its own flags carries the flags Redirection applies to it (case ' . wp_json_encode( $plain['case_insensitive'] ) . ' → ' . $upper['status'] . ', slash ' . $plain['trailing_slash'] . ' → ' . $slash['status'] . ')' );
check( 'conditional-match:login' === $rule( 'redirection:' . $f['login'] )['reason'] && '/out/' === $rule( 'redirection:' . $f['login'] )['condition']['logged_out'], 'a login-conditional rule is excluded with its condition kept' );
$old = null;
foreach ( $redirects['redirects'] as $r ) { if ( 'wordpress' === $r['source'] && false !== strpos( $r['from'], $fixture['old_slug'] . '/' ) ) { $old = $r; } }
$live = $old ? fetch( $old['from'] ) : null;
check( $old && $fixture['paths']['renamed'] === $old['to'] && 301 === $live['status'] && $old['to'] === relative( $live['location'] ), 'WordPress\'s own old-slug redirect is exported, and the site serves it' );
foreach ( array( 'yoast_premium', 'rank_math', 'safe_redirect_manager' ) as $source ) {
	$reasons = array();
	foreach ( $redirects['excluded'] as $r ) { if ( $source === $r['source'] ) { $reasons[] = $r['reason']; } }
	check( 'inactive-with-data' === $redirects['sources'][ $source ]['status'] && in_array( 'source-inactive', $reasons, true ), "$source rules are kept but not listed as served: its plugin is not running" );
}
check( 'yoast-new/' !== $rule( 'yoast_premium:0' )['to'] && '/yoast-new/' === $rule( 'yoast_premium:0' )['to'] && '/yoast-old-' . $run === $rule( 'yoast_premium:0' )['from'], 'Yoast Premium paths are made root-relative' );
check( 410 === $rule( 'yoast_premium:2' )['status'] && '' === $rule( 'yoast_premium:2' )['to'] && true === $rule( 'yoast_premium:1' )['regex'], 'Yoast Premium 410 and regex rules are classified' );
// Simple 301 Redirects (inactive): a plain rule and a wildcard as a regex.
check( 'inactive-with-data' === $redirects['sources']['simple_301_redirects']['status'] && '/s301-new/' === $rule( 'simple_301_redirects:0' )['to'] && '^/s301-wild-' . $run . '/(.*)$' === $rule( 'simple_301_redirects:1' )['from'] && '/s301-target/$1' === $rule( 'simple_301_redirects:1' )['to'], 'Simple 301 Redirects: plain and wildcard rules, kept but not served' );
// .htaccess: each rule as exported, then as the live site answers it.
$ht = array();
foreach ( array_merge( $redirects['redirects'], $redirects['excluded'] ) as $r ) {
	if ( 'htaccess' === $r['source'] && false !== strpos( $r['from'], $run ) ) {
		$ht[ preg_replace( '/[^a-z]/', '', explode( $run, $r['from'] )[0] ) ] = $r;
	}
}
check( 'active' === $redirects['sources']['htaccess']['status'] && 6 === count( $ht ), '.htaccess: six rules outside WordPress\'s block are read: ' . implode( ',', array_keys( $ht ) ) );
$live = fetch( '/ht-old-' . $run . '/deep' );
check( 'start' === $ht['htold']['match'] && '/ht-new' === $ht['htold']['to'] && 301 === $live['status'] && '/ht-new/deep' === relative( $live['location'] ), 'Redirect is a prefix rule, and the site carries the rest of the path' );
$live = fetch( '/ht-match-' . $run . '/a' );
check( true === $ht['htmatch']['regex'] && 302 === $ht['htmatch']['status'] && 302 === $live['status'] && '/blog/a' === relative( $live['location'] ), 'RedirectMatch is a regex rule, as served' );
check( 410 === $ht['htgone']['status'] && '' === $ht['htgone']['to'] && 410 === fetch( '/ht-gone-' . $run )['status'], 'Redirect gone is a 410' );
$live = fetch( '/HT-RW-' . $run . '/abc' );
check( '^/ht-rw-' . $run . '/(.*)$' === $ht['htrw']['from'] && true === $ht['htrw']['case_insensitive'] && 301 === $live['status'] && '/rw-new/abc' === relative( $live['location'] ), 'RewriteRule [R=301,NC]: the pattern gets its leading slash, and the site matches any case' );
check( 'conditional-match:htaccess' === $ht['htcond']['reason'] && array( 'host' ) === $ht['htcond']['condition'] && false === strpos( wp_json_encode( $ht['htcond'] ), 'never' ) && 404 === fetch( '/ht-cond-' . $run )['status'], 'a RewriteRule behind a RewriteCond is excluded with what its condition tests (not the value), and the site does not serve it here' );
check( 'not-a-redirect:rewrite' === $ht['htinternal']['reason'], 'an internal rewrite is accounted for, not a redirect' );
$rm_rules = array_values( array_filter( array_merge( $redirects['redirects'], $redirects['excluded'] ), static function ( $r ) use ( $run ) { return 'rank_math' === $r['source'] && false !== strpos( $r['from'], $run ); } ) );
check( 3 === count( $rm_rules ) && '/rm-new/' === $rm_rules[0]['to'] && 'start' === $rm_rules[1]['match'] && 'disabled' === $rm_rules[2]['reason'], 'Rank Math: two patterns become two rules, the inactive row is disabled' );
foreach ( $redirects['redirects'] as $r ) {
	$gone = in_array( $r['status'] ?? 0, array( 410, 451 ), true ) && '' === ( $r['to'] ?? null );
	if ( ! isset( $r['from'], $r['to'], $r['status'], $r['source'] ) || ( ! $gone && ( $r['status'] < 300 || $r['status'] > 399 ) ) ) {
		throw new RuntimeException( 'FAIL: RawRedirect shape ' . wp_json_encode( $r ) );
	}
}
check( true, 'every listed redirect has from, to, a 3xx status (or 410/451 with no target) and a source (RawRedirect)' );

// ---- Routing. ----
check( '/blog/%year%/%postname%/' === $routing['permalink_structure'] && true === $routing['trailing_slash'] && 'topics' === $routing['category_base'] && 'labels' === $routing['tag_base'], 'the custom permalink structure and bases are exported' );
check( '/' === $routing['page_on_front']['path'] && $fixture['posts']['front'] === $routing['page_on_front']['id'] && $fixture['paths']['blog'] === $routing['page_for_posts']['path'], 'the static front page and posts page are exported with their addresses' );
$book = array_values( array_filter( $routing['post_types'], static function ( $t ) { return 'bridge_seo_book' === $t['name']; } ) )[0] ?? null;
// with_front defaults to true: the structure's static `/blog/` prefix applies to the CPT too.
check( $book && 'library' === $book['rewrite']['slug'] && true === $book['rewrite']['with_front'] && '/blog/library/' === $book['archive_path'] && '/blog/library/%bridge_seo_book%' === $book['permastruct'] && '/blog/' === $routing['front'], 'a CPT\'s rewrite slug, with_front, archive and permastruct are exported' );
$category = array_values( array_filter( $routing['taxonomies'], static function ( $t ) { return 'category' === $t['name']; } ) )[0];
check( '/topics/%category%' === $category['permastruct'], 'the category base reaches the taxonomy permastruct' );
$a_path = wp_make_link_relative( get_permalink( $fixture['posts']['custom_title'] ) );
check( (bool) preg_match( '#^/blog/\d{4}/seo-custom-title-' . $run . '/$#', $a_path ), 'the structure predicts the served address: ' . $a_path );

// ---- Nothing secret anywhere in the export. ----
$leaks = array();
foreach ( array_keys( $job['files'] ) as $path ) {
	if ( 0 === strpos( $path, 'media/' ) ) { continue; }
	$content = Files::read( $out, $path );
	foreach ( array( 'never-export-semrush', 'never-export-wincher', 'never-export-myyoast', 'ghp_' ) as $canary ) {
		if ( false !== strpos( $content, $canary ) ) { $leaks[] = "$path: $canary"; }
	}
}
check( ! $leaks, 'no planted secret in any of ' . count( $job['files'] ) . ' exported files' . ( $leaks ? ': ' . implode( ', ', $leaks ) : '' ) );

// ---- Artifacts for the verify parity test. ----
foreach ( array( 'bridge/seo.json', 'bridge/seo-entries.json', 'bridge/redirects.json', 'bridge/routing.json' ) as $path ) {
	copy( Files::path( $out, $path ), $artifacts . '/' . basename( $path ) );
}
file_put_contents( $artifacts . '/pages.json', wp_json_encode( array( 'site' => untrailingslashit( home_url() ), 'pages' => $pages ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
Files::remove( Files::dir( $job['id'] ) );
delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
if ( $previous ) { update_user_meta( $admin->ID, 'contentrain_bridge_job_1', $previous ); }
echo "\n$checks checks passed ($compared pages compared field by field). SEO output: $artifacts\n";
