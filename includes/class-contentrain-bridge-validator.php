<?php
/** Fail-closed validation before files are offered for delivery. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class Validator {
	public static function run( $job ) {
		$count = 0;
		foreach ( $job['models'] as $model ) {
			if ( ! preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $model['id'] ) ) {
				throw new \RuntimeException( 'Invalid model identifier: ' . $model['id'] );
			}
			if ( 'dictionary' !== $model['kind'] && ! isset( $model['fields'][ $model['title_field'] ] ) ) {
				throw new \RuntimeException( 'Model has no valid title field.' );
			}
			$root = '.contentrain/content/' . $model['domain'] . '/' . $model['id'] . '/';
			foreach ( array_keys( $job['locales'] ) as $locale ) {
				$path = Models::content_path( $job, $model, $locale );
				if ( isset( $job['tables'][ $path ] ) ) {
					foreach ( $job['tables'][ $path ] as $key => $row ) {
						$value = json_decode( Files::read( Files::dir( $job['id'] ), 'rows/' . hash( 'sha256', $path ) . '/' . $row . '.json' ), true );
						if ( 'dictionary' === $model['kind'] ) {
							if ( ! is_string( $value ) ) {
								throw new \RuntimeException( 'Dictionary contains a non-string value.' );
							}
						} else {
							self::entry( $job, $model, $value, $locale );
						}
						++$count;
					}
				} elseif ( 'singleton' === $model['kind'] && isset( $job['files'][ $path ] ) ) {
					self::entry( $job, $model, json_decode( Files::read( Files::dir( $job['id'] ) . '/output', $path ), true ), $locale );
					++$count;
				}
			}
			if ( 'document' === $model['kind'] ) {
				foreach ( array_keys( $job['files'] ) as $path ) {
					if ( 0 !== strpos( $path, $root ) || '.md' !== substr( $path, -3 ) ) {
						continue;
					}
					$content = Files::read( Files::dir( $job['id'] ) . '/output', $path );
					if ( ! preg_match( '/^---\n(.*?)\n---\n/s', $content, $match ) ) {
						throw new \RuntimeException( 'Document frontmatter is invalid.' );
					}
					// i18n documents are {slug}/{locale}.md; a monolingual one is {slug}.md.
					self::entry( $job, $model, Policy::parse_frontmatter( $match[1] ), $model['i18n'] ? basename( $path, '.md' ) : $job['default_locale'] );
					++$count;
				}
			}
		}
		return array( 'valid' => true, 'entries_checked' => $count, 'models_checked' => count( $job['models'] ), 'scope' => 'generated models, required fields, field types and relation targets; source completeness is separate' );
	}

	private static function entry( $job, $model, $data, $locale ) {
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Content record is not an object.' );
		}
		foreach ( $model['fields'] as $name => $field ) {
			if ( ! array_key_exists( $name, $data ) ) {
				if ( ! empty( $field['required'] ) ) {
					throw new \RuntimeException( 'Missing required field: ' . $model['id'] . '.' . $name );
				}
				continue;
			}
			$value = $data[ $name ];
			$type = $field['type'];
			$valid = true;
			if ( 'integer' === $type ) {
				$valid = is_int( $value );
			} elseif ( 'number' === $type ) {
				$valid = is_int( $value ) || is_float( $value );
			} elseif ( 'boolean' === $type ) {
				$valid = is_bool( $value );
			} elseif ( 'relation' === $type || 'relations' === $type ) {
				$refs = 'relations' === $type ? $value : array( $value );
				$valid = is_array( $refs );
				foreach ( $valid ? $refs : array() as $ref ) {
					if ( ! is_string( $ref ) || ! isset( $job['models'][ $field['model'] ] ) ) {
						$valid = false;
						break;
					}
					$target = $job['models'][ $field['model'] ];
					if ( ! isset( $job['tables'][ Models::content_path( $job, $target, $locale ) ][ $ref ] ) ) {
						$valid = false;
					}
				}
			} else {
				$valid = is_string( $value );
				if ( $valid && 'url' === $type ) {
					$valid = false !== filter_var( $value, FILTER_VALIDATE_URL );
				}
				if ( $valid && 'datetime' === $type ) {
					$valid = false !== strtotime( $value );
				}
			}
			if ( ! $valid ) {
				throw new \RuntimeException( 'Invalid value or missing relation: ' . $model['id'] . '.' . $name . ' (' . $locale . ')' );
			}
		}
	}
}
