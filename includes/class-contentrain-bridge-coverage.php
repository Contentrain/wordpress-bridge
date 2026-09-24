<?php
/** Source coverage: every content-bearing source, counted, with one outcome each. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * "Complete source coverage" made checkable. Every source WordPress keeps
 * content in is listed with a count taken from the database on its own, and
 * that count is split into outcomes: `exported`, `excluded:<reason>` or
 * `unsupported:<reason>`. A source whose outcomes do not add up to its count is
 * `balanced: false`, and a table the export never reads is listed rather than
 * passed over. Silence is the one answer this report cannot give.
 */
final class Coverage {
	const FORMAT = 'contentrain-bridge-coverage@1';

	/** Post types that are not content, and why. Anything else not selected is `type-not-selected`. */
	const NOT_CONTENT = array(
		'revision'            => 'excluded:revision',
		'oembed_cache'        => 'excluded:oembed-cache',
		'customize_changeset' => 'excluded:customizer-draft',
		'user_request'        => 'excluded:privacy-request',
		'wp_global_styles'    => 'excluded:design-settings',
		'wp_font_family'      => 'excluded:design-settings',
		'wp_font_face'        => 'excluded:design-settings',
		'custom_css'          => 'excluded:design-settings',
	);

	/** WordPress bookkeeping meta: not content, never selected. */
	const INTERNAL_META = '/^(_edit_lock|_edit_last|_wp_old_slug|_wp_old_date|_wp_trash_meta_.+|_encloseme|_pingme|_wp_desired_post_slug|_wp_suggested_privacy_policy_content|_menu_item_.+)$/';

	/** Tables this export reads, beyond the WordPress core set. */
	const READ_TABLES = array( 'redirection_items', 'redirection_groups', 'rank_math_redirections', 'aioseo_posts' );

	/** Tables derived from content by a plugin: a cache of what is exported, not a source. */
	const DERIVED_TABLES = '/^(yoast_indexable|yoast_indexable_hierarchy|yoast_seo_links|yoast_primary_term|yoast_migrations|yoast_expiring_store|redirection_404|redirection_logs|actionscheduler_.+|wc_admin_.+)$/';

	/** Record one outcome during an export step. */
	public static function tally( &$job, $path, $outcome, $n = 1 ) {
		$node = &$job['coverage'];
		foreach ( $path as $segment ) {
			if ( ! isset( $node[ $segment ] ) ) {
				$node[ $segment ] = array();
			}
			$node = &$node[ $segment ];
		}
		$node[ $outcome ] = ( $node[ $outcome ] ?? 0 ) + $n;
		unset( $node );
	}

	/** Where a post's meta keys went: exported, exported as ACF, or excluded with a reason. */
	public static function post_meta( &$job, $post_id, $record, $excluded ) {
		$acf = array();
		foreach ( array_keys( $record['raw']['acf'] ?? array() ) as $name ) {
			$acf[ $name ] = true;
			$acf[ '_' . $name ] = true;
		}
		$reasons = array();
		foreach ( $excluded as $item ) {
			if ( preg_match( '#^post/' . (int) $post_id . '/(.+)$#', (string) ( $item['source'] ?? '' ), $m ) ) {
				$reasons[ $m[1] ] = $item['reason'];
			}
		}
		foreach ( array_keys( get_post_meta( $post_id ) ) as $key ) {
			if ( isset( $acf[ $key ] ) ) {
				$outcome = 'exported:acf';
			} elseif ( array_key_exists( $key, $record['raw']['meta'] ?? array() ) ) {
				$outcome = 'exported';
			} elseif ( preg_match( self::INTERNAL_META, $key ) ) {
				$outcome = 'excluded:wordpress-internal';
			} elseif ( 0 === strpos( $key, '_oembed_' ) ) {
				$outcome = 'excluded:oembed-cache';
			} elseif ( isset( $reasons[ $key ] ) ) {
				$outcome = 'excluded:' . $reasons[ $key ];
			} else {
				$outcome = 'unsupported:unclassified';
			}
			self::tally( $job, array( 'post_meta' ), $outcome );
		}
	}

	/** The report: sources, counts from the database, outcomes, and whether each adds up. */
	public static function report( $job ) {
		global $wpdb;
		$c = $job['coverage'] ?? array();
		$sources = array();
		$types = $job['options']['types'];

		// ---- Posts table: every row, by type and status. ----
		$rows = (array) $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) AS n FROM {$wpdb->posts} GROUP BY post_type, post_status ORDER BY post_type, post_status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An independent count; caches are what it checks against.
		$by_type = array();
		foreach ( $rows as $row ) {
			$by_type[ $row['post_type'] ][ $row['post_status'] ] = (int) $row['n'];
		}
		$menu_items = (int) ( $c['menu_items']['exported'] ?? 0 );
		foreach ( $by_type as $type => $statuses ) {
			$outcomes = array();
			foreach ( $statuses as $status => $n ) {
				if ( 'nav_menu_item' === $type ) {
					continue;
				}
				if ( in_array( $type, $types, true ) ) {
					$tallied = (array) ( $c['posts'][ $type ][ $status ] ?? array() );
					foreach ( $tallied as $outcome => $count ) {
						$outcomes[ $outcome ] = ( $outcomes[ $outcome ] ?? 0 ) + $count;
					}
					if ( array_sum( $tallied ) < $n ) {
						$outcomes['unsupported:not-reached'] = ( $outcomes['unsupported:not-reached'] ?? 0 ) + $n - array_sum( $tallied );
					}
				} else {
					$outcome = self::NOT_CONTENT[ $type ] ?? ( post_type_exists( $type ) ? 'excluded:type-not-selected' : 'unsupported:type-not-registered' );
					$outcomes[ $outcome ] = ( $outcomes[ $outcome ] ?? 0 ) + $n;
				}
			}
			if ( 'nav_menu_item' === $type ) {
				// Menus are exported by menu: an item in no menu is not reached.
				$total = array_sum( $statuses );
				$outcomes = array_filter( array( 'exported:menus' => min( $menu_items, $total ), 'excluded:not-in-a-menu' => max( 0, $total - $menu_items ) ) );
			}
			$sources[] = self::source( 'posts:' . $type, 'posts', array_sum( $statuses ), $outcomes, array( 'statuses' => $statuses, 'registered' => post_type_exists( $type ), 'show_in_rest' => post_type_exists( $type ) ? (bool) get_post_type_object( $type )->show_in_rest : null ) );
		}

		// ---- Taxonomies and term meta. ----
		foreach ( (array) $wpdb->get_results( "SELECT taxonomy, COUNT(*) AS n FROM {$wpdb->term_taxonomy} GROUP BY taxonomy ORDER BY taxonomy", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent count.
			$sources[] = self::source( 'terms:' . $row['taxonomy'], 'terms', (int) $row['n'], (array) ( $c['terms'][ $row['taxonomy'] ] ?? array() ), array( 'registered' => taxonomy_exists( $row['taxonomy'] ) ) );
		}
		$sources[] = self::source( 'term_meta', 'meta', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta}" ), (array) ( $c['term_meta'] ?? array() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent count.

		// ---- Meta of exported posts and attachments: one row per (record, key). ----
		$exported_posts = array_map( 'intval', array_keys( $job['tables']['bridge/raw-posts.json'] ?? array() ) );
		$exported_media = array_map( 'intval', array_keys( $job['tables']['bridge/raw-attachments.json'] ?? array() ) );
		$sources[] = self::source( 'post_meta', 'meta', self::meta_pairs( $exported_posts ), (array) ( $c['post_meta'] ?? array() ), array( 'scope' => 'meta keys of exported posts; a post that is not exported takes its meta with it' ) );
		$sources[] = self::source( 'attachment_meta', 'meta', self::meta_pairs( $exported_media ), (array) ( $c['attachment_meta'] ?? array() ), array( 'scope' => 'meta keys of exported attachments' ) );

		// ---- Comments, users. ----
		foreach ( (array) $wpdb->get_results( "SELECT comment_approved, COUNT(*) AS n FROM {$wpdb->comments} GROUP BY comment_approved ORDER BY comment_approved", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent count.
			$n = (int) $row['n'];
			$outcomes = $job['options']['comments'] ? (array) ( $c['comments'][ $row['comment_approved'] ] ?? array() ) : array( 'excluded:comments-not-selected' => $n );
			$sources[] = self::source( 'comments:' . $row['comment_approved'], 'comments', $n, $outcomes );
		}
		$users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent count.
		$authors = count( $job['tables']['bridge/raw-authors.json'] ?? array() );
		$sources[] = self::source( 'users', 'people', $users, array_filter( array( 'exported:author-public-fields' => $authors, 'excluded:no-exported-content' => $users - $authors ) ), array( 'fields' => 'login and display name only; email, password, and user meta are never exported' ) );

		// ---- Options: identity, SEO, widgets, Customizer, and the rest by name. ----
		$options = array();
		$options_pages = self::options_page_index();
		$locale_suffixes = self::translation_suffixes( $job );
		foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" ) as $name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent enumeration.
			$outcome = self::option( $name, $job, $options_pages, $locale_suffixes );
			$options[ $outcome ] = ( $options[ $outcome ] ?? 0 ) + 1;
		}
		$sources[] = self::source( 'options', 'site', array_sum( $options ), $options );

		// ---- Widgets and Customizer: exported as interface text when that scan ran. ----
		$text = ! empty( $job['options']['scan_sources'] ) ? 'exported:interface-text' : 'excluded:interface-text-scan-off';
		$widgets = 0;
		foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%'" ) as $name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent enumeration.
			$widgets += count( array_filter( (array) get_option( $name ), 'is_array' ) );
		}
		$sources[] = self::source( 'widgets', 'site', $widgets, $widgets ? array( $text => $widgets ) : array() );
		$mods = array_filter( (array) get_theme_mods(), 'is_string' );
		$sources[] = self::source( 'customizer', 'site', count( (array) get_theme_mods() ), array_filter( array( $text => count( $mods ), 'excluded:not-text' => count( (array) get_theme_mods() ) - count( $mods ) ) ) );

		// ---- Body features the export keeps as source but cannot render. ----
		$bodies = self::bodies( $exported_posts );
		$sources[] = self::source( 'sticky', 'flags', count( (array) get_option( 'sticky_posts', array() ) ), array_filter( array( 'exported:flag' => count( array_intersect( array_map( 'intval', (array) get_option( 'sticky_posts', array() ) ), $exported_posts ) ), 'excluded:post-not-exported' => count( array_diff( array_map( 'intval', (array) get_option( 'sticky_posts', array() ) ), $exported_posts ) ) ) ) );
		$sources[] = self::source( 'page_templates', 'flags', $bodies['templates'], $bodies['templates'] ? array( 'exported:meta' => $bodies['templates'] ) : array() );
		$sources[] = self::source( 'shortcodes', 'bodies', $bodies['shortcodes'], $bodies['shortcodes'] ? array( 'unsupported:kept-as-source-needs-renderer' => $bodies['shortcodes'] ) : array() );
		$sources[] = self::source( 'embeds', 'bodies', $bodies['embeds'], $bodies['embeds'] ? array( 'unsupported:embed-url-kept-needs-renderer' => $bodies['embeds'] ) : array() );
		$sources[] = self::source( 'reusable_block_references', 'bodies', count( $bodies['block_refs'] ), array_filter( array( 'exported:target-in-export' => count( array_intersect( $bodies['block_refs'], $exported_posts ) ), 'excluded:target-not-exported' => count( array_diff( $bodies['block_refs'], $exported_posts ) ) ) ) );
		$patterns = class_exists( '\WP_Block_Patterns_Registry' ) ? count( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() ) : 0;
		$sources[] = self::source( 'block_patterns_registered', 'code', $patterns, $patterns ? array( 'excluded:registered-in-code' => $patterns ) : array() );

		// ---- Network, and every table in the database. ----
		$others = is_multisite() ? max( 0, count( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) ) - 1 ) : 0;
		$sources[] = self::source( 'network_sites', 'site', $others, $others ? array( 'excluded:other-site-in-network' => $others ) : array(), array( 'multisite' => is_multisite() ) );
		$sources[] = self::tables( $job );

		$totals = array( 'sources' => count( $sources ), 'unbalanced' => 0, 'unsupported' => 0 );
		foreach ( $sources as $source ) {
			$totals['unbalanced'] += $source['balanced'] ? 0 : 1;
			foreach ( $source['outcomes'] as $outcome => $n ) {
				if ( 0 === strpos( $outcome, 'unsupported:' ) ) {
					$totals['unsupported'] += $n;
				}
			}
		}
		return array(
			'format'            => self::FORMAT,
			'scope'             => array( 'types' => $types, 'private' => (bool) $job['options']['private'], 'comments' => (bool) $job['options']['comments'], 'interface_text' => ! empty( $job['options']['scan_sources'] ) ),
			'sources'           => $sources,
			'totals'            => $totals,
			// The export's own verdict, derived: complete only when nothing is unsupported or unbalanced.
			'complete'          => 0 === $totals['unbalanced'] && 0 === $totals['unsupported'],
		);
	}

	private static function source( $name, $group, $count, $outcomes, $detail = array() ) {
		ksort( $outcomes );
		$outcomes = array_filter( $outcomes );
		return array( 'source' => $name, 'group' => $group, 'count' => (int) $count, 'outcomes' => $outcomes ?: (object) array(), 'balanced' => (int) $count === (int) array_sum( $outcomes ) ) + $detail;
	}

	/** Field types whose own storage is more than one row: a sub-field's row belongs to the parent field that owns it, not to no field at all. */
	const OPTIONS_COMPOSITE_TYPES = array( 'repeater', 'flexible_content', 'group' );

	/** Every registered Options Page's storage `post_id` (default `options`, shared by every page that does not set its own), its own field names, and which of those fields store more than one row under their own name. */
	private static function options_page_index() {
		$pages = array();
		foreach ( Source::options_pages() as $page ) {
			$post_id = (string) ( $page['post_id'] ?: 'options' );
			$slug = $page['menu_slug'] ?? sanitize_title( $post_id );
			$entry = $pages[ $post_id ] ?? array( 'names' => array(), 'composite' => array() );
			if ( function_exists( 'acf_get_field_groups' ) ) {
				foreach ( acf_get_field_groups( array( 'options_page' => $slug ) ) as $group ) {
					foreach ( acf_get_fields( $group ) as $field ) {
						$entry['names'][] = $field['name'];
						if ( in_array( $field['type'], self::OPTIONS_COMPOSITE_TYPES, true ) || ( 'clone' === $field['type'] && 'group' === ( $field['display'] ?? 'seamless' ) ) ) {
							$entry['composite'][] = $field['name'];
						}
					}
				}
			}
			$entry['names'] = array_values( array_unique( $entry['names'] ) );
			$entry['composite'] = array_values( array_unique( $entry['composite'] ) );
			$pages[ $post_id ] = $entry;
		}
		return $pages;
	}

	/** A row name belongs to a field either directly, or (for a repeater/group/flexible_content/group-display-clone) as one of that field's own sub-rows. */
	private static function options_field_for( $rest, $field_names, $composite ) {
		if ( in_array( $rest, $field_names, true ) ) {
			return true;
		}
		foreach ( $composite as $name ) {
			if ( 0 === strpos( $rest, $name . '_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every locale suffix a third-party translation plugin could realistically
	 * write onto an Options Page's storage: Polylang's own `locale` and `slug`
	 * forms (`da_DK`/`da`, either can be the configured suffix attribute — see
	 * "ACF Options for Polylang"'s `bea.aofp.lang_attribute` filter), for every
	 * configured language including the default one — measured empirically,
	 * the default language gets a redundant suffixed copy too, because the
	 * plugin never configures ACF's own `default_language` setting. WPML +
	 * ACFML has no free tier to test against, so the project's own generic
	 * locale list is kept only as a best-effort fallback pattern alongside it.
	 */
	private static function translation_suffixes( $job ) {
		$suffixes = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$suffixes = array_merge( $suffixes, (array) pll_languages_list( array( 'fields' => 'locale' ) ), (array) pll_languages_list( array( 'fields' => 'slug' ) ) );
		}
		$suffixes = array_merge( $suffixes, array_map( array( Source::class, 'locale' ), (array) $job['inventory']['languages'] ) );
		return array_values( array_unique( array_filter( $suffixes ) ) );
	}

	/**
	 * A third-party plugin can give an Options Page a per-language copy of its
	 * values — WPML + ACFML, or the free "ACF Options for Polylang" (BeAPI),
	 * which suffixes a locale onto a `post_id` (`{post_id}_{locale}_{field}`,
	 * including the default `options` post_id itself) — storage this plugin
	 * never reads (see `i18n: false` in `Models::options_page()`), so those
	 * rows must not be counted as if nothing exists for them.
	 */
	private static function option( $name, $job, $options_pages = array(), $locale_suffixes = array() ) {
		$bare = '_' === ( $name[0] ?? '' ) ? substr( $name, 1 ) : $name;
		foreach ( $options_pages as $post_id => $page ) {
			if ( 0 !== strpos( $bare, $post_id . '_' ) ) {
				continue;
			}
			$rest = substr( $bare, strlen( $post_id ) + 1 );
			if ( self::options_field_for( $rest, $page['names'], $page['composite'] ) ) {
				return 'exported:acf-options';
			}
			foreach ( $locale_suffixes as $locale ) {
				if ( 0 === strpos( $rest, $locale . '_' ) && self::options_field_for( substr( $rest, strlen( $locale ) + 1 ), $page['names'], $page['composite'] ) ) {
					return 'unsupported:options-page-translation';
				}
			}
		}
		if ( in_array( $name, array( 'blogname', 'blogdescription', 'siteurl', 'home', 'WPLANG', 'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'timezone_string', 'date_format', 'time_format', 'category_base', 'tag_base' ), true ) ) {
			return 'exported:site-identity';
		}
		if ( preg_match( '/^(wpseo|wpseo_titles|wpseo_social|wpseo_taxonomy_meta|rank-math-options-.+|aioseo_options|wpseo-premium-redirects-base)$/', $name ) ) {
			return 'exported:seo';
		}
		if ( 'sticky_posts' === $name ) {
			return 'exported:flag';
		}
		if ( in_array( $name, array( 'stylesheet', 'template', 'elementor_active_kit', 'et_divi' ), true ) ) {
			return 'exported:design';
		}
		if ( 0 === strpos( $name, 'widget_' ) || 'sidebars_widgets' === $name ) {
			return empty( $job['options']['scan_sources'] ) ? 'excluded:interface-text-scan-off' : 'exported:interface-text';
		}
		if ( 0 === strpos( $name, 'theme_mods_' ) ) {
			if ( 'theme_mods_' . get_option( 'stylesheet' ) !== $name ) {
				return 'excluded:inactive-theme';
			}
			return empty( $job['options']['scan_sources'] ) ? 'excluded:interface-text-scan-off' : 'exported:interface-text';
		}
		if ( preg_match( '/^_(site_)?transient_/', $name ) ) {
			return 'excluded:transient';
		}
		if ( 0 === strpos( $name, 'contentrain_bridge_' ) ) {
			return 'excluded:this-plugin';
		}
		return 'excluded:configuration-not-content';
	}

	private static function meta_pairs( $ids ) {
		global $wpdb;
		$total = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$total += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id, meta_key) FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The interpolated part is a list of %d placeholders; independent count.
		}
		return $total;
	}

	/** Features of exported bodies the store keeps as source text. */
	private static function bodies( $ids ) {
		$out = array( 'shortcodes' => 0, 'embeds' => 0, 'templates' => 0, 'block_refs' => array() );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$content = (string) $post->post_content;
			if ( preg_match_all( '/\[([a-z0-9_-]+)[\s\]]/i', $content, $m ) && array_filter( $m[1], 'shortcode_exists' ) ) {
				++$out['shortcodes'];
			}
			if ( preg_match( '/<!-- wp:embed|^\s*https?:\/\/\S+\s*$/m', $content ) ) {
				++$out['embeds'];
			}
			if ( preg_match_all( '/<!-- wp:block \{"ref":(\d+)/', $content, $m ) ) {
				$out['block_refs'] = array_merge( $out['block_refs'], array_map( 'intval', $m[1] ) );
			}
			$template = get_post_meta( $id, '_wp_page_template', true );
			if ( $template && 'default' !== $template ) {
				++$out['templates'];
			}
		}
		$out['block_refs'] = array_values( array_unique( $out['block_refs'] ) );
		return $out;
	}

	/** Every table in the database with this site's prefix: read, core, derived, or not read. */
	private static function tables( $job ) {
		global $wpdb;
		$outcomes = array();
		$detail = array();
		$core = array_map( static function ( $t ) use ( $wpdb ) { return $wpdb->prefix . $t; }, array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta', 'users', 'usermeta', 'options', 'links' ) );
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerating tables.
		foreach ( $tables as $table ) {
			$short = substr( $table, strlen( $wpdb->prefix ) );
			if ( in_array( $table, $core, true ) ) {
				$outcome = in_array( $short, array( 'links' ), true ) ? 'excluded:legacy-links' : ( in_array( $short, array( 'usermeta', 'commentmeta' ), true ) ? 'excluded:personal-data' : 'exported:core' );
			} elseif ( in_array( $short, self::READ_TABLES, true ) ) {
				$outcome = 'exported:plugin-table';
			} elseif ( preg_match( self::DERIVED_TABLES, $short ) ) {
				$outcome = 'excluded:derived-cache';
			} else {
				$outcome = 'unsupported:plugin-table-not-read';
				$detail[ $short ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Row count of a table the export does not read.
			}
			$outcomes[ $outcome ] = ( $outcomes[ $outcome ] ?? 0 ) + 1;
		}
		return self::source( 'database_tables', 'site', count( $tables ), $outcomes, array( 'not_read' => $detail ?: (object) array() ) );
	}
}
