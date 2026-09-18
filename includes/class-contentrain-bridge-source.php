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

	public static function language( $post ) {
		$code = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : null;
		if ( ! $code && has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post->ID );
			$code = is_array( $details ) ? ( $details['language_code'] ?? null ) : null;
		}
		return self::locale( $code ?: get_locale() );
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
		$canonical = min( array_values( self::translations( $post ) ) );
		return array( 'model_id' => 'wp-' . sanitize_title( str_replace( '_', '-', $post->post_type ) ), 'entry_id' => 'post' === $post->post_type ? 'entry-' . $canonical : substr( hash( 'sha256', 'wp:' . $canonical ), 0, 12 ), 'locale' => self::language( $post ) );
	}

	/** Capture source without executing shortcodes or arbitrary theme code. */
	public static function post( $post, $selected, &$excluded ) {
		$raw = Exporter::map_post( $post, $selected, $excluded );
		$raw['lang'] = self::language( $post );
		if ( $post->post_password ) {
			$excluded[] = array( 'source' => 'post/' . $post->ID . '/password', 'reason' => 'password-not-exported' );
		}
		$schema = array();
		if ( function_exists( 'get_field_objects' ) ) {
			$fields = get_field_objects( $post->ID, false, true ) ?: array();
			foreach ( $fields as $name => $field ) {
				if ( Policy::sensitive( $name ) || in_array( $field['type'], Acf::EXCLUDED, true ) ) {
					$excluded[] = array( 'source' => 'acf/' . $post->ID . '/' . $name, 'reason' => 'sensitive-field' );
					continue;
				}
				$raw['acf'][ $name ] = array( 'value' => self::acf_value( $field, $field['value'], $excluded, 'acf/' . $post->ID . '/' . $name ), 'field_key' => $field['key'] );
				$schema[ $name ] = self::schema( $field );
			}
		}
		return array( 'raw' => $raw, 'acf_schema' => Policy::clean( $schema, $excluded, 'acf-schema/' . $post->ID ), 'address' => self::address( $post ), 'translations' => self::translations( $post ) );
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
		$out = array_intersect_key( (array) $field, array_flip( array( 'key', 'name', 'label', 'type', 'required', 'choices', 'multiple', 'return_format', 'sub_fields', 'layouts', 'taxonomy', 'field_type' ) ) );
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
