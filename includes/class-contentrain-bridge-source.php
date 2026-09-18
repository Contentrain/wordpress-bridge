<?php
/** WordPress source adapters. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class Source {
	public static function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$types = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( ! $type->public && ! $type->show_ui && ! in_array( $type->name, array( 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ), true ) ) {
				continue;
			}
			$types[ $type->name ] = array( 'label' => $type->label, 'rest' => (bool) $type->show_in_rest, 'public' => (bool) $type->public, 'counts' => (array) wp_count_posts( $type->name ) );
		}
		$plugins = array();
		foreach ( get_plugins() as $path => $plugin ) {
			if ( is_plugin_active( $path ) || is_plugin_active_for_network( $path ) ) {
				$plugins[ $path ] = array( 'name' => $plugin['Name'], 'version' => $plugin['Version'] );
			}
		}
		$theme = wp_get_theme();
		global $wpdb;
		// Media: how many files and how much space, from the attachment metadata WordPress keeps.
		$media = array( 'count' => (int) wp_count_posts( 'attachment' )->inherit, 'bytes' => 0 );
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'" ) as $value ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One pass over attachment metadata.
			$data = is_serialized( $value ) ? unserialize( $value, array( 'allowed_classes' => false, 'max_depth' => 8 ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Class instantiation is explicitly disabled.
			$media['bytes'] += is_array( $data ) ? (int) ( $data['filesize'] ?? 0 ) : 0;
		}
		// ACF field groups: which exist, whether they are active, and where they show.
		$groups = array();
		if ( function_exists( 'acf_get_field_groups' ) ) {
			foreach ( acf_get_field_groups() as $group ) {
				$groups[] = array( 'key' => $group['key'], 'title' => $group['title'], 'active' => (bool) $group['active'], 'show_in_rest' => ! empty( $group['show_in_rest'] ), 'location' => $group['location'], 'local' => ! empty( $group['local'] ) );
			}
		}
		// Plugins by what they do, for the things a migration has to answer for.
		$slugs = array_map( static function ( $path ) { return dirname( $path ); }, array_keys( $plugins ) );
		$detect = static function ( $known ) use ( $slugs ) { return array_values( array_intersect( $known, $slugs ) ); };
		return array(
			'format' => 'contentrain-bridge-inventory@1',
			'site' => home_url( '/' ),
			'locale' => get_locale(),
			'post_types' => $types,
			'plugins' => $plugins,
			'theme' => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ),
			'acf' => function_exists( 'get_field_objects' ),
			'languages' => function_exists( 'pll_languages_list' ) ? pll_languages_list() : array( get_locale() ),
			'menu_locations' => get_nav_menu_locations(),
			'media' => $media,
			'comments' => array_map( 'intval', (array) wp_count_comments() ),
			'acf_groups' => $groups,
			'capabilities' => array(
				'forms' => $detect( array( 'contact-form-7', 'wpforms-lite', 'wpforms', 'gravityforms', 'ninja-forms', 'formidable', 'fluentform', 'forminator' ) ),
				'seo' => $detect( array( 'wordpress-seo', 'wordpress-seo-premium', 'seo-by-rank-math', 'all-in-one-seo-pack', 'autodescription', 'wp-seopress' ) ),
				'languages' => $detect( array( 'polylang', 'polylang-pro', 'sitepress-multilingual-cms', 'translatepress-multilingual', 'weglot' ) ),
				'redirects' => $detect( array( 'redirection', 'safe-redirect-manager', 'simple-301-redirects' ) ),
			),
			'multisite' => is_multisite(),
		);
	}

	public static function locale( $code ) {
		$locale = strtolower( str_replace( '_', '-', $code ?: get_locale() ) );
		if ( ! preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/D', $locale ) ) {
			throw new \RuntimeException( 'Unsupported locale identifier: ' . $locale ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
		}
		return $locale;
	}

	/**
	 * The project's own default locale. `get_locale()` is WordPress's install
	 * locale, a site-level setting a multilingual plugin's own language list
	 * need not even contain (a WP site installed as `en_US` can run Polylang
	 * with only `da`/`sv` configured); once a multilingual plugin manages its
	 * own default language, that is the one every other locale is judged
	 * against, or `default_locale`/`canonical` would use a locale string no
	 * post ever actually carries.
	 */
	public static function default_locale() {
		if ( function_exists( 'pll_default_language' ) && pll_default_language() ) {
			return self::locale( pll_default_language() );
		}
		if ( has_filter( 'wpml_default_language' ) ) {
			$default = apply_filters( 'wpml_default_language', null );
			if ( $default ) {
				return self::locale( $default );
			}
		}
		return self::locale( get_locale() );
	}

	public static function language( $post ) {
		$code = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : null;
		if ( ! $code && has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post->ID );
			$code = is_array( $details ) ? ( $details['language_code'] ?? null ) : null;
		}
		// An untagged post (Polylang does not manage every post type, e.g.
		// attachments by default) falls back to the project's own default
		// locale, not WordPress's install locale — the two diverge as soon as a
		// multilingual plugin's default language is not the site's install
		// locale, which would otherwise scatter untagged content into a locale
		// nothing else in the export uses.
		return self::locale( $code ?: self::default_locale() );
	}

	public static function translations( $post ) {
		$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post->ID ) : array();
		if ( ! $translations && has_filter( 'wpml_element_trid' ) ) {
			$type = 'post_' . $post->post_type;
			$trid = apply_filters( 'wpml_element_trid', null, $post->ID, $type );
			foreach ( (array) apply_filters( 'wpml_get_element_translations', array(), $trid, $type ) as $code => $item ) {
				$translations[ $code ] = (int) $item->element_id;
			}
		}
		if ( ! $translations ) {
			$translations[ self::language( $post ) ] = $post->ID;
		}
		return array_map( 'intval', $translations );
	}

	public static function address( $post ) {
		$canonical = self::canonical( self::translations( $post ) );
		return array( 'model_id' => 'wp-' . sanitize_title( str_replace( '_', '-', $post->post_type ) ), 'entry_id' => 'post' === $post->post_type ? 'entry-' . $canonical : substr( hash( 'sha256', 'wp:' . $canonical ), 0, 12 ), 'locale' => self::language( $post ) );
	}

	/**
	 * Which translation is the group's canonical member: the default-locale
	 * post, else the lowest id. Matches `@contentrain/wp-import`'s documented
	 * rule so a WXR/REST import and a Bridge export of the same site agree on
	 * one entry id per translation group instead of picking differently.
	 */
	public static function canonical( $translations ) {
		return $translations[ self::default_locale() ] ?? min( array_values( $translations ) );
	}

	/**
	 * A post reference is only as good as this export's own scope: a target
	 * outside the selected types, or excluded by status/private, would point
	 * at an entry that never gets written. Shared by ACF post_object/
	 * relationship fields and by menu items linking to a post.
	 */
	public static function reference( $job, $post_id ) {
		$target = get_post( (int) $post_id );
		if ( ! $target || ! in_array( $target->post_type, $job['options']['types'], true ) || in_array( $target->post_status, array( 'auto-draft', 'trash' ), true ) ) {
			return null;
		}
		if ( ! $job['options']['private'] && ( $target->post_password || ! in_array( $target->post_status, array( 'publish', 'inherit' ), true ) ) ) {
			return null;
		}
		$address = self::address( $target );
		return array( $address['model_id'], $address['entry_id'] );
	}

	/** Capture source without executing shortcodes or arbitrary theme code. */
	public static function post( $post, $selected, &$excluded ) {
		$raw = Exporter::map_post( $post, $selected, $excluded );
		$raw['lang'] = self::language( $post );
		if ( $post->post_password ) {
			$excluded[] = array( 'source' => 'post/' . $post->ID . '/password', 'reason' => 'password-not-exported' );
		}
		list( $acf, $schema ) = self::acf_fields( $post->ID, 'acf/' . $post->ID, $excluded );
		// A post with no ACF fields carries no `acf` key at all, not an empty
		// one: an inventory fingerprint must not change for every non-ACF
		// record just because this plugin now also knows how to read ACF.
		if ( $acf ) {
			$raw['acf'] = $acf;
		}
		return array( 'raw' => $raw, 'acf_schema' => Policy::clean( $schema, $excluded, 'acf-schema/' . $post->ID ), 'address' => self::address( $post ), 'translations' => self::translations( $post ) );
	}

	/** ACF/SCF field objects for a real post: every field group whose location rule matches it, exactly what `get_field_objects()` already scopes correctly for a single, ordinary post. */
	public static function acf_fields( $post_id, $source_prefix, &$excluded ) {
		$raw = array();
		$schema = array();
		if ( function_exists( 'get_field_objects' ) ) {
			$fields = get_field_objects( $post_id, false, true ) ?: array();
			foreach ( $fields as $name => $field ) {
				if ( Policy::sensitive( $name ) || in_array( $field['type'], Acf::EXCLUDED, true ) ) {
					$excluded[] = array( 'source' => $source_prefix . '/' . $name, 'reason' => 'sensitive-field' );
					continue;
				}
				$raw[ $name ] = array( 'value' => self::acf_value( $field, $field['value'], $excluded, $source_prefix . '/' . $name ), 'field_key' => $field['key'] );
				$schema[ $name ] = self::schema( $field );
			}
		}
		return array( $raw, $schema );
	}

	/**
	 * ACF/SCF field objects for one specific Options Page — never everything
	 * stored at its `post_id`. Every options page defaults to the same
	 * `post_id` ('options') unless a site explicitly gives it its own, so
	 * `get_field_objects( $post_id )` there would return every options page's
	 * fields, not just this one's — scoped instead by the field groups whose
	 * own location rule names this page.
	 */
	public static function acf_fields_for_options_page( $post_id, $slug, $source_prefix, &$excluded ) {
		$raw = array();
		$schema = array();
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return array( $raw, $schema );
		}
		// "ACF Options for Polylang" redirects a normal `get_field_object()`
		// read to whatever language Polylang's `curlang` happens to be set to
		// at the moment this runs — in wp-admin that is the admin-bar
		// language filter, not this site's default language (verified: the
		// plugin never configures ACF's own `default_language` setting, so
		// its own "is this the default" check never holds). This plugin's
		// `i18n: false` Options Page model only has room for one value, so it
		// must always be the untranslated one, regardless of who happens to
		// be running the export and what they last clicked in the admin bar.
		$untranslated = function_exists( 'bea_aofp_switch_to_untranslated' );
		if ( $untranslated ) {
			bea_aofp_switch_to_untranslated();
		}
		try {
			foreach ( acf_get_field_groups( array( 'options_page' => $slug ) ) as $group ) {
				foreach ( acf_get_fields( $group ) as $field ) {
					$loaded = get_field_object( $field['key'], $post_id, false );
					if ( ! $loaded ) {
						continue;
					}
					$name = $loaded['name'];
					if ( Policy::sensitive( $name ) || in_array( $loaded['type'], Acf::EXCLUDED, true ) ) {
						$excluded[] = array( 'source' => $source_prefix . '/' . $name, 'reason' => 'sensitive-field' );
						continue;
					}
					$raw[ $name ] = array( 'value' => self::acf_value( $loaded, $loaded['value'], $excluded, $source_prefix . '/' . $name ), 'field_key' => $loaded['key'] );
					$schema[ $name ] = self::schema( $loaded );
				}
			}
		} finally {
			if ( $untranslated ) {
				bea_aofp_restore_current_lang();
			}
		}
		return array( $raw, $schema );
	}

	/** Every registered ACF/SCF Options Page, however many sub-pages the site groups fields under. */
	public static function options_pages() {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return array();
		}
		// `acf_get_options_pages()` returns `false`, not an empty array, when
		// nothing is registered — `(array) false` would silently produce one
		// bogus page instead of none.
		return array_values( (array) ( acf_get_options_pages() ?: array() ) );
	}

	/**
	 * A term already identified by slug+taxonomy or by term_taxonomy_id, read
	 * regardless of Polylang's current admin-bar language filter (`curlang`).
	 * `get_term_by()` routes both fields through `get_terms()`, which Polylang
	 * filters to the current language whenever the caller does not set `lang`
	 * — a term in any other language then reads back as "not found", even
	 * though the reference itself (a post's own term relationship, a raw
	 * term_taxonomy row) has nothing to do with which language an admin
	 * happens to be browsing in. `lang => ''` is Polylang's own documented way
	 * to disable that filter for a single query.
	 */
	public static function term_by( $field, $value, $taxonomy = '' ) {
		$args = array( 'lang' => '', 'get' => 'all', 'hide_empty' => false, 'number' => 1, 'update_term_meta_cache' => false );
		if ( 'term_taxonomy_id' === $field ) {
			$args['term_taxonomy_id'] = $value;
		} elseif ( 'slug' === $field ) {
			$value = (string) $value;
			if ( '' === $value || ! taxonomy_exists( $taxonomy ) ) {
				return false;
			}
			$args['taxonomy'] = $taxonomy;
			$args['slug'] = $value;
		} else {
			return false;
		}
		$terms = get_terms( $args );
		return is_wp_error( $terms ) || empty( $terms ) ? false : reset( $terms );
	}

	/** ACF groups can use opaque field keys: inspect types before values reach RawIR. */
	public static function acf_value( $field, $value, &$excluded, $path ) {
		if ( in_array( $field['type'] ?? '', Acf::EXCLUDED, true ) || Policy::sensitive( $field['name'] ?? '' ) ) {
			$excluded[] = array( 'source' => $path, 'reason' => 'sensitive-field' );
			return null;
		}
		if ( is_array( $value ) ) {
			$rows = in_array( $field['type'] ?? '', array( 'repeater', 'flexible_content' ), true ) ? $value : array( $value );
			$sub_fields = $field['sub_fields'] ?? array();
			foreach ( $field['layouts'] ?? array() as $layout ) {
				$sub_fields = array_merge( $sub_fields, $layout['sub_fields'] ?? array() );
			}
			foreach ( $rows as &$row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( $sub_fields as $sub ) {
					foreach ( array_unique( array( $sub['name'] ?? '', $sub['key'] ?? '' ) ) as $key ) {
						if ( array_key_exists( $key, $row ) ) {
							$clean = self::acf_value( $sub, $row[ $key ], $excluded, $path . '/' . $key );
							if ( null === $clean ) {
								unset( $row[ $key ] );
							} else {
								$row[ $key ] = $clean;
							}
						}
					}
				}
			}
			unset( $row );
			$value = in_array( $field['type'] ?? '', array( 'repeater', 'flexible_content' ), true ) ? $rows : $rows[0];
		}
		return Policy::clean( $value, $excluded, $path );
	}

	/** The parts of an ACF field definition that describe content, at every depth. */
	private static function schema( $field ) {
		$out = array_intersect_key( (array) $field, array_flip( array( 'key', 'name', 'label', 'type', 'required', 'choices', 'multiple', 'return_format', 'sub_fields', 'layouts', 'taxonomy', 'field_type', 'display' ) ) );
		foreach ( array( 'sub_fields', 'layouts' ) as $nested ) {
			if ( ! empty( $out[ $nested ] ) && is_array( $out[ $nested ] ) ) {
				$out[ $nested ] = array_values( array_map( array( self::class, 'schema' ), $out[ $nested ] ) );
			}
		}
		return $out;
	}

	public static function revision() {
		global $wpdb;
		// Bypass the request's option cache when checking concurrent source changes.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'contentrain_bridge_revision' ) );
	}

	/**
	 * A new revision, labelled with the hook that caused it, so a refused export
	 * can say what changed.
	 *
	 * Caches and bookkeeping are not content changes. Reading a post can write
	 * both: an excerpt filter (Yoast builds one) runs autoembed, which stores an
	 * `oembed_cache` post or an `_oembed_*` meta row. Counting those made an
	 * export refuse its own snapshot on any site with an embed.
	 */
	public static function changed( ...$args ) {
		$hook = (string) current_filter();
		if ( in_array( $hook, array( 'save_post', 'deleted_post' ), true ) && isset( $args[0] ) ) {
			$type = get_post_type( (int) $args[0] ) ?: ( isset( $args[1] ) && is_object( $args[1] ) ? $args[1]->post_type : '' );
			// The same list the coverage report calls "not content": caches, drafts of settings, design.
			if ( isset( Coverage::NOT_CONTENT[ $type ] ) ) {
				return;
			}
			$hook .= ':' . $type;
		}
		if ( preg_match( '/_post_meta$/', $hook ) && isset( $args[2] ) && preg_match( '/^(_oembed_|_edit_lock$|_edit_last$)/', (string) $args[2] ) ) {
			return;
		}
		self::mark( $hook );
	}

	/** Changes kept for an export in progress to judge; older ones are forgotten. */
	const CHANGE_LOG = 200;

	private static function mark( $label ) {
		$revision = wp_generate_uuid4() . '|' . preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( (string) $label ) );
		$log = (array) get_option( 'contentrain_bridge_changes', array() );
		$log[] = $revision;
		update_option( 'contentrain_bridge_changes', array_slice( $log, -self::CHANGE_LOG ), false );
		update_option( 'contentrain_bridge_revision', $revision, false );
	}

	/**
	 * The labels of every change after `$revision`, oldest first; null when
	 * `$revision` is no longer in the log and what happened since is unknown.
	 */
	public static function changes_since( $revision ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'contentrain_bridge_changes' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Other requests write this log; the cached copy is stale by design.
		$log = is_string( $raw ) && is_serialized( $raw ) ? (array) unserialize( $raw, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- An array of strings this plugin wrote; classes disabled.
		$at = array_search( $revision, $log, true );
		if ( false === $at ) {
			return '' === (string) $revision && $log ? array_map( array( self::class, 'label' ), $log ) : null;
		}
		return array_map( array( self::class, 'label' ), array_slice( $log, $at + 1 ) );
	}

	private static function label( $revision ) {
		$parts = explode( '|', (string) $revision, 2 );
		return $parts[1] ?? '';
	}

	/** The hook behind the current revision, when it was recorded. */
	public static function changed_by() {
		$parts = explode( '|', self::revision(), 2 );
		return $parts[1] ?? '';
	}

	/**
	 * Site settings that change what an export contains. For theme and widget
	 * options what the export takes is their text, so a write that changes no
	 * text is bookkeeping: WordPress creates `theme_mods_<theme>` on a theme's
	 * first front-end load, which Bridge's own render scan can be, and counting
	 * that made the export refuse its own snapshot.
	 */
	public static function option_changed( $option, $first = null, $second = null ) {
		if ( 0 === strpos( $option, 'contentrain_bridge_' ) ) {
			return;
		}
		$hook = (string) current_filter();
		if ( preg_match( '/^(theme_mods_|widget_)/', $option ) ) {
			if ( 'added_option' === $hook && ! self::texts( $first ) ) {
				return;
			}
			if ( 'updated_option' === $hook && self::texts( $first ) === self::texts( $second ) ) {
				return;
			}
			self::mark( $hook . ':' . $option );
		} elseif ( in_array( $option, array( 'blogname', 'blogdescription', 'permalink_structure', 'sidebars_widgets', 'active_plugins', 'page_on_front', 'page_for_posts', 'WPLANG' ), true ) ) {
			self::mark( $hook . ':' . $option );
		}
	}

	/** Every string in a value, in order: what an export would take from it. */
	private static function texts( $value ) {
		$out = array();
		$walk = static function ( $node ) use ( &$walk, &$out ) {
			if ( is_string( $node ) ) {
				$out[] = $node;
			} elseif ( is_array( $node ) || is_object( $node ) ) {
				foreach ( (array) $node as $child ) {
					$walk( $child );
				}
			}
		};
		$walk( $value );
		return $out;
	}
}
