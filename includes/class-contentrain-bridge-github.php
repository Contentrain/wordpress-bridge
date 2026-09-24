<?php
/** Opt-in GitHub delivery; credentials exist only in the current request. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class GitHub {
	public static function request( $token, $method, $path, $body = null ) {
		return self::without_trace_arguments( static function () use ( $token, $method, $path, $body ) {
			return self::send( $token, $method, $path, $body );
		} );
	}

	/**
	 * Runs GitHub work with `zend.exception_ignore_args` on, so an exception
	 * raised inside it records no function arguments in its stack trace: the
	 * token is an argument of every call on the way down, and a trace that
	 * reaches a log (an uncaught exception, an error handler) would otherwise
	 * print its first characters. Restored afterwards; a trace is captured when
	 * the exception is created, so the setting holds for it after that.
	 */
	private static function without_trace_arguments( callable $work ) {
		$previous = ini_set( 'zend.exception_ignore_args', '1' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to this call and restored below; keeps the token out of exception traces.
		try {
			return $work();
		} finally {
			if ( false !== $previous ) {
				ini_set( 'zend.exception_ignore_args', $previous ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Restores the value saved above.
			}
		}
	}

	/** A branch name as a path: each segment encoded, the slashes kept, the way GitHub's refs API addresses `heads/feature/a`. */
	private static function ref_path( $branch ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $branch ) ) );
	}

	private static function send( $token, $method, $path, $body ) {
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
		if ( 204 === (int) $status ) {
			return array();
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		// GitHub answers every Git data request on a repository with no commit
		// at all with 409 "Git Repository is empty.". Its first commit would
		// create the default branch, which delivery never writes.
		if ( 409 === (int) $status && is_array( $data ) && 'Git Repository is empty.' === ( $data['message'] ?? '' ) ) {
			throw new \RuntimeException( 'The GitHub repository is empty. Create its first commit on the default branch (for example by adding a README on GitHub), then deliver again. Bridge never writes the default branch, so it does not create that commit itself.', 409 );
		}
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			throw new \RuntimeException( 'GitHub rejected the request (HTTP ' . $status . '). Check repository access, branch protection and rate limits.', (int) $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
		}
		return $data;
	}

	/**
	 * What to do when a managed file was edited in the repository since the last
	 * delivery. `refuse` stops and says who changed it and when; the other two
	 * are the person's decision, and every such file is named in the commit.
	 * The default branch is never written either way: delivery is a new branch.
	 */
	const CONFLICT_CHOICES = array( 'refuse', 'keep-repository', 'use-wordpress' );

	/**
	 * `$base_branch` is the branch delivery starts from and compares against —
	 * the previous export, the delta cursor, edits made since. Empty means the
	 * repository's default branch. It is only ever read: delivery still writes
	 * one new branch and nothing else.
	 */
	public static function start( $id, $token, $repository, $on_conflict = 'refuse', $base_branch = '' ) {
		return self::without_trace_arguments( static function () use ( $id, $token, $repository, $on_conflict, $base_branch ) {
			return self::begin( $id, $token, $repository, $on_conflict, $base_branch );
		} );
	}

	private static function begin( $id, $token, $repository, $on_conflict, $base_branch ) {
		if ( ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#D', $repository ) ) {
			throw new \RuntimeException( 'Use owner/repository, not a URL.' );
		}
		if ( ! in_array( $on_conflict, self::CONFLICT_CHOICES, true ) ) {
			throw new \RuntimeException( 'Unknown conflict choice.' );
		}
		$base_branch = is_string( $base_branch ) ? trim( $base_branch ) : '';
		if ( '' !== $base_branch && ( strlen( $base_branch ) > 200 || ! preg_match( '#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#D', $base_branch ) || preg_match( '#\.\.|//|/$|\.lock$|/\.#', $base_branch ) ) ) {
			throw new \RuntimeException( 'Use a plain branch name for the base branch, for example main.' );
		}
		return Jobs::mutate( $id, static function ( &$job ) use ( $token, $repository, $on_conflict, $base_branch ) {
			if ( 'ready' !== $job['phase'] ) {
				throw new \RuntimeException( 'Finalize the export before GitHub delivery.' );
			}
			if ( isset( $job['github'] ) ) {
				if ( $job['github']['repository'] !== $repository || ( '' !== $base_branch && ( $job['github']['base_branch'] ?? '' ) !== $base_branch ) ) {
					throw new \RuntimeException( 'This export already has a delivery destination.' );
				}
				// A refused conflict is answered by choosing again; nothing has been committed yet.
				if ( 'files' === $job['github']['phase'] ) {
					$job['github']['on_conflict'] = $on_conflict;
				}
				return Jobs::summary( $job );
			}
			$prefix = '/repos/' . $repository;
			$repo = self::request( $token, 'GET', $prefix );
			if ( $job['options']['private'] && empty( $repo['private'] ) ) {
				throw new \RuntimeException( 'An export including private/draft content requires a private GitHub repository.' );
			}
			// Empty repositories must first have an initial commit; we never write to the default branch.
			$branch = '' !== $base_branch ? $base_branch : (string) $repo['default_branch'];
			try {
				$base = self::request( $token, 'GET', $prefix . '/git/ref/heads/' . self::ref_path( $branch ) );
			} catch ( \RuntimeException $error ) {
				if ( 404 === $error->getCode() && '' !== $base_branch ) {
					throw new \RuntimeException( 'The base branch ' . $base_branch . ' does not exist in ' . $repository . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
				}
				throw $error;
			}
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
			// The inventory the repository was built from is the delta cursor. It
			// lives in the repository, not in WordPress, so it survives a reinstall;
			// the previous manifest's hash is what says nobody edited it since.
			if ( isset( $remote['bridge/inventory.json'], $job['files']['bridge/inventory.json'] ) ) {
				$blob = self::request( $token, 'GET', $prefix . '/git/blobs/' . $remote['bridge/inventory.json'] );
				$raw = (string) base64_decode( str_replace( "\n", '', $blob['content'] ), true );
				$trusted = $previous['bridge/inventory.json']['sha256'] ?? null;
				$after = json_decode( Files::read( Files::dir( $job['id'] ) . '/output', 'bridge/inventory.json' ), true );
				// Edited in Git after delivery: not a cursor this export can trust.
				$before = $trusted && ! hash_equals( $trusted, hash( 'sha256', $raw ) ) ? null : json_decode( $raw, true );
				$plan = Delta::compare( $before, $after );
				Models::file( $job, 'bridge/delta.json', Policy::json( $plan ) );
				Jobs::manifest( $job );
				$removable = self::removable( $plan, $token, $prefix, $remote );
			}
			$job['github'] = array( 'repository' => $repository, 'base_branch' => $branch, 'base' => $base['object']['sha'], 'base_tree' => $commit['tree']['sha'], 'branch' => 'contentrain/bridge-' . $job['id'], 'phase' => 'files', 'cursor' => 0, 'nodes' => array(), 'remote' => $remote, 'previous' => $previous, 'on_conflict' => $on_conflict, 'removable' => $removable ?? array(), 'removed' => array(), 'conflicts' => array() );
		} );
	}

	/**
	 * Files of the previous export that belong to records the delta proves were
	 * deleted: a post's document and metadata, an attachment's uploads. Read from
	 * the repository's own entry map and attachment table, the T0 the delta was
	 * measured against. Empty unless deletions are detectable.
	 */
	private static function removable( $plan, $token, $prefix, $remote ) {
		if ( empty( $plan['deletions_detectable'] ) || ! empty( $plan['refused'] ) ) {
			return array();
		}
		$read = static function ( $path ) use ( $token, $prefix, $remote ) {
			if ( ! isset( $remote[ $path ] ) ) {
				return array();
			}
			$blob = self::request( $token, 'GET', $prefix . '/git/blobs/' . $remote[ $path ] );
			$data = json_decode( (string) base64_decode( str_replace( "\n", '', $blob['content'] ), true ), true );
			return is_array( $data ) ? $data : array();
		};
		$entries = null;
		$attachments = null;
		$out = array();
		foreach ( $plan['entries'] as $entry ) {
			if ( 'deleted' !== $entry['op'] ) {
				continue;
			}
			$reason = $entry['wp_type'] . ' ' . $entry['wp_id'] . ', ' . ( $entry['deleted_kind'] ?? 'deleted' );
			if ( 'attachment' === $entry['wp_type'] ) {
				$attachments = $attachments ?? $read( 'bridge/raw-attachments.json' );
				$row = $attachments[ (string) $entry['wp_id'] ] ?? null;
				if ( $row && ! empty( $row['file'] ) ) {
					$files = array( $row['file'] );
					foreach ( (array) ( $row['image_meta']['sizes'] ?? array() ) as $size ) {
						if ( ! empty( $size['file'] ) ) {
							$files[] = dirname( $row['file'] ) . '/' . $size['file'];
						}
					}
					foreach ( $files as $file ) {
						$out[ Jobs::media_path( ltrim( $file, '/' ) ) ] = $reason;
					}
				}
				continue;
			}
			$entries = $entries ?? $read( 'bridge/entry-source-map.json' );
			$address = $entries[ (string) $entry['wp_id'] ] ?? null;
			if ( ! $address ) {
				continue;
			}
			$model = preg_quote( $address['model_id'], '#' );
			$id = preg_quote( $address['entry_id'], '#' );
			foreach ( array_keys( $remote ) as $path ) {
				// Document content ({id}.md or {id}/{locale}.md) and its metadata directory.
				if ( preg_match( '#^\.contentrain/content/[^/]+/' . $model . '/' . $id . '(\.md|/[^/]+\.md)$#D', $path ) || preg_match( '#^\.contentrain/meta/' . $model . '/' . $id . '/[^/]+\.json$#D', $path ) ) {
					$out[ $path ] = $reason;
				}
			}
		}
		return $out;
	}

	/** The last commit on the base branch that touched a path, for a conflict message. */
	private static function last_change( $token, $prefix, $base, $path ) {
		try {
			$commits = self::request( $token, 'GET', $prefix . '/commits?sha=' . rawurlencode( $base ) . '&path=' . rawurlencode( $path ) . '&per_page=1' );
		} catch ( \RuntimeException $error ) {
			return null;
		}
		$commit = $commits[0] ?? null;
		return $commit ? array( 'sha' => (string) $commit['sha'], 'author' => (string) ( $commit['commit']['author']['name'] ?? 'unknown' ), 'date' => (string) ( $commit['commit']['author']['date'] ?? '' ) ) : null;
	}

	public static function step( $id, $token, $expected ) {
		return self::without_trace_arguments( static function () use ( $id, $token, $expected ) {
			return self::advance( $id, $token, $expected );
		} );
	}

	private static function advance( $id, $token, $expected ) {
		return Jobs::mutate( $id, static function ( &$job ) use ( $token, $expected ) {
			if ( ! isset( $job['github'] ) ) {
				throw new \RuntimeException( 'Choose a GitHub destination first.' );
			}
			$g = &$job['github'];
			if ( 'done' === $g['phase'] || (int) $expected !== $g['cursor'] ) {
				return Jobs::summary( $job );
			}
			$prefix = '/repos/' . $g['repository'];
			// Never RawIR: a raw copy of every record does not belong in the site's repository.
			$paths = array_values( array_diff( array_keys( $job['files'] ), array( Rawir::PATH ) ) );
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
						$edit = self::last_change( $token, $prefix, $g['base'], $path );
						$choice = $g['on_conflict'] ?? 'refuse';
						if ( 'refuse' === $choice ) {
							throw new \RuntimeException( 'Git content conflict at ' . $path . ': changed in the repository' . ( $edit ? ' by ' . $edit['author'] . ' on ' . $edit['date'] . ' (commit ' . substr( $edit['sha'], 0, 7 ) . ')' : '' ) . ' after the last Bridge delivery. Nothing was written. Choose "keep the repository version" to deliver everything else, or "use the WordPress version" to put this export\'s version on the delivery branch for review.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
						}
						$g['conflicts'][] = array( 'path' => $path, 'resolution' => $choice ) + ( $edit ? $edit : array() );
						if ( 'keep-repository' === $choice ) {
							// The branch starts from the base branch, so leaving the path out keeps the edit.
							++$g['cursor'];
							return;
						}
					}
				}
				$blob = self::request( $token, 'POST', $prefix . '/git/blobs', array( 'content' => base64_encode( $content ), 'encoding' => 'base64' ) );
				$g['nodes'][] = array( 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha'] );
				++$g['cursor'];
			} elseif ( 'files' === $g['phase'] ) {
				// A managed file the new export no longer has is removed only when the
				// delta proves its record was deleted in WordPress. Anything else stops
				// the delivery: silently leaving it is a stale page, silently removing
				// it could be a person's work.
				$unexplained = array();
				foreach ( $g['previous'] as $path => $info ) {
					if ( ! isset( $g['remote'][ $path ] ) || isset( $job['files'][ $path ] ) ) {
						continue;
					}
					if ( isset( $g['removable'][ $path ] ) ) {
						$g['nodes'][] = array( 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null );
						$g['removed'][ $path ] = $g['removable'][ $path ];
					} else {
						$unexplained[] = $path;
					}
				}
				if ( $unexplained ) {
					$g['nodes'] = array_values( array_filter( $g['nodes'], static function ( $node ) { return null !== $node['sha']; } ) );
					$g['removed'] = array();
					throw new \RuntimeException( count( $unexplained ) . ' file(s) from the previous export are not in this one and no verified deletion explains them (' . implode( ', ', array_slice( $unexplained, 0, 5 ) ) . ( count( $unexplained ) > 5 ? ', …' : '' ) . '). Nothing was written. Reconcile them in Git, or restore the records in WordPress.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
				}
				$message = 'Export WordPress content with Contentrain Bridge';
				if ( $g['removed'] ) {
					$message .= "\n\nRemoved, deleted in WordPress:";
					foreach ( $g['removed'] as $path => $reason ) {
						$message .= "\n- " . $path . ' (' . $reason . ')';
					}
				}
				if ( $g['conflicts'] ) {
					$message .= "\n\nEdited in the repository since the last delivery:";
					foreach ( $g['conflicts'] as $conflict ) {
						$message .= "\n- " . $conflict['path'] . ': ' . ( 'keep-repository' === $conflict['resolution'] ? 'repository version kept' : 'WordPress version delivered' ) . ( isset( $conflict['author'] ) ? ' (edited by ' . $conflict['author'] . ', ' . $conflict['date'] . ')' : '' );
					}
				}
				$tree = self::request( $token, 'POST', $prefix . '/git/trees', array( 'base_tree' => $g['base_tree'], 'tree' => $g['nodes'] ) );
				$commit = self::request( $token, 'POST', $prefix . '/git/commits', array( 'message' => $message, 'tree' => $tree['sha'], 'parents' => array( $g['base'] ), 'author' => array( 'name' => 'Contentrain Bridge', 'email' => 'bridge@users.noreply.github.com', 'date' => $job['created_at'] ), 'committer' => array( 'name' => 'Contentrain Bridge', 'email' => 'bridge@users.noreply.github.com', 'date' => $job['created_at'] ) ) );
				$g['commit'] = $commit['sha'];
				$g['phase'] = 'ref';
				++$g['cursor'];
			} elseif ( 'ref' === $g['phase'] ) {
				// A timeout may mean the ref was already created. Reconcile only this exact commit.
				try {
					self::request( $token, 'POST', $prefix . '/git/refs', array( 'ref' => 'refs/heads/' . $g['branch'], 'sha' => $g['commit'] ) );
				} catch ( \RuntimeException $error ) {
					$ref = self::request( $token, 'GET', $prefix . '/git/ref/heads/' . self::ref_path( $g['branch'] ) );
					if ( $ref['object']['sha'] !== $g['commit'] ) {
						throw new \RuntimeException( 'Delivery branch already exists with different content; it was not overwritten.' );
					}
				}
				$g['phase'] = 'done';
				$g['url'] = 'https://github.com/' . $g['repository'] . '/commit/' . $g['commit'];
				unset( $g['remote'], $g['previous'], $g['nodes'], $g['removable'] );
				++$g['cursor'];
			}
		} );
	}
}
