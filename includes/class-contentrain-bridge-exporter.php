<?php
/**
 * RawIR v1 exporter.
 *
 * @package ContentrainBridge
 * @license GPL-2.0-or-later
 */

namespace Contentrain\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds the MIT-defined RawIR JSON contract without depending on service code. */
final class Exporter {
	/** Build a complete RawIR v1 document. */
	public static function build( $include_comments = false ) {
		$posts       = array();
		$attachments = array();
		$post_types  = get_post_types( array( 'public' => true ), 'names' );

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}

			foreach ( self::all_posts( $post_type ) as $post ) {
				$posts[] = self::map_post( $post );
			}
		}

		foreach ( self::all_posts( 'attachment' ) as $attachment ) {
			$attachments[] = self::map_attachment( $attachment );
		}

		$document = array(
			'version'     => 1,
			'provenance'  => array(
				'kind'       => 'bridge',
				'fetched_at' => gmdate( 'c' ),
				'tool'       => 'contentrain-bridge/' . CONTENTRAIN_BRIDGE_VERSION,
			),
			'site'        => array(
				'url'           => home_url( '/' ),
				'title'         => get_bloginfo( 'name' ),
				'description'   => get_bloginfo( 'description' ),
				'base_site_url' => site_url( '/' ),
				'base_blog_url' => home_url( '/' ),
				'language'      => get_locale(),
				'generator'     => 'WordPress/' . get_bloginfo( 'version' ),
				'export_date'   => gmdate( 'c' ),
				'wxr_version'   => null,
			),
			'authors'     => self::authors(),
			'terms'       => self::terms(),
			'posts'       => $posts,
			'attachments' => $attachments,
			'menus'       => self::menus(),
			'options'     => self::options(),
		);

		if ( $include_comments ) {
			$document['comments'] = self::comments();
		}

		return $document;
	}

	/** Load posts in bounded database pages while preserving every status. */
	public static function all_posts( $post_type ) {
		$results = array();
		$page    = 1;

		do {
			$batch = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'attachment' === $post_type ? 'inherit' : 'any',
					'posts_per_page' => 200,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			$results = array_merge( $results, $batch );
			++$page;
		} while ( 200 === count( $batch ) );

		return $results;
	}

	/** Map a WordPress post without interpreting or rewriting its HTML. */
	public static function map_post( $post, $selected = array(), &$excluded = array() ) {
		$author = get_userdata( $post->post_author );
		$terms  = wp_get_object_terms( $post->ID, get_object_taxonomies( $post->post_type ) );

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
			'password'       => null,
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
			'meta'           => Policy::meta( get_post_meta( $post->ID ), $selected, $excluded, 'post/' . $post->ID ),
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

	/** Return authors without email addresses; the contract permits omission. */
	public static function authors() {
		return array_map(
			static function ( $user ) {
				return array(
					'id'           => (int) $user->ID,
					'login'        => $user->user_login,
					'display_name' => $user->display_name,
					'first_name'   => $user->first_name ?: null,
					'last_name'    => $user->last_name ?: null,
				);
			},
			get_users( array( 'fields' => 'all' ) )
		);
	}

	/** Export public taxonomy terms with parent slugs. */
	public static function terms() {
		$results = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$parent = $term->parent ? get_term( $term->parent, $taxonomy ) : null;
				$results[] = array(
					'id'              => (int) $term->term_id,
					'taxonomy'        => $taxonomy,
					'slug'            => $term->slug,
					'name'            => $term->name,
					'parent'          => $parent && ! is_wp_error( $parent ) ? $parent->slug : null,
					'parent_resolved' => ! $term->parent || ( $parent && ! is_wp_error( $parent ) ),
					'description'     => $term->description,
				);
			}
		}
		return $results;
	}

	/** Export registered navigation menus and resolved target metadata. */
	public static function menus() {
		$results = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			$results[] = array(
				'id'    => (int) $menu->term_id,
				'slug'  => $menu->slug,
				'name'  => $menu->name,
				'items' => array_map( array( self::class, 'map_menu_item' ), is_array( $items ) ? $items : array() ),
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

	/** Export comments without email, IP, or user-agent fields. */
	public static function comments() {
		return array_map(
			static function ( $comment ) {
				$post = get_post( $comment->comment_post_ID );
				return array(
					'id'              => (int) $comment->comment_ID,
					'post'            => (int) $comment->comment_post_ID,
					'post_type'       => $post ? $post->post_type : null,
					'parent'          => (int) $comment->comment_parent ?: null,
					'parent_resolved' => 0 === (int) $comment->comment_parent || null !== get_comment( $comment->comment_parent ),
					'author'          => $comment->comment_author,
					'url'             => $comment->comment_author_url ?: null,
					'date'            => self::utc_date( $comment->comment_date_gmt ),
					'content'         => $comment->comment_content,
					'approved'        => (string) $comment->comment_approved,
					'type'            => $comment->comment_type,
					'user_id'         => (int) $comment->user_id ?: null,
					'meta'            => (object) array(),
				);
			},
			get_comments( array( 'status' => 'all', 'number' => 0, 'orderby' => 'comment_ID', 'order' => 'ASC' ) )
		);
	}

	/** Export only migration-relevant, non-secret WordPress options. */
	public static function options() {
		$keys   = array( 'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'timezone_string', 'date_format', 'time_format' );
		$result = array();
		foreach ( $keys as $key ) {
			$result[ $key ] = get_option( $key );
		}
		return $result;
	}

	/** Convert a WordPress GMT timestamp to ISO 8601 or null. */
	public static function utc_date( $value ) {
		if ( ! $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		return gmdate( 'c', strtotime( $value . ' UTC' ) );
	}
}
