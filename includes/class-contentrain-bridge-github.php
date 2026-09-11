<?php
/** Opt-in GitHub delivery; credentials exist only in the current request. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class GitHub {
	public static function request( $token, $method, $path, $body = null ) {
		if ( ! is_string( $token ) || strlen( $token ) < 10 || strlen( $token ) > 512 || preg_match( '/\s/', $token ) ) {
			throw new \RuntimeException( 'Provide a GitHub token with Contents read/write permission for the selected repository.' );
		}
		if ( ! preg_match( '#^/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/#', $path ) && ! preg_match( '#^/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $path ) ) {
			throw new \RuntimeException( 'Invalid GitHub API path.' );
		}
		$args = array( 'method' => $method, 'timeout' => 25, 'redirection' => 0, 'limit_response_size' => 12 * MB_IN_BYTES, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'Content-Type' => 'application/json', 'User-Agent' => 'Contentrain-Bridge/' . CONTENTRAIN_BRIDGE_VERSION ) );
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( 'https://api.github.com' . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'GitHub could not be reached. Retry without starting a new export.' );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			throw new \RuntimeException( 'GitHub rejected the request (HTTP ' . $status . '). Check repository access, branch protection and rate limits.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
		}
		return $data;
	}

	public static function start( $id, $token, $repository ) {
		if ( ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#D', $repository ) ) {
			throw new \RuntimeException( 'Use owner/repository, not a URL.' );
		}
		return Jobs::mutate( $id, static function ( &$job ) use ( $token, $repository ) {
			if ( 'ready' !== $job['phase'] ) {
				throw new \RuntimeException( 'Finalize the export before GitHub delivery.' );
			}
			if ( isset( $job['github'] ) ) {
				if ( $job['github']['repository'] !== $repository ) {
					throw new \RuntimeException( 'This export already has a delivery destination.' );
				}
				return Jobs::summary( $job );
			}
			$prefix = '/repos/' . $repository;
			$repo = self::request( $token, 'GET', $prefix );
			if ( $job['options']['private'] && empty( $repo['private'] ) ) {
				throw new \RuntimeException( 'An export including private/draft content requires a private GitHub repository.' );
			}
			// Empty repositories must first have an initial commit; we never write to the default branch.
			$base = self::request( $token, 'GET', $prefix . '/git/ref/heads/' . rawurlencode( $repo['default_branch'] ) );
			$commit = self::request( $token, 'GET', $prefix . '/git/commits/' . $base['object']['sha'] );
			$tree = self::request( $token, 'GET', $prefix . '/git/trees/' . $commit['tree']['sha'] . '?recursive=1' );
			if ( ! empty( $tree['truncated'] ) ) {
				throw new \RuntimeException( 'Repository tree is too large to check safely. Use a dedicated content repository.' );
			}
			$remote = array();
			foreach ( $tree['tree'] as $entry ) {
				if ( 'blob' === $entry['type'] ) {
					$remote[ $entry['path'] ] = $entry['sha'];
				}
			}
			$previous = array();
			if ( isset( $remote['bridge/manifest.json'] ) ) {
				$blob = self::request( $token, 'GET', $prefix . '/git/blobs/' . $remote['bridge/manifest.json'] );
				$manifest = json_decode( base64_decode( str_replace( "\n", '', $blob['content'] ), true ), true );
				if ( ! is_array( $manifest ) || 'contentrain-bridge@1' !== ( $manifest['format'] ?? '' ) || $manifest['site'] !== $job['inventory']['site'] ) {
					throw new \RuntimeException( 'Existing Bridge content belongs to another source or has an invalid manifest.' );
				}
				$previous = $manifest['files'];
			}
			$job['github'] = array( 'repository' => $repository, 'base' => $base['object']['sha'], 'base_tree' => $commit['tree']['sha'], 'branch' => 'contentrain/bridge-' . $job['id'], 'phase' => 'files', 'cursor' => 0, 'nodes' => array(), 'remote' => $remote, 'previous' => $previous );
		} );
	}

	public static function step( $id, $token, $expected ) {
		return Jobs::mutate( $id, static function ( &$job ) use ( $token, $expected ) {
			if ( ! isset( $job['github'] ) ) {
				throw new \RuntimeException( 'Choose a GitHub destination first.' );
			}
			$g = &$job['github'];
			if ( 'done' === $g['phase'] || (int) $expected !== $g['cursor'] ) {
				return Jobs::summary( $job );
			}
			$prefix = '/repos/' . $g['repository'];
			$paths = array_keys( $job['files'] );
			if ( isset( $paths[ $g['cursor'] ] ) ) {
				$path = $paths[ $g['cursor'] ];
				$content = Files::read( Files::dir( $job['id'] ) . '/output', $path );
				if ( strlen( $content ) > 8 * MB_IN_BYTES ) {
					throw new \RuntimeException( 'File exceeds GitHub delivery memory limit: ' . $path . '. Download locally and split the model.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
				}
				$git_sha = sha1( 'blob ' . strlen( $content ) . "\0" . $content );
				if ( isset( $g['remote'][ $path ] ) && $g['remote'][ $path ] !== $git_sha ) {
					$old = self::request( $token, 'GET', $prefix . '/git/blobs/' . $g['remote'][ $path ] );
					$old_content = base64_decode( str_replace( "\n", '', $old['content'] ), true );
					$trusted = $g['previous'][ $path ]['sha256'] ?? null;
					// The manifest identifies the previous snapshot; it is the only self-describing file.
					if ( 'bridge/manifest.json' !== $path && ( ! $trusted || hash( 'sha256', $old_content ) !== $trusted ) ) {
						throw new \RuntimeException( 'Git content conflict at ' . $path . '. Keep the repository edit and reconcile before exporting again.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
					}
				}
				$blob = self::request( $token, 'POST', $prefix . '/git/blobs', array( 'content' => base64_encode( $content ), 'encoding' => 'base64' ) );
				$g['nodes'][] = array( 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha'] );
				++$g['cursor'];
			} elseif ( 'files' === $g['phase'] ) {
				// Never leave stale managed records silently in an updated content store.
				foreach ( $g['previous'] as $path => $info ) {
					if ( isset( $g['remote'][ $path ] ) && ! isset( $job['files'][ $path ] ) ) {
						throw new \RuntimeException( 'Previous export contains ' . $path . ' outside the new scope. Reconcile removals in Git before delivery.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
					}
				}
				$tree = self::request( $token, 'POST', $prefix . '/git/trees', array( 'base_tree' => $g['base_tree'], 'tree' => $g['nodes'] ) );
				$commit = self::request( $token, 'POST', $prefix . '/git/commits', array( 'message' => 'Export WordPress content with Contentrain Bridge', 'tree' => $tree['sha'], 'parents' => array( $g['base'] ), 'author' => array( 'name' => 'Contentrain Bridge', 'email' => 'bridge@users.noreply.github.com', 'date' => $job['created_at'] ), 'committer' => array( 'name' => 'Contentrain Bridge', 'email' => 'bridge@users.noreply.github.com', 'date' => $job['created_at'] ) ) );
				$g['commit'] = $commit['sha'];
				$g['phase'] = 'ref';
				++$g['cursor'];
			} elseif ( 'ref' === $g['phase'] ) {
				// A timeout may mean the ref was already created. Reconcile only this exact commit.
				try {
					self::request( $token, 'POST', $prefix . '/git/refs', array( 'ref' => 'refs/heads/' . $g['branch'], 'sha' => $g['commit'] ) );
				} catch ( \RuntimeException $error ) {
					$ref = self::request( $token, 'GET', $prefix . '/git/ref/heads/' . rawurlencode( $g['branch'] ) );
					if ( $ref['object']['sha'] !== $g['commit'] ) {
						throw new \RuntimeException( 'Delivery branch already exists with different content; it was not overwritten.' );
					}
				}
				$g['phase'] = 'done';
				$g['url'] = 'https://github.com/' . $g['repository'] . '/commit/' . $g['commit'];
				unset( $g['remote'], $g['previous'], $g['nodes'] );
				++$g['cursor'];
			}
		} );
	}
}
