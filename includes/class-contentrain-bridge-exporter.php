<?php
/**
 * RawIR v1 record mappers.
 *
 * @package ContentrainBridge
 * @license GPL-2.0-or-later
 */

namespace Contentrain\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maps WordPress records to their RawIR v1 shape; the export pipeline assembles them. */
final class Exporter {
	/**
	 * What a password-protected post carries instead of its password (QA-23): that it is protected, never the
	 * password itself. A reader needs the first — wp-import keeps such a post out of the public site — and must
	 * never get the second.
	 */
	const PROTECTED_PASSWORD = '[protected]';

	/** Map a WordPress post without interpreting or rewriting its HTML. */
	public static function map_post( $post, $selected = array(), &$excluded = array() ) {
		$author = get_userdata( $post->post_author );
		// A multilingual plugin registers its own bookkeeping taxonomies (Polylang's
		// `language`, `post_translations`) against every translatable post type;
		// they are never content, and `@contentrain/wp-import` explicitly documents
		// that these must never become models.
		$taxonomies = array_filter( get_object_taxonomies( $post->post_type ), static function ( $taxonomy ) {
			$definition = get_taxonomy( $taxonomy );
			return $definition && $definition->public;
		} );
		$terms = wp_get_object_terms( $post->ID, array_values( $taxonomies ) );

		return array(
			'id'             => (int) $post->ID,
			'type'           => $post->post_type,
			'status'         => $post->post_status,
			'slug'           => $post->post_name,
			'title'          => $post->post_title,
			'link'           => get_permalink( $post ),
			'guid'           => $post->guid,
			'author'         => $author ? $author->user_login : null,
			'date'           => self::utc_date( $post->post_date_gmt ),
			'modified'       => self::utc_date( $post->post_modified_gmt ),
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'parent'         => (int) $post->post_parent ?: null,
			'menu_order'     => (int) $post->menu_order,
			'sticky'         => is_sticky( $post->ID ),
			'password'       => '' !== (string) $post->post_password ? self::PROTECTED_PASSWORD : null,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'terms'          => is_wp_error( $terms ) ? array() : array_map(
				static function ( $term ) {
					return array(
						'taxonomy' => $term->taxonomy,
						'slug'     => $term->slug,
						'name'     => $term->name,
						'resolved' => true,
					);
				},
				$terms
			),
			'meta'           => Policy::meta( get_post_meta( $post->ID ), $selected, $excluded, 'post/' . $post->ID, '' !== (string) $post->post_password ),
		);
	}

	/** Map an attachment as metadata; binary transfer is a downstream concern. */
	public static function map_attachment( $attachment ) {
		$author = get_userdata( $attachment->post_author );
		return array(
			'id'              => (int) $attachment->ID,
			'title'           => $attachment->post_title,
			'slug'            => $attachment->post_name,
			'url'             => wp_get_attachment_url( $attachment->ID ) ?: null,
			'alt'             => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'caption'         => $attachment->post_excerpt,
			'description'     => $attachment->post_content,
			'file'            => get_post_meta( $attachment->ID, '_wp_attached_file', true ) ?: null,
			'image_meta'      => wp_get_attachment_metadata( $attachment->ID ) ?: null,
			'mime'            => $attachment->post_mime_type ?: null,
			'parent'          => (int) $attachment->post_parent ?: null,
			'parent_resolved' => 0 === (int) $attachment->post_parent || null !== get_post( $attachment->post_parent ),
			'author'          => $author ? $author->user_login : null,
			'date'            => self::utc_date( $attachment->post_date_gmt ),
			'status'          => $attachment->post_status,
			'meta'            => array(),
		);
	}

	/** Export registered navigation menus and resolved target metadata. */
	public static function menus() {
		$results = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$raw_items = wp_get_nav_menu_items( $menu->term_id );
			$items     = array_map( array( self::class, 'map_menu_item' ), is_array( $raw_items ) ? $raw_items : array() );
			// A parent id only means something within its own menu; flag one that
			// names no sibling here rather than leaving a dangling reference implicit.
			$ids = array_column( $items, 'id' );
			foreach ( $items as &$item ) {
				$item['parent_unresolved'] = (bool) ( $item['parent'] && ! in_array( $item['parent'], $ids, true ) );
			}
			unset( $item );
			$results[] = array(
				'id'    => (int) $menu->term_id,
				'slug'  => $menu->slug,
				'name'  => $menu->name,
				'items' => $items,
			);
		}
		return $results;
	}

	/** Map one menu item into the shared target union. */
	public static function map_menu_item( $item ) {
		if ( 'post_type' === $item->type ) {
			$target_post = get_post( $item->object_id );
			$target      = array(
				'kind'      => 'post',
				'post_type' => $item->object,
				'id'        => (int) $item->object_id ?: null,
				'slug'      => $target_post ? $target_post->post_name : null,
				'resolved'  => null !== $target_post,
			);
		} elseif ( 'taxonomy' === $item->type ) {
			$target_term = get_term( $item->object_id, $item->object );
			$target      = array(
				'kind'     => 'term',
				'taxonomy' => $item->object,
				'id'       => (int) $item->object_id ?: null,
				'slug'     => $target_term && ! is_wp_error( $target_term ) ? $target_term->slug : null,
				'resolved' => $target_term && ! is_wp_error( $target_term ),
			);
		} elseif ( 'post_type_archive' === $item->type ) {
			$target = array( 'kind' => 'archive', 'post_type' => $item->object, 'resolved' => true );
		} else {
			$target = array( 'kind' => 'url', 'url' => $item->url, 'resolved' => true );
		}

		return array(
			'id'          => (int) $item->ID,
			'title'       => $item->title,
			'order'       => (int) $item->menu_order,
			'parent'      => (int) $item->menu_item_parent ?: null,
			'url'         => $item->url,
			'target'      => $target,
			'target_attr' => $item->target ?: null,
			'classes'     => array_values( array_filter( (array) $item->classes ) ),
			'description' => $item->description,
			'status'      => $item->post_status,
		);
	}

	/** Export only migration-relevant, non-secret WordPress options. */
	public static function options() {
		$keys   = array( 'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'timezone_string', 'date_format', 'time_format' );
		$result = array();
		foreach ( $keys as $key ) {
			$result[ $key ] = get_option( $key );
		}
		return $result + self::design();
	}

	/**
	 * The site's design system as WordPress and the builders hold it: the active theme, block-theme global
	 * settings and styles (theme.json merged with the user's Site Editor changes) and templates, the
	 * Elementor kit (global colours, fonts, layout) and Divi's theme options. Read-only calls, no theme code
	 * run; every value passes the same secret filter as selected meta (Divi keeps integration API keys in
	 * `et_divi`).
	 */
	public static function design() {
		$excluded = array();
		$out      = array(
			'stylesheet' => get_stylesheet(),
			'template'   => get_template(),
		);
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$out['global_settings'] = Policy::clean( wp_get_global_settings(), $excluded, 'options/global_settings', 0, Policy::BUILDER_DEPTH );
		}
		if ( function_exists( 'wp_get_global_styles' ) ) {
			$out['global_styles'] = Policy::clean( wp_get_global_styles(), $excluded, 'options/global_styles', 0, Policy::BUILDER_DEPTH );
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && function_exists( 'get_block_templates' ) ) {
			$templates = array();
			foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
				foreach ( get_block_templates( array(), $type ) as $template ) {
					$templates[] = array(
						'type'    => $type,
						'slug'    => $template->slug,
						'area'    => isset( $template->area ) ? $template->area : null,
						'source'  => $template->source,
						'content' => $template->content,
					);
				}
			}
			usort( $templates, static function ( $a, $b ) { return strcmp( $a['type'] . '/' . $a['slug'], $b['type'] . '/' . $b['slug'] ); } );
			$out['block_templates'] = $templates;
		}
		$kit = (int) get_option( 'elementor_active_kit' );
		if ( $kit ) {
			$settings = get_post_meta( $kit, '_elementor_page_settings', true );
			$out['elementor_kit'] = is_array( $settings ) ? Policy::clean( $settings, $excluded, 'options/elementor_kit', 0, Policy::BUILDER_DEPTH ) : array();
		}
		$divi = get_option( 'et_divi' );
		if ( is_array( $divi ) ) {
			$out['et_divi'] = Policy::clean( $divi, $excluded, 'options/et_divi' );
		}
		return $out;
	}

	/** Convert a WordPress GMT timestamp to ISO 8601 or null. */
	public static function utc_date( $value ) {
		if ( ! $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		return gmdate( 'c', strtotime( $value . ' UTC' ) );
	}
}
