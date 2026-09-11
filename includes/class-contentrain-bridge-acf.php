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

	/** Types whose value is a person or a secret; never content. */
	const EXCLUDED = array( 'password', 'user' );

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
		if ( 'link' === $type && is_array( $value ) ) {
			// Link labels and targets are content too, not just the URL.
			return null;
		}
		if ( in_array( $type, self::LAYOUT, true ) ) {
			return array( null, null );
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
}
