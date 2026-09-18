<?php
/** SEO plugin metadata, settings and structured data. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * What the site tells search engines, per record and site-wide.
 *
 * Two kinds of value, kept apart on purpose. `stored` is what the SEO plugin
 * saved (a title template such as `%%title%% %%sep%% %%sitename%%`); the other
 * fields are what the running plugin renders into the page, and are only
 * present when that plugin is active (`resolved: true`). A migrated site has to
 * reproduce the rendered value; the stored one says how it was produced.
 *
 * A deactivated SEO plugin still left its data behind, and a migration still
 * needs it, so providers are detected by their data as well as by being active.
 */
final class Seo {
	const FORMAT = 'contentrain-bridge-seo@1';

	/** Yoast title separator keys, for when Yoast is not running to resolve them. */
	const YOAST_SEPARATORS = array(
		'sc-dash' => '-', 'sc-ndash' => '–', 'sc-mdash' => '—', 'sc-colon' => ':', 'sc-middot' => '·', 'sc-bull' => '•',
		'sc-star' => '*', 'sc-smstar' => '⋆', 'sc-pipe' => '|', 'sc-tilde' => '~', 'sc-laquo' => '«', 'sc-raquo' => '»',
		'sc-lt' => '<', 'sc-gt' => '>',
	);

	/** Keys of the general Yoast option that describe the site, not the install. */
	const YOAST_GENERAL = array( 'site_type', 'enable_xml_sitemap', 'disable-author', 'disable-date', 'breadcrumbs-enable', 'baiduverify', 'googleverify', 'msverify', 'yandexverify', 'ahrefsverify', 'remove_feed_global', 'remove_shortlinks', 'enable_llms_txt' );

	/** Which SEO plugins are active, and which left data behind. */
	public static function providers() {
		global $wpdb;
		$meta = static function ( $like ) use ( $wpdb ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Presence probe over all posts; no API exists.
		};
		$table = static function ( $name ) use ( $wpdb ) {
			return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Presence probe for a plugin table.
		};
		$found = array(
			'yoast'     => array( defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : null, false !== get_option( 'wpseo_titles' ) || $meta( $wpdb->esc_like( '_yoast_wpseo_' ) . '%' ) ),
			'rank_math' => array( defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null, false !== get_option( 'rank-math-options-titles' ) || $meta( $wpdb->esc_like( 'rank_math_' ) . '%' ) ),
			'aioseo'    => array( defined( 'AIOSEO_VERSION' ) ? AIOSEO_VERSION : null, false !== get_option( 'aioseo_options' ) || $table( $wpdb->prefix . 'aioseo_posts' ) ),
		);
		$out = array();
		foreach ( $found as $name => list( $version, $data ) ) {
			if ( $version ) {
				$out[ $name ] = array( 'status' => 'active', 'version' => (string) $version );
			} elseif ( $data ) {
				$out[ $name ] = array( 'status' => 'inactive-with-data' );
			} else {
				$out[ $name ] = array( 'status' => 'absent' );
			}
		}
		return $out;
	}

	/** Site-wide document: providers, the one whose output the site serves, and settings. */
	public static function document() {
		$providers = self::providers();
		$active = array_keys( array_filter( $providers, static function ( $p ) { return 'active' === $p['status']; } ) );
		$present = array_keys( array_filter( $providers, static function ( $p ) { return 'absent' !== $p['status']; } ) );
		$document = array(
			'format'    => self::FORMAT,
			// "none" is an answer: no SEO plugin, so WordPress core renders the title and nothing else.
			'status'    => $present ? 'present' : 'none',
			'serving'   => $active ? $active[0] : 'wordpress-core',
			'providers' => $providers,
			'settings'  => array(),
		);
		if ( count( $active ) > 1 ) {
			$document['warnings'][] = 'multiple-active-seo-plugins: ' . implode( ', ', $active ) . '; the page may carry duplicate tags.';
		}
		$excluded = array();
		foreach ( $present as $name ) {
			if ( 'yoast' === $name ) {
				$document['settings'][ $name ] = self::settings_yoast( $excluded );
			} elseif ( 'rank_math' === $name ) {
				$document['settings'][ $name ] = self::settings_rank_math( $excluded );
			} else {
				$document['settings'][ $name ] = self::settings_aioseo( $excluded );
			}
		}
		if ( ! $document['settings'] ) {
			$document['settings'] = (object) array();
		}
		if ( $excluded ) {
			$document['excluded'] = $excluded;
		}
		return $document;
	}

	/** Per-post entry: one block per provider with data for it; null when there is none. */
	public static function post( $post ) {
		$excluded = array();
		$entry = array();
		$providers = self::providers_cached();
		if ( 'absent' !== $providers['yoast']['status'] ) {
			$entry['yoast'] = self::yoast_post( $post, 'active' === $providers['yoast']['status'], $excluded );
		}
		if ( 'absent' !== $providers['rank_math']['status'] ) {
			$entry['rank_math'] = self::rank_math_post( $post, $excluded );
		}
		if ( 'absent' !== $providers['aioseo']['status'] ) {
			$entry['aioseo'] = self::aioseo_post( $post, $excluded );
		}
		$entry = array_filter( $entry );
		return $entry ? $entry : null;
	}

	public static function term( $term ) {
		$providers = self::providers_cached();
		if ( 'absent' === $providers['yoast']['status'] ) {
			return null;
		}
		$excluded = array();
		$stored = (array) ( get_option( 'wpseo_taxonomy_meta' )[ $term->taxonomy ][ $term->term_id ] ?? array() );
		$out = array( 'stored' => Policy::clean( $stored, $excluded, 'seo/term/' . $term->term_id ) );
		if ( 'active' === $providers['yoast']['status'] && function_exists( 'YoastSEO' ) ) {
			$meta = YoastSEO()->meta->for_term( $term->term_id );
			if ( $meta ) {
				$out = self::yoast_resolved( $meta ) + $out;
			}
			self::yoast_forget();
		}
		$out = array_filter( $out, array( self::class, 'filled' ) );
		return isset( $out['resolved'] ) || $out['stored'] ? array( 'yoast' => $out ) : null;
	}

	/** Providers are fixed for one request; detection runs queries. */
	private static function providers_cached( $refresh = false ) {
		static $cache;
		if ( null === $cache || $refresh ) {
			$cache = self::providers();
		}
		return $cache;
	}

	/** Forget the detected providers (a test that seeds plugin data mid-request). */
	public static function reset() {
		self::providers_cached( true );
	}

	/** Stored plugin values: serialized PHP decoded without instantiating classes, JSON decoded. */
	private static function decode( $value ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			return unserialize( $value, array( 'allowed_classes' => false, 'max_depth' => 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Plugin data; class instantiation is explicitly disabled.
		}
		if ( is_string( $value ) && preg_match( '/^[\[{]/', $value ) ) {
			$json = json_decode( $value, true );
			return null === $json ? $value : $json;
		}
		return $value;
	}

	private static function yoast_post( $post, $active, &$excluded ) {
		$stored = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 === strpos( $key, '_yoast_wpseo_' ) ) {
				$stored[ substr( $key, 13 ) ] = self::decode( $values[0] );
			}
		}
		ksort( $stored );
		$out = array( 'stored' => Policy::clean( $stored, $excluded, 'seo/post/' . $post->ID ) );
		if ( $active && function_exists( 'YoastSEO' ) ) {
			// The static front page and posts page render as the home and blog pages, not as a page.
			$static = 'page' === get_option( 'show_on_front' );
			if ( $static && (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
				$meta = YoastSEO()->meta->for_home_page();
			} elseif ( $static && (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
				$meta = YoastSEO()->meta->for_posts_page();
			} else {
				$meta = YoastSEO()->meta->for_post( $post->ID );
			}
			if ( $meta ) {
				$out = self::yoast_resolved( $meta ) + $out;
			}
			self::yoast_forget();
		}
		if ( isset( $stored['focuskw'] ) && '' !== $stored['focuskw'] ) {
			$out['focus_keyword'] = $stored['focuskw'];
		}
		return array_filter( $out, array( self::class, 'filled' ) );
	}

	/**
	 * The robots directives the page actually carries. Yoast's own value is one
	 * input: WordPress core's `wp_robots` filters add theirs (for instance
	 * `max-image-preview:large`), and Yoast then reconciles the two
	 * (`noimageindex` turns that into `max-image-preview:none`). The core
	 * pipeline is run with Yoast's front-end hook set aside, and Yoast's own
	 * reconciliation is applied to the merge, so the result is what `wp_robots()`
	 * prints rather than a re-implementation of it. Null when that cannot be done.
	 */
	private static function yoast_served_robots( $robots ) {
		$class = 'Yoast\\WP\\SEO\\Integrations\\Front_End\\WP_Robots_Integration';
		if ( ! class_exists( $class ) || ! function_exists( 'wp_robots' ) ) {
			return null;
		}
		try {
			$integration = YoastSEO()->classes->get( $class );
			$call = static function ( $method, $value ) use ( $integration ) {
				$reflection = new \ReflectionMethod( $integration, $method );
				$reflection->setAccessible( true );
				return $reflection->invoke( $integration, $value );
			};
			$priority = has_filter( 'wp_robots', array( $integration, 'add_robots' ) );
			if ( false !== $priority ) {
				remove_filter( 'wp_robots', array( $integration, 'add_robots' ), $priority );
			}
			$core = apply_filters( 'wp_robots', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, read to reproduce the rendered value.
			if ( false !== $priority ) {
				add_filter( 'wp_robots', array( $integration, 'add_robots' ), $priority );
			}
			$merged = array_filter( $call( 'sort_robots', $call( 'enforce_robots_congruence', array_merge( (array) $core, $call( 'format_robots', array_filter( $robots ) ) ) ) ) );
		} catch ( \Throwable $error ) {
			return null;
		}
		// wp_robots(): `true` prints the key, a string prints `key:value`.
		$out = array();
		foreach ( $merged as $key => $value ) {
			$out[] = true === $value ? $key : $key . ':' . $value;
		}
		return $out;
	}

	/**
	 * Yoast memoizes a page's context by indexable id for the whole request. Where
	 * indexables are not persisted (any non-production environment) every id is
	 * empty, so the first record's values would be returned for every later one.
	 */
	private static function yoast_forget() {
		$class = 'Yoast\\WP\\SEO\\Memoizers\\Meta_Tags_Context_Memoizer';
		if ( class_exists( $class ) ) {
			YoastSEO()->classes->get( $class )->clear();
		}
	}

	/** The values Yoast renders into the page head, in the normalized shape. */
	private static function yoast_resolved( $meta ) {
		$robots = (array) $meta->robots;
		$images = array_values( array_filter( array_map( static function ( $image ) { return $image['url'] ?? null; }, (array) $meta->open_graph_images ) ) );
		$schema = (array) $meta->schema;
		return array(
			'resolved'    => true,
			'title'       => self::text( $meta->title ),
			'description' => self::text( $meta->description ),
			'canonical'   => (string) $meta->canonical,
			'robots'      => array_filter(
				array(
					'index'    => $robots['index'] ?? null,
					'follow'   => $robots['follow'] ?? null,
					'advanced' => array_values( array_diff_key( $robots, array_flip( array( 'index', 'follow' ) ) ) ),
				),
				array( self::class, 'filled' )
			),
			'robots_served' => self::yoast_served_robots( $robots ),
			'open_graph'  => array_filter(
				array(
					'title'       => self::text( $meta->open_graph_title ),
					'description' => self::text( $meta->open_graph_description ),
					'type'        => (string) $meta->open_graph_type,
					'url'         => (string) $meta->open_graph_url,
					'image'       => $images[0] ?? '',
					'site_name'   => self::text( $meta->open_graph_site_name ),
				),
				array( self::class, 'filled' )
			),
			'twitter'     => array_filter(
				array(
					'card'        => (string) $meta->twitter_card,
					'title'       => self::text( $meta->twitter_title ),
					'description' => self::text( $meta->twitter_description ),
					'image'       => (string) $meta->twitter_image,
				),
				array( self::class, 'filled' )
			),
			'schema'      => array( 'types' => self::schema_types( $schema ), 'graph' => $schema ),
		);
	}

	private static function rank_math_post( $post, &$excluded ) {
		$stored = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 === strpos( $key, 'rank_math_' ) ) {
				$stored[ substr( $key, 10 ) ] = self::decode( $values[0] );
			}
		}
		if ( ! $stored ) {
			return null;
		}
		ksort( $stored );
		$robots = (array) ( $stored['robots'] ?? array() );
		$schema = array();
		foreach ( $stored as $key => $value ) {
			if ( 0 === strpos( $key, 'schema_' ) && is_array( $value ) ) {
				$schema[] = $value;
			}
		}
		// Rank Math renders from templates at page time; without it running, these are the stored values.
		return array_filter(
			array(
				'resolved'      => false,
				'title'         => (string) ( $stored['title'] ?? '' ),
				'description'   => (string) ( $stored['description'] ?? '' ),
				'canonical'     => (string) ( $stored['canonical_url'] ?? '' ),
				'robots'        => array_filter( array( 'index' => in_array( 'noindex', $robots, true ) ? 'noindex' : ( $robots ? 'index' : null ), 'follow' => in_array( 'nofollow', $robots, true ) ? 'nofollow' : ( $robots ? 'follow' : null ), 'advanced' => array_values( array_diff( $robots, array( 'index', 'noindex', 'follow', 'nofollow' ) ) ) ), array( self::class, 'filled' ) ),
				'open_graph'    => array_filter( array( 'title' => (string) ( $stored['facebook_title'] ?? '' ), 'description' => (string) ( $stored['facebook_description'] ?? '' ), 'image' => (string) ( $stored['facebook_image'] ?? '' ) ), array( self::class, 'filled' ) ),
				'twitter'       => array_filter( array( 'card' => (string) ( $stored['twitter_card_type'] ?? '' ), 'title' => (string) ( $stored['twitter_title'] ?? '' ), 'description' => (string) ( $stored['twitter_description'] ?? '' ), 'image' => (string) ( $stored['twitter_image'] ?? '' ) ), array( self::class, 'filled' ) ),
				'focus_keyword' => (string) ( $stored['focus_keyword'] ?? '' ),
				'schema'        => $schema ? array( 'types' => self::schema_types( $schema ), 'graph' => $schema ) : null,
				'stored'        => Policy::clean( $stored, $excluded, 'seo/post/' . $post->ID ),
			),
			array( self::class, 'filled' )
		);
	}

	private static function aioseo_post( $post, &$excluded ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table probe.
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $table, $post->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- AIOSEO has no read API when inactive.
		if ( ! $row ) {
			return null;
		}
		$row = array_map( array( self::class, 'decode' ), $row );
		unset( $row['id'], $row['post_id'], $row['created'], $row['updated'] );
		ksort( $row );
		$types = array();
		foreach ( (array) ( $row['schema']['graphs'] ?? array() ) as $graph ) {
			if ( ! empty( $graph['graphName'] ) ) {
				$types[] = (string) $graph['graphName'];
			}
		}
		if ( ! empty( $row['schema_type'] ) && 'default' !== $row['schema_type'] ) {
			$types[] = (string) $row['schema_type'];
		}
		$types = array_values( array_unique( $types ) );
		sort( $types, SORT_STRING );
		$noindex = ! empty( $row['robots_noindex'] ) && empty( $row['robots_default'] );
		$nofollow = ! empty( $row['robots_nofollow'] ) && empty( $row['robots_default'] );
		return array_filter(
			array(
				'resolved'      => false,
				'title'         => (string) ( $row['title'] ?? '' ),
				'description'   => (string) ( $row['description'] ?? '' ),
				'canonical'     => (string) ( $row['canonical_url'] ?? '' ),
				'robots'        => empty( $row['robots_default'] ) ? array( 'index' => $noindex ? 'noindex' : 'index', 'follow' => $nofollow ? 'nofollow' : 'follow' ) : null,
				'open_graph'    => array_filter( array( 'title' => (string) ( $row['og_title'] ?? '' ), 'description' => (string) ( $row['og_description'] ?? '' ), 'type' => (string) ( $row['og_object_type'] ?? '' ), 'image' => (string) ( $row['og_image_custom_url'] ?? '' ) ), array( self::class, 'filled' ) ),
				'twitter'       => array_filter( array( 'card' => (string) ( $row['twitter_card'] ?? '' ), 'title' => (string) ( $row['twitter_title'] ?? '' ), 'description' => (string) ( $row['twitter_description'] ?? '' ), 'image' => (string) ( $row['twitter_image_custom_url'] ?? '' ) ), array( self::class, 'filled' ) ),
				'focus_keyword' => (string) ( $row['keyphrases']['focus']['keyphrase'] ?? '' ),
				'schema'        => $types ? array( 'types' => $types ) : null,
				'stored'        => Policy::clean( $row, $excluded, 'seo/post/' . $post->ID ),
			),
			array( self::class, 'filled' )
		);
	}

	private static function settings_yoast( &$excluded ) {
		$titles = (array) get_option( 'wpseo_titles', array() );
		$social = (array) get_option( 'wpseo_social', array() );
		$general = array_intersect_key( (array) get_option( 'wpseo', array() ), array_flip( self::YOAST_GENERAL ) );
		$separator = function_exists( 'YoastSEO' ) ? YoastSEO()->helpers->options->get_title_separator() : ( self::YOAST_SEPARATORS[ $titles['separator'] ?? '' ] ?? ( $titles['separator'] ?? null ) );
		$templates = array();
		$descriptions = array();
		$noindex = array();
		foreach ( $titles as $key => $value ) {
			if ( preg_match( '/^title-(.+?)(?:-wpseo)?$/D', $key, $m ) && '' !== $value ) {
				$templates[ self::scope_key( $m[1] ) ] = $value;
			} elseif ( preg_match( '/^metadesc-(.+?)(?:-wpseo)?$/D', $key, $m ) && '' !== $value ) {
				$descriptions[ self::scope_key( $m[1] ) ] = $value;
			} elseif ( preg_match( '/^noindex-(.+?)(?:-wpseo)?$/D', $key, $m ) && 0 !== strpos( $m[1], 'author-noposts' ) ) {
				$noindex[ self::scope_key( $m[1] ) ] = (bool) $value;
			}
		}
		ksort( $templates );
		ksort( $descriptions );
		ksort( $noindex );
		return array(
			'separator'             => $separator,
			'title_templates'       => $templates ?: (object) array(),
			'description_templates' => $descriptions ?: (object) array(),
			'noindex'               => $noindex ?: (object) array(),
			'social'                => Policy::clean( array_filter( array_intersect_key( $social, array_flip( array( 'og_default_image', 'og_frontpage_title', 'og_frontpage_desc', 'og_frontpage_image', 'opengraph', 'twitter', 'twitter_site', 'twitter_card_type', 'facebook_site', 'instagram_url', 'linkedin_url', 'youtube_url', 'pinterest_url', 'wikipedia_url', 'mastodon_url', 'other_social_urls' ) ) ), array( self::class, 'filled' ) ), $excluded, 'seo/settings/yoast/social' ),
			'verification'          => array_filter( array_intersect_key( $general, array_flip( array( 'baiduverify', 'googleverify', 'msverify', 'yandexverify', 'ahrefsverify' ) ) ), array( self::class, 'filled' ) ) ?: (object) array(),
			'raw'                   => array(
				'wpseo_titles' => Policy::clean( $titles, $excluded, 'seo/settings/yoast/titles' ),
				'wpseo_social' => Policy::clean( $social, $excluded, 'seo/settings/yoast/social' ),
				// The general option also holds integration tokens (SEMrush, Wincher, MyYoast); only site-describing keys leave.
				'wpseo'        => Policy::clean( $general, $excluded, 'seo/settings/yoast/general' ),
			),
		);
	}

	private static function settings_rank_math( &$excluded ) {
		$titles = (array) get_option( 'rank-math-options-titles', array() );
		$general = (array) get_option( 'rank-math-options-general', array() );
		$templates = array();
		$descriptions = array();
		foreach ( $titles as $key => $value ) {
			if ( preg_match( '/^(pt|tax)_(.+)_title$/D', $key, $m ) && '' !== $value ) {
				$templates[ ( 'tax' === $m[1] ? 'tax:' : '' ) . $m[2] ] = $value;
			} elseif ( preg_match( '/^(pt|tax)_(.+)_description$/D', $key, $m ) && '' !== $value ) {
				$descriptions[ ( 'tax' === $m[1] ? 'tax:' : '' ) . $m[2] ] = $value;
			}
		}
		foreach ( array( 'homepage_title' => 'home', 'author_archive_title' => 'author', 'date_archive_title' => 'archive', 'search_title' => 'search', '404_title' => '404' ) as $key => $scope ) {
			if ( ! empty( $titles[ $key ] ) ) {
				$templates[ $scope ] = $titles[ $key ];
			}
		}
		ksort( $templates );
		ksort( $descriptions );
		return array(
			'separator'             => $titles['title_separator'] ?? null,
			'title_templates'       => $templates ?: (object) array(),
			'description_templates' => $descriptions ?: (object) array(),
			'social'                => array_filter( array_intersect_key( $titles, array_flip( array( 'open_graph_image', 'twitter_author_names', 'social_url_facebook', 'twitter_card_type' ) ) ), array( self::class, 'filled' ) ) ?: (object) array(),
			'raw'                   => array(
				'rank-math-options-titles'  => Policy::clean( $titles, $excluded, 'seo/settings/rank_math/titles' ),
				'rank-math-options-general' => Policy::clean( $general, $excluded, 'seo/settings/rank_math/general' ),
			),
		);
	}

	private static function settings_aioseo( &$excluded ) {
		$raw = get_option( 'aioseo_options', '' );
		$options = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$options = is_array( $options ) ? $options : array();
		$appearance = (array) ( $options['searchAppearance'] ?? array() );
		$templates = array();
		$descriptions = array();
		foreach ( (array) ( $options['searchAppearance']['global'] ?? array() ) as $key => $value ) {
			if ( 'siteTitle' === $key ) {
				$templates['home'] = $value;
			} elseif ( 'metaDescription' === $key ) {
				$descriptions['home'] = $value;
			}
		}
		foreach ( array( 'postTypes' => '', 'taxonomies' => 'tax:' ) as $group => $prefix ) {
			foreach ( (array) ( $appearance[ $group ] ?? array() ) as $name => $settings ) {
				if ( ! empty( $settings['title'] ) ) {
					$templates[ $prefix . $name ] = $settings['title'];
				}
				if ( ! empty( $settings['metaDescription'] ) ) {
					$descriptions[ $prefix . $name ] = $settings['metaDescription'];
				}
			}
		}
		ksort( $templates );
		ksort( $descriptions );
		return array(
			'separator'             => $appearance['global']['separator'] ?? null,
			'title_templates'       => $templates ?: (object) array(),
			'description_templates' => $descriptions ?: (object) array(),
			'social'                => Policy::clean( (array) ( $options['social'] ?? array() ), $excluded, 'seo/settings/aioseo/social' ) ?: (object) array(),
			'raw'                   => array( 'aioseo_options' => Policy::clean( array_intersect_key( $options, array_flip( array( 'searchAppearance', 'social', 'sitemap' ) ) ), $excluded, 'seo/settings/aioseo' ) ),
		);
	}

	/** `post`, `page`, `tax:category` — the same keys whichever plugin stored them. */
	private static function scope_key( $key ) {
		return 0 === strpos( $key, 'tax-' ) ? 'tax:' . substr( $key, 4 ) : $key;
	}

	/** Every `@type` in a JSON-LD value, including inside `@graph` and nested nodes. */
	public static function schema_types( $value ) {
		$types = array();
		$walk = static function ( $node ) use ( &$walk, &$types ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $type ) {
					$types[] = (string) $type;
				}
			}
			foreach ( $node as $child ) {
				$walk( $child );
			}
		};
		$walk( $value );
		$types = array_values( array_unique( $types ) );
		sort( $types, SORT_STRING );
		return $types;
	}

	/**
	 * Text as a crawler reads it. Yoast can hand back entities (`&hellip;` in a
	 * generated excerpt) that `esc_attr` prints unchanged, so the page says `…`.
	 */
	private static function text( $value ) {
		return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function filled( $value ) {
		return null !== $value && '' !== $value && array() !== $value;
	}
}
