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

	/**
	 * ACF field type → Contentrain field type. Absent means "not a scalar we can map".
	 * `link` is absent on purpose: it is {url, title, target}, and casting it to a
	 * URL inside a group, repeater or flexible row lost the label without a word.
	 * A top-level link is modelled in `field()`; a row holding one falls back.
	 */
	const SCALARS = array(
		'text' => 'string',
		'textarea' => 'text',
		'wysiwyg' => 'richtext',
		'email' => 'email',
		'url' => 'url',
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

	/**
	 * ACF types that are one value with parts, or a list of them: written into
	 * the entry as an `object` or `array` field (the same table as
	 * `@contentrain/wp-import`'s ACF reader), not as models of their own.
	 */
	const STRUCTURED = array( 'group', 'repeater', 'flexible_content', 'link', 'google_map', 'checkbox', 'icon_picker' );

	/** Containers (group, repeater, flexible content) nest at most this deep: Contentrain's own limit. */
	const MAX_DEPTH = 2;

	const LINK_FIELDS = array( 'url' => array( 'type' => 'url' ), 'title' => array( 'type' => 'string' ), 'target' => array( 'type' => 'string' ) );

	const MAP_FIELDS = array( 'address' => array( 'type' => 'string' ), 'lat' => array( 'type' => 'decimal' ), 'lng' => array( 'type' => 'decimal' ), 'zoom' => array( 'type' => 'integer' ) );

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
		if ( 'page_link' === $type && ! empty( $field['multiple'] ) ) {
			return null; // Several addresses are not one URL; keep them through the structured fallback.
		}
		if ( ! isset( self::SCALARS[ $type ] ) ) {
			return null;
		}
		$definition = array( 'type' => self::SCALARS[ $type ] );
		// A page_link's target can be unpublished at export time; its value is then left out, so it cannot be required.
		if ( ! empty( $field['required'] ) && 'page_link' !== $type ) {
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
				} elseif ( is_numeric( $value ) ) {
					// Only a page_link stores a number in a URL field: the linked post's ID.
					$value = self::page_address( (int) $value );
				}
				return is_string( $value ) && '' !== $value ? $value : null;
			default:
				return is_scalar( $value ) ? (string) $value : null;
		}
	}

	/**
	 * A page_link's post ID → that post's public address. A draft, private,
	 * password-protected or trashed target has no public address to give, and
	 * resolving it would leak one; it stays unresolved (see `unresolved_page_link()`).
	 */
	private static function page_address( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_password || ! in_array( $post->post_status, array( 'publish', 'inherit' ), true ) ) {
			return null;
		}
		// An attachment inherits its parent's status, and its address carries the parent's slug.
		if ( 'inherit' === $post->post_status && $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( ! $parent || $parent->post_password || 'publish' !== $parent->post_status ) {
				return null;
			}
		}
		return get_permalink( $post ) ?: null;
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
	 * model, so the caller can fall back and say so. `$i18n` names whether the
	 * model this field belongs to is itself per-locale content: null inherits
	 * the job's own (a post's ACF fields), `false` forces a single-copy
	 * collection (an Options Page's, which only ever gets one locale's worth
	 * of entries — see `Models::options_page()`) so the validator does not
	 * demand a same-language copy this export never writes.
	 *
	 * `$inline` says the owning entry is JSON (a collection or singleton), where
	 * a group, repeater, flexible content, link, map or multi-choice field is
	 * written in place as an `object`/`array` (see `inline()`). A document's
	 * frontmatter holds no nested values, so there the models below remain.
	 */
	public static function field( &$job, $schema, $value, $locale, $source, $i18n = null, $inline = false ) {
		$type = $schema['type'] ?? '';
		if ( in_array( $type, self::EXCLUDED, true ) ) {
			return array( null, null );
		}
		if ( $inline && self::structured( $schema ) ) {
			return self::inline( $job, $schema, $value, $source );
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
		if ( 'link' === $type ) {
			// An empty link has nothing to lose. A link inside a row is not a
			// scalar either (see SCALARS): its label and target would be dropped.
			return null === $value || '' === $value || array() === $value ? array( null, null ) : null;
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
			return self::flexible( $job, $schema, $value, $locale, $source, $i18n );
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
					} elseif ( null !== $raw && '' !== $raw && ! self::unresolved_page_link( $job, $definition, $raw, $source . '/' . $index . '/' . $key ) ) {
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
			Models::model( $job, $model, 'collection', 'site', $name, $fields, $shape['title'], $i18n );
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
		if ( null === $cast && self::unresolved_page_link( $job, $definition, $value, $source ) ) {
			return array( null, null );
		}
		if ( null !== $cast && ! self::chosen( $job, $definition, $cast, $source ) ) {
			return array( null, null );
		}
		return null === $cast ? ( null === $value || '' === $value ? array( null, null ) : null ) : array( $definition, $cast );
	}

	/** Whether a field is one of the STRUCTURED shapes (a multi-select counts; a group-display clone is a group). */
	private static function structured( $field ) {
		$type = $field['type'] ?? '';
		return in_array( $type, self::STRUCTURED, true )
			|| ( 'select' === $type && ! empty( $field['multiple'] ) )
			|| ( 'clone' === $type && 'group' === ( $field['display'] ?? '' ) );
	}

	/**
	 * A structured field written in place. Returns null (the caller's structured
	 * fallback) when the schema holds something with no value type — a reference,
	 * a third-party field type, nesting past MAX_DEPTH — or a non-empty value
	 * that cannot be converted; never a partial value.
	 */
	private static function inline( &$job, $schema, $value, $source ) {
		if ( self::blank( $value ) ) {
			return array( null, null );
		}
		$definition = self::definition( $schema );
		if ( ! $definition ) {
			return null;
		}
		try {
			$cast = self::value( $job, $schema, $definition, $value, $source );
		} catch ( \UnexpectedValueException $e ) {
			return null;
		}
		return null === $cast ? array( null, null ) : array( $definition, $cast );
	}

	/**
	 * The Contentrain definition of an ACF field from its schema alone, so every
	 * entry of a model gets the same one: `object` for a group, link or map,
	 * `array` of `object` for a repeater, `array` of `object` with a `layout`
	 * select for flexible content, `array` of `select` for a checkbox or
	 * multi-select. Null when any part has no value type. Nested fields are
	 * never `required`: a row's empty sub-field is left out, not invented.
	 */
	public static function definition( $field, $depth = 1 ) {
		$type = $field['type'] ?? '';
		if ( 'clone' === $type && 'group' === ( $field['display'] ?? '' ) ) {
			$type = 'group';
		}
		if ( 'link' === $type ) {
			return array( 'type' => 'object', 'fields' => self::LINK_FIELDS );
		}
		if ( 'google_map' === $type ) {
			return array( 'type' => 'object', 'fields' => self::MAP_FIELDS );
		}
		if ( 'icon_picker' === $type ) {
			return array( 'type' => 'icon' );
		}
		if ( 'checkbox' === $type || ( 'select' === $type && ! empty( $field['multiple'] ) ) ) {
			$options = self::options( $field );
			return array( 'type' => 'array', 'items' => $options ? array( 'type' => 'select', 'options' => $options ) : 'string' );
		}
		if ( in_array( $type, array( 'group', 'repeater', 'flexible_content' ), true ) ) {
			if ( $depth > self::MAX_DEPTH ) {
				return null;
			}
			if ( 'flexible_content' === $type ) {
				return self::layouts_definition( $field, $depth );
			}
			$fields = self::fields_definition( $field['sub_fields'] ?? array(), $depth );
			if ( ! $fields ) {
				return null;
			}
			$object = array( 'type' => 'object', 'fields' => $fields );
			return 'group' === $type ? $object : array( 'type' => 'array', 'items' => $object );
		}
		$scalar = self::scalar( $field );
		if ( $scalar ) {
			unset( $scalar['required'] );
		}
		return $scalar;
	}

	/** Definitions of a container's sub-fields by name; an empty array for none, null when one has no value type. */
	private static function fields_definition( $sub_fields, $depth ) {
		$fields = array();
		foreach ( (array) $sub_fields as $sub ) {
			$name = sanitize_key( $sub['name'] ?? '' );
			$type = $sub['type'] ?? '';
			// Secrets never reach here as values (`Source::acf_value()`), so they are not fields either.
			if ( '' === $name || in_array( $type, self::LAYOUT, true ) || in_array( $type, self::EXCLUDED, true ) || Policy::secret_name( $name ) ) {
				continue;
			}
			$definition = self::definition( $sub, $depth + 1 );
			if ( ! $definition ) {
				return null;
			}
			$fields[ $name ] = $definition;
		}
		return $fields;
	}

	/**
	 * Flexible content: one `array` of rows in their order, each row's `layout`
	 * naming the layout that made it (options: every layout the schema has,
	 * sorted) and the rest the union of the layouts' fields. A name two layouts
	 * type differently has no single honest shape.
	 */
	private static function layouts_definition( $field, $depth ) {
		$names = array();
		$fields = array();
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			$name = (string) ( $layout['name'] ?? '' );
			$own = self::fields_definition( $layout['sub_fields'] ?? array(), $depth );
			if ( '' === $name || null === $own || isset( $own['layout'] ) ) {
				return null;
			}
			$names[] = $name;
			foreach ( $own as $key => $definition ) {
				if ( isset( $fields[ $key ] ) && $fields[ $key ] !== $definition ) {
					return null;
				}
				$fields[ $key ] = $definition;
			}
		}
		if ( ! $names ) {
			return null;
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		return array( 'type' => 'array', 'items' => array( 'type' => 'object', 'fields' => array( 'layout' => array( 'type' => 'select', 'options' => $names, 'required' => true ) ) + $fields ) );
	}

	/** A select/checkbox's option values, as stored. */
	private static function options( $field ) {
		return array_values( array_map( 'strval', array_keys( (array) ( $field['choices'] ?? array() ) ) ) );
	}

	private static function blank( $value ) {
		return null === $value || '' === $value || array() === $value;
	}

	/**
	 * A structured value converted to its definition; null when there is nothing
	 * to store. Throws when a non-empty value cannot be converted, so the field
	 * falls back whole instead of losing part of itself.
	 */
	private static function value( &$job, $field, $definition, $raw, $source ) {
		if ( self::blank( $raw ) ) {
			return null;
		}
		$type = $field['type'] ?? '';
		switch ( $definition['type'] ) {
			case 'object':
				if ( ! is_array( $raw ) ) {
					throw new \UnexpectedValueException( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal control flow, never shown.
				}
				if ( 'link' === $type ) {
					return self::compact( array( 'url' => self::cast( array( 'type' => 'url' ), $raw['url'] ?? null ), 'title' => self::text( $raw['title'] ?? null ), 'target' => self::text( $raw['target'] ?? null ) ) );
				}
				if ( 'google_map' === $type ) {
					return self::compact(
						array(
							'address' => self::text( $raw['address'] ?? null ),
							'lat' => is_numeric( $raw['lat'] ?? null ) ? (float) $raw['lat'] : null,
							'lng' => is_numeric( $raw['lng'] ?? null ) ? (float) $raw['lng'] : null,
							'zoom' => is_numeric( $raw['zoom'] ?? null ) ? (int) $raw['zoom'] : null,
						)
					);
				}
				return self::row( $job, $field['sub_fields'] ?? array(), $definition['fields'], $raw, $source );
			case 'array':
				if ( 'repeater' === $type || 'flexible_content' === $type ) {
					if ( ! is_array( $raw ) && ! $raw ) {
						return null; // An unformatted list with no rows is stored as its row count: 0.
					}
					if ( ! is_array( $raw ) ) {
						throw new \UnexpectedValueException( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal control flow, never shown.
					}
					$rows = array();
					foreach ( array_values( $raw ) as $index => $item ) {
						if ( ! is_array( $item ) ) {
							throw new \UnexpectedValueException( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal control flow, never shown.
						}
						$at = $source . '/' . $index;
						if ( 'repeater' === $type ) {
							$row = self::row( $job, $field['sub_fields'] ?? array(), $definition['items']['fields'], $item, $at );
							if ( null !== $row ) {
								$rows[] = $row;
							}
							continue;
						}
						$layout = self::layout( $field, (string) ( $item['acf_fc_layout'] ?? '' ) );
						if ( ! $layout ) {
							throw new \UnexpectedValueException( $at ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal control flow, never shown.
						}
						$rows[] = array( 'layout' => $layout['name'] ) + ( self::row( $job, $layout['sub_fields'] ?? array(), $definition['items']['fields'], $item, $at ) ?? array() );
					}
					return $rows ?: null;
				}
				$item = is_array( $definition['items'] ) ? $definition['items'] : array( 'type' => $definition['items'] );
				$out = array();
				foreach ( is_array( $raw ) ? array_values( $raw ) : array( $raw ) as $choice ) {
					if ( ! is_scalar( $choice ) ) {
						throw new \UnexpectedValueException( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal control flow, never shown.
					}
					if ( '' !== (string) $choice && self::chosen( $job, $item, (string) $choice, $source ) ) {
						$out[] = (string) $choice;
					}
				}
				return $out ?: null;
			case 'icon':
				$icon = is_array( $raw ) ? ( $raw['value'] ?? null ) : $raw;
				if ( ! is_string( $icon ) ) {
					throw new \UnexpectedValueException( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal control flow, never shown.
				}
				return '' === $icon ? null : $icon;
			default:
				$cast = self::cast( $definition, $raw );
				if ( null !== $cast ) {
					return self::chosen( $job, $definition, $cast, $source ) ? $cast : null;
				}
				if ( self::unresolved_page_link( $job, $definition, $raw, $source ) ) {
					return null;
				}
				throw new \UnexpectedValueException( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal control flow, never shown.
		}
	}

	/** One group/repeater/layout row: sub-fields read by name, else by field key (a group's raw value is keyed by key). */
	private static function row( &$job, $sub_fields, $fields, $raw, $source ) {
		$out = array();
		foreach ( (array) $sub_fields as $sub ) {
			$name = sanitize_key( $sub['name'] ?? '' );
			if ( ! isset( $fields[ $name ] ) ) {
				continue;
			}
			$key = $sub['key'] ?? $name;
			$item = array_key_exists( $name, $raw ) ? $raw[ $name ] : ( $raw[ $key ] ?? null );
			$value = self::value( $job, $sub, $fields[ $name ], $item, $source . '/' . $name );
			if ( null !== $value ) {
				$out[ $name ] = $value;
			}
		}
		return $out ?: null;
	}

	/** A flexible content field's layout by the name a row stores. */
	private static function layout( $field, $name ) {
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			if ( '' !== $name && ( $layout['name'] ?? '' ) === $name ) {
				return $layout;
			}
		}
		return null;
	}

	private static function text( $value ) {
		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : null;
	}

	/** An object without its empty parts; null when nothing is left. */
	private static function compact( $object ) {
		$out = array_filter( $object, static function ( $v ) { return null !== $v; } );
		return $out ?: null;
	}

	/**
	 * Whether a select value is one of its options. A stored value whose choice
	 * the site has since removed would fail validation and stop the whole export;
	 * it is left out and reported instead.
	 */
	private static function chosen( &$job, $definition, $value, $source ) {
		if ( 'select' !== $definition['type'] || in_array( (string) $value, $definition['options'] ?? array(), true ) ) {
			return true;
		}
		Jobs::warning( $job, array( 'source' => $source, 'reason' => 'acf-choice-not-in-options' ) );
		return false;
	}

	/**
	 * A page_link (a post ID in a URL field) whose target has no public address.
	 * Its value is left out with a warning instead of the field falling back:
	 * a fallback would give the same field a different type in another entry,
	 * and a model with inconsistent field types fails the whole export.
	 */
	private static function unresolved_page_link( &$job, $definition, $raw, $source ) {
		if ( 'url' !== $definition['type'] || ! is_numeric( $raw ) ) {
			return false;
		}
		Jobs::warning( $job, array( 'source' => $source, 'reason' => 'acf-page-link-target-not-public' ) );
		return true;
	}

	/**
	 * A page_link (a post ID in a URL field) whose target has no public address.
	 * Its value is left out with a warning instead of the field falling back:
	 * a fallback would give the same field a different type in another entry,
	 * and a model with inconsistent field types fails the whole export.
	 */
	private static function unresolved_page_link( &$job, $definition, $raw, $source ) {
		if ( 'url' !== $definition['type'] || ! is_numeric( $raw ) ) {
			return false;
		}
		Jobs::warning( $job, array( 'source' => $source, 'reason' => 'acf-page-link-target-not-public' ) );
		return true;
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
	private static function flexible( &$job, $schema, $value, $locale, $source, $i18n = null ) {
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
				} elseif ( null !== $raw && '' !== $raw && ! self::unresolved_page_link( $job, $definition, $raw, $source . '/' . $index . '/' . $key ) ) {
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
		Models::model( $job, $model, 'collection', 'site', $name, $merged, $title, $i18n );
		foreach ( $prepared as $id => $data ) {
			Models::entry( $job, $model, $locale, $id, $data );
		}
		return array( array( 'type' => 'relations', 'model' => $model ), $ids );
	}
}
