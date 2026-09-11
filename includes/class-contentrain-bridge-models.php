<?php
/** Canonical Contentrain serializer and field modelling. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class Models {
	public static function file( &$job, $path, $content ) {
		if ( strlen( $content ) > 8 * MB_IN_BYTES ) {
			throw new \RuntimeException( 'One content file exceeds the 8 MiB export safety limit: ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
		}
		Files::put( Files::dir( $job['id'] ) . '/output', $path, $content );
		$job['files'][ $path ] = array( 'sha256' => hash( 'sha256', $content ), 'bytes' => strlen( $content ) );
	}

	/** Copy a source file into the export and record it like any other output. */
	public static function binary( &$job, $path, $source ) {
		$bytes = Files::copy( Files::dir( $job['id'] ) . '/output', $path, $source );
		$job['files'][ $path ] = array( 'sha256' => hash_file( 'sha256', Files::path( Files::dir( $job['id'] ) . '/output', $path ), false ), 'bytes' => $bytes );
		return $bytes;
	}

	/**
	 * Rewrite uploads URLs to the stored `media/...` path Contentrain reads.
	 * Only URLs whose file was actually copied are rewritten: pointing content
	 * at a path that is not in the export would trade a working WordPress link
	 * for a broken local one.
	 */
	public static function relink( $job, $value, $root_relative = false ) {
		$base = $job['uploads']['baseurl'] ?? '';
		if ( ! $base || ! is_string( $value ) || false === strpos( $value, $base ) ) {
			return $value;
		}
		$files = $job['files'];
		$mapping = $job['media_paths'] ?? array();
		return preg_replace_callback(
			'#' . preg_quote( $base, '#' ) . '/([^\s"\x27<>?\#),]+)#u',
			static function ( $match ) use ( $files, $mapping, $root_relative ) {
				$relative = rawurldecode( $match[1] );
				$path = $mapping[ $relative ] ?? 'media/' . $relative;
				return isset( $files[ $path ] ) ? ( $root_relative ? '/' : '' ) . $path : $match[0];
			},
			$value
		);
	}

	public static function row( &$job, $path, $key, $value ) {
		$bucket = hash( 'sha256', $path );
		$row = hash( 'sha256', (string) $key );
		Files::put( Files::dir( $job['id'] ), 'rows/' . $bucket . '/' . $row . '.json', Policy::json( $value ) );
		$job['tables'][ $path ][ (string) $key ] = $row;
	}

	/**
	 * Where one entry's content file lives. A monolingual site writes
	 * `data.json` / `{slug}.md` with no locale in the name, exactly as
	 * `@contentrain/mcp` resolves it — a single-language store that carries
	 * locale-named files is a different store from the one the rest of the
	 * toolchain writes for the same site.
	 */
	public static function content_path( $job, $model, $locale, $id = null ) {
		$m = is_array( $model ) ? $model : $job['models'][ $model ];
		$root = '.contentrain/content/' . $m['domain'] . '/' . $m['id'] . '/';
		if ( 'document' === $m['kind'] ) {
			return $root . ( $m['i18n'] ? $id . '/' . $locale . '.md' : $id . '.md' );
		}
		return $root . ( $m['i18n'] ? $locale . '.json' : 'data.json' );
	}

	/** Meta always carries a locale: a non-i18n model pins it to the project default. */
	public static function meta_path( $job, $model, $locale, $id = null ) {
		$m = is_array( $model ) ? $model : $job['models'][ $model ];
		$effective = $m['i18n'] ? $locale : $job['default_locale'];
		$root = '.contentrain/meta/' . $m['id'] . '/';
		return 'document' === $m['kind'] ? $root . $id . '/' . $effective . '.json' : $root . $effective . '.json';
	}

	public static function model( &$job, $id, $kind, $domain, $name, $fields, $title = 'title' ) {
		if ( 'dictionary' !== $kind && isset( $fields[ $title ] ) ) {
			$fields[ $title ]['required'] = true;
		}
		$existing = $job['models'][ $id ] ?? null;
		if ( $existing ) {
			foreach ( $fields as $key => $definition ) {
				if ( isset( $existing['fields'][ $key ] ) && $existing['fields'][ $key ] != $definition ) {
					throw new \RuntimeException( 'Inconsistent field types in ' . $id . '.' . $key . '; choose a consistent model before exporting.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
				}
			}
			$fields = array_merge( $existing['fields'] ?? array(), $fields );
		}
		$job['models'][ $id ] = array( 'id' => $id, 'kind' => $kind, 'domain' => $domain, 'name' => $name, 'i18n' => $job['i18n'], 'title_field' => $title );
		if ( 'dictionary' !== $kind ) {
			$job['models'][ $id ]['fields'] = $fields ?: (object) array();
		}
	}

	public static function meta( $status = 'publish', $date = null ) {
		$map = array( 'publish' => 'published', 'inherit' => 'published', 'pending' => 'in_review', 'trash' => 'archived', 'future' => 'published' );
		$meta = array( 'status' => $map[ $status ] ?? 'draft', 'source' => 'import', 'updated_by' => 'contentrain-bridge' );
		if ( 'future' === $status ) {
			if ( ! $date || false === strtotime( $date ) ) {
				throw new \RuntimeException( 'Scheduled content has no valid publication date.' );
			}
			$meta['publish_at'] = $date;
		}
		return $meta;
	}

	public static function entry( &$job, $model, $locale, $id, $data, $meta = null, $body = '' ) {
		$job['locales'][ $locale ] = true;
		$m = $job['models'][ $model ];
		// `url` fields are addresses, not content: the media record's own source
		// URL and a post's original permalink must survive relinking. Everything
		// else — bodies, excerpts, and image/file fields — points at the copy.
		foreach ( is_array( $data ) ? $data : array() as $key => $value ) {
			if ( 'url' === ( $m['fields'][ $key ]['type'] ?? '' ) ) {
				continue;
			}
			$data[ $key ] = self::relink( $job, $value, in_array( $m['fields'][ $key ]['type'] ?? '', array( 'richtext', 'markdown' ), true ) );
		}
		$body = self::relink( $job, $body, true );
		$content = self::content_path( $job, $m, $locale, $id );
		$meta_file = self::meta_path( $job, $m, $locale, $id );
		$meta = $meta ?: self::meta();
		if ( 'document' === $m['kind'] ) {
			// Original HTML is valid Markdown and retained without a lossy HTML-to-Markdown pass.
			self::file( $job, $content, "---\n" . Policy::frontmatter( $data ) . "\n---\n\n" . $body . "\n" );
			self::file( $job, $meta_file, Policy::json( $meta ) );
		} elseif ( 'singleton' === $m['kind'] ) {
			self::file( $job, $content, Policy::json( (object) $data ) );
			self::file( $job, $meta_file, Policy::json( $meta ) );
		} else {
			self::row( $job, $content, $id, $data );
			if ( 'dictionary' === $m['kind'] ) {
				self::file( $job, $meta_file, Policy::json( $meta ) );
			} else {
				self::row( $job, $meta_file, $id, $meta );
			}
		}
	}

	public static function post( &$job, $record ) {
		$p = $record['raw'];
		$a = $record['address'];
		$kind = 'post' === $p['type'] ? 'document' : 'collection';
		$domain = 'post' === $p['type'] ? 'blog' : 'site';
		$fields = array( 'title' => array( 'type' => 'string', 'required' => true ), 'source_slug' => array( 'type' => 'string' ), 'source_url' => array( 'type' => 'url' ), 'excerpt' => array( 'type' => 'richtext' ), 'wp_id' => array( 'type' => 'integer' ) );
		$data = array( 'title' => wp_strip_all_tags( $p['title'] ) ?: '#' . $p['id'], 'source_slug' => $p['slug'], 'excerpt' => $p['excerpt'], 'wp_id' => $p['id'] );
		if ( $p['link'] ) {
			$data['source_url'] = $p['link'];
		}
		if ( 'collection' === $kind ) {
			$fields['body'] = array( 'type' => 'richtext' );
			$data['body'] = $p['content'];
		}
		foreach ( array( 'date', 'modified' ) as $key ) {
			$fields[ $key ] = array( 'type' => 'datetime' );
			if ( $p[ $key ] ) {
				$data[ $key ] = $p[ $key ];
			}
		}
		$author = get_userdata( get_post( $p['id'] )->post_author );
		if ( $author ) {
			$ref = substr( hash( 'sha256', 'author:' . $author->ID ), 0, 12 );
			self::model( $job, 'wp-authors', 'collection', 'blog', 'Authors', array( 'name' => array( 'type' => 'string' ), 'wp_id' => array( 'type' => 'integer' ) ), 'name' );
			self::entry( $job, 'wp-authors', $a['locale'], $ref, array( 'name' => $author->display_name, 'wp_id' => (int) $author->ID ) );
			$fields['author'] = array( 'type' => 'relation', 'model' => 'wp-authors' );
			$data['author'] = $ref;
			self::row( $job, 'bridge/raw-authors.json', $author->ID, array( 'id' => (int) $author->ID, 'login' => $author->user_login, 'display_name' => $author->display_name ) );
		}
		foreach ( $p['terms'] as $term ) {
			$t = get_term_by( 'slug', $term['slug'], $term['taxonomy'] );
			if ( ! $t || is_wp_error( $t ) ) {
				throw new \RuntimeException( 'A referenced taxonomy term could not be read.' );
			}
			$target = self::term( $job, $t, $a['locale'] );
			$key = 'terms_' . sanitize_key( $term['taxonomy'] );
			$fields[ $key ] = array( 'type' => 'relations', 'model' => $target['model'] );
			$data[ $key ][] = $target['id'];
		}
		foreach ( $p['meta'] as $key => $value ) {
			if ( null === $value || in_array( $key, Policy::CORE, true ) ) {
				continue;
			}
			$name = 'meta_' . str_replace( '-', '_', sanitize_key( ltrim( $key, '_' ) ) );
			list( $field, $content ) = self::value( $job, $value, $a['locale'], $a['model_id'] . '/' . $a['entry_id'] . '/' . $key );
			$fields[ $name ] = $field;
			$data[ $name ] = $content;
		}
		foreach ( $p['acf'] ?? array() as $key => $acf ) {
			$name = 'acf_' . str_replace( '-', '_', sanitize_key( $key ) );
			$source = $a['model_id'] . '/' . $a['entry_id'] . '/acf/' . $key;
			// The field group knows what this is; use it before falling back to a
			// shape-only guess.
			$modelled = isset( $record['acf_schema'][ $key ] ) ? Acf::field( $job, $record['acf_schema'][ $key ], $acf['value'], $a['locale'], $source ) : null;
			if ( null === $modelled ) {
				Jobs::warning( $job, array( 'source' => $source, 'reason' => 'acf-shape-not-modelled: exported as structured values' ) );
				$modelled = self::value( $job, $acf['value'], $a['locale'], $source );
			}
			list( $field, $content ) = $modelled;
			if ( null === $field ) {
				continue;
			}
			$fields[ $name ] = $field;
			$data[ $name ] = $content;
		}
		$cover = (int) ( $p['meta']['_thumbnail_id'] ?? 0 );
		if ( $cover ) {
			$url = wp_get_attachment_url( $cover );
			if ( $url ) {
				$fields['cover'] = array( 'type' => 'image' );
				$data['cover'] = $url;
			}
		}
		$name = $job['options']['labels'][ $p['type'] ] ?? $job['inventory']['post_types'][ $p['type'] ]['label'];
		self::model( $job, $a['model_id'], $kind, $domain, $name, $fields );
		self::entry( $job, $a['model_id'], $a['locale'], $a['entry_id'], $data, self::meta( $p['status'], $p['date'] ), $p['content'] );
		self::row( $job, 'bridge/entry-source-map.json', $p['id'], $a );
		self::row( $job, 'bridge/raw-posts.json', $p['id'], $p );
		self::row( $job, 'bridge/routes.json', $p['id'], array( 'source_url' => $p['link'], 'entry' => $a, 'body_format' => 'wordpress-html', 'source_hash' => hash( 'sha256', Policy::json( $p ) ) ) );
		self::row( $job, 'bridge/language-pairs.json', $p['id'], array( 'post' => $p['id'], 'translations' => $record['translations'] ) );
		if ( $record['acf_schema'] ) {
			self::row( $job, 'bridge/acf-schema.json', $p['id'], $record['acf_schema'] );
		}
	}

	/** Complex fields become editable, ordered related records instead of opaque JSON blobs. */
	private static function value( &$job, $value, $locale, $source, $depth = 0 ) {
		if ( is_array( $value ) ) {
			if ( $depth > 10 ) {
				throw new \RuntimeException( 'Content nesting exceeds the supported modelling depth.' );
			}
			$fields = array( 'name' => array( 'type' => 'string' ), 'position' => array( 'type' => 'integer' ), 'text' => array( 'type' => 'text' ), 'number' => array( 'type' => 'number' ), 'boolean' => array( 'type' => 'boolean' ), 'children' => array( 'type' => 'relations', 'model' => 'wp-structured-values' ) );
			self::model( $job, 'wp-structured-values', 'collection', 'site', 'Structured fields', $fields, 'name' );
			$ids = array();
			$position = 0;
			foreach ( $value as $key => $child ) {
				$id = substr( hash( 'sha256', $source . '/' . $key ), 0, 12 );
				$row = array( 'name' => (string) $key, 'position' => $position++ );
				list( $type, $data ) = self::value( $job, $child, $locale, $source . '/' . $key, $depth + 1 );
				$column = array( 'relations' => 'children', 'number' => 'number', 'boolean' => 'boolean' )[ $type['type'] ] ?? 'text';
				$row[ $column ] = $data;
				self::entry( $job, 'wp-structured-values', $locale, $id, $row );
				$ids[] = $id;
			}
			return array( array( 'type' => 'relations', 'model' => 'wp-structured-values' ), $ids );
		}
		if ( is_bool( $value ) ) {
			return array( array( 'type' => 'boolean' ), $value );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return array( array( 'type' => 'number' ), $value );
		}
		return array( array( 'type' => 'text' ), null === $value ? '' : (string) $value );
	}

	public static function term( &$job, $term, $locale ) {
		$mid = 'wp-tax-' . str_replace( '_', '-', sanitize_title( $term->taxonomy ) );
		$id = substr( hash( 'sha256', 'term:' . $term->term_id ), 0, 12 );
		self::model( $job, $mid, 'collection', 'site', $term->taxonomy, array( 'name' => array( 'type' => 'string' ), 'description' => array( 'type' => 'richtext' ), 'wp_id' => array( 'type' => 'integer' ), 'source_slug' => array( 'type' => 'string' ) ), 'name' );
		self::entry( $job, $mid, $locale, $id, array( 'name' => $term->name, 'description' => $term->description, 'wp_id' => (int) $term->term_id, 'source_slug' => $term->slug ) );
		$parent = $term->parent ? get_term( $term->parent, $term->taxonomy ) : null;
		self::row( $job, 'bridge/raw-terms.json', $term->term_id, array( 'id' => (int) $term->term_id, 'taxonomy' => $term->taxonomy, 'slug' => $term->slug, 'name' => $term->name, 'description' => $term->description, 'parent' => $parent && ! is_wp_error( $parent ) ? $parent->slug : null, 'parent_resolved' => ! $term->parent || ( $parent && ! is_wp_error( $parent ) ) ) );
		return array( 'model' => $mid, 'id' => $id );
	}

	/** Check short writes so disk exhaustion cannot produce a successful truncated table. */
	private static function write_stream( $stream, $bytes ) {
		while ( '' !== $bytes ) {
			$written = fwrite( $stream, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded row streaming; WP_Filesystem has no append stream primitive.
			if ( false === $written || 0 === $written ) {
				throw new \RuntimeException( 'Cannot finish writing content table.' );
			}
			$bytes = substr( $bytes, $written );
		}
	}

	/** Materialize one object map; memory use is bounded by one row. */
	public static function table( &$job, $path ) {
		$rows = $job['tables'][ $path ];
		ksort( $rows, SORT_STRING );
		$dir = Files::dir( $job['id'] );
		$target = Files::path( $dir . '/output', $path );
		if ( ! Files::mkdir( dirname( $target ) ) ) {
			throw new \RuntimeException( 'Cannot create content directory.' );
		}
		$stream = fopen( $target . '.tmp', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream required for bounded output or advisory locking; WP_Filesystem has no equivalent.
		if ( ! $stream ) {
			throw new \RuntimeException( 'Cannot write content table.' );
		}
		try {
			self::write_stream( $stream, "{\n" );
			$first = true;
			foreach ( $rows as $key => $row ) {
				$json = trim( Files::read( $dir, 'rows/' . hash( 'sha256', $path ) . '/' . $row . '.json' ) );
				self::write_stream( $stream, ( $first ? '' : ",\n" ) . '  ' . wp_json_encode( (string) $key ) . ': ' . str_replace( "\n", "\n  ", $json ) );
				$first = false;
			}
			self::write_stream( $stream, "\n}\n" );
		} finally {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream required for bounded output or advisory locking; WP_Filesystem has no equivalent.
		}
		if ( ! Files::fs()->move( $target . '.tmp', $target, true ) ) {
			throw new \RuntimeException( 'Cannot finalize content table.' );
		}
		Files::fs()->chmod( $target, 0600 );
		$job['files'][ $path ] = array( 'sha256' => hash_file( 'sha256', $target ), 'bytes' => filesize( $target ) );
	}
}
