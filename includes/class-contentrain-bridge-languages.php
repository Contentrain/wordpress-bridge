<?php
/** The site's languages as its multilingual plugin declares them. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * One reader of a site's languages for Polylang and WPML, each through its own
 * API (Polylang's `pll_*` functions and model, WPML's `wpml_*` filters), never
 * guessed from URLs or names. Every value is the plugin's own, unchanged: a
 * language's `slug` is what the plugin keys translations by ('en', 'pt-br'),
 * `locale` and `w3c` keep their case ('pt_BR', 'pt-BR'). Normalising them is
 * the reader's job (Migrate's `packages/locales`), so the two plugins can never
 * be normalised two different ways here.
 *
 * Without either plugin every method answers "no language data": `null`, an
 * empty list, or the field left out by the caller.
 */
final class Languages {
	/**
	 * Taxonomies a multilingual plugin keeps its own bookkeeping in — which post
	 * or term is in which language, which ones translate each other. Never
	 * content; what they say travels as `lang`, `language_pairs` and `term_pairs`.
	 */
	/** The only options `strings()` reads untranslated: the site title and tagline, never a name found in the database. */
	const STRING_OPTIONS = array( 'blogname', 'blogdescription' );
	/** WPML String Translation's own names for `STRING_OPTIONS`. */
	const WPML_STRING_NAMES = array( 'blogname' => 'Blog Title', 'blogdescription' => 'Tagline' );
	const BOOKKEEPING = array( 'language', 'term_language', 'post_translations', 'term_translations', 'translation_priority' );

	/** `polylang`, `wpml`, or null when neither manages the site's languages. */
	public static function plugin() {
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'PLL' ) && PLL() && isset( PLL()->model ) ) {
			return 'polylang';
		}
		if ( has_filter( 'wpml_active_languages' ) ) {
			return 'wpml';
		}
		return null;
	}

	public static function bookkeeping( $taxonomy ) {
		return in_array( (string) $taxonomy, self::BOOKKEEPING, true );
	}

	/** The default language's code as the plugin names it; without a plugin, the install locale. */
	public static function default_code() {
		$plugin = self::plugin();
		if ( 'polylang' === $plugin && pll_default_language() ) {
			return (string) pll_default_language();
		}
		if ( 'wpml' === $plugin ) {
			$default = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, spelled WPML's way.
			if ( $default ) {
				return (string) $default;
			}
		}
		return get_locale();
	}

	/** Every language's code, in the plugin's own order; without a plugin, the install locale alone. */
	public static function codes() {
		$plugin = self::plugin();
		if ( 'polylang' === $plugin ) {
			return array_values( array_map( 'strval', (array) pll_languages_list() ) );
		}
		if ( 'wpml' === $plugin ) {
			return array_values( array_map( 'strval', array_keys( self::wpml_languages() ) ) );
		}
		return array( get_locale() );
	}

	/**
	 * `RawIR.languages`: the plugin, its default, how its URLs carry a language,
	 * and every language with the fields Polylang's own `pll/v1/languages`
	 * answers with. Null without a multilingual plugin.
	 */
	public static function document() {
		$plugin = self::plugin();
		if ( 'polylang' === $plugin ) {
			$options = PLL()->options;
			// Polylang's `force_lang`: 0 the language is set from the content, 1 a
			// directory, 2 a subdomain, 3 a domain per language.
			$schemes = array( 0 => 'content', 1 => 'directory', 2 => 'subdomain', 3 => 'domain' );
			$force = (int) ( $options['force_lang'] ?? 0 );
			$list = array();
			foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $language ) {
				$list[] = array(
					'slug' => (string) $language->slug,
					'locale' => (string) $language->locale,
					'w3c' => (string) $language->w3c,
					'name' => (string) $language->name,
					'is_default' => (bool) $language->is_default,
					'is_rtl' => (bool) $language->is_rtl,
					'home_url' => (string) $language->get_home_url(),
					'order' => (int) $language->term_group,
					'page_on_front' => (int) $language->page_on_front ?: null,
					'page_for_posts' => (int) $language->page_for_posts ?: null,
				);
			}
			return array(
				'plugin' => 'polylang',
				'default' => self::default_code(),
				'scheme' => $schemes[ $force ] ?? 'content',
				// Polylang's own settings, as stored: the language of the default
				// left out of its URLs, and `/language/` removed from them.
				'hide_default' => (bool) ( $options['hide_default'] ?? false ),
				'rewrite' => (bool) ( $options['rewrite'] ?? false ),
				'list' => $list,
			);
		}
		if ( 'wpml' === $plugin ) {
			// WPML's `language_negotiation_type`: 1 a directory, 2 a domain, 3 a `?lang=` parameter.
			$schemes = array( 1 => 'directory', 2 => 'domain', 3 => 'query' );
			$negotiation = (int) apply_filters( 'wpml_setting', 0, 'language_negotiation_type' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			$urls = (array) apply_filters( 'wpml_setting', array(), 'urls' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			$default = self::default_code();
			$front = (int) get_option( 'page_on_front' );
			$posts = (int) get_option( 'page_for_posts' );
			$list = array();
			$order = 0;
			foreach ( self::wpml_languages() as $code => $language ) {
				$entry = array(
					'slug' => (string) $code,
					'locale' => (string) ( $language['default_locale'] ?? '' ),
					'name' => (string) ( $language['native_name'] ?? $code ),
					'is_default' => $code === $default,
					'home_url' => (string) ( $language['url'] ?? '' ),
					'order' => $order++,
					'page_on_front' => $front ? ( (int) apply_filters( 'wpml_object_id', $front, 'page', false, $code ) ?: null ) : null, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
					'page_for_posts' => $posts ? ( (int) apply_filters( 'wpml_object_id', $posts, 'page', false, $code ) ?: null ) : null, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
				);
				// Only what WPML itself answers: no tag or direction made up from the code.
				if ( isset( $language['tag'] ) ) {
					$entry['w3c'] = (string) $language['tag'];
				}
				$rtl = apply_filters( 'wpml_is_rtl', null, $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
				if ( null !== $rtl ) {
					$entry['is_rtl'] = (bool) $rtl;
				}
				$list[] = $entry;
			}
			$document = array( 'plugin' => 'wpml', 'default' => $default, 'list' => $list );
			if ( isset( $schemes[ $negotiation ] ) ) {
				$document['scheme'] = $schemes[ $negotiation ];
			}
			if ( array_key_exists( 'directory_for_default_language', $urls ) ) {
				$document['hide_default'] = ! $urls['directory_for_default_language'];
			}
			return $document;
		}
		return null;
	}

	/** A post's language code as the plugin names it; an untagged post is the default's. Without a plugin, Source's locale. */
	public static function post_code( $post ) {
		if ( ! self::plugin() ) {
			return Source::locale( get_locale() );
		}
		$code = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : null;
		if ( ! $code && has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			$code = is_array( $details ) ? ( $details['language_code'] ?? null ) : null;
		}
		return (string) ( $code ?: self::default_code() );
	}

	/** A term's language code, or null when the plugin does not translate its taxonomy. */
	public static function term_code( $term ) {
		$plugin = self::plugin();
		if ( 'polylang' === $plugin ) {
			return pll_is_translated_taxonomy( $term->taxonomy ) ? ( pll_get_term_language( (int) $term->term_id ) ?: null ) : null;
		}
		if ( 'wpml' === $plugin ) {
			$details = self::wpml_term_details( $term );
			return $details && ! empty( $details->language_code ) ? (string) $details->language_code : null;
		}
		return null;
	}

	/** code => term id of each translation of a term, the term itself included; empty when its taxonomy is not translated. */
	public static function term_translations( $term ) {
		$plugin = self::plugin();
		$translations = array();
		if ( 'polylang' === $plugin && pll_is_translated_taxonomy( $term->taxonomy ) ) {
			$translations = (array) pll_get_term_translations( (int) $term->term_id );
		} elseif ( 'wpml' === $plugin ) {
			$details = self::wpml_term_details( $term );
			if ( $details && ! empty( $details->trid ) ) {
				foreach ( (array) apply_filters( 'wpml_get_element_translations', array(), $details->trid, 'tax_' . $term->taxonomy ) as $code => $item ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
					// A taxonomy's element is its term_taxonomy_id; WPML names the term id beside it.
					$id = isset( $item->term_id ) ? (int) $item->term_id : 0;
					if ( ! $id && isset( $item->element_id ) ) {
						$found = Source::term_by( 'term_taxonomy_id', (int) $item->element_id );
						$id = $found ? (int) $found->term_id : 0;
					}
					if ( $id ) {
						$translations[ (string) $code ] = $id;
					}
				}
			}
		}
		$code = self::term_code( $term );
		if ( ! $translations && null !== $code ) {
			$translations[ $code ] = (int) $term->term_id;
		}
		return array_map( 'intval', $translations );
	}

	/**
	 * Which menus the plugin shows in which language, and where: menu id =>
	 * `{ lang, locations }`. Polylang keeps one menu per location per language
	 * in its `nav_menus` setting; WPML translates the menu term itself, and a
	 * translation shows where its default-language original is assigned.
	 */
	public static function menus( $menus, $assigned ) {
		$plugin = self::plugin();
		$result = array();
		if ( 'polylang' === $plugin ) {
			$nav_menus = PLL()->options['nav_menus'] ?? array();
			foreach ( (array) ( $nav_menus[ get_stylesheet() ] ?? array() ) as $location => $by_language ) {
				foreach ( (array) $by_language as $code => $menu_id ) {
					if ( ! $menu_id ) {
						continue;
					}
					$result[ (int) $menu_id ]['lang'] = (string) $code;
					$result[ (int) $menu_id ]['locations'][] = (string) $location;
				}
			}
		} elseif ( 'wpml' === $plugin ) {
			$default = self::default_code();
			foreach ( $menus as $menu ) {
				$term = get_term( (int) $menu['id'], 'nav_menu' );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$code = self::term_code( $term );
				if ( null === $code ) {
					continue;
				}
				$result[ (int) $menu['id'] ]['lang'] = $code;
				$original = self::term_translations( $term )[ $default ] ?? null;
				if ( $code !== $default && $original ) {
					$locations = array_keys( array_filter( (array) $assigned, static function ( $id ) use ( $original ) { return (int) $id === (int) $original; } ) );
					if ( $locations ) {
						$result[ (int) $menu['id'] ]['locations'] = array_map( 'strval', $locations );
					}
				}
			}
		}
		foreach ( $result as &$entry ) {
			if ( isset( $entry['locations'] ) ) {
				$entry['locations'] = array_values( array_unique( $entry['locations'] ) );
				sort( $entry['locations'] );
			}
		}
		unset( $entry );
		return $result;
	}

	/**
	 * `RawIR.strings`: interface strings the plugin translates (the site title
	 * and tagline, a theme's or plugin's registered strings), each with the
	 * translations the plugin holds for it, keyed by language code. A string
	 * nobody translated has no entry for that language.
	 */
	public static function strings() {
		$plugin = self::plugin();
		$strings = array();
		if ( 'polylang' === $plugin && class_exists( 'PLL_MO' ) ) {
			// Names and groups as registered, where Polylang has its registry loaded.
			$registered = array();
			if ( class_exists( 'PLL_Admin_Strings' ) ) {
				foreach ( (array) \PLL_Admin_Strings::get_strings() as $item ) {
					$registered[ (string) $item['string'] ] = $item;
				}
			}
			foreach ( self::STRING_OPTIONS as $option ) {
				$value = self::untranslated_option( $option );
				if ( '' !== $value && ! isset( $registered[ $value ] ) ) {
					$registered[ $value ] = array( 'name' => $option, 'context' => 'WordPress' );
				}
			}
			foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $language ) {
				$mo = new \PLL_MO();
				$mo->import_from_db( $language );
				foreach ( (array) $mo->entries as $entry ) {
					$source = (string) $entry->singular;
					$translation = (string) ( $entry->translations[0] ?? '' );
					if ( '' === $source || '' === $translation ) {
						continue;
					}
					$strings[ $source ]['translations'][ (string) $language->slug ] = $translation;
				}
			}
			foreach ( $strings as $source => &$item ) {
				$item = array_filter( array( 'name' => $registered[ $source ]['name'] ?? null, 'context' => $registered[ $source ]['context'] ?? null ), 'is_string' ) + array( 'string' => (string) $source, 'translations' => $item['translations'] );
			}
			unset( $item );
		} elseif ( 'wpml' === $plugin ) {
			foreach ( self::STRING_OPTIONS as $option ) {
				$name = self::WPML_STRING_NAMES[ $option ];
				$value = self::untranslated_option( $option );
				if ( '' === $value ) {
					continue;
				}
				foreach ( self::codes() as $code ) {
					$translation = (string) apply_filters( 'wpml_translate_single_string', $value, 'WP', $name, $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
					if ( '' !== $translation && $translation !== $value ) {
						$strings[ $value ]['translations'][ $code ] = $translation;
					}
				}
				if ( isset( $strings[ $value ] ) ) {
					$strings[ $value ] = array( 'name' => $option, 'context' => 'WordPress', 'string' => $value, 'translations' => $strings[ $value ]['translations'] );
				}
			}
		}
		ksort( $strings, SORT_STRING );
		return array_values( $strings );
	}

	/** Run `$callback` with the plugin's current language set to `$code`, then put the previous one back. */
	public static function in_language( $code, $callback ) {
		$plugin = self::plugin();
		if ( 'polylang' === $plugin ) {
			$previous = PLL()->curlang ?? false;
			PLL()->curlang = PLL()->model->get_language( $code );
			try {
				return $callback();
			} finally {
				PLL()->curlang = $previous;
			}
		}
		if ( 'wpml' === $plugin ) {
			$previous = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			do_action( 'wpml_switch_language', $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own action.
			try {
				return $callback();
			} finally {
				do_action( 'wpml_switch_language', $previous ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own action.
			}
		}
		return $callback();
	}

	/** Whether a plugin gives ACF Options Pages a copy per language (WPML + ACFML, the free "ACF Options for Polylang"). */
	public static function translates_options_pages() {
		$plugin = self::plugin();
		return ( 'wpml' === $plugin && ( function_exists( 'acfml' ) || class_exists( 'ACFML' ) ) ) || ( 'polylang' === $plugin && defined( 'BEA_ACF_OPTIONS_FOR_POLYLANG_VERSION' ) );
	}

	/**
	 * An option as stored, before a multilingual plugin's `option_{name}` filter
	 * translates it into whatever language happens to be current. Only `STRING_OPTIONS`; any other name is ''.
	 */
	private static function untranslated_option( $option ) {
		if ( ! in_array( $option, self::STRING_OPTIONS, true ) ) {
			return '';
		}
		$stored = wp_load_alloptions();
		return (string) ( $stored[ $option ] ?? get_option( $option ) );
	}

	/** WPML's active languages, code => details, in its own order. */
	private static function wpml_languages() {
		return (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0, 'orderby' => 'custom' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
	}

	private static function wpml_term_details( $term ) {
		$details = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => (int) $term->term_taxonomy_id, 'element_type' => 'tax_' . $term->taxonomy ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
		return is_object( $details ) ? $details : null;
	}
}
