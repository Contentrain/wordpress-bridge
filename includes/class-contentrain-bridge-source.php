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
			$types[ $type->name ] = array( 'label' => $type->label, 'rest' => (bool) $type->show_in_rest, 'counts' => (array) wp_count_posts( $type->name ) );
		}
		$plugins = array();
		foreach ( get_plugins() as $path => $plugin ) {
			if ( is_plugin_active( $path ) || is_plugin_active_for_network( $path ) ) {
				$plugins[ $path ] = array( 'name' => $plugin['Name'], 'version' => $plugin['Version'] );
			}
		}
		$theme = wp_get_theme();
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
		);
	}

	public static function locale( $code ) {
		$locale = strtolower( str_replace( '_', '-', $code ?: get_locale() ) );
		if ( ! preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/D', $locale ) ) {
			throw new \RuntimeException( 'Unsupported locale identifier: ' . $locale );
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
				if ( Policy::sensitive( $name ) || in_array( $field['type'], array( 'password', 'user' ), true ) ) {
					$excluded[] = array( 'source' => 'acf/' . $post->ID . '/' . $name, 'reason' => 'sensitive-field' );
					continue;
				}
				$raw['acf'][ $name ] = array( 'value' => Policy::clean( $field['value'], $excluded, 'acf/' . $post->ID . '/' . $name ), 'field_key' => $field['key'] );
				$schema[ $name ] = self::schema( $field );
			}
		}
		return array( 'raw' => $raw, 'acf_schema' => Policy::clean( $schema, $excluded, 'acf-schema/' . $post->ID ), 'address' => self::address( $post ), 'translations' => self::translations( $post ) );
	}

	/** The parts of an ACF field definition that describe content, at every depth. */
	private static function schema( $field ) {
		$out = array_intersect_key( (array) $field, array_flip( array( 'key', 'name', 'label', 'type', 'required', 'choices', 'multiple', 'return_format', 'sub_fields', 'layouts' ) ) );
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

	public static function changed() {
		update_option( 'contentrain_bridge_revision', wp_generate_uuid4(), false );
	}

	public static function option_changed( $option ) {
		if ( 0 !== strpos( $option, 'contentrain_bridge_' ) && ( preg_match( '/^(theme_mods_|widget_)/', $option ) || in_array( $option, array( 'blogname', 'blogdescription', 'permalink_structure', 'sidebars_widgets', 'active_plugins', 'page_on_front', 'page_for_posts', 'WPLANG' ), true ) ) ) {
			self::changed();
		}
	}
}
