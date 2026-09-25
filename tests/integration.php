<?php
/** Executed inside the dedicated WordPress test container, never a production site. */
// Polylang only builds its language model (PLL()) in an admin, REST, or
// already-has-languages context; a plain CLI script is none of those until a
// language exists. This never runs a real request, so it is safe to force.
define( 'WP_ADMIN', true );
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Admin;
use Contentrain\Bridge\Files;
use Contentrain\Bridge\GitHub;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Models;
use Contentrain\Bridge\Policy;
use Contentrain\Bridge\Scanner;
use Contentrain\Bridge\Source;
use Contentrain\Bridge\Validator;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
function rejects( $callback, $message ) {
	try { $callback(); } catch ( Throwable $error ) { check( true, $message . ' [' . $error->getMessage() . ']' ); return; }
	check( false, $message );
}
$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
Files::cleanup();
$existing = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
if ( $existing ) { Files::remove( Files::dir( $existing ) ); delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' ); }
register_post_type( 'bridge_book', array( 'public' => true, 'show_in_rest' => false, 'label' => 'Books' ) );
$category = term_exists( 'bridge-fixture-category', 'category' );
if ( ! $category ) { $category = wp_insert_term( 'Bridge fixture category', 'category', array( 'slug' => 'bridge-fixture-category' ) ); }
$post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Bridge article', 'post_content' => '<h2>Heading</h2><p>Hello <strong>world</strong>.</p><table><tr><td>Preserved</td></tr></table>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
wp_set_post_categories( $post, array( (int) $category['term_id'] ) );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'About Bridge', 'post_content' => '<p>Editable page body</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
$draft = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Private draft', 'post_content' => 'Should not be public', 'post_status' => 'draft', 'post_author' => $admin->ID ) );
$book = wp_insert_post( array( 'post_type' => 'bridge_book', 'post_title' => 'Hidden REST book', 'post_content' => '<p>Book body</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
update_post_meta( $post, 'api_token', 'never-export-me' );
update_post_meta( $post, 'selected_copy', 'Editable custom content' );
update_post_meta( $post, 'structured', array( 'title' => 'Hero title', 'api_key' => 'never-export-nested', 'items' => array( array( 'label' => 'First', 'enabled' => true ) ) ) );
// A real upload, so media transfer and relinking are exercised end to end.
require_once ABSPATH . 'wp-admin/includes/image.php';
$uploads = wp_get_upload_dir();
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$image_path = $uploads['path'] . '/bridge-pixel.png';
wp_mkdir_p( $uploads['path'] );
file_put_contents( $image_path, $png );
$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Bridge pixel', 'post_status' => 'inherit' ), $image_path );
wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $image_path ) );
$image_url = wp_get_attachment_url( $attachment );
$missing_path = $uploads['path'] . '/bridge-ghost.png';
file_put_contents( $missing_path, $png );
$ghost = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Bridge ghost', 'post_status' => 'inherit' ), $missing_path );
unlink( $missing_path );
$illustrated = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Illustrated', 'post_content' => '<p>Before</p><img src="' . esc_url( $image_url ) . '" alt="Pixel" /><p>After</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );

// An ACF group whose shape is the point: a repeater of records and a group of
// named fields must become models, not anonymous structured rows.
// Not `$acf`: this script runs in global scope and ACF keeps its instance in
// $GLOBALS['acf'], so that name would overwrite the plugin out from under itself.
$has_acf = function_exists( 'acf_add_local_field_group' );
if ( $has_acf ) {
	acf_add_local_field_group( array(
		'key' => 'group_bridge',
		'title' => 'Bridge fields',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		'fields' => array(
			array( 'key' => 'field_bridge_tagline', 'name' => 'tagline', 'label' => 'Tagline', 'type' => 'text' ),
			// B4: an email field the owner built is published content; a secret is judged by type or a credential name.
			array( 'key' => 'field_bridge_contact_email', 'name' => 'contact_email', 'label' => 'Contact email', 'type' => 'email' ),
			array( 'key' => 'field_bridge_api_token', 'name' => 'api_token', 'label' => 'API token', 'type' => 'text' ),
			array( 'key' => 'field_bridge_door', 'name' => 'door_code', 'label' => 'Door code', 'type' => 'password' ),
			array( 'key' => 'field_bridge_rank', 'name' => 'rank', 'label' => 'Rank', 'type' => 'number' ),
			array( 'key' => 'field_bridge_featured', 'name' => 'featured', 'label' => 'Featured', 'type' => 'true_false' ),
			array(
				'key' => 'field_bridge_hero', 'name' => 'hero', 'label' => 'Hero', 'type' => 'group',
				'sub_fields' => array(
					array( 'key' => 'field_bridge_hero_title', 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ),
					array( 'key' => 'field_bridge_hero_cta', 'name' => 'cta', 'label' => 'Call to action', 'type' => 'text' ),
				),
			),
			array(
				'key' => 'field_bridge_quotes', 'name' => 'quotes', 'label' => 'Testimonials', 'type' => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_bridge_quote_person', 'name' => 'person', 'label' => 'Person', 'type' => 'text' ),
					array( 'key' => 'field_bridge_quote_text', 'name' => 'quote', 'label' => 'Quote', 'type' => 'textarea' ),
				),
			),
			array( 'key' => 'field_bridge_rel', 'name' => 'rel', 'label' => 'Related posts', 'type' => 'relationship' ),
			array( 'key' => 'field_bridge_rel_mixed', 'name' => 'rel_mixed', 'label' => 'Related mixed', 'type' => 'relationship' ),
			array( 'key' => 'field_bridge_rel_out_of_scope', 'name' => 'rel_out_of_scope', 'label' => 'Related out of scope', 'type' => 'relationship' ),
			array( 'key' => 'field_bridge_po', 'name' => 'po', 'label' => 'Featured post', 'type' => 'post_object' ),
			array( 'key' => 'field_bridge_cat', 'name' => 'cat', 'label' => 'Category', 'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'select' ),
			array( 'key' => 'field_bridge_gallery', 'name' => 'gallery', 'label' => 'Gallery', 'type' => 'gallery' ),
			// Named `person`, exactly like the repeater's own sub-field below:
			// two fields sharing a name at different nesting depths is legal
			// ACF/SCF, resolved correctly by key — see the `update_field` calls
			// below, which save by key for exactly this reason.
			array( 'key' => 'field_bridge_person', 'name' => 'person', 'label' => 'Person', 'type' => 'user' ),
			array( 'key' => 'field_bridge_cta', 'name' => 'cta_link', 'label' => 'CTA link', 'type' => 'link' ),
			// B1: a page_link stores the linked post's ID; modelled as a URL, it
			// once failed the whole export's validation.
			array( 'key' => 'field_bridge_landing', 'name' => 'landing', 'label' => 'Landing page', 'type' => 'page_link' ),
			array( 'key' => 'field_bridge_landing_draft', 'name' => 'landing_draft', 'label' => 'Draft landing page', 'type' => 'page_link' ),
			// B3: values with parts, and lists of choices.
			array( 'key' => 'field_bridge_map', 'name' => 'office', 'label' => 'Office', 'type' => 'google_map' ),
			array( 'key' => 'field_bridge_topics', 'name' => 'topics', 'label' => 'Topics', 'type' => 'checkbox', 'choices' => array( 'news' => 'News', 'guides' => 'Guides', 'events' => 'Events' ) ),
			array( 'key' => 'field_bridge_tags', 'name' => 'audience', 'label' => 'Audience', 'type' => 'select', 'multiple' => 1, 'choices' => array( 'dev' => 'Developers', 'ed' => 'Editors' ) ),
			array( 'key' => 'field_bridge_tier', 'name' => 'tier', 'label' => 'Tier', 'type' => 'select', 'choices' => array( 'gold' => 'Gold', 'silver' => 'Silver' ) ),
			array(
				'key' => 'field_bridge_faq', 'name' => 'faq', 'label' => 'FAQ', 'type' => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_bridge_faq_q', 'name' => 'question', 'label' => 'Question', 'type' => 'text' ),
					array(
						'key' => 'field_bridge_faq_links', 'name' => 'links', 'label' => 'Links', 'type' => 'repeater',
						'sub_fields' => array( array( 'key' => 'field_bridge_faq_link', 'name' => 'link', 'label' => 'Link', 'type' => 'link' ) ),
					),
				),
			),
			array(
				'key' => 'field_bridge_deep', 'name' => 'deep', 'label' => 'Too deep', 'type' => 'group',
				'sub_fields' => array(
					array(
						'key' => 'field_bridge_deep_2', 'name' => 'inner', 'type' => 'group',
						'sub_fields' => array( array( 'key' => 'field_bridge_deep_3', 'name' => 'innermost', 'type' => 'group', 'sub_fields' => array( array( 'key' => 'field_bridge_deep_text', 'name' => 'text', 'type' => 'text' ) ) ) ),
					),
				),
			),
			array(
				'key' => 'field_bridge_sections', 'name' => 'sections', 'label' => 'Sections', 'type' => 'flexible_content',
				'layouts' => array(
					'layout_bridge_text' => array( 'key' => 'layout_bridge_text', 'name' => 'text_block', 'label' => 'Text', 'sub_fields' => array(
						array( 'key' => 'field_bridge_sec_heading_a', 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ),
						array( 'key' => 'field_bridge_sec_body', 'name' => 'body', 'label' => 'Body', 'type' => 'textarea' ),
					) ),
					'layout_bridge_quote' => array( 'key' => 'layout_bridge_quote', 'name' => 'quote_block', 'label' => 'Quote', 'sub_fields' => array(
						array( 'key' => 'field_bridge_sec_heading_b', 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ),
						array( 'key' => 'field_bridge_sec_quote', 'name' => 'quote', 'label' => 'Quote', 'type' => 'text' ),
					) ),
				),
			),
		),
	) );
	update_field( 'tagline', 'Content you own', $page );
	update_field( 'contact_email', 'hello@example.test', $page );
	update_field( 'api_token', 'planted-acf-token-value', $page );
	update_field( 'door_code', 'planted-acf-door-code', $page );
	update_field( 'rank', 3, $page );
	update_field( 'featured', true, $page );
	update_field( 'hero', array( 'heading' => 'Own your words', 'cta' => 'Start now' ), $page );
	update_field( 'quotes', array(
		array( 'person' => 'Ada', 'quote' => 'It reads like my site.' ),
		array( 'person' => 'Grace', 'quote' => 'The diff is the content.' ),
	), $page );
	update_field( 'rel', array( $post ), $page );
	update_field( 'rel_mixed', array( $post, $book ), $page );
	update_field( 'rel_out_of_scope', array( $draft, 999999 ), $page );
	update_field( 'po', $post, $page );
	update_field( 'cat', (int) $category['term_id'], $page );
	update_field( 'gallery', array( $attachment, $ghost ), $page );
	update_field( 'field_bridge_person', $admin->ID, $page );
	update_field( 'cta_link', array( 'title' => 'Read more', 'url' => 'https://example.test/read-more', 'target' => '_blank' ), $page );
	update_field( 'landing', $post, $page );
	update_field( 'office', array( 'address' => '1 Example Street', 'lat' => 52.37, 'lng' => 4.89, 'zoom' => 14 ), $page );
	update_field( 'topics', array( 'guides', 'news' ), $page );
	update_field( 'audience', array( 'ed' ), $page );
	// A choice the site has since removed: kept in the database, gone from the field.
	update_post_meta( $page, 'tier', 'bronze' );
	update_post_meta( $page, '_tier', 'field_bridge_tier' );
	update_field( 'faq', array(
		array( 'question' => 'Where do I start?', 'links' => array( array( 'link' => array( 'url' => 'https://example.test/start', 'title' => 'Start here', 'target' => '' ) ) ) ),
		array( 'question' => 'Is it mine?', 'links' => array() ),
	), $page );
	update_field( 'deep', array( 'inner' => array( 'innermost' => array( 'text' => 'Three levels down' ) ) ), $page );
	update_field( 'landing_draft', $draft, $page );
	update_field( 'sections', array(
		array( 'acf_fc_layout' => 'text_block', 'heading' => 'Intro', 'body' => 'Welcome copy' ),
		array( 'acf_fc_layout' => 'quote_block', 'heading' => 'Praise', 'quote' => 'It just works.' ),
	), $page );
}

// clone and Options Page have no free ACF license to test against; Secure
// Custom Fields (WP.org, an ACF fork with the same function names and
// database format) carries them for free. `function_exists('acf_add_options_page')`
// is false under ACF Free specifically, so this fixture degrades gracefully
// if it is ever run there instead.
$has_scf_pro = function_exists( 'acf_add_options_page' );
if ( $has_scf_pro ) {
	acf_add_local_field_group( array(
		'key' => 'group_bridge_clone_source', 'title' => 'Clone source',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		'fields' => array(
			array( 'key' => 'field_bridge_clone_src_text', 'name' => 'note', 'label' => 'Note', 'type' => 'text' ),
			array( 'key' => 'field_bridge_clone_src_num', 'name' => 'priority', 'label' => 'Priority', 'type' => 'number' ),
		),
	) );
	// Seamless: the clone field replaces itself with the source fields under
	// their own names — indistinguishable from adding `note`/`priority` to
	// this field group directly. No prefix, since the only way found to save
	// a *prefixed* seamless clone's value correctly is through its wrapper
	// field, and a wrapper is exactly what seamless does not have.
	acf_add_local_field_group( array(
		'key' => 'group_bridge_clone_seamless', 'title' => 'Clone seamless use',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		'fields' => array(
			array( 'key' => 'field_bridge_clone_seamless', 'name' => 'clone_seamless', 'label' => 'Clone seamless', 'type' => 'clone', 'clone' => array( 'group_bridge_clone_source' ), 'display' => 'seamless', 'prefix_name' => 0 ),
		),
	) );
	update_field( 'note', 'Seamless note', $page );
	update_field( 'priority', 3, $page );
	// Group display, prefixed: saved through the clone field's own key with a
	// nested value, the way ACF/SCF's own admin form does it — saving through
	// the flat prefixed meta key directly (the naive approach) writes a value
	// with no resolvable field-key reference and `get_field_objects()` never
	// sees it at all, found empirically, not assumed.
	acf_add_local_field_group( array(
		'key' => 'group_bridge_clone_group', 'title' => 'Clone group use',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		'fields' => array(
			array( 'key' => 'field_bridge_clone_group', 'name' => 'meta_info', 'label' => 'Meta info', 'type' => 'clone', 'clone' => array( 'group_bridge_clone_source' ), 'display' => 'group', 'prefix_name' => 1, 'prefix_label' => 0 ),
		),
	) );
	update_field( 'field_bridge_clone_group', array( 'note' => 'Grouped note', 'priority' => 5 ), $page );

	// Options Page: fields that belong to no post at all.
	acf_add_options_page( array( 'page_title' => 'Bridge Options', 'menu_slug' => 'bridge-options' ) );
	acf_add_local_field_group( array(
		'key' => 'group_bridge_options', 'title' => 'Bridge options fields',
		'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'bridge-options' ) ) ),
		'fields' => array(
			array( 'key' => 'field_bridge_opt_tagline', 'name' => 'site_wide_tagline', 'label' => 'Tagline', 'type' => 'text' ),
			array( 'key' => 'field_bridge_opt_year', 'name' => 'copyright_year', 'label' => 'Copyright year', 'type' => 'number' ),
			// A repeater's own sub-rows (`options_social_links_0_url`, ...) are
			// stored under the field's own name, not the field's own row —
			// coverage must attribute them to `social_links`, not fold them
			// into `excluded:configuration-not-content` for not matching a
			// field name exactly.
			array(
				'key' => 'field_bridge_opt_social', 'name' => 'social_links', 'label' => 'Social links', 'type' => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_bridge_opt_social_url', 'name' => 'url', 'label' => 'URL', 'type' => 'text' ),
				),
			),
		),
	) );
	update_field( 'field_bridge_opt_tagline', 'Owns its content', 'option' );
	update_field( 'field_bridge_opt_year', 2026, 'option' );
	update_field( 'field_bridge_opt_social', array(
		array( 'url' => 'https://example.test/a' ),
		array( 'url' => 'https://example.test/b' ),
	), 'option' );

	// Two more Options Pages, neither given its own `post_id` — the common
	// setup (a parent page and its sub-pages) defaults every one of them to
	// the same `options` post_id. Each singleton must get only the fields
	// its own field group is located to, not every field stored at that
	// shared post_id.
	acf_add_options_page( array( 'page_title' => 'Bridge Header', 'menu_slug' => 'bridge-header' ) );
	acf_add_local_field_group( array(
		'key' => 'group_bridge_header_options', 'title' => 'Bridge header fields',
		'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'bridge-header' ) ) ),
		'fields' => array(
			array( 'key' => 'field_bridge_opt_header_text', 'name' => 'header_text', 'label' => 'Header text', 'type' => 'text' ),
		),
	) );
	update_field( 'field_bridge_opt_header_text', 'Welcome banner', 'option' );

	acf_add_options_page( array( 'page_title' => 'Bridge Footer', 'menu_slug' => 'bridge-footer' ) );
	acf_add_local_field_group( array(
		'key' => 'group_bridge_footer_options', 'title' => 'Bridge footer fields',
		'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'bridge-footer' ) ) ),
		'fields' => array(
			array( 'key' => 'field_bridge_opt_footer_text', 'name' => 'footer_text', 'label' => 'Footer text', 'type' => 'text' ),
		),
	) );
	update_field( 'field_bridge_opt_footer_text', 'All rights reserved', 'option' );

	// An Options Page with its own custom `post_id`. Its per-language copy is
	// planted further below, after Polylang's languages exist, through the
	// real "ACF Options for Polylang" plugin's own runtime — not a guessed
	// `update_option()` shape.
	acf_add_options_page( array( 'page_title' => 'Bridge Locale Options', 'menu_slug' => 'bridge-locale-options', 'post_id' => 'bridge_locale_options' ) );
	acf_add_local_field_group( array(
		'key' => 'group_bridge_locale_options', 'title' => 'Bridge locale options fields',
		'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'bridge-locale-options' ) ) ),
		'fields' => array(
			array( 'key' => 'field_bridge_opt_locale_note', 'name' => 'locale_note', 'label' => 'Locale note', 'type' => 'text' ),
		),
	) );
	update_field( 'field_bridge_opt_locale_note', 'Default language value', 'bridge_locale_options' );
}

// Polylang (free): 2 languages, 3 translation groups. Real plugin, not a
// simulated filter — the language taxonomies it registers, and the mismatch
// between WordPress's install locale and Polylang's own default language,
// only ever show up against the real thing.
$has_polylang = function_exists( 'pll_languages_list' );
if ( $has_polylang ) {
	if ( ! in_array( 'en', pll_languages_list(), true ) ) {
		PLL()->model->languages->add( array( 'locale' => 'en_US' ) );
	}
	if ( ! in_array( 'da', pll_languages_list(), true ) ) {
		PLL()->model->languages->add( array( 'locale' => 'da_DK' ) );
	}
	// A real Polylang site assigns every translated-taxonomy term a language;
	// this fixture's category should not stay "untagged" indefinitely. Explicit
	// "en" so a later read under curlang=da genuinely exercises the mismatch
	// BO-10 found in the real plugin, not merely a language Polylang never
	// bothers to filter because nothing was ever assigned.
	pll_set_term_language( (int) $category['term_id'], 'en' );
	pll_set_post_language( $post, 'en' );
	pll_set_post_language( $page, 'en' );
	$post_da = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Bridge artikel', 'post_content' => '<p>Dansk indhold.</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
	pll_set_post_language( $post_da, 'da' );
	pll_save_post_translations( array( 'en' => $post, 'da' => $post_da ) );
	$page_da = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Om Bridge', 'post_content' => '<p>Redigerbar side.</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
	pll_set_post_language( $page_da, 'da' );
	pll_save_post_translations( array( 'en' => $page, 'da' => $page_da ) );
	$team_en = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Team', 'post_content' => '<p>Our team.</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
	pll_set_post_language( $team_en, 'en' );
	$team_da = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Team', 'post_content' => '<p>Vores team.</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
	pll_set_post_language( $team_da, 'da' );
	pll_save_post_translations( array( 'en' => $team_en, 'da' => $team_da ) );

	// A custom post type Polylang does not manage by default still accepts an
	// explicit language and translation group through the same API.
	pll_set_post_language( $book, 'en' );
	$book_da = wp_insert_post( array( 'post_type' => 'bridge_book', 'post_title' => 'Skjult REST-bog', 'post_content' => '<p>Bogindhold.</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
	pll_set_post_language( $book_da, 'da' );
	pll_save_post_translations( array( 'en' => $book, 'da' => $book_da ) );

	// A real site is rarely fully translated: a post nobody has translated
	// yet is not a translation group of one member sharing a locale with
	// itself, it is a group with a real gap. Tagged with a language (so it is
	// not merely "unset"), never given a `da` counterpart.
	$solo_post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'English only', 'post_content' => '<p>Never translated.</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
	pll_set_post_language( $solo_post, 'en' );

	// A translated page has its own field values, not a copy of the source
	// language's; mirroring them here (rather than leaving $page_da's ACF
	// fields unset) is what a real translated page looks like.
	if ( $has_acf ) {
		update_field( 'tagline', 'Ejer dit indhold', $page_da );
		update_field( 'rank', 3, $page_da );
		update_field( 'featured', true, $page_da );
		update_field( 'hero', array( 'heading' => 'Ejer dine ord', 'cta' => 'Start nu' ), $page_da );
		update_field( 'quotes', array(
			array( 'person' => 'Ada', 'quote' => 'Det ligner min side.' ),
			array( 'person' => 'Grace', 'quote' => 'Diffen er indholdet.' ),
		), $page_da );
		update_field( 'rel', array( $post_da ), $page_da );
		update_field( 'rel_mixed', array( $post_da, $book_da ), $page_da );
		update_field( 'rel_out_of_scope', array( $draft, 999999 ), $page_da );
		update_field( 'po', $post_da, $page_da );
		update_field( 'cat', (int) $category['term_id'], $page_da );
		update_field( 'gallery', array( $attachment, $ghost ), $page_da );
		update_field( 'field_bridge_person', $admin->ID, $page_da );
		update_field( 'cta_link', array( 'title' => 'Læs mere', 'url' => 'https://example.test/read-more', 'target' => '_blank' ), $page_da );
		// Its three-deep group falls back to structured values, which the store expects in every locale.
		update_field( 'deep', array( 'inner' => array( 'innermost' => array( 'text' => 'Tre niveauer nede' ) ) ), $page_da );
		update_field( 'sections', array(
			array( 'acf_fc_layout' => 'text_block', 'heading' => 'Intro', 'body' => 'Velkomst' ),
			array( 'acf_fc_layout' => 'quote_block', 'heading' => 'Ros', 'quote' => 'Det virker bare.' ),
		), $page_da );
	}
	// A group-display clone is an object on the page, like a native `group`
	// field; the translated page carries its own copy. The seamless clone
	// above needs no mirror — its fields flatten onto the page itself.
	if ( $has_scf_pro ) {
		update_field( 'field_bridge_clone_group', array( 'note' => 'Grupperet note', 'priority' => 5 ), $page_da );
	}

	// A real per-language copy through the actual "ACF Options for Polylang"
	// plugin (BeAPI, installed for real above), for both a page's own custom
	// `post_id` (`bridge_locale_options`) and the default `options` post_id
	// shared by every page that does not set its own — its own
	// `acf/validate_post_id` filter does this suffixing, not a planted
	// `update_option()`.
	if ( $has_scf_pro ) {
		PLL()->curlang = PLL()->model->get_language( 'da' );
		update_field( 'field_bridge_opt_locale_note', 'Dansk værdi', 'bridge_locale_options' );
		update_field( 'field_bridge_opt_header_text', 'Dansk banner', 'option' );
		// The default language gets a redundant suffixed copy too: this
		// plugin never configures ACF's own `default_language` setting, so
		// its own "is this the default language" check is never true for any
		// explicit `curlang` — measured empirically against the real plugin,
		// not assumed. Coverage's translation-row detection must not be a
		// "da only" special case.
		PLL()->curlang = PLL()->model->get_language( 'en' );
		update_field( 'field_bridge_opt_year', 2027, 'option' );
		PLL()->curlang = false;
	}

	// Custom post meta is per-post, not per-translation-group; the Danish
	// member needs its own value or its own structured-value rows have no
	// same-language counterpart.
	update_post_meta( $post_da, 'structured', array( 'title' => 'Helt titel', 'items' => array( array( 'label' => 'Første', 'enabled' => true ) ) ) );

	// WordPress's own default page is never tagged with a language; on a real
	// Polylang site an admin either translates or removes it; removing it
	// here keeps this fixture's own untranslated-content warnings limited to
	// the ones this test deliberately creates ($draft, $illustrated).
	$sample_pages = get_posts( array( 'post_type' => 'page', 'name' => 'sample-page', 'post_status' => 'any', 'numberposts' => 1 ) );
	foreach ( $sample_pages as $sample_page ) {
		wp_delete_post( $sample_page->ID, true );
	}
}

// Menus: 2 registered theme locations, a post link, a term link, a custom
// URL, a nested item, an item whose parent id names no sibling, and an item
// whose target was deleted out from under it.
register_nav_menus( array( 'bridge-primary' => 'Primary', 'bridge-footer' => 'Footer' ) );
$existing_primary = wp_get_nav_menu_object( 'Bridge Primary' );
$primary_menu = $existing_primary ? $existing_primary->term_id : wp_update_nav_menu_object( 0, array( 'menu-name' => 'Bridge Primary' ) );
$existing_footer = wp_get_nav_menu_object( 'Bridge Footer' );
$footer_menu = $existing_footer ? $existing_footer->term_id : wp_update_nav_menu_object( 0, array( 'menu-name' => 'Bridge Footer' ) );
set_theme_mod( 'nav_menu_locations', array( 'bridge-primary' => $primary_menu, 'bridge-footer' => $footer_menu ) );
$menu_post_item = wp_update_nav_menu_item( $primary_menu, 0, array( 'menu-item-title' => 'Article', 'menu-item-object' => 'post', 'menu-item-object-id' => $post, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
$menu_child_item = wp_update_nav_menu_item( $primary_menu, 0, array( 'menu-item-title' => 'About (child)', 'menu-item-object' => 'page', 'menu-item-object-id' => $page, 'menu-item-parent-id' => $menu_post_item, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-target' => '_blank', 'menu-item-classes' => 'nav-child featured' ) );
$menu_orphan_item = wp_update_nav_menu_item( $primary_menu, 0, array( 'menu-item-title' => 'Orphaned child', 'menu-item-object' => 'page', 'menu-item-object-id' => $page, 'menu-item-parent-id' => 999999, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
$menu_term_item = wp_update_nav_menu_item( $footer_menu, 0, array( 'menu-item-title' => 'Category', 'menu-item-object' => 'category', 'menu-item-object-id' => (int) $category['term_id'], 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) );
$menu_url_item = wp_update_nav_menu_item( $footer_menu, 0, array( 'menu-item-title' => 'External', 'menu-item-url' => 'https://example.test/about', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
// A menu item whose target post was later deleted cannot be fixtured by
// deleting it: WordPress itself removes the menu item when its target post
// is deleted (`_wp_delete_post_menu_item`), so the only way a menu item
// actually carries an unresolvable post-type target is one that never
// resolved in the first place.
$menu_broken_item = wp_update_nav_menu_item( $footer_menu, 0, array( 'menu-item-title' => 'Gone', 'menu-item-object' => 'page', 'menu-item-object-id' => 987654321, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );

$comment = wp_insert_comment( array( 'comment_post_ID' => $post, 'comment_author' => 'Reviewer', 'comment_author_email' => 'private@example.test', 'comment_author_IP' => '192.0.2.1', 'comment_content' => 'Public comment', 'comment_approved' => 1 ) );
update_comment_meta( $comment, 'other_email', 'private-in-meta@example.test' );
$warnings = array();
$clean = Policy::meta( get_post_meta( $post ), array( 'selected_copy', 'structured', 'api_token' ), $warnings, 'test' );
check( ! isset( $clean['api_token'] ), 'explicit selection cannot export a sensitive key' );
check( ! isset( $clean['structured']['api_key'] ), 'nested secrets are excluded' );
check( 'Editable custom content' === $clean['selected_copy'], 'selected content metadata is decoded' );
rejects( static function () { Files::path( '/tmp', '../secret' ); }, 'path traversal is rejected' );
rejects( static function () { Files::dir( '../../' ); }, 'invalid job id is rejected' );

$fixture = sys_get_temp_dir() . '/bridge-scan-fixture.php';
file_put_contents( $fixture, '<h1>Visible heading</h1><input placeholder="Your name" aria-label="Full name"><script>var secret="Do not export this script"</script><?php __("Save changes", "fixture"); _n("One item", "%d items", 3, "fixture"); ?>' );
// The job itself scans with `$job['default_locale']` (`Jobs::sources()`); this
// unit-level probe of the Scanner class predates the job, so it must use the
// same `Source::default_locale()` computation directly, not WordPress's raw
// install locale — the two are no longer the same string once Polylang's own
// default language is active, and a mismatch here seeds a phantom locale.
$scan = Scanner::scan( $fixture, Source::default_locale() );
$values = array_column( $scan['candidates'], 'value' );
check( in_array( 'Save changes', $values, true ), 'PHP gettext literal is captured' );
check( in_array( '%d items', $values, true ), 'plural placeholder is preserved' );
check( ! in_array( 'fixture', $values, true ), 'gettext domain is not treated as content' );
check( in_array( 'Full name', $values, true ), 'accessible label is captured' );
check( ! in_array( 'Do not export this script', $values, true ), 'script text is not treated as rendered content' );
unlink( $fixture );

$summary = Jobs::create( array( 'types' => array( 'post', 'page', 'bridge_book', 'attachment' ), 'comments' => true, 'private' => false, 'scan_sources' => false, 'selected_meta' => array( 'selected_copy', 'structured', 'api_token' ) ) );
$id = $summary['id'];
check( Admin::permitted(), 'administrator with export capability is allowed' );
wp_set_current_user( 0 );
check( ! Admin::permitted(), 'anonymous user is denied' );
rejects( static function () use ( $id ) { Jobs::read( $id ); }, 'another user cannot read private export' );
wp_set_current_user( $admin->ID );
$before = $summary['step'];
// BO-10: with an admin's language filter (`curlang`) set, the posts step used
// to blow up resolving the `en`-tagged fixture category — Polylang's own
// `get_term_by()`/`get_terms()` filtering hides a term in any language other
// than curlang unless a query explicitly disables it. Held across the whole
// run (posts, terms, inventory), not just the posts phase, so any other
// language-filtered lookup on the same path would have failed the same way.
// Only the phases that read terms back by slug/term_taxonomy_id (posts,
// terms, inventory) need curlang forced; leaving it on through the later
// text-scanning phase would filter `get_posts()`/`get_terms()` there too and
// is not what BO-10 reported.
$danish_phases = array( 'posts', 'terms', 'inventory' );
$set_curlang = static function ( $phase ) use ( $has_polylang, $danish_phases ) {
	if ( $has_polylang ) {
		PLL()->curlang = in_array( $phase, $danish_phases, true ) ? PLL()->model->get_language( 'da' ) : false;
	}
};
$set_curlang( $summary['phase'] );
$summary = Jobs::step( $id, $before );
$duplicate = Jobs::step( $id, $before );
check( $duplicate['step'] === $summary['step'], 'retried step is idempotent' );
for ( $i = 0; $i < 200 && 'review' !== $summary['phase']; ++$i ) {
	$set_curlang( $summary['phase'] );
	$summary = Jobs::step( $id, $summary['step'] );
}
$set_curlang( 'done' );
check( 'review' === $summary['phase'], 'resumable export reaches review while an admin\'s language filter is set to Danish' );
$job = Jobs::read( $id );
check( isset( $job['tables']['bridge/raw-posts.json'][ $book ] ), 'REST-hidden CPT is exported' );
check( ! isset( $job['tables']['bridge/raw-posts.json'][ $draft ] ), 'draft excluded in public scope' );
// A post outside every ACF field group's location rule must keep exactly the
// raw shape it had before this plugin knew how to read ACF at all: an
// inventory fingerprint must not change for every non-ACF record just
// because ACF support exists now.
$post_raw = json_decode( Files::read( Files::dir( $id ), \Contentrain\Bridge\Models::row_file( 'bridge/raw-posts.json', $post ) ), true );
check( ! array_key_exists( 'acf', $post_raw ), 'a post outside every ACF field group carries no acf key at all, so its inventory fingerprint is unchanged by ACF support' );
check( ! isset( $job['models']['wp-post']['fields']['slug'] ), 'document system slug is not declared as a field' );
check( isset( $job['models']['wp-structured-values'] ), 'nested content becomes editable related records' );
if ( $has_acf ) {
	// B3: a page's (a collection entry's) group, repeater, flexible content and
	// link are values with parts, written in place — not models of their own.
	$acf_models = array_filter( array_keys( $job['models'] ), static function ( $m ) { return 0 === strpos( $m, 'acf-' ) && 0 !== strpos( $m, 'acf-options-' ); } );
	check( array() === array_values( $acf_models ), 'a page\'s ACF fields create no models of their own: ' . implode( ',', $acf_models ) );
}
// Adversarial ACF shapes must either model losslessly or use the explicit fallback.
$probe = $job;
$probe['id'] = bin2hex( random_bytes( 16 ) );
Files::mkdir( Files::dir( $probe['id'] ) );
$probe['models'] = array(); $probe['files'] = array(); $probe['tables'] = array();
$acf_class = '\Contentrain\Bridge\Acf';
check( $acf_class::model_id( 'hero', 'field_a' ) !== $acf_class::model_id( 'hero', 'field_b' ), 'same-name ACF models have stable distinct identities' );
check( '2026-09-11' === $acf_class::cast( array( 'type' => 'date' ), '20260911' ), 'ACF stored dates normalize to ISO date' );
check( null === $acf_class::scalar( array( 'type' => 'select', 'multiple' => true, 'choices' => array( 'a' => 'A', 'b' => 'B' ) ) ), 'multi-select cannot be treated as a scalar select' );
check( get_permalink( $page ) === $acf_class::cast( array( 'type' => 'url' ), (string) $page ), 'a page_link post ID casts to its public address' );
check( null === $acf_class::cast( array( 'type' => 'url' ), $draft ) && null === $acf_class::cast( array( 'type' => 'url' ), 999999 ), 'a page_link to a draft or missing post has no address to give' );
check( null === $acf_class::scalar( array( 'type' => 'page_link', 'multiple' => true ) ), 'a multiple page_link is not one URL' );
// B3: definitions come from the schema alone.
check( null === $acf_class::definition( array( 'type' => 'repeater', 'sub_fields' => array( array( 'name' => 'heading', 'type' => 'text' ), array( 'name' => 'post', 'type' => 'post_object' ) ) ) ), 'a repeater holding a reference has no inline shape' );
check( null === $acf_class::definition( array( 'type' => 'flexible_content', 'layouts' => array( array( 'name' => 'a', 'sub_fields' => array( array( 'name' => 'x', 'type' => 'text' ) ) ), array( 'name' => 'b', 'sub_fields' => array( array( 'name' => 'x', 'type' => 'number' ) ) ) ) ) ), 'two layouts typing one name differently have no single shape' );
check( array( 'type' => 'object', 'fields' => array( 'heading' => array( 'type' => 'string' ) ) ) === $acf_class::definition( array( 'type' => 'group', 'sub_fields' => array( array( 'name' => 'heading', 'type' => 'text', 'required' => 1 ), array( 'name' => 'pin', 'type' => 'password' ), array( 'name' => 'api_key', 'type' => 'text' ) ) ) ), 'a group object has no password or credential-named field, and no nested required' );
check( null === $acf_class::definition( array( 'type' => 'group', 'sub_fields' => array( array( 'name' => 'g', 'type' => 'group', 'sub_fields' => array( array( 'name' => 'r', 'type' => 'repeater', 'sub_fields' => array( array( 'name' => 't', 'type' => 'text' ) ) ) ) ) ) ) ), 'containers nest at most two deep' );
$draft_attachment = wp_insert_attachment( array( 'post_title' => 'Draft child file', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), false, $draft );
check( null === $acf_class::cast( array( 'type' => 'url' ), $draft_attachment ), 'a page_link to a file attached to a draft has no public address' );
$link_schema = array( 'type' => 'link', 'key' => 'field_probe_link', 'name' => 'link' );
$result = $acf_class::field( $probe, $link_schema, array( 'url' => 'https://example.test', 'title' => 'Read more', 'target' => '_blank' ), $job['default_locale'], 'link' );
$link_model = $acf_class::model_id( 'link', 'field_probe_link' );
check( null !== $result && 'relation' === $result[0]['type'] && $link_model === $result[0]['model'], 'a link becomes its own model instead of a lossy URL-only cast' );
check( 'url' === $probe['models'][ $link_model ]['title_field'], 'a link is titled by its URL, which is never empty, rather than its optional label' );
// B2: a link inside a row is {url, title, target}; casting it to a URL lost the label.
check( null === $acf_class::scalar( array( 'type' => 'link' ) ), 'a link is not a scalar URL' );
$flex_link_schema = array(
	'type' => 'flexible_content', 'key' => 'field_probe_flex_link', 'name' => 'flex_link',
	'layouts' => array( array( 'sub_fields' => array( array( 'name' => 'heading', 'type' => 'text' ), array( 'name' => 'cta', 'type' => 'link' ) ) ) ),
);
$models_before = $probe['models'];
$result = $acf_class::field( $probe, $flex_link_schema, array( array( 'acf_fc_layout' => 'cta', 'heading' => 'Talk to us', 'cta' => array( 'url' => 'https://example.test/contact', 'title' => 'Contact', 'target' => '' ) ) ), $job['default_locale'], 'flex_link' );
check( null === $result && $models_before === $probe['models'], 'a flexible row holding a link falls back whole instead of dropping the link label' );
$repeater_link_schema = array( 'type' => 'repeater', 'key' => 'field_probe_rep_link', 'name' => 'rep_link', 'sub_fields' => array( array( 'name' => 'heading', 'type' => 'text' ), array( 'name' => 'cta', 'type' => 'link' ) ) );
$result = $acf_class::field( $probe, $repeater_link_schema, array( array( 'heading' => 'Hi', 'cta' => array( 'url' => 'https://example.test', 'title' => 'Go', 'target' => '' ) ) ), $job['default_locale'], 'rep_link' );
check( null === $result, 'a repeater row holding a link falls back instead of dropping the link label' );
check( array( null, null ) === $acf_class::field( $probe, $link_schema, '', $job['default_locale'], 'link' ), 'an empty link has nothing to export' );
// Two flexible_content layouts that cannot agree on a title field have no single
// honest shape, so the field falls back whole rather than half-modelling it.
$flex_schema = array(
	'type' => 'flexible_content', 'key' => 'field_probe_flex', 'name' => 'flex',
	'layouts' => array(
		array( 'sub_fields' => array( array( 'name' => 'heading', 'type' => 'text' ) ) ),
		array( 'sub_fields' => array( array( 'name' => 'body', 'type' => 'textarea' ) ) ),
	),
);
$result = $acf_class::field( $probe, $flex_schema, array( array( 'acf_fc_layout' => 'a', 'heading' => 'Only in layout a' ) ), $job['default_locale'], 'flex' );
check( null === $result, 'flexible_content layouts that share no common title field fall back instead of half-modelling' );
$nested_schema = array( 'key' => 'field_nested', 'name' => 'nested', 'type' => 'group', 'sub_fields' => array( array( 'key' => 'field_heading', 'name' => 'heading', 'type' => 'text' ), array( 'key' => 'field_children', 'name' => 'children', 'type' => 'repeater' ) ) );
$models_before = $probe['models'];
$result = $acf_class::field( $probe, $nested_schema, array( 'heading' => 'Hello', 'children' => array( array( 'text' => 'Must survive' ) ) ), $job['default_locale'], 'nested' );
check( null === $result && $models_before === $probe['models'], 'unsupported nested ACF fields cannot be silently dropped from a successful model' );
$no_title_schema = array( 'key' => 'field_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => array( array( 'key' => 'field_heading', 'name' => 'heading', 'type' => 'text' ) ) );
$models_before = $probe['models'];
$result = $acf_class::field( $probe, $no_title_schema, array( array( 'heading' => 'One' ), array( 'heading' => '' ) ), $job['default_locale'], 'rows' );
check( null === $result && $models_before === $probe['models'], 'untitled row keeps the entire value in fallback without orphan partial models' );
$redactions = array();
$private_schema = array( 'type' => 'group', 'name' => 'public_group', 'sub_fields' => array( array( 'key' => 'field_opaque', 'name' => 'connection', 'type' => 'password' ), array( 'key' => 'field_heading', 'name' => 'heading', 'type' => 'text' ) ) );
$clean_acf = Source::acf_value( $private_schema, array( 'field_opaque' => 'hidden-credential', 'field_heading' => 'Safe headline' ), $redactions, 'acf' );
check( ! isset( $clean_acf['field_opaque'] ) && 'Safe headline' === $clean_acf['field_heading'], 'nested ACF password is removed by its type even behind an opaque field key' );
$team_schema = array( 'type' => 'repeater', 'name' => 'team', 'sub_fields' => array( array( 'key' => 'field_team_name', 'name' => 'name', 'type' => 'text' ), array( 'key' => 'field_team_email', 'name' => 'email', 'type' => 'email' ), array( 'key' => 'field_team_secret', 'name' => 'secret', 'type' => 'text' ) ) );
$clean_team = Source::acf_value( $team_schema, array( array( 'name' => 'Ada', 'email' => 'ada@example.test', 'secret' => 'planted' ) ), $redactions, 'acf' );
check( 'ada@example.test' === $clean_team[0]['email'] && ! isset( $clean_team[0]['secret'] ), 'a nested ACF email sub-field is content; a secret-named one is not' );
check( array() === Policy::clean( array( 'customer_email' => 'x@example.test' ), $redactions, 'meta' ), 'unknown meta keeps the broad name rule' );
foreach ( array( 'user_pass', 'apiKey', 'access_token', 'client-secret', 'credentials', 'private_key' ) as $name ) {
	check( Policy::secret_name( $name ), $name . ' is a credential name' );
}
foreach ( array( 'passage', 'compass', 'session_title', 'cookie_recipe', 'tokenomics', 'contact_email' ) as $name ) {
	check( ! Policy::secret_name( $name ), $name . ' is content, not a credential name' );
}
// The same table as @contentrain/wp-import, case by case (tests/fixtures/acf-parity.json).
require __DIR__ . '/acf-parity.php';
Files::remove( Files::dir( $probe['id'] ) );

// A real candidate review round, independent of the installed theme's size.
Jobs::mutate( $id, static function ( &$j ) use ( $scan ) { $j['candidates'] = array_column( $scan['candidates'], null, 'id' ); } );
rejects( static function () use ( $id ) { Jobs::review( $id, array(), true ); }, 'unreviewed text blocks finalization' );
$decisions = array();
foreach ( $scan['candidates'] as $candidate ) { $decisions[ $candidate['id'] ] = array( 'decision' => 'include', 'key' => $candidate['key'] ); }
$summary = Jobs::review( $id, $decisions, true );
for ( $i = 0; $i < 300 && 'ready' !== $summary['phase']; ++$i ) { $summary = Jobs::step( $id, $summary['step'] ); }
check( 'ready' === $summary['phase'], 'validated JSON/Markdown export is ready' );
$job = Jobs::read( $id );
$dir = Files::dir( $id ) . '/output';
$address = Source::address( get_post( $post ) );
$doc = Files::read( $dir, Models::content_path( $job, 'wp-post', $address['locale'], $address['entry_id'] ) );
check( false !== strpos( $doc, '<table><tr><td>Preserved</td></tr></table>' ), 'Markdown retains complex source HTML without loss' );
check( false !== strpos( $doc, 'selected_copy' ), 'custom content is modelled in frontmatter' );
$raw_comments = json_decode( Files::read( $dir, 'bridge/raw-comments.json' ), true );
check( ! isset( $raw_comments[ $comment ]['email'] ), 'comment email field excluded' );
check( false === strpos( Policy::json( $raw_comments ), 'private-in-meta' ), 'comment metadata cannot leak personal information' );
if ( $has_polylang ) {
	// A real Polylang site with more than one language must produce the
	// locale-per-file store the rest of the toolchain expects for it.
	check( true === $job['i18n'], 'a Polylang site with more than one language is modelled as i18n' );
	check( true === $job['models']['wp-post']['i18n'], 'models declare i18n true' );
	check( isset( $job['files'][ '.contentrain/content/blog/wp-post/' . $address['entry_id'] . '/' . $address['locale'] . '.md' ] ), 'i18n document is {id}/{locale}.md' );
	check( isset( $job['tables'][ '.contentrain/content/site/wp-page/' . $address['locale'] . '.json' ] ), 'i18n collection is {locale}.json' );
	check( isset( $job['files'][ '.contentrain/meta/wp-post/' . $address['entry_id'] . '/' . $address['locale'] . '.json' ] ), 'meta keeps its own locale' );
	check( ! isset( $job['files'][ '.contentrain/content/blog/wp-post/' . $address['entry_id'] . '.md' ] ), 'an i18n document is not also written flat' );
} else {
	// A single-language site must produce the store the rest of the toolchain
	// writes for it: no locale in content file names, and no i18n models.
	check( false === $job['i18n'], 'single-language site is not modelled as i18n' );
	check( false === $job['models']['wp-post']['i18n'], 'models declare i18n false' );
	check( isset( $job['files'][ '.contentrain/content/blog/wp-post/' . $address['entry_id'] . '.md' ] ), 'monolingual document is {slug}.md' );
	check( isset( $job['tables']['.contentrain/content/site/wp-page/data.json'] ), 'monolingual collection is data.json' );
	check( isset( $job['files'][ '.contentrain/meta/wp-post/' . $address['entry_id'] . '/' . $address['locale'] . '.json' ] ), 'meta keeps the locale even when content does not' );
	check( ! isset( $job['files'][ '.contentrain/content/blog/wp-post/' . $address['entry_id'] . '/' . $address['locale'] . '.md' ] ), 'no locale directory is written for one language' );
}
// The monolingual path shape is real code (`Models::content_path`'s `i18n`
// branch), just not exercised end to end while Polylang makes this fixture
// i18n; check it directly against a synthetic non-i18n model so it cannot
// regress silently.
check( '.contentrain/content/blog/wp-post/entry-9.md' === Models::content_path( $job, array( 'domain' => 'blog', 'id' => 'wp-post', 'kind' => 'document', 'i18n' => false ), 'en', 'entry-9' ), 'a monolingual document path has no locale segment' );
check( '.contentrain/content/site/wp-page/data.json' === Models::content_path( $job, array( 'domain' => 'site', 'id' => 'wp-page', 'kind' => 'collection', 'i18n' => false ), 'en' ), 'a monolingual collection path is data.json' );

if ( $has_polylang ) {
	// One pair per translation group (`RawLanguagePair`'s contract), keyed by
	// its canonical (default-locale) member — not one row per post.
	// `translations` is a locale => id map (`RawLanguagePair`'s `Record<string, number>`),
	// with no defined key order; Polylang itself returns it ordered by which
	// post you queried, not by locale, so compare as a map, not by identity.
	$same_map = static function ( $expected, $actual ) { ksort( $expected ); ksort( $actual ); return $expected === $actual; };
	$pairs = json_decode( Files::read( $dir, 'bridge/language-pairs.json' ), true );
	check( isset( $pairs[ $post ] ) && $same_map( array( 'en' => $post, 'da' => $post_da ), $pairs[ $post ]['translations'] ), 'the post/post_da group has exactly one pair, keyed by the English member' );
	check( isset( $pairs[ $page ] ) && $same_map( array( 'en' => $page, 'da' => $page_da ), $pairs[ $page ]['translations'] ), 'the page/page_da group has exactly one pair' );
	check( isset( $pairs[ $team_en ] ) && $same_map( array( 'en' => $team_en, 'da' => $team_da ), $pairs[ $team_en ]['translations'] ), 'a third, unrelated translation group is also a single pair' );
	check( ! isset( $pairs[ $post_da ] ) && ! isset( $pairs[ $page_da ] ) && ! isset( $pairs[ $team_da ] ), 'the non-canonical member of each group writes no pair of its own' );
	check( ! isset( $pairs[ $post ]['missing_translations'] ) && ! isset( $pairs[ $page ]['missing_translations'] ), 'a fully bilingual group reports no missing translation' );
	check( isset( $pairs[ $solo_post ] ) && array( 'en' => $solo_post ) === $pairs[ $solo_post ]['translations'] && array( 'da' ) === $pairs[ $solo_post ]['missing_translations'], 'a post nobody has translated yet is its own one-member group, with the gap named rather than hidden' );
	// A re-used test database accumulates earlier runs' groups (RELEASING.md's
	// own caveat), so this counts at least this run's three rather than an
	// exact total; each da-tagged member above already proves no duplicate
	// per-post row exists for this run's own groups.
	check( count( $pairs ) >= 3, 'at least this run\'s three translation groups produced a pair' );

	// Polylang's own bookkeeping taxonomies must never surface as content models.
	foreach ( array( 'wp-tax-language', 'wp-tax-post-translations', 'wp-tax-term-language', 'wp-tax-term-translations' ) as $bookkeeping_model ) {
		check( ! isset( $job['models'][ $bookkeeping_model ] ), "Polylang's own $bookkeeping_model taxonomy never becomes a model" );
	}

	// Menus: two locations, a post link, a term link, a custom URL, a resolved
	// parent, an unresolved parent, and a target deleted out from under it.
	$menu_items = json_decode( Files::read( $dir, Models::content_path( $job, 'wp-menu-items', $job['default_locale'] ) ), true );
	$menu_id = static function ( $wp_item_id ) { return substr( hash( 'sha256', 'menu:' . $wp_item_id ), 0, 12 ); };
	$post_row = $menu_items[ $menu_id( $menu_post_item ) ];
	check( 'post' === $post_row['target_kind'] && true === $post_row['target_resolved'] && 'post' === $post_row['target_post_type'] && get_post( $post )->post_name === $post_row['target_slug'], 'a post-type menu item resolves kind/post_type/slug' );
	check( 'bridge-primary' === $post_row['location'], 'a menu assigned to a theme location carries it' );
	check( ! isset( $post_row['parent'] ), 'a top-level item has no parent field' );
	$child_row = $menu_items[ $menu_id( $menu_child_item ) ];
	check( $menu_id( $menu_post_item ) === $child_row['parent'], 'a nested item is a relation to its own parent row, not a raw WordPress id' );
	check( '_blank' === $child_row['window_target'] && 'nav-child featured' === $child_row['classes'], 'window target and classes survive' );
	$orphan_row = $menu_items[ $menu_id( $menu_orphan_item ) ];
	check( ! isset( $orphan_row['parent'] ), 'a parent id naming no sibling in this menu is not written as a relation' );
	$term_row = $menu_items[ $menu_id( $menu_term_item ) ];
	check( 'term' === $term_row['target_kind'] && true === $term_row['target_resolved'] && 'category' === $term_row['target_taxonomy'] && 'bridge-fixture-category' === $term_row['target_slug'], 'a taxonomy menu item resolves kind/taxonomy/slug' );
	check( 'bridge-footer' === $term_row['location'], 'the second menu carries the second location' );
	$url_row = $menu_items[ $menu_id( $menu_url_item ) ];
	check( 'url' === $url_row['target_kind'] && true === $url_row['target_resolved'] && ! isset( $url_row['target_post_type'] ) && ! isset( $url_row['target_taxonomy'] ), 'a custom URL item carries no post/term target fields' );
	$broken_row = $menu_items[ $menu_id( $menu_broken_item ) ];
	// WordPress itself never resolves a link for a post/term target that never
	// existed, so there is no URL to fall back to; the honest outcome is a
	// reported, unresolved target with no `url` field, not an invented link.
	check( false === $broken_row['target_resolved'] && ! isset( $broken_row['url'] ), 'a target that never resolves is reported, not given a fabricated URL' );
	$warnings = json_decode( Files::read( $dir, 'bridge/warnings.json' ), true );
	$warning_reasons = implode( '|', array_column( $warnings, 'reason' ) );
	check( false !== strpos( $warning_reasons, 'menu-parent-not-in-document' ), 'an unresolved menu parent is reported, not silently dropped' );
	check( false !== strpos( $warning_reasons, 'menu-target-not-in-document' ), 'an unresolved menu target is reported, not silently dropped' );
}

// Model files must carry Contentrain's canonical key order, or the first write
// from Studio/MCP reorders every model and shows a whole-file diff.
$model_json = Files::read( $dir, '.contentrain/models/wp-post.json' );
$model_keys = array_keys( json_decode( $model_json, true ) );
check( array( 'id', 'name', 'kind', 'domain', 'i18n', 'title_field', 'fields' ) === $model_keys, 'model file uses MODEL_FIELD_ORDER: ' . implode( ',', $model_keys ) );
check( array( 'model', 'type' ) === array_keys( json_decode( $model_json, true )['fields']['author'] ), 'nested objects stay alphabetical' );
check( "\n" === substr( $model_json, -1 ) && false === strpos( $model_json, "\n    \"id\"" ), 'canonical two-space indent and trailing newline' );
check( array( 'description', 'name', 'source_slug', 'wp_id' ) === array_keys( json_decode( Files::read( $dir, '.contentrain/models/wp-tax-category.json' ), true )['fields'] ), 'the model key order does not leak into nested objects' );
// Contentrain drops null when it serializes, so a null in the store would make
// the file differ from what the toolchain writes back. Nothing may emit one.
foreach ( array_keys( $job['files'] ) as $path ) {
	if ( 0 !== strpos( $path, '.contentrain/' ) || '.json' !== substr( $path, -5 ) ) { continue; }
	$decoded = json_decode( Files::read( $dir, $path ), true );
	$has_null = static function ( $value ) use ( &$has_null ) {
		if ( null === $value ) { return true; }
		if ( ! is_array( $value ) ) { return false; }
		foreach ( $value as $item ) { if ( $has_null( $item ) ) { return true; } }
		return false;
	};
	if ( $has_null( $decoded ) ) { throw new RuntimeException( 'Null value in canonical store file: ' . $path ); }
}
check( true, 'no canonical store file contains a null value' );

// Media has to travel with the content: an export whose images still point at
// WordPress is not a delivery the user can turn WordPress off after.
$stored = 'media/' . ltrim( get_post_meta( $attachment, '_wp_attached_file', true ), '/' );
check( isset( $job['files'][ $stored ] ), 'the original upload is copied into the export' );
check( hash( 'sha256', $png ) === $job['files'][ $stored ]['sha256'], 'copied media is byte-identical' );
$media_entry = json_decode( Files::read( $dir, Models::content_path( $job, 'wp-media', $job['default_locale'] ) ), true )[ substr( hash( 'sha256', 'media:' . $attachment ), 0, 12 ) ];
check( $stored === $media_entry['file'], 'the media record points at the stored path' );
check( $image_url === $media_entry['url'], 'the source URL is kept alongside it' );
$illustrated_body = Files::read( $dir, Models::content_path( $job, 'wp-post', $job['default_locale'], Source::address( get_post( $illustrated ) )['entry_id'] ) );
check( false !== strpos( $illustrated_body, 'src="/' . $stored . '"' ), 'content is relinked to the stored media path' );
check( false === strpos( $illustrated_body, $image_url ), 'no WordPress upload URL is left in the relinked body' );
// A file that is not in the export must keep its working WordPress URL.
$ghost_entry = json_decode( Files::read( $dir, Models::content_path( $job, 'wp-media', $job['default_locale'] ) ), true )[ substr( hash( 'sha256', 'media:' . $ghost ), 0, 12 ) ];
check( ! isset( $ghost_entry['file'] ), 'a missing upload is not claimed as transferred' );
// A re-used test database accumulates fixtures, so this counts at least the
// ghost rather than pinning an exact total.
check( $job['counts']['media_kept_remote'] >= 1, 'media kept remote is counted' );
$manifest_media = json_decode( Files::read( $dir, 'bridge/manifest.json' ), true )['media'];
check( $manifest_media['transferred_files'] >= 1 && 'media/' === $manifest_media['stored_under'], 'the manifest reports real media counts' );


check( isset( json_decode( Files::read( $dir, 'bridge/manifest.json' ), true )['files']['CONTENTRAIN-EXPORT.md'] ), 'README hash is in the manifest for repeat Git delivery' );

// ACF content, once the tables have been written.
if ( $has_acf ) {
	$page_data = json_decode( Files::read( $dir, Models::content_path( $job, 'wp-page', $job['default_locale'] ) ), true );
	$page_entry = $page_data[ Source::address( get_post( $page ) )['entry_id'] ];
	check( 'Content you own' === $page_entry['acf_tagline'], 'a scalar ACF field is a scalar field' );
	check( 'email' === $job['models']['wp-page']['fields']['acf_contact_email']['type'] && 'hello@example.test' === $page_entry['acf_contact_email'], 'a public ACF email field is exported, not dropped by its name' );
	check( ! isset( $page_entry['acf_api_token'] ) && ! isset( $page_entry['acf_door_code'] ), 'a credential-named field and a password field are never exported' );
	check( false === strpos( wp_json_encode( $page_entry ), 'planted-acf-' ), 'no planted secret reaches the entry' );
	check( 3 === $page_entry['acf_rank'] || 3.0 === $page_entry['acf_rank'], 'a number stays a number' );
	check( true === $page_entry['acf_featured'], 'true_false becomes a boolean' );
	$page_fields = $job['models']['wp-page']['fields'];
	check( array( 'type' => 'array', 'items' => array( 'type' => 'object', 'fields' => array( 'person' => array( 'type' => 'string' ), 'quote' => array( 'type' => 'text' ) ) ) ) == $page_fields['acf_quotes'], 'a repeater is an array of objects with its sub-fields\' own types: ' . wp_json_encode( $page_fields['acf_quotes'] ) );
	check( array( array( 'person' => 'Ada', 'quote' => 'It reads like my site.' ), array( 'person' => 'Grace', 'quote' => 'The diff is the content.' ) ) === $page_entry['acf_quotes'], 'every repeater row is kept, in order, with its own values: ' . wp_json_encode( $page_entry['acf_quotes'] ) );
	check( array( 'type' => 'object', 'fields' => array( 'heading' => array( 'type' => 'string' ), 'cta' => array( 'type' => 'string' ) ) ) == $page_fields['acf_hero'], 'a group is an object: ' . wp_json_encode( $page_fields['acf_hero'] ) );
	check( array( 'cta' => 'Start now', 'heading' => 'Own your words' ) == $page_entry['acf_hero'], 'the group\'s values are in place: ' . wp_json_encode( $page_entry['acf_hero'] ) );
	check( 'object' === $page_fields['acf_office']['type'] && 'decimal' === $page_fields['acf_office']['fields']['lat']['type'] && 'integer' === $page_fields['acf_office']['fields']['zoom']['type'], 'a map is an object of address, lat, lng, zoom' );
	check( '1 Example Street' === $page_entry['acf_office']['address'] && 52.37 === $page_entry['acf_office']['lat'] && 14 === $page_entry['acf_office']['zoom'], 'a map keeps its address and position: ' . wp_json_encode( $page_entry['acf_office'] ?? null ) );
	check( array( 'type' => 'array', 'items' => array( 'type' => 'select', 'options' => array( 'news', 'guides', 'events' ) ) ) == $page_fields['acf_topics'] && array( 'guides', 'news' ) === $page_entry['acf_topics'], 'a checkbox is an array of its choices: ' . wp_json_encode( array( $page_fields['acf_topics'] ?? null, $page_entry['acf_topics'] ?? null ) ) );
	check( 'array' === $page_fields['acf_audience']['type'] && array( 'ed' ) === $page_entry['acf_audience'], 'a multi-select is an array of its choices' );
	// No entry has a valid tier, so the field is not declared at all; were it, it would be the schema's select.
	check( ! isset( $page_entry['acf_tier'] ) && ( ! isset( $page_fields['acf_tier'] ) || array( 'type' => 'select', 'options' => array( 'gold', 'silver' ) ) == $page_fields['acf_tier'] ), 'a stored choice the field no longer offers is left out, not a failed export: ' . wp_json_encode( array( $page_fields['acf_tier'] ?? null, $page_entry['acf_tier'] ?? null ) ) );
	check( 'object' === $page_fields['acf_faq']['items']['fields']['links']['items']['fields']['link']['type'], 'a repeater inside a repeater (depth 2) holds a link object' );
	check( array( array( 'question' => 'Where do I start?', 'links' => array( array( 'link' => array( 'title' => 'Start here', 'url' => 'https://example.test/start' ) ) ) ), array( 'question' => 'Is it mine?' ) ) == $page_entry['acf_faq'], 'nested rows keep their values; an empty nested list and an empty target are left out: ' . wp_json_encode( $page_entry['acf_faq'] ?? null ) );
	check( 'wp-structured-values' === ( $page_fields['acf_deep']['model'] ?? null ), 'a group nested three deep takes the structured fallback' );
	$b3_warnings = array_column( json_decode( Files::read( $dir, 'bridge/warnings.json' ), true ), 'reason' );
	check( in_array( 'acf-choice-not-in-options', $b3_warnings, true ), 'the removed choice is reported' );

	// ACF Pro field types: relationship, post_object, taxonomy, gallery, user, link, flexible_content.
	$post_address = Source::address( get_post( $post ) );
	check( 'relation' === $job['models']['wp-page']['fields']['acf_po']['type'] && 'wp-post' === $job['models']['wp-page']['fields']['acf_po']['model'] && $post_address['entry_id'] === $page_entry['acf_po'], 'post_object becomes a relation to the referenced post' );
	check( 'relations' === $job['models']['wp-page']['fields']['acf_rel']['type'] && 'wp-post' === $job['models']['wp-page']['fields']['acf_rel']['model'] && array( $post_address['entry_id'] ) === $page_entry['acf_rel'], 'relationship becomes relations to the referenced posts' );
	check( 'wp-structured-values' === $job['models']['wp-page']['fields']['acf_rel_mixed']['model'] && 2 === count( $page_entry['acf_rel_mixed'] ), 'a relationship spanning more than one post type falls back without dropping either reference' );
	check( 'wp-structured-values' === $job['models']['wp-page']['fields']['acf_rel_out_of_scope']['model'] && 2 === count( $page_entry['acf_rel_out_of_scope'] ), 'relationship targets outside the export scope fall back rather than vanish' );
	$term_ref = substr( hash( 'sha256', 'term:' . (int) $category['term_id'] ), 0, 12 );
	check( 'relation' === $job['models']['wp-page']['fields']['acf_cat']['type'] && 'wp-tax-category' === $job['models']['wp-page']['fields']['acf_cat']['model'] && $term_ref === $page_entry['acf_cat'], 'taxonomy becomes a relation to the term collection' );
	$media_ref = substr( hash( 'sha256', 'media:' . $attachment ), 0, 12 );
	$ghost_ref = substr( hash( 'sha256', 'media:' . $ghost ), 0, 12 );
	check( 'relations' === $job['models']['wp-page']['fields']['acf_gallery']['type'] && 'wp-media' === $job['models']['wp-page']['fields']['acf_gallery']['model'] && array( $media_ref, $ghost_ref ) === $page_entry['acf_gallery'], 'gallery becomes relations to the media collection, including a not-yet-transferred file' );
	$author_ref = substr( hash( 'sha256', 'author:' . $admin->ID ), 0, 12 );
	check( 'relation' === $job['models']['wp-page']['fields']['acf_person']['type'] && 'wp-authors' === $job['models']['wp-page']['fields']['acf_person']['model'] && $author_ref === $page_entry['acf_person'], 'a user field becomes a relation to the same author collection a post author uses' );
	check( $page_entry['acf_person'] === $page_entry['author'], 'the ACF user reference and the post author resolve to the same author record' );
	check( array( 'type' => 'object', 'fields' => array( 'url' => array( 'type' => 'url' ), 'title' => array( 'type' => 'string' ), 'target' => array( 'type' => 'string' ) ) ) == $job['models']['wp-page']['fields']['acf_cta_link'], 'a link is an object' );
	check( array( 'target' => '_blank', 'title' => 'Read more', 'url' => 'https://example.test/read-more' ) == $page_entry['acf_cta_link'], 'a link keeps its label and target alongside the URL: ' . wp_json_encode( $page_entry['acf_cta_link'] ?? null ) );
	check( 'url' === $job['models']['wp-page']['fields']['acf_landing']['type'] && get_permalink( $post ) === $page_entry['acf_landing'], 'a page_link becomes the linked post\'s address, not its ID' );
	check( ! isset( $page_entry['acf_landing_draft'] ), 'a page_link to a draft is left out: its address is not public' );
	check( in_array( 'acf-page-link-target-not-public', array_column( json_decode( Files::read( $dir, 'bridge/warnings.json' ), true ), 'reason' ), true ), 'and the export says so' );
	$sections_field = $job['models']['wp-page']['fields']['acf_sections'];
	check( 'array' === $sections_field['type'] && array( 'type' => 'select', 'options' => array( 'quote_block', 'text_block' ), 'required' => true ) == $sections_field['items']['fields']['layout'], 'flexible_content is an array of rows naming their layout' );
	$section_keys = array_keys( $sections_field['items']['fields'] );
	sort( $section_keys );
	check( array( 'body', 'heading', 'layout', 'quote' ) === $section_keys, 'a flexible row\'s fields are the union of its layouts\' fields' );
	$section_rows = $page_entry['acf_sections'];
	check( 2 === count( $section_rows ), 'every flexible_content row is exported' );
	check( 'text_block' === $section_rows[0]['layout'] && 'Intro' === $section_rows[0]['heading'] && 'Welcome copy' === $section_rows[0]['body'], 'a flexible_content row keeps its own layout and fields' );
	check( 'quote_block' === $section_rows[1]['layout'] && 'Praise' === $section_rows[1]['heading'] && 'It just works.' === $section_rows[1]['quote'], 'a different layout in the same field keeps its own fields' );
	check( ! isset( $section_rows[0]['quote'] ) && ! isset( $section_rows[1]['body'] ), 'a row does not carry fields from a layout it was not written in' );
}

if ( $has_scf_pro ) {
	// Seamless clone: indistinguishable from `note`/`priority` added directly —
	// no `acf_clone_seamless` field exists at all, proving the replacement is real.
	check( 'Seamless note' === $page_entry['acf_note'] && ( 3 === $page_entry['acf_priority'] || 3.0 === $page_entry['acf_priority'] ), 'a seamless clone flattens into the parent field group under the source fields\' own names' );
	check( ! isset( $job['models']['wp-page']['fields']['acf_clone_seamless'] ), 'a seamless clone field itself is never a field on the parent' );

	// Group-display clone: an object, exactly like a native `group` field.
	check( 'object' === $job['models']['wp-page']['fields']['acf_meta_info']['type'], 'a group-display clone becomes an object, like a native group field' );
	$clone_group_row = $page_entry['acf_meta_info'];
	// `prefix_name => 1` on the clone usage itself, so the values are honestly
	// under the prefixed names ACF/SCF actually reports for it (`meta_info_note`),
	// not the source group's own unprefixed names — that guarantee is what the
	// seamless case above already covers, at `prefix_name => 0`.
	check( 'Grouped note' === $clone_group_row['meta_info_note'] && ( 5 === $clone_group_row['meta_info_priority'] || 5.0 === $clone_group_row['meta_info_priority'] ), 'a group-display clone keeps its own field names and values' );

	// Options Page: a singleton, one entry, fields the site actually configured.
	$options_model_id = 'acf-options-bridge-options';
	check( isset( $job['models'][ $options_model_id ] ) && 'singleton' === $job['models'][ $options_model_id ]['kind'], 'an Options Page becomes a singleton model' );
	$options_entry = json_decode( Files::read( $dir, Models::content_path( $job, $options_model_id, $job['default_locale'] ) ), true );
	check( 'Bridge Options' === $options_entry['page_title'], 'the Options Page singleton always has a valid title field, whatever its configured fields are named' );
	check( 'Owns its content' === $options_entry['acf_site_wide_tagline'] && ( 2026 === $options_entry['acf_copyright_year'] || 2026.0 === $options_entry['acf_copyright_year'] ), 'Options Page field values are modelled the same way a post\'s own ACF fields are' );

	// Two Options Pages sharing the default `options` post_id: each singleton
	// must carry only the fields its own field group is located to.
	$header_id = 'acf-options-bridge-header';
	$footer_id = 'acf-options-bridge-footer';
	check( isset( $job['models'][ $header_id ]['fields']['acf_header_text'] ) && ! isset( $job['models'][ $header_id ]['fields']['acf_footer_text'] ), 'an Options Page sharing the default post_id carries only its own field, not a sibling page\'s' );
	check( isset( $job['models'][ $footer_id ]['fields']['acf_footer_text'] ) && ! isset( $job['models'][ $footer_id ]['fields']['acf_header_text'] ), 'a sibling Options Page on the same shared post_id carries only its own field in turn' );
	$header_entry = json_decode( Files::read( $dir, Models::content_path( $job, $header_id, $job['default_locale'] ) ), true );
	$footer_entry = json_decode( Files::read( $dir, Models::content_path( $job, $footer_id, $job['default_locale'] ) ), true );
	check( 'Welcome banner' === $header_entry['acf_header_text'], 'the header Options Page value is its own, not the footer\'s' );
	check( 'All rights reserved' === $footer_entry['acf_footer_text'], 'the footer Options Page value is its own, not the header\'s' );

	if ( $has_polylang ) {
		// The export must not depend on whatever language an admin's own
		// admin-bar filter happens to be set to when they run it — Polylang
		// sets `curlang` from exactly that filter (`admin-base.php`
		// `set_current_language()`), and the real "ACF Options for Polylang"
		// plugin then redirects a normal `get_field_object()` read to that
		// language's row. Proven directly against the same read the export
		// itself performs, on the real per-language row planted above.
		PLL()->curlang = PLL()->model->get_language( 'da' );
		$curlang_excluded = array();
		list( $curlang_raw, ) = Source::acf_fields_for_options_page( 'options', 'bridge-header', 'acf-options/bridge-header', $curlang_excluded );
		PLL()->curlang = false;
		check( 'Welcome banner' === ( $curlang_raw['header_text']['value'] ?? null ), 'an Options Page read while an admin\'s language filter is set to Danish still returns the untranslated default, not the Danish redirect' );

		// BO-10: a term reference is resolved once, from a post's own term
		// relationship or a raw term_taxonomy row; re-reading it later must
		// find the same term regardless of whichever language an admin's
		// filter happens to be set to. Proven against the real `en`-tagged
		// fixture category under all three states the export can run in.
		$default_term = Source::term_by( 'slug', 'bridge-fixture-category', 'category' );
		PLL()->curlang = PLL()->model->get_language( 'da' );
		$da_term = Source::term_by( 'slug', 'bridge-fixture-category', 'category' );
		PLL()->curlang = PLL()->model->get_language( 'en' );
		$en_term = Source::term_by( 'slug', 'bridge-fixture-category', 'category' );
		PLL()->curlang = false;
		check(
			$default_term && $da_term && $en_term
			&& $default_term->term_id === $da_term->term_id
			&& $default_term->term_id === $en_term->term_id,
			'a term reference resolves to the same term whether curlang is unset, Danish or English'
		);
	}

	// A third-party plugin can give an Options Page a per-language copy this
	// plugin does not read; a real detection (the plugin installed above) must
	// name the gap, and coverage must attribute both the exported default
	// value and the unread translation honestly, not fold either into
	// `excluded:configuration-not-content`.
	$options_warning_reasons = implode( '|', array_column( json_decode( Files::read( $dir, 'bridge/warnings.json' ), true ), 'reason' ) );
	check( false !== strpos( $options_warning_reasons, 'acf-options-translation-plugin-detected' ), 'a detected Options Page translation plugin is reported, not silently ignored' );
	$coverage = json_decode( Files::read( $dir, 'bridge/coverage.json' ), true );
	$options_source = null;
	foreach ( $coverage['sources'] as $source ) {
		if ( 'options' === $source['source'] ) {
			$options_source = $source;
			break;
		}
	}
	// 5 scalar fields (value + reference, 10 rows) plus one 2-row repeater
	// (its own value + reference, and each row's own sub-field value +
	// reference: 3 pairs, 6 rows) = 16.
	check( 16 === ( $options_source['outcomes']['exported:acf-options'] ?? 0 ), 'every exported Options Page field\'s storage rows, including a repeater\'s own sub-rows, are reported as exported' );
	// Three real per-language rows (value + reference each, 6 rows): the
	// custom `bridge_locale_options` post_id, the default `options` post_id,
	// and — matching the plugin's own default-language quirk — a copy under
	// the *default* language's own suffix, not only a non-default one.
	check( 6 === ( $options_source['outcomes']['unsupported:options-page-translation'] ?? 0 ), 'every real per-language Options Page row (value + reference) is reported as unsupported, not silently excluded' );
	$locale_options_id = 'acf-options-bridge-locale-options';
	$locale_entry = json_decode( Files::read( $dir, Models::content_path( $job, $locale_options_id, $job['default_locale'] ) ), true );
	check( 'Default language value' === $locale_entry['acf_locale_note'], 'an Options Page with a detected per-language copy still exports only its default-language value' );
}

if ( $has_polylang ) {
	// BO-12: `Source::inventory()`'s site-wide comment count comes from
	// `wp_count_comments()`, which Polylang filters to whichever language an
	// admin's own filter happens to be set to, the same way it filters terms
	// — a count that should describe the whole site, not one language of it.
	$default_comments = Source::inventory()['comments'];
	PLL()->curlang = PLL()->model->get_language( 'da' );
	$da_comments = Source::inventory()['comments'];
	PLL()->curlang = false;
	check( $default_comments === $da_comments, 'the site-wide comment count does not depend on an admin\'s language filter' );
}

check( Validator::run( $job )['valid'], 'generated relation graph validates' );
$broken = $job;
$broken['tables'][ Models::content_path( $job, 'wp-authors', $address['locale'] ) ] = array();
rejects( static function () use ( $broken ) { Validator::run( $broken ); }, 'missing author relation fails validation' );
foreach ( $job['files'] as $path => $info ) {
	check( hash_file( 'sha256', Files::path( $dir, $path ) ) === $info['sha256'], 'file hash: ' . $path );
}
if ( class_exists( '\ZipArchive' ) ) {
	do { $zip = Admin::zip( $id ); } while ( ! $zip['done'] );
	$archive = new ZipArchive(); $archive->open( Files::dir( $id ) . '/export.zip' );
	check( false !== $archive->locateName( '.contentrain/config.json' ), 'ZIP includes dot-prefixed content store' );
	check( $archive->numFiles === count( $job['files'] ), 'ZIP contains each exported file once' );
	$archive->close();
}
// GitHub API fixture: all network traffic is intercepted. No repository is mutated.
$requests = array();
$github_filter = static function ( $pre, $args, $url ) use ( &$requests ) {
	$requests[] = array( 'url' => $url, 'method' => $args['method'] );
	if ( 0 !== strpos( $url, 'https://api.github.com/repos/test-owner/test-repo' ) ) { throw new RuntimeException( 'Unexpected external request: ' . $url ); }
	$path = substr( $url, strlen( 'https://api.github.com/repos/test-owner/test-repo' ) );
	if ( '' === $path ) { $data = array( 'private' => true, 'default_branch' => 'main' ); }
	elseif ( '/git/ref/heads/main' === $path ) { $data = array( 'object' => array( 'sha' => str_repeat( 'a', 40 ) ) ); }
	elseif ( 0 === strpos( $path, '/git/commits/' ) ) { $data = array( 'tree' => array( 'sha' => str_repeat( 'b', 40 ) ) ); }
	elseif ( 0 === strpos( $path, '/git/trees/' ) ) { $data = array( 'truncated' => false, 'tree' => array() ); }
	elseif ( '/git/blobs' === $path || '/git/trees' === $path || '/git/commits' === $path ) { $data = array( 'sha' => hash( 'sha1', $args['body'] ) ); }
	elseif ( '/git/refs' === $path ) { $data = array( 'ref' => 'ok' ); }
	else { throw new RuntimeException( 'Unmocked GitHub endpoint.' ); }
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $github_filter, 10, 3 );
$summary = GitHub::start( $id, 'test-only-token-123456', 'test-owner/test-repo' );
for ( $i = 0; $i < 500 && 'done' !== $summary['github']['phase']; ++$i ) { $summary = GitHub::step( $id, 'test-only-token-123456', $summary['github']['cursor'] ); }
check( 'done' === $summary['github']['phase'], 'GitHub branch delivery completes through mocked HTTP API' );
$ref_writes = array_filter( $requests, static function ( $r ) { return 'POST' === $r['method'] && substr( $r['url'], -9 ) === '/git/refs'; } );
check( 1 === count( $ref_writes ), 'only one new Git branch is created' );
check( false === strpos( Files::read( Files::dir( $id ), 'state.json' ), 'test-only-token' ), 'GitHub token is never persisted' );
$repeat = GitHub::step( $id, 'test-only-token-123456', 0 );
check( 'done' === $repeat['github']['phase'], 'repeated delivery returns the existing receipt' );
remove_filter( 'pre_http_request', $github_filter, 10 );

// An empty repository (no commit at all): GitHub answers every Git data request
// with 409 "Git Repository is empty.". Delivery must say what to do, write
// nothing, and — like every GitHub failure — keep the token out of the trace.
$reset_github = static function () use ( $id ) { Jobs::mutate( $id, static function ( &$job ) { unset( $job['github'] ); } ); };
$github_token = 'github_pat_TESTONLY0000000000000000';
$requests = array();
$empty_filter = static function ( $pre, $args, $url ) use ( &$requests ) {
	$requests[] = array( 'url' => $url, 'method' => $args['method'] );
	$path = substr( $url, strlen( 'https://api.github.com/repos/test-owner/test-repo' ) );
	if ( '' === $path ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'private' => true, 'default_branch' => 'main' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'message' => 'Git Repository is empty.', 'status' => '409' ) ), 'response' => array( 'code' => 409, 'message' => 'Conflict' ), 'cookies' => array() );
};
$reset_github();
add_filter( 'pre_http_request', $empty_filter, 10, 3 );
$empty_error = null;
try { GitHub::start( $id, $github_token, 'test-owner/test-repo' ); } catch ( RuntimeException $error ) { $empty_error = $error; }
remove_filter( 'pre_http_request', $empty_filter, 10 );
check( $empty_error && false !== strpos( $empty_error->getMessage(), 'repository is empty' ), 'an empty GitHub repository is reported with what to do, not as a bare HTTP 409' );
check( ! array_filter( $requests, static function ( $r ) { return 'GET' !== $r['method']; } ), 'nothing is written to an empty repository, not even its first commit' );
check( $empty_error && false === strpos( $empty_error->getTraceAsString(), 'github_pat_' ) && false === strpos( (string) $empty_error, 'github_pat_' ), 'a failed GitHub request\'s stack trace carries no part of the token' );
check( '0' === ini_get( 'zend.exception_ignore_args' ) || '' === ini_get( 'zend.exception_ignore_args' ), 'the trace setting is restored after the GitHub call' );
check( ! isset( Jobs::read( $id )['github'] ), 'an empty repository leaves no half-started delivery behind' );

// A chosen base branch (here `e2e/run-1`, a name with a slash): read, compared
// against, never written; the default branch is not even read.
$requests = array();
$base_filter = static function ( $pre, $args, $url ) use ( &$requests ) {
	$requests[] = array( 'url' => $url, 'method' => $args['method'] );
	$path = substr( $url, strlen( 'https://api.github.com/repos/test-owner/test-repo' ) );
	$code = 200;
	if ( '' === $path ) { $data = array( 'private' => true, 'default_branch' => 'main' ); }
	elseif ( '/git/ref/heads/e2e/run-1' === $path ) { $data = array( 'object' => array( 'sha' => str_repeat( 'c', 40 ) ) ); }
	elseif ( '/git/ref/heads/missing' === $path ) { $code = 404; $data = array( 'message' => 'Not Found' ); }
	elseif ( 0 === strpos( $path, '/git/commits/' ) ) { $data = array( 'tree' => array( 'sha' => str_repeat( 'd', 40 ) ) ); }
	elseif ( 0 === strpos( $path, '/git/trees/' ) ) { $data = array( 'truncated' => false, 'tree' => array() ); }
	else { throw new RuntimeException( 'Unmocked GitHub endpoint: ' . $path ); }
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array() );
};
$reset_github();
add_filter( 'pre_http_request', $base_filter, 10, 3 );
$based = GitHub::start( $id, $github_token, 'test-owner/test-repo', 'refuse', 'e2e/run-1' );
$based_state = Jobs::read( $id )['github'];
check( 'e2e/run-1' === $based_state['base_branch'] && str_repeat( 'c', 40 ) === $based_state['base'], 'delivery starts from the chosen base branch' );
check( 'contentrain/bridge-' . $id === $based_state['branch'], 'a chosen base branch still delivers to a new branch of its own' );
check( ! array_filter( $requests, static function ( $r ) { return false !== strpos( $r['url'], '/heads/main' ) || 'GET' !== $r['method']; } ), 'the default branch is not read and nothing is written when a base branch is chosen' );
rejects( static function () use ( $id, $github_token ) { GitHub::start( $id, $github_token, 'test-owner/test-repo', 'refuse', 'other-base' ); }, 'a started delivery cannot switch to another base branch' );
$reset_github();
foreach ( array( '../main', 'a..b', 'feature/', '-x', 'refs.lock', 'a b' ) as $bad_branch ) {
	rejects( static function () use ( $id, $github_token, $bad_branch ) { GitHub::start( $id, $github_token, 'test-owner/test-repo', 'refuse', $bad_branch ); }, 'an unusable base branch name is refused: ' . $bad_branch );
}
$missing_error = null;
try { GitHub::start( $id, $github_token, 'test-owner/test-repo', 'refuse', 'missing' ); } catch ( RuntimeException $error ) { $missing_error = $error; }
remove_filter( 'pre_http_request', $base_filter, 10 );
check( $missing_error && false !== strpos( $missing_error->getMessage(), 'base branch missing does not exist' ), 'a base branch that does not exist is named, not reported as a bare HTTP 404' );
$reset_github();
file_put_contents( '/tmp/bridge-test-output-path', $dir );
// Authenticated media transfer must survive JSON encoding without corrupting PNG bytes.
$request = new WP_REST_Request( 'GET', '/contentrain-bridge/v1/exports/' . $id );
$request->set_param( 'id', $id ); $request->set_param( 'file', $stored );
$rest_file = Admin::read_export( $request );
check( ! is_wp_error( $rest_file ) && 'base64' === $rest_file->get_data()['encoding'], 'REST marks binary export content as base64' );
check( $png === base64_decode( $rest_file->get_data()['content'], true ), 'REST preserves media bytes' );
check( 'private, no-store' === $rest_file->get_headers()['Cache-Control'], 'export responses cannot be publicly cached' );
$request->set_param( 'file', '../state.json' );
check( is_wp_error( Admin::read_export( $request ) ), 'REST cannot read files outside the export manifest' );
$lock = fopen( Files::dir( $id ) . '/lock', 'c' );
flock( $lock, LOCK_EX );
rejects( static function () use ( $id ) { Jobs::delete( $id ); }, 'cancel cannot delete an actively locked export' );
flock( $lock, LOCK_UN ); fclose( $lock );
$expired = bin2hex( random_bytes( 16 ) );
Files::mkdir( Files::dir( $expired ) );
Files::put( Files::dir( $expired ), 'state.json', Policy::json( array( 'created_at' => gmdate( 'c', time() - 2 * DAY_IN_SECONDS ) ) ) );
Files::cleanup();
check( ! is_dir( Files::dir( $expired ) ), 'expiry uses creation time even when state was just written' );
$mapping_job = array( 'uploads' => array( 'baseurl' => 'https://example.test/uploads' ), 'files' => array( 'media/safe.png' => array() ), 'media_paths' => array( 'ç.png' => 'media/safe.png' ) );
check( '/media/safe.png' === Models::relink( $mapping_job, 'https://example.test/uploads/%C3%A7.png', true ), 'encoded Unicode upload URLs map to safe exported paths' );

// Escape-bearing metadata is written, not refused. It used to be refused, because
// the published reader stripped a scalar's quotes without decoding its escapes;
// `@contentrain/types@1.14.0` fixed that and `tests/reader-compat.mjs` proves it
// against this writer's own output on every CI run.
$quoted = Policy::frontmatter( array( 'title' => 'A "quoted" title' ) );
check( 'title: "A ' . chr( 92 ) . '"quoted' . chr( 92 ) . '" title"' === $quoted, 'a quote in metadata is written as an escaped quote' );
$multiline = Policy::frontmatter( array( 'excerpt' => "line one\nline two" ) );
check( 'excerpt: "line one' . chr( 92 ) . 'nline two"' === $multiline, 'a newline in metadata is written as an escape' );
check( 1 === count( explode( "\n", $multiline ) ), 'a multi-line value occupies exactly one frontmatter line' );

// The fixture the reader gate reads: this writer's real output for
// escape-bearing values, with the values that went in beside it.
$compat = array(
	'title'    => 'A "quoted" title',
	'excerpt'  => "line one\nline two",
	'path'     => 'C:' . chr( 92 ) . 'Users' . chr( 92 ) . 'ada',
	'tabbed'   => "col1\tcol2",
	'slash'    => 'a/b/c',
	'unicode'  => 'İstanbul — café ✅ 日本語',
	'colon'    => 'Title: subtitle',
	'padded'   => '  padded  ',
	'blank'    => '',
	'sku'      => '007',
	'flagish'  => 'true',
	'count'    => 42,
	'flag'     => true,
);
Files::put( $dir, 'reader-compat.md', "---\n" . Policy::frontmatter( $compat ) . "\n---\nBody text." );
Files::put( $dir, 'reader-compat.json', Policy::json( $compat ) );
check( is_file( $dir . '/reader-compat.md' ), 'reader compatibility fixture written for escape-bearing metadata' );

// Snapshot consistency check on a separate job, preserving the finished artifact for external validation.
delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
$other = Jobs::create( array( 'types' => array( 'page' ) ) );
Source::changed();
rejects( static function () use ( $other ) { Jobs::step( $other['id'], $other['step'] ); }, 'changed source snapshot blocks continuation' );
Files::remove( Files::dir( $other['id'] ) );
update_user_meta( $admin->ID, 'contentrain_bridge_job_1', $id );
echo "\n$checks checks passed. Output: $dir\n";
