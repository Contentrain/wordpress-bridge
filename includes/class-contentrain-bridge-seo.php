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
			'seopress'  => array( defined( 'SEOPRESS_VERSION' ) ? SEOPRESS_VERSION : null, false !== get_option( 'seopress_titles_option_name' ) || $meta( $wpdb->esc_like( '_seopress_' ) . '%' ) ),
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
			} elseif ( 'aioseo' === $name ) {
				$document['settings'][ $name ] = self::settings_aioseo( $excluded );
			} else {
				$document['settings'][ $name ] = self::settings_seopress( $excluded );
			}
		}
		// A home page that lists posts is no record, so its head lives here (a static front page is `post:<ID>`).
		if ( $present && 'page' !== get_option( 'show_on_front' ) ) {
			foreach ( $present as $name ) {
				$document['home'][ $name ] = self::home( $name );
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
		if ( 'absent' !== $providers['seopress']['status'] ) {
			$entry['seopress'] = self::seopress_post( $post, $excluded );
		}
		$entry = array_filter( $entry );
		return $entry ? $entry : null;
	}

	public static function term( $term ) {
		$providers = self::providers_cached();
		$entry = array();
		if ( 'absent' !== $providers['yoast']['status'] ) {
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
			if ( ! isset( $out['resolved'] ) ) {
				$out += self::yoast_term_rendered( $term, $stored );
			}
			$entry['yoast'] = array_filter( $out, array( self::class, 'filled' ) );
		}
		foreach ( array( 'rank_math', 'aioseo', 'seopress' ) as $name ) {
			if ( 'absent' !== $providers[ $name ]['status'] ) {
				$entry[ $name ] = self::term_rendered( $name, $term );
			}
		}
		$entry = array_filter( $entry );
		return $entry ? $entry : null;
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
		if ( ! isset( $out['resolved'] ) ) {
			$out += self::yoast_post_rendered( $post, $stored );
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
		ksort( $stored );
		$robots = (array) ( $stored['robots'] ?? array() );
		$schema = array();
		foreach ( $stored as $key => $value ) {
			if ( 0 === strpos( $key, 'schema_' ) && is_array( $value ) ) {
				$schema[] = $value;
			}
		}
		// Rank Math renders from templates at page time; the stored values say how, `rendered` says what.
		$titles = (array) get_option( 'rank-math-options-titles', array() );
		$type = $post->post_type;
		$rendered = SeoRender::block(
			'rank_math',
			array(
				'title'       => array( $stored['title'] ?? '', $titles[ 'pt_' . $type . '_title' ] ?? '', SeoRender::DEFAULTS['rank_math']['post'][0] ),
				'description' => array( $stored['description'] ?? '', $titles[ 'pt_' . $type . '_description' ] ?? '', SeoRender::DEFAULTS['rank_math']['post'][1] ),
				'robots'      => self::rank_math_robots( $robots, $titles, 'pt_' . $type ),
				'canonical'   => '' !== (string) ( $stored['canonical_url'] ?? '' ) ? (string) $stored['canonical_url'] : (string) get_permalink( $post ),
				'og'          => array( $stored['facebook_title'] ?? '', $stored['facebook_description'] ?? '', self::image_of( $stored, 'facebook_image', $post ) ),
				'twitter'     => array( $stored['twitter_title'] ?? '', $stored['twitter_description'] ?? '', self::image_of( $stored, 'twitter_image', $post ) ),
				'schema'      => $schema,
			),
			SeoRender::post_values( $post, self::separator( 'rank_math' ), (string) ( $stored['focus_keyword'] ?? '' ), (int) ( $stored['primary_category'] ?? 0 ) ),
			self::meta_reader( $post )
		);
		return $rendered + array_filter(
			array(
				'resolved'      => false,
				'title'         => (string) ( $stored['title'] ?? '' ),
				'description'   => (string) ( $stored['description'] ?? '' ),
				'canonical'     => (string) ( $stored['canonical_url'] ?? '' ),
				'robots'        => array_filter( array( 'index' => in_array( 'noindex', $robots, true ) ? 'noindex' : ( $robots ? 'index' : null ), 'follow' => in_array( 'nofollow', $robots, true ) ? 'nofollow' : ( $robots ? 'follow' : null ), 'advanced' => array_values( array_diff( $robots, array( 'index', 'noindex', 'follow', 'nofollow' ) ) ) ), array( self::class, 'filled' ) ),
				'open_graph'    => array_filter( array( 'title' => (string) ( $stored['facebook_title'] ?? '' ), 'description' => (string) ( $stored['facebook_description'] ?? '' ), 'image' => (string) ( $stored['facebook_image'] ?? '' ) ), array( self::class, 'filled' ) ),
				'twitter'       => array_filter( array( 'card' => (string) ( $stored['twitter_card_type'] ?? '' ), 'title' => (string) ( $stored['twitter_title'] ?? '' ), 'description' => (string) ( $stored['twitter_description'] ?? '' ), 'image' => (string) ( $stored['twitter_image'] ?? '' ) ), array( self::class, 'filled' ) ),
				'focus_keyword' => (string) ( $stored['focus_keyword'] ?? '' ),
				// Top-level `schema.graph` is only ever what a running plugin printed; the stored nodes carry
				// template tokens, so they are in `stored`, and rendered in `rendered.schema.graph`.
				'schema'        => $schema ? array( 'types' => self::schema_types( $schema ) ) : null,
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
		$row = $row ? $row : array();
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
		$appearance = (array) ( self::aioseo_options()['searchAppearance'] ?? array() );
		$type = (array) ( $appearance['postTypes'][ $post->post_type ] ?? array() );
		$rendered = SeoRender::block(
			'aioseo',
			array(
				'title'       => array( $row['title'] ?? '', $type['title'] ?? '', SeoRender::DEFAULTS['aioseo']['post'][0] ),
				'description' => array( $row['description'] ?? '', $type['metaDescription'] ?? '', SeoRender::DEFAULTS['aioseo']['post'][1] ),
				'robots'      => array_key_exists( 'robots_default', $row ) && empty( $row['robots_default'] ) ? array( ! $noindex, ! $nofollow, 'post' ) : self::aioseo_robots( (array) ( $type['advanced']['robotsMeta'] ?? array() ), (array) ( $appearance['advanced']['globalRobotsMeta'] ?? array() ) ),
				'canonical'   => '' !== (string) ( $row['canonical_url'] ?? '' ) ? (string) $row['canonical_url'] : (string) get_permalink( $post ),
				'og'          => array( $row['og_title'] ?? '', $row['og_description'] ?? '', '' !== (string) ( $row['og_image_custom_url'] ?? '' ) ? $row['og_image_custom_url'] : (int) get_post_thumbnail_id( $post ) ),
				'twitter'     => array( $row['twitter_title'] ?? '', $row['twitter_description'] ?? '', '' !== (string) ( $row['twitter_image_custom_url'] ?? '' ) ? $row['twitter_image_custom_url'] : (int) get_post_thumbnail_id( $post ) ),
			),
			SeoRender::post_values( $post, self::separator( 'aioseo' ), (string) ( $row['keyphrases']['focus']['keyphrase'] ?? '' ) ),
			self::meta_reader( $post )
		);
		return $rendered + array_filter(
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

	/** SEOPress keeps its per-post values in `_seopress_*` meta; `yes` in a robots key means "no". */
	private static function seopress_post( $post, &$excluded ) {
		$stored = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 === strpos( $key, '_seopress_' ) ) {
				$stored[ substr( $key, 10 ) ] = self::decode( $values[0] );
			}
		}
		ksort( $stored );
		$options = self::seopress_options();
		$type = (array) ( $options['seopress_titles_single_titles'][ $post->post_type ] ?? array() );
		$rendered = SeoRender::block(
			'seopress',
			array(
				'title'       => array( $stored['titles_title'] ?? '', $type['title'] ?? '', SeoRender::DEFAULTS['seopress']['post'][0] ),
				'description' => array( $stored['titles_desc'] ?? '', $type['description'] ?? '', SeoRender::DEFAULTS['seopress']['post'][1] ),
				'robots'      => self::seopress_robots( $stored, $type, $options ),
				'canonical'   => '' !== (string) ( $stored['robots_canonical'] ?? '' ) ? (string) $stored['robots_canonical'] : (string) get_permalink( $post ),
				'og'          => array( $stored['social_fb_title'] ?? '', $stored['social_fb_desc'] ?? '', '' !== (string) ( $stored['social_fb_img'] ?? '' ) ? $stored['social_fb_img'] : (int) get_post_thumbnail_id( $post ) ),
				'twitter'     => array( $stored['social_twitter_title'] ?? '', $stored['social_twitter_desc'] ?? '', '' !== (string) ( $stored['social_twitter_img'] ?? '' ) ? $stored['social_twitter_img'] : (int) get_post_thumbnail_id( $post ) ),
			),
			SeoRender::post_values( $post, self::separator( 'seopress' ), (string) ( $stored['analysis_target_kw'] ?? '' ) ),
			self::meta_reader( $post )
		);
		return $rendered + array_filter(
			array(
				'resolved'      => false,
				'title'         => (string) ( $stored['titles_title'] ?? '' ),
				'description'   => (string) ( $stored['titles_desc'] ?? '' ),
				'canonical'     => (string) ( $stored['robots_canonical'] ?? '' ),
				'robots'        => array_filter( array( 'index' => 'yes' === ( $stored['robots_index'] ?? '' ) ? 'noindex' : null, 'follow' => 'yes' === ( $stored['robots_follow'] ?? '' ) ? 'nofollow' : null ), array( self::class, 'filled' ) ),
				'open_graph'    => array_filter( array( 'title' => (string) ( $stored['social_fb_title'] ?? '' ), 'description' => (string) ( $stored['social_fb_desc'] ?? '' ), 'image' => (string) ( $stored['social_fb_img'] ?? '' ) ), array( self::class, 'filled' ) ),
				'twitter'       => array_filter( array( 'title' => (string) ( $stored['social_twitter_title'] ?? '' ), 'description' => (string) ( $stored['social_twitter_desc'] ?? '' ), 'image' => (string) ( $stored['social_twitter_img'] ?? '' ) ), array( self::class, 'filled' ) ),
				'focus_keyword' => (string) ( $stored['analysis_target_kw'] ?? '' ),
				'stored'        => Policy::clean( $stored, $excluded, 'seo/post/' . $post->ID ),
			),
			array( self::class, 'filled' )
		);
	}

	/** Yoast's values rendered by Bridge, for when Yoast is not running to resolve them. */
	private static function yoast_post_rendered( $post, $stored ) {
		$titles = (array) get_option( 'wpseo_titles', array() );
		$type = $post->post_type;
		$noindex = (string) ( $stored['meta-robots-noindex'] ?? '' );
		if ( '1' === $noindex || '2' === $noindex ) {
			$robots = array( '2' === $noindex, '1' !== (string) ( $stored['meta-robots-nofollow'] ?? '' ), 'post' );
		} elseif ( isset( $titles[ 'noindex-' . $type ] ) ) {
			$robots = array( ! $titles[ 'noindex-' . $type ], '1' !== (string) ( $stored['meta-robots-nofollow'] ?? '' ), 'post_type' );
		} else {
			$robots = array( true, '1' !== (string) ( $stored['meta-robots-nofollow'] ?? '' ), 'default' );
		}
		return SeoRender::block(
			'yoast',
			array(
				'title'       => array( $stored['title'] ?? '', $titles[ 'title-' . $type ] ?? '', SeoRender::DEFAULTS['yoast']['post'][0] ),
				'description' => array( $stored['metadesc'] ?? '', $titles[ 'metadesc-' . $type ] ?? '', SeoRender::DEFAULTS['yoast']['post'][1] ),
				'robots'      => self::site_robots( $robots ),
				'canonical'   => '' !== (string) ( $stored['canonical'] ?? '' ) ? (string) $stored['canonical'] : (string) get_permalink( $post ),
				'og'          => array( $stored['opengraph-title'] ?? '', $stored['opengraph-description'] ?? '', '' !== (string) ( $stored['opengraph-image'] ?? '' ) ? $stored['opengraph-image'] : (int) get_post_thumbnail_id( $post ) ),
				'twitter'     => array( $stored['twitter-title'] ?? '', $stored['twitter-description'] ?? '', '' !== (string) ( $stored['twitter-image'] ?? '' ) ? $stored['twitter-image'] : (int) get_post_thumbnail_id( $post ) ),
			),
			SeoRender::post_values( $post, self::separator( 'yoast' ), (string) ( $stored['focuskw'] ?? '' ), (int) ( $stored['primary_category'] ?? 0 ) ),
			self::meta_reader( $post )
		);
	}

	private static function yoast_term_rendered( $term, $stored ) {
		$titles = (array) get_option( 'wpseo_titles', array() );
		$key = 'tax-' . $term->taxonomy;
		$noindex = (string) ( $stored['wpseo_noindex'] ?? 'default' );
		if ( 'noindex' === $noindex || 'index' === $noindex ) {
			$robots = array( 'index' === $noindex, true, 'post' );
		} elseif ( isset( $titles[ 'noindex-' . $key ] ) ) {
			$robots = array( ! $titles[ 'noindex-' . $key ], true, 'post_type' );
		} else {
			$robots = array( true, true, 'default' );
		}
		return SeoRender::block(
			'yoast',
			array(
				'title'       => array( $stored['wpseo_title'] ?? '', $titles[ 'title-' . $key ] ?? '', SeoRender::DEFAULTS['yoast']['term'][0] ),
				'description' => array( $stored['wpseo_desc'] ?? '', $titles[ 'metadesc-' . $key ] ?? '', SeoRender::DEFAULTS['yoast']['term'][1] ),
				'robots'      => self::site_robots( $robots ),
				'canonical'   => '' !== (string) ( $stored['wpseo_canonical'] ?? '' ) ? (string) $stored['wpseo_canonical'] : (string) get_term_link( $term ),
			),
			SeoRender::term_values( $term, self::separator( 'yoast' ) )
		);
	}

	/** Rank Math and SEOPress keep term values in term meta; AIOSEO (free) only has the taxonomy template. */
	private static function term_rendered( $provider, $term ) {
		$meta = static function ( $key ) use ( $term ) { return (string) get_term_meta( $term->term_id, $key, true ); };
		if ( 'rank_math' === $provider ) {
			$titles = (array) get_option( 'rank-math-options-titles', array() );
			$own = get_term_meta( $term->term_id, 'rank_math_robots', true );
			$spec = array(
				'title'       => array( $meta( 'rank_math_title' ), $titles[ 'tax_' . $term->taxonomy . '_title' ] ?? '', SeoRender::DEFAULTS['rank_math']['term'][0] ),
				'description' => array( $meta( 'rank_math_description' ), $titles[ 'tax_' . $term->taxonomy . '_description' ] ?? '', SeoRender::DEFAULTS['rank_math']['term'][1] ),
				'robots'      => self::rank_math_robots( is_array( $own ) ? $own : array(), $titles, 'tax_' . $term->taxonomy ),
				'canonical'   => '' !== $meta( 'rank_math_canonical_url' ) ? $meta( 'rank_math_canonical_url' ) : (string) get_term_link( $term ),
			);
		} elseif ( 'seopress' === $provider ) {
			$options = self::seopress_options();
			$tax = (array) ( $options['seopress_titles_tax_titles'][ $term->taxonomy ] ?? array() );
			$spec = array(
				'title'       => array( $meta( '_seopress_titles_title' ), $tax['title'] ?? '', SeoRender::DEFAULTS['seopress']['term'][0] ),
				'description' => array( $meta( '_seopress_titles_desc' ), $tax['description'] ?? '', SeoRender::DEFAULTS['seopress']['term'][1] ),
				'robots'      => self::seopress_robots( array( 'robots_index' => $meta( '_seopress_robots_index' ), 'robots_follow' => $meta( '_seopress_robots_follow' ) ), $tax, $options ),
				'canonical'   => '' !== $meta( '_seopress_robots_canonical' ) ? $meta( '_seopress_robots_canonical' ) : (string) get_term_link( $term ),
			);
		} else {
			$appearance = (array) ( self::aioseo_options()['searchAppearance'] ?? array() );
			$tax = (array) ( $appearance['taxonomies'][ $term->taxonomy ] ?? array() );
			$spec = array(
				'title'       => array( '', $tax['title'] ?? '', SeoRender::DEFAULTS['aioseo']['term'][0] ),
				'description' => array( '', $tax['metaDescription'] ?? '', SeoRender::DEFAULTS['aioseo']['term'][1] ),
				'robots'      => self::aioseo_robots( (array) ( $tax['advanced']['robotsMeta'] ?? array() ), (array) ( $appearance['advanced']['globalRobotsMeta'] ?? array() ) ),
				'canonical'   => (string) get_term_link( $term ),
			);
		}
		return SeoRender::block( $provider, $spec, SeoRender::term_values( $term, self::separator( $provider ) ) );
	}

	/** The home page when it lists posts: each plugin's home title and description. */
	private static function home( $provider ) {
		if ( 'yoast' === $provider ) {
			$titles = (array) get_option( 'wpseo_titles', array() );
			$own = array( $titles['title-home-wpseo'] ?? '', $titles['metadesc-home-wpseo'] ?? '' );
		} elseif ( 'rank_math' === $provider ) {
			$titles = (array) get_option( 'rank-math-options-titles', array() );
			$own = array( $titles['homepage_title'] ?? '', $titles['homepage_description'] ?? '' );
		} elseif ( 'aioseo' === $provider ) {
			$global = (array) ( self::aioseo_options()['searchAppearance']['global'] ?? array() );
			$own = array( $global['siteTitle'] ?? '', $global['metaDescription'] ?? '' );
		} else {
			$options = self::seopress_options();
			$own = array( $options['seopress_titles_home_site_title'] ?? '', $options['seopress_titles_home_site_desc'] ?? '' );
		}
		return SeoRender::block(
			$provider,
			array(
				'title'       => array( $own[0], '', SeoRender::DEFAULTS[ $provider ]['home'][0] ),
				'description' => array( $own[1], '', SeoRender::DEFAULTS[ $provider ]['home'][1] ),
				'robots'      => self::site_robots( array( true, true, 'default' ) ),
				'canonical'   => home_url( '/' ),
			),
			SeoRender::site_values( self::separator( $provider ) )
		);
	}

	/** A site that asks search engines not to index it (Settings → Reading) is noindex everywhere. */
	private static function site_robots( $robots ) {
		return '0' === (string) get_option( 'blog_public', '1' ) ? array( false, $robots[1], 'default' ) : $robots;
	}

	/** Rank Math: the record's own list, else the type's custom robots, else the global list. */
	private static function rank_math_robots( $own, $titles, $prefix ) {
		if ( $own ) {
			list( $list, $source ) = array( $own, 'post' );
		} elseif ( 'on' === ( $titles[ $prefix . '_custom_robots' ] ?? '' ) ) {
			list( $list, $source ) = array( (array) ( $titles[ $prefix . '_robots' ] ?? array() ), 'post_type' );
		} else {
			list( $list, $source ) = array( (array) ( $titles['robots_global'] ?? array() ), 'default' );
		}
		return self::site_robots( array( ! in_array( 'noindex', $list, true ), ! in_array( 'nofollow', $list, true ), $source ) );
	}

	/** AIOSEO: the type's robots unless it defers to the global setting. */
	private static function aioseo_robots( $type, $global ) {
		if ( $type && empty( $type['default'] ) ) {
			return self::site_robots( array( empty( $type['noindex'] ), empty( $type['nofollow'] ), 'post_type' ) );
		}
		return self::site_robots( array( empty( $global['noindex'] ) || ! empty( $global['default'] ), empty( $global['nofollow'] ) || ! empty( $global['default'] ), 'default' ) );
	}

	/** SEOPress: `yes` on the record, else the type's switch, else the global one. */
	private static function seopress_robots( $stored, $type, $options ) {
		$own_index = 'yes' === ( $stored['robots_index'] ?? '' );
		$own_follow = 'yes' === ( $stored['robots_follow'] ?? '' );
		if ( $own_index || $own_follow ) {
			return self::site_robots( array( ! $own_index, ! $own_follow, 'post' ) );
		}
		if ( ! empty( $type['noindex'] ) || ! empty( $type['nofollow'] ) ) {
			return self::site_robots( array( empty( $type['noindex'] ), empty( $type['nofollow'] ), 'post_type' ) );
		}
		return self::site_robots( array( empty( $options['seopress_titles_noindex'] ), empty( $options['seopress_titles_nofollow'] ), 'default' ) );
	}

	/** Each plugin's title separator as text. */
	private static function separator( $provider ) {
		if ( 'yoast' === $provider ) {
			$titles = (array) get_option( 'wpseo_titles', array() );
			$sep = self::YOAST_SEPARATORS[ $titles['separator'] ?? '' ] ?? ( $titles['separator'] ?? '-' );
		} elseif ( 'rank_math' === $provider ) {
			$sep = ( (array) get_option( 'rank-math-options-titles', array() ) )['title_separator'] ?? '-';
		} elseif ( 'aioseo' === $provider ) {
			$sep = self::aioseo_options()['searchAppearance']['global']['separator'] ?? '-';
		} else {
			$sep = self::seopress_options()['seopress_titles_sep'] ?? '-';
		}
		return html_entity_decode( (string) $sep, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** A plugin's stored image field: its id key when it has one, else the URL, else the featured image. */
	private static function image_of( $stored, $key, $post ) {
		if ( ! empty( $stored[ $key . '_id' ] ) ) {
			return (int) $stored[ $key . '_id' ];
		}
		return '' !== (string) ( $stored[ $key ] ?? '' ) ? (string) $stored[ $key ] : (int) get_post_thumbnail_id( $post );
	}

	/** Custom-field variables read the post's meta; a secret-looking key is never rendered into a title. */
	private static function meta_reader( $post ) {
		return static function ( $key ) use ( $post ) {
			if ( Policy::sensitive( $key ) || ! metadata_exists( 'post', $post->ID, $key ) ) {
				return null;
			}
			$value = get_post_meta( $post->ID, $key, true );
			return is_scalar( $value ) ? (string) $value : null;
		};
	}

	private static function aioseo_options() {
		$raw = get_option( 'aioseo_options', '' );
		$options = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $options ) ? $options : array();
	}

	private static function seopress_options() {
		return (array) get_option( 'seopress_titles_option_name', array() );
	}

	private static function settings_seopress( &$excluded ) {
		$options = self::seopress_options();
		$social = (array) get_option( 'seopress_social_option_name', array() );
		$templates = array();
		$descriptions = array();
		foreach ( array( 'seopress_titles_single_titles' => '', 'seopress_titles_tax_titles' => 'tax:' ) as $group => $prefix ) {
			foreach ( (array) ( $options[ $group ] ?? array() ) as $name => $settings ) {
				if ( ! empty( $settings['title'] ) ) {
					$templates[ $prefix . $name ] = $settings['title'];
				}
				if ( ! empty( $settings['description'] ) ) {
					$descriptions[ $prefix . $name ] = $settings['description'];
				}
			}
		}
		if ( ! empty( $options['seopress_titles_home_site_title'] ) ) {
			$templates['home'] = $options['seopress_titles_home_site_title'];
		}
		if ( ! empty( $options['seopress_titles_home_site_desc'] ) ) {
			$descriptions['home'] = $options['seopress_titles_home_site_desc'];
		}
		ksort( $templates );
		ksort( $descriptions );
		return array(
			'separator'             => $options['seopress_titles_sep'] ?? null,
			'title_templates'       => $templates ?: (object) array(),
			'description_templates' => $descriptions ?: (object) array(),
			'social'                => Policy::clean( $social, $excluded, 'seo/settings/seopress/social' ) ?: (object) array(),
			'raw'                   => array( 'seopress_titles_option_name' => Policy::clean( $options, $excluded, 'seo/settings/seopress/titles' ) ),
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
