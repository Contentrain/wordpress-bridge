<?php
/** ACF field groups become real Contentrain models. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * A repeater of testimonials is a list of records with a person and a quote —
 * not an anonymous bag of name/value rows. ACF already knows that: the field
 * group carries the sub-field names, labels and types. This turns that schema
 * into models an editor can read and a developer can query, and falls back to
 * the generic structured-value model only for shapes it cannot name honestly.
 */
final class Acf {

	/** ACF field type → Contentrain field type. Absent means "not a scalar we can map". */
	const SCALARS = array(
		'text' => 'string',
		'textarea' => 'text',
		'wysiwyg' => 'richtext',
		'email' => 'email',
		'url' => 'url',
		'link' => 'url',
		'page_link' => 'url',
		'oembed' => 'url',
		'number' => 'number',
		'range' => 'number',
		'true_false' => 'boolean',
		'date_picker' => 'date',
		'date_time_picker' => 'datetime',
		'time_picker' => 'string',
		'color_picker' => 'color',
		'image' => 'image',
		'file' => 'file',
	);

	/** Layout-only field types carry no content. */
	const LAYOUT = array( 'tab', 'accordion', 'message' );

	/**
	 * Types whose value is a secret; never content. A `user` field's raw value
	 * is just an ID (format_value=false never resolves it to an email or other
	 * profile data), so it is modelled as an author relation instead — the same
	 * name/wp_id a post's own author already gets, not a new disclosure.
	 */
	const EXCLUDED = array( 'password' );

	/** Contentrain types that may name a model's title_field. */
	const TITLE_TYPES = array( 'string', 'text', 'slug', 'email', 'url', 'code', 'markdown', 'richtext' );

	public static function model_id( $name, $key ) {
		$base = 'acf-' . trim( str_replace( '_', '-', sanitize_title( $name ) ), '-' );
		return ( 'acf-' === $base ? 'acf-field' : $base ) . '-' . substr( hash( 'sha256', $key ), 0, 8 );
	}

	/** Field definition for one ACF sub-field, or null when it is not a scalar. */
	public static function scalar( $field ) {
		$type = $field['type'] ?? '';
		if ( in_array( $type, self::LAYOUT, true ) || in_array( $type, self::EXCLUDED, true ) ) {
			return null;
		}
		if ( in_array( $type, array( 'select', 'radio', 'button_group' ), true ) ) {
			if ( ! empty( $field['multiple'] ) ) {
				return null; // Keep every selected option through the structured fallback.
			}
			$options = array_values( array_map( 'strval', array_keys( (array) ( $field['choices'] ?? array() ) ) ) );
			return $options ? array( 'type' => 'select', 'options' => $options ) : array( 'type' => 'string' );
		}
		if ( ! isset( self::SCALARS[ $type ] ) ) {
			return null;
		}
		$definition = array( 'type' => self::SCALARS[ $type ] );
		if ( ! empty( $field['required'] ) ) {
			$definition['required'] = true;
		}
		return $definition;
	}

	/** Coerce a stored ACF value into the shape its declared type promises. */
	public static function cast( $definition, $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		switch ( $definition['type'] ) {
			case 'date':
				$date = \DateTimeImmutable::createFromFormat( '!Ymd', (string) $value );
				if ( $date && $date->format( 'Ymd' ) === (string) $value ) {
					return $date->format( 'Y-m-d' );
				}
				return is_string( $value ) ? $value : null;
			case 'datetime':
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $value, wp_timezone() );
				return $date && $date->format( 'Y-m-d H:i:s' ) === (string) $value ? $date->format( 'c' ) : ( is_string( $value ) ? $value : null );
			case 'boolean':
				return (bool) $value;
			case 'number':
				return is_numeric( $value ) ? $value + 0 : null;
			case 'image':
			case 'file':
				if ( is_array( $value ) ) {
					$value = $value['url'] ?? null;
				} elseif ( is_numeric( $value ) ) {
					$value = wp_get_attachment_url( (int) $value ) ?: null;
				}
				return is_string( $value ) ? $value : null;
			case 'url':
				if ( is_array( $value ) ) {
					$value = $value['url'] ?? null;
				}
				return is_string( $value ) && '' !== $value ? $value : null;
			default:
				return is_scalar( $value ) ? (string) $value : null;
		}
	}

	/**
	 * Fields of a container's sub-schema, and the field that can title a row.
	 * Returns null when nothing in it can serve as a title: a collection whose
	 * rows have no readable label is worse than an honest structured fallback.
	 */
	public static function shape( $sub_fields ) {
		$fields = array();
		$keys = array();
		$title = null;
		$skipped = array();
		foreach ( (array) $sub_fields as $sub ) {
			$name = sanitize_key( $sub['name'] ?? '' );
			if ( '' === $name || in_array( $sub['type'] ?? '', self::LAYOUT, true ) ) {
				continue;
			}
			$definition = self::scalar( $sub );
			if ( ! $definition ) {
				$skipped[] = $name;
				continue;
			}
			$fields[ $name ] = $definition;
			// An unformatted repeater row is keyed by sub-field name, a group by
			// sub-field key. Carry both so a value is never missed for it.
			$keys[ $name ] = $sub['key'] ?? $name;
			if ( ! $title && in_array( $definition['type'], self::TITLE_TYPES, true ) ) {
				$title = $name;
			}
		}
		return $title ? array( 'fields' => $fields, 'keys' => $keys, 'title' => $title, 'skipped' => $skipped ) : null;
	}

	/** Reference types resolved against content that lives in its own model. */
	const REFERENCES = array( 'relationship', 'post_object', 'taxonomy', 'user', 'gallery' );

	/**
	 * One ACF field → a Contentrain field definition plus its value, creating a
	 * model for a group or repeater. Returns null when the shape has no honest
	 * model, so the caller can fall back and say so.
	 */
	public static function field( &$job, $schema, $value, $locale, $source ) {
		$type = $schema['type'] ?? '';
		if ( in_array( $type, self::EXCLUDED, true ) ) {
			return array( null, null );
		}
		// A clone field's own `display` decides its shape, not its type. Seamless
		// never reaches here as `clone` at all — ACF/SCF replace it with the
		// cloned fields directly at the parent's own level, under their own
		// names, so whatever type they really are already gets handled. A
		// group-display clone is indistinguishable from a real `group` field
		// once loaded (same `sub_fields`, a nested value keyed by the cloned
		// fields' own keys — `shape()`'s name-or-key lookup already covers
		// that), so it is treated as one rather than duplicating that logic.
		if ( 'clone' === $type && 'group' === ( $schema['display'] ?? '' ) ) {
			$type = 'group';
			$schema = array( 'type' => 'group', 'key' => $schema['key'] ?? $source, 'name' => $schema['name'] ?? '', 'label' => $schema['label'] ?? '', 'sub_fields' => $schema['sub_fields'] ?? array() );
		}
		if ( 'link' === $type && is_array( $value ) ) {
			// A link's label and target are content too, not just the URL; model
			// it exactly like a two-or-three-field group instead of discarding them.
			$key = $schema['key'] ?? $source;
			$schema = array(
				'type' => 'group',
				'key' => $key,
				'name' => $schema['name'] ?? 'link',
				'label' => $schema['label'] ?? 'Link',
				'sub_fields' => array(
					array( 'key' => $key . '_url', 'name' => 'url', 'type' => 'url' ),
					array( 'key' => $key . '_title', 'name' => 'title', 'type' => 'text' ),
					array( 'key' => $key . '_target', 'name' => 'target', 'type' => 'text' ),
				),
			);
			$type = 'group';
		}
		if ( in_array( $type, self::LAYOUT, true ) ) {
			return array( null, null );
		}
		if ( in_array( $type, self::REFERENCES, true ) ) {
			if ( null === $value || '' === $value || array() === $value ) {
				return array( null, null );
			}
			return self::reference( $job, $schema, $type, $value, $locale, $source );
		}
		if ( 'flexible_content' === $type ) {
			if ( ! is_array( $value ) || ! $value ) {
				return array( null, null );
			}
			return self::flexible( $job, $schema, $value, $locale, $source );
		}
		if ( 'group' === $type || 'repeater' === $type ) {
			$shape = self::shape( $schema['sub_fields'] ?? array() );
			if ( ! $shape || $shape['skipped'] ) {
				return null;
			}
			$model = self::model_id( $schema['name'] ?? '', $schema['key'] ?? $source );
			$name = $schema['label'] ?? $schema['name'] ?? $model;
			$fields = $shape['fields'];
			if ( 'repeater' === $type ) {
				$fields['position'] = array( 'type' => 'integer' );
			}
			$prepared = array();
			$rows = 'repeater' === $type ? ( is_array( $value ) ? array_values( $value ) : array() ) : array( $value );
			$ids = array();
			foreach ( $rows as $index => $row ) {
				if ( ! is_array( $row ) ) {
					return null;
				}
				$data = array();
				foreach ( $shape['fields'] as $key => $definition ) {
					$raw = $row[ $key ] ?? ( $row[ $shape['keys'][ $key ] ] ?? null );
					$cast = self::cast( $definition, $raw );
					if ( null !== $cast ) {
						$data[ $key ] = $cast;
					} elseif ( null !== $raw && '' !== $raw ) {
						return null; // Never silently drop a non-empty value during conversion.
					}
				}
				if ( ! isset( $data[ $shape['title'] ] ) ) {
					// A required title with no value cannot validate; report the row
					// rather than invent a label for it.
					Jobs::warning( $job, array( 'source' => $source . '/' . $index, 'reason' => 'acf-row-has-no-title-value' ) );
					return null;
				}
				if ( 'repeater' === $type ) {
					$data['position'] = (int) $index;
				}
				$id = substr( hash( 'sha256', $source . '/' . $index ), 0, 12 );
				$prepared[ $id ] = $data;
				$ids[] = $id;
			}
			Models::model( $job, $model, 'collection', 'site', $name, $fields, $shape['title'] );
			foreach ( $prepared as $id => $data ) {
				Models::entry( $job, $model, $locale, $id, $data );
			}
			foreach ( $shape['skipped'] as $key ) {
				Jobs::warning( $job, array( 'source' => $source . '/' . $key, 'reason' => 'acf-subfield-type-not-modelled' ) );
			}
			if ( 'group' === $type ) {
				return $ids ? array( array( 'type' => 'relation', 'model' => $model ), $ids[0] ) : array( null, null );
			}
			return array( array( 'type' => 'relations', 'model' => $model ), $ids );
		}
		$definition = self::scalar( $schema );
		if ( ! $definition ) {
			return null;
		}
		$cast = self::cast( $definition, $value );
		return null === $cast ? ( null === $value || '' === $value ? array( null, null ) : null ) : array( $definition, $cast );
	}

	/**
	 * relationship/post_object/taxonomy/user/gallery all point at content that
	 * already has, or will have, its own model — a post, a term, an author, a
	 * media record — so each one becomes a relation instead of an opaque id.
	 * A target outside the export's own scope is reported and skipped rather
	 * than guessed at; a field whose picks span more than one target model has
	 * no single honest shape and falls back whole, not partially.
	 */
	private static function reference( &$job, $schema, $type, $value, $locale, $source ) {
		$multiple = true;
		if ( 'post_object' === $type || 'user' === $type ) {
			$multiple = ! empty( $schema['multiple'] );
		} elseif ( 'taxonomy' === $type ) {
			$multiple = in_array( $schema['field_type'] ?? '', array( 'checkbox', 'multi_select' ), true );
		}
		$ids = $multiple && is_array( $value ) ? array_values( $value ) : array( $value );
		$models = array();
		$refs = array();
		foreach ( $ids as $raw ) {
			$target = null;
			if ( 'relationship' === $type || 'post_object' === $type ) {
				$target = Source::reference( $job, $raw );
			} elseif ( 'taxonomy' === $type ) {
				$target = self::resolve_term( $job, $raw, $schema['taxonomy'] ?? '', $locale );
			} elseif ( 'user' === $type ) {
				$author = Models::author( $job, (int) $raw, $locale );
				$target = $author ? array( $author['model'], $author['id'] ) : null;
			} elseif ( 'gallery' === $type ) {
				$target = self::resolve_media( $job, $raw );
			}
			if ( ! $target ) {
				Jobs::warning( $job, array( 'source' => $source . '/' . $raw, 'reason' => 'acf-relation-target-not-in-scope' ) );
				continue;
			}
			$models[ $target[0] ] = true;
			$refs[] = $target[1];
		}
		if ( ! $refs ) {
			return null; // A non-empty value with nothing resolvable is reported above, not silently dropped.
		}
		if ( count( $models ) > 1 ) {
			Jobs::warning( $job, array( 'source' => $source, 'reason' => 'acf-relation-spans-multiple-models: exported as structured values' ) );
			return null;
		}
		$model = array_key_first( $models );
		return $multiple ? array( array( 'type' => 'relations', 'model' => $model ), $refs ) : array( array( 'type' => 'relation', 'model' => $model ), $refs[0] );
	}

	/** Taxonomy terms always get a model: the term collection is built on demand, exactly like a post's own terms. */
	private static function resolve_term( &$job, $term_id, $taxonomy, $locale ) {
		if ( '' === $taxonomy ) {
			return null;
		}
		$term = get_term( (int) $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		$target = Models::term( $job, $term, $locale );
		return array( $target['model'], $target['id'] );
	}

	/** Media is exported before posts are, so a gallery target either already exists in wp-media or never will. */
	private static function resolve_media( $job, $attachment_id ) {
		if ( ! isset( $job['models']['wp-media'] ) ) {
			return null;
		}
		$entry_id = substr( hash( 'sha256', 'media:' . (int) $attachment_id ), 0, 12 );
		$path = Models::content_path( $job, 'wp-media', $job['default_locale'] );
		if ( ! isset( $job['tables'][ $path ][ $entry_id ] ) ) {
			return null;
		}
		return array( 'wp-media', $entry_id );
	}

	/**
	 * Every layout becomes rows of one collection sharing a merged field set, a
	 * `layout` column naming which one produced each row, and `position`. This
	 * only holds together when every layout can supply the same title field and
	 * no two layouts give the same field name a different type; anything looser
	 * has no single honest shape, so the whole field falls back instead.
	 */
	private static function flexible( &$job, $schema, $value, $locale, $source ) {
		$layouts = $schema['layouts'] ?? array();
		if ( ! $layouts ) {
			return null;
		}
		$merged = array();
		// Two layouts can each name a sub-field `heading` while ACF/SCF still
		// gives each its own field key. A saved row is only ever shaped like the
		// one layout that produced it, so every key ever seen for a name is a
		// safe, unambiguous fallback when the row has no entry under the name
		// itself — exactly the dual lookup `shape()` already does for group and
		// repeater rows, needed here because a real, formatted flexible_content
		// field (unlike ACF Free's unregistered fallback) keys its own raw rows
		// by field key, not by name.
		$keys = array();
		foreach ( $layouts as $layout ) {
			foreach ( (array) ( $layout['sub_fields'] ?? array() ) as $sub ) {
				$name = sanitize_key( $sub['name'] ?? '' );
				if ( '' === $name || in_array( $sub['type'] ?? '', self::LAYOUT, true ) ) {
					continue;
				}
				$definition = self::scalar( $sub );
				if ( ! $definition || ( isset( $merged[ $name ] ) && $merged[ $name ] !== $definition ) ) {
					return null;
				}
				$merged[ $name ] = $definition;
				$keys[ $name ][] = $sub['key'] ?? $name;
			}
		}
		$title = null;
		foreach ( $merged as $name => $definition ) {
			if ( in_array( $definition['type'], self::TITLE_TYPES, true ) ) {
				$title = $name;
				break;
			}
		}
		if ( ! $title ) {
			return null;
		}
		foreach ( $layouts as $layout ) {
			$names = array_map( static function ( $sub ) { return sanitize_key( $sub['name'] ?? '' ); }, (array) ( $layout['sub_fields'] ?? array() ) );
			if ( ! in_array( $title, $names, true ) ) {
				return null; // A layout missing the title field could never satisfy it.
			}
		}
		$merged['layout'] = array( 'type' => 'string', 'required' => true );
		$merged['position'] = array( 'type' => 'integer' );
		$model = self::model_id( $schema['name'] ?? '', $schema['key'] ?? $source );
		$name = $schema['label'] ?? $schema['name'] ?? $model;
		$prepared = array();
		$ids = array();
		foreach ( array_values( $value ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}
			$data = array( 'layout' => (string) ( $row['acf_fc_layout'] ?? '' ), 'position' => (int) $index );
			foreach ( $merged as $key => $definition ) {
				if ( in_array( $key, array( 'layout', 'position' ), true ) ) {
					continue;
				}
				$raw = $row[ $key ] ?? null;
				if ( null === $raw ) {
					foreach ( $keys[ $key ] ?? array() as $alt ) {
						if ( array_key_exists( $alt, $row ) ) {
							$raw = $row[ $alt ];
							break;
						}
					}
				}
				$cast = self::cast( $definition, $raw );
				if ( null !== $cast ) {
					$data[ $key ] = $cast;
				} elseif ( null !== $raw && '' !== $raw ) {
					return null;
				}
			}
			if ( ! isset( $data[ $title ] ) ) {
				Jobs::warning( $job, array( 'source' => $source . '/' . $index, 'reason' => 'acf-row-has-no-title-value' ) );
				return null;
			}
			$id = substr( hash( 'sha256', $source . '/' . $index ), 0, 12 );
			$prepared[ $id ] = $data;
			$ids[] = $id;
		}
		Models::model( $job, $model, 'collection', 'site', $name, $merged, $title );
		foreach ( $prepared as $id => $data ) {
			Models::entry( $job, $model, $locale, $id, $data );
		}
		return array( array( 'type' => 'relations', 'model' => $model ), $ids );
	}
}
