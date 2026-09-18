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

	public static function model( &$job, $id, $kind, $domain, $name, $fields, $title = 'title', $i18n = null ) {
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
		// A model whose rows are not per-language content — shared reference data
		// like authors, terms, media and menu items, none of which this plugin
		// sources a translation for — declares `i18n` false on its own rather
		// than inheriting the job's, or an i18n site's own validator demands a
		// same-language copy of every one of them that does not exist.
		$job['models'][ $id ] = array( 'id' => $id, 'kind' => $kind, 'domain' => $domain, 'name' => $name, 'i18n' => $i18n ?? $job['i18n'], 'title_field' => $title );
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

	/** Every locale the site's multilingual plugin knows about, normalized the same way `Jobs::create()` derives `$job['i18n']`. */
	private static function site_locales( $job ) {
		return array_values( array_unique( array_map( array( Source::class, 'locale' ), (array) $job['inventory']['languages'] ) ) );
	}

	/** Locales the site has but this specific translation group does not. */
	private static function missing_locales( $job, $present ) {
		return array_values( array_diff( self::site_locales( $job ), $present ) );
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
		$author = self::author( $job, get_post( $p['id'] )->post_author, $a['locale'] );
		if ( $author ) {
			$fields['author'] = array( 'type' => 'relation', 'model' => $author['model'] );
			$data['author'] = $author['id'];
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
		// One pair per translation group (`RawLanguagePair`'s own contract), not
		// one per post: every member of the group carries the identical map, so
		// only the canonical member writes it. A locale the site has but this
		// group does not is named, not hidden behind a copy of another
		// locale's text pretending to be a translation.
		if ( Source::canonical( $record['translations'] ) === $p['id'] ) {
			$pair = array( 'post' => $p['id'], 'translations' => $record['translations'] );
			$missing = self::missing_locales( $job, array_keys( $record['translations'] ) );
			if ( $missing ) {
				$pair['missing_translations'] = $missing;
			}
			self::row( $job, 'bridge/language-pairs.json', $p['id'], $pair );
		}
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

	/** The wp-authors collection is shared by post authorship and ACF user fields alike. */
	public static function author( &$job, $user_id, $locale ) {
		$author = get_userdata( $user_id );
		if ( ! $author ) {
			return null;
		}
		$ref = substr( hash( 'sha256', 'author:' . $author->ID ), 0, 12 );
		self::model( $job, 'wp-authors', 'collection', 'blog', 'Authors', array( 'name' => array( 'type' => 'string' ), 'wp_id' => array( 'type' => 'integer' ) ), 'name', false );
		self::entry( $job, 'wp-authors', $locale, $ref, array( 'name' => $author->display_name, 'wp_id' => (int) $author->ID ) );
		self::row( $job, 'bridge/raw-authors.json', $author->ID, array( 'id' => (int) $author->ID, 'login' => $author->user_login, 'display_name' => $author->display_name ) );
		return array( 'model' => 'wp-authors', 'id' => $ref );
	}

	/**
	 * Raw evidence for one term, independent of whether it becomes a model.
	 * `RawTerm`'s own contract has no "public taxonomy only" carve-out — a
	 * WXR export of the same site includes every taxonomy, bookkeeping ones
	 * included, and Bridge's raw completeness claim has to match it. Content
	 * modelling is a separate, narrower decision made by the caller.
	 */
	public static function term_raw( &$job, $term ) {
		$parent = $term->parent ? get_term( $term->parent, $term->taxonomy ) : null;
		// Term meta travels with the raw term; secret-like keys and values never do.
		$meta = array();
		$excluded = array();
		foreach ( get_term_meta( $term->term_id ) as $key => $values ) {
			if ( ! Policy::sensitive( $key ) ) {
				$value = 1 === count( (array) $values ) ? reset( $values ) : array_values( (array) $values );
				$meta[ $key ] = Policy::clean( is_string( $value ) && is_serialized( $value ) ? unserialize( $value, array( 'allowed_classes' => false, 'max_depth' => 32 ) ) : $value, $excluded, 'term/' . $term->term_id . '/' . $key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Class instantiation is explicitly disabled.
			}
		}
		ksort( $meta );
		self::row( $job, 'bridge/raw-terms.json', $term->term_id, array( 'id' => (int) $term->term_id, 'taxonomy' => $term->taxonomy, 'slug' => $term->slug, 'name' => $term->name, 'description' => $term->description, 'parent' => $parent && ! is_wp_error( $parent ) ? $parent->slug : null, 'parent_resolved' => ! $term->parent || ( $parent && ! is_wp_error( $parent ) ), 'meta' => $meta ?: (object) array() ) );
	}

	public static function term( &$job, $term, $locale ) {
		$mid = 'wp-tax-' . str_replace( '_', '-', sanitize_title( $term->taxonomy ) );
		$id = substr( hash( 'sha256', 'term:' . $term->term_id ), 0, 12 );
		self::model( $job, $mid, 'collection', 'site', $term->taxonomy, array( 'name' => array( 'type' => 'string' ), 'description' => array( 'type' => 'richtext' ), 'wp_id' => array( 'type' => 'integer' ), 'source_slug' => array( 'type' => 'string' ) ), 'name', false );
		self::entry( $job, $mid, $locale, $id, array( 'name' => $term->name, 'description' => $term->description, 'wp_id' => (int) $term->term_id, 'source_slug' => $term->slug ) );
		self::term_raw( $job, $term );
		return array( 'model' => $mid, 'id' => $id );
	}

	/**
	 * Menu items become one collection. A target can be a post, a term, an
	 * archive or a plain URL — heterogeneous across items of the very same
	 * field — so it is carried as descriptive columns (kind/post_type/
	 * taxonomy/slug/resolved) rather than forced into a single fixed relation
	 * a Contentrain field cannot represent across more than one target model.
	 * `parent` is the one relation here: every parent is another row of this
	 * same collection, never a different model.
	 */
	public static function menus( &$job, $menus, $locations ) {
		self::model( $job, 'wp-menu-items', 'collection', 'site', 'Navigation links', array(
			'title' => array( 'type' => 'string' ),
			'url' => array( 'type' => 'url' ),
			'position' => array( 'type' => 'integer' ),
			'menu' => array( 'type' => 'string' ),
			'location' => array( 'type' => 'string' ),
			'target_kind' => array( 'type' => 'string' ),
			'target_post_type' => array( 'type' => 'string' ),
			'target_taxonomy' => array( 'type' => 'string' ),
			'target_slug' => array( 'type' => 'string' ),
			'target_resolved' => array( 'type' => 'boolean' ),
			'window_target' => array( 'type' => 'string' ),
			'classes' => array( 'type' => 'text' ),
			'parent' => array( 'type' => 'relation', 'model' => 'wp-menu-items' ),
		), 'title', false );
		foreach ( $menus as $menu ) {
			$menu_locations = array();
			foreach ( (array) $locations as $slug => $assigned ) {
				if ( (int) $assigned === (int) $menu['id'] ) {
					$menu_locations[] = $slug;
				}
			}
			foreach ( $menu['items'] as $item ) {
				$target = $item['target'];
				$data = array(
					'title' => $item['title'],
					'position' => (int) $item['order'],
					'menu' => $menu['name'],
					'target_kind' => $target['kind'],
					'target_resolved' => (bool) $target['resolved'],
				);
				// WordPress itself only ever resolves a link for a post/term whose
				// target still exists; one that never resolved carries no URL to
				// fall back to, not an empty string a `url` field would reject.
				if ( ! empty( $item['url'] ) ) {
					$data['url'] = $item['url'];
				}
				if ( $menu_locations ) {
					$data['location'] = implode( ',', $menu_locations );
				}
				if ( ! empty( $target['post_type'] ) ) {
					$data['target_post_type'] = $target['post_type'];
				}
				if ( ! empty( $target['taxonomy'] ) ) {
					$data['target_taxonomy'] = $target['taxonomy'];
				}
				if ( ! empty( $target['slug'] ) ) {
					$data['target_slug'] = $target['slug'];
				}
				if ( ! empty( $item['target_attr'] ) ) {
					$data['window_target'] = $item['target_attr'];
				}
				if ( ! empty( $item['classes'] ) ) {
					$data['classes'] = implode( ' ', $item['classes'] );
				}
				if ( ! $target['resolved'] ) {
					Jobs::warning( $job, array( 'source' => 'menu-item/' . $item['id'], 'reason' => 'menu-target-not-in-document: url fallback used' ) );
				}
				if ( $item['parent'] ) {
					if ( $item['parent_unresolved'] ) {
						Jobs::warning( $job, array( 'source' => 'menu-item/' . $item['id'], 'reason' => 'menu-parent-not-in-document' ) );
					} else {
						$data['parent'] = substr( hash( 'sha256', 'menu:' . $item['parent'] ), 0, 12 );
					}
				}
				self::entry( $job, 'wp-menu-items', $job['default_locale'], substr( hash( 'sha256', 'menu:' . $item['id'] ), 0, 12 ), $data );
			}
		}
	}

	/**
	 * One Options Page becomes one singleton, its fields whatever the site
	 * actually configured — arbitrary, unlike a post's fixed title/body. A
	 * `page_title` field is always present so the model always has a valid
	 * title field regardless of what those configured fields are named; ACF
	 * fields are prefixed the same way a post's own are, so a field legitimately
	 * named `page_title` cannot collide with it.
	 */
	public static function options_page( &$job, $page ) {
		$post_id = $page['post_id'] ?: 'options';
		$slug = sanitize_title( $page['menu_slug'] ?? $post_id );
		$excluded = array();
		list( $raw, $schema ) = Source::acf_fields_for_options_page( $post_id, $page['menu_slug'] ?? $slug, 'acf-options/' . $slug, $excluded );
		foreach ( $excluded as $warning ) {
			Jobs::warning( $job, $warning );
		}
		$schema = Policy::clean( $schema, $excluded, 'acf-options-schema/' . $slug );
		$mid = 'acf-options-' . $slug;
		$fields = array( 'page_title' => array( 'type' => 'string', 'required' => true ) );
		$data = array( 'page_title' => $page['page_title'] ?: $page['menu_slug'] ?: $slug );
		foreach ( $raw as $key => $acf ) {
			$name = 'acf_' . str_replace( '-', '_', sanitize_key( $key ) );
			$source = 'acf-options/' . $slug . '/' . $key;
			// An Options Page's own singleton is `i18n: false` (see below); a
			// repeater/group/flexible_content field belonging to it must create
			// its own collection model the same way, or the validator demands a
			// same-language copy this export never writes for it either.
			$modelled = isset( $schema[ $key ] ) ? Acf::field( $job, $schema[ $key ], $acf['value'], $job['default_locale'], $source, false ) : null;
			if ( null === $modelled ) {
				Jobs::warning( $job, array( 'source' => $source, 'reason' => 'acf-shape-not-modelled: exported as structured values' ) );
				$modelled = self::value( $job, $acf['value'], $job['default_locale'], $source );
			}
			list( $field, $content ) = $modelled;
			if ( null === $field ) {
				continue;
			}
			$fields[ $name ] = $field;
			$data[ $name ] = $content;
		}
		// ACF/SCF's own Options Page storage has no per-language copy — one
		// `options_{name}` (or `{post_id}_{name}`) row per field, not one per
		// locale — so this model declares `i18n` false rather than inheriting
		// an i18n site's own, which would demand a same-language copy that
		// storage structurally cannot hold. A third-party plugin CAN add one
		// (WPML + ACFML; the free "ACF Options for Polylang"), and this plugin
		// does not read it — named below rather than silently dropped.
		self::model( $job, $mid, 'singleton', 'site', $page['page_title'] ?: $page['menu_slug'] ?: $slug, $fields, 'page_title', false );
		self::entry( $job, $mid, $job['default_locale'], '', $data );
		if ( has_filter( 'wpml_default_language' ) && ( function_exists( 'acfml' ) || class_exists( 'ACFML' ) ) ) {
			Jobs::warning( $job, array( 'source' => 'acf-options/' . $slug, 'reason' => 'acf-options-translation-plugin-detected: WPML + ACFML can translate this Options Page; only its default-language values are exported' ) );
		} elseif ( defined( 'BEA_ACF_OPTIONS_FOR_POLYLANG_VERSION' ) ) {
			Jobs::warning( $job, array( 'source' => 'acf-options/' . $slug, 'reason' => 'acf-options-translation-plugin-detected: ACF Options for Polylang can translate this Options Page; only its default-language values are exported' ) );
		}
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
