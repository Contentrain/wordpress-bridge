<?php
/** Exports started and advanced over REST, for Migrate. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * The export, driven by a program with an application password instead of a
 * person on the admin screen (BR-19). Same permission as the read API
 * (`export` + `manage_options`), same jobs, same snapshot.
 *
 *   POST /contentrain-bridge/v1/exports                { types?, private?, comments?, media_files?, max_age?, fresh? }
 *        201 { export, reused: false } — a new export
 *        200 { export, reused: true }  — the caller's live export with the same scope,
 *                                        started at most `max_age` seconds ago
 *        409 bridge_export_busy        — a stale export to replace is running; retry
 *   GET  /contentrain-bridge/v1/exports                → { exports: [ export ] }
 *   POST /contentrain-bridge/v1/exports/{id}/advance   → { export, busy? }
 *
 * `export` is `Jobs::summary`: `id`, `phase`, `step`, `cursor`, `counts`, `files`,
 * `scope`, `created_at`, `expires_at` (24 hours), and `error { code, message }`
 * when failed. `phase` ends in `ready` (read it with GET /exports/{id}) or
 * `failed`; error codes: `content_changed` (WordPress changed under the
 * snapshot — start again), `too_large`, `review_required`, `export_failed`.
 *
 * Advancing runs steps for about 20 seconds and returns; the caller polls until
 * a terminal phase. Two advances at once are safe: one runs, the other returns
 * the export as it is with `busy: true`. A remote export never waits on a
 * person: no source scan, so the text review is empty and closes itself.
 */
final class Remote {
	const META = 'contentrain_bridge_remote_jobs_';
	const BUDGET = 20;
	const LIVE_LIMIT = 3;
	/** Touched on every read of a snapshot; one read within READ_GUARD seconds keeps it from being replaced. */
	const READ_MARK = 'read';
	const READ_GUARD = 600;

	public static function routes() {
		register_rest_route( 'contentrain-bridge/v1', '/exports', array(
			array( 'methods' => 'POST', 'permission_callback' => array( Admin::class, 'permitted' ), 'callback' => array( self::class, 'start' ) ),
			array( 'methods' => 'GET', 'permission_callback' => array( Admin::class, 'permitted' ), 'callback' => array( self::class, 'index' ) ),
		) );
		register_rest_route( 'contentrain-bridge/v1', '/exports/(?P<id>[a-f0-9]{32})/advance', array( 'methods' => 'POST', 'permission_callback' => array( Admin::class, 'permitted' ), 'callback' => array( self::class, 'advance' ) ) );
	}

	/** Start an export, or return the caller's live one with the same scope: never two alike. */
	public static function start( $request ) {
		try {
			$types = $request->get_param( 'types' );
			if ( null !== $types && ( ! is_array( $types ) || array_filter( $types, static function ( $t ) { return ! is_string( $t ); } ) ) ) {
				return self::error( 'invalid_scope', 'types must be a list of post type names.', 400 );
			}
			$available = array_keys( Source::inventory()['post_types'] );
			$scope = array(
				'types' => self::sorted( array_intersect( $available, null === $types ? $available : $types ) ),
				'private' => (bool) $request->get_param( 'private' ),
				'comments' => (bool) $request->get_param( 'comments' ),
				// Default true: media files travel with the snapshot unless the reader asks otherwise.
				'media_files' => null === $request->get_param( 'media_files' ) || rest_sanitize_boolean( $request->get_param( 'media_files' ) ),
			);
			if ( ! $scope['types'] ) {
				return self::error( 'invalid_scope', 'Select at least one content type.', 400 );
			}
			// How old a reused export may be: `fresh` always starts a new one; no limit keeps the 24-hour life.
			$max_age = $request->get_param( 'max_age' );
			if ( null !== $max_age && ! preg_match( '/^[0-9]{1,9}$/D', (string) $max_age ) ) {
				return self::error( 'invalid_scope', 'max_age must be a number of seconds.', 400 );
			}
			$max_age = rest_sanitize_boolean( $request->get_param( 'fresh' ) ) ? 0 : ( null === $max_age ? null : (int) $max_age );
			return self::locked( static function () use ( $scope, $max_age ) {
				$live = 0;
				foreach ( self::jobs() as $job ) {
					if ( 'failed' === $job['phase'] ) {
						continue;
					}
					$same = self::sorted( $job['options']['types'] ) === $scope['types'] && $job['options']['private'] === $scope['private'] && $job['options']['comments'] === $scope['comments'] && ( $job['options']['media_files'] ?? true ) === $scope['media_files'];
					if ( $same && null !== $max_age && ( 0 === $max_age || time() - strtotime( $job['created_at'] ) > $max_age ) ) {
						// Too old to be the content the caller pays for: replaced, and no longer counted.
						if ( ! self::discard( $job['id'] ) ) {
							return self::error( 'export_busy', 'The export being replaced is running; retry in a few seconds.', 409 );
						}
						continue;
					}
					++$live;
					if ( $same ) {
						return new \WP_REST_Response( array( 'export' => Jobs::summary( $job ), 'reused' => true ), 200, self::headers() );
					}
				}
				if ( $live >= self::LIVE_LIMIT ) {
					return self::error( 'too_many_exports', 'Three exports are already in progress for this user; let one finish or expire.', 429 );
				}
				$summary = Jobs::create( $scope, true );
				$ids = self::ids();
				$ids[] = $summary['id'];
				update_user_meta( get_current_user_id(), self::META . get_current_blog_id(), $ids );
				return new \WP_REST_Response( array( 'export' => $summary, 'reused' => false ), 201, self::headers() );
			} );
		} catch ( \Throwable $error ) {
			// Input is checked above; what is left is the server's fault, not "not ready".
			return self::error( 'export_failed', $error->getMessage(), 500 );
		}
	}

	/** The caller's remote exports that still exist, oldest first. */
	public static function index() {
		try {
			return new \WP_REST_Response( array( 'exports' => array_map( array( Jobs::class, 'summary' ), self::jobs() ) ), 200, self::headers() );
		} catch ( \Throwable $error ) {
			// Input is checked above; what is left is the server's fault, not "not ready".
			return self::error( 'export_failed', $error->getMessage(), 500 );
		}
	}

	/** Run steps for up to BUDGET seconds, closing the (empty) review on the way. */
	public static function advance( $request ) {
		$id = $request['id'];
		try {
			$job = Jobs::read( $id );
		} catch ( \Throwable $error ) {
			return false !== strpos( $error->getMessage(), 'expired' ) ? self::error( 'export_expired', $error->getMessage(), 410 ) : self::error( 'not_found', 'No such export for this user.', 404 );
		}
		if ( empty( $job['remote'] ) ) {
			return self::error( 'not_remote', 'This export belongs to the admin screen; continue it there.', 409 );
		}
		$deadline = microtime( true ) + self::BUDGET;
		$summary = Jobs::summary( $job );
		try {
			while ( ! in_array( $summary['phase'], array( 'ready', 'failed' ), true ) && microtime( true ) < $deadline ) {
				if ( 'review' === $summary['phase'] ) {
					if ( $summary['unreviewed'] > 0 ) {
						$summary = Jobs::fail( $id, 'review_required', 'Text candidates need a person to review them.' );
						break;
					}
					$summary = Jobs::review( $id, array(), true );
				} else {
					$summary = Jobs::step( $id, $summary['step'] );
				}
			}
		} catch ( \Throwable $error ) {
			if ( 409 === $error->getCode() ) {
				return new \WP_REST_Response( array( 'export' => Jobs::summary( Jobs::read( $id ) ), 'busy' => true ), 200, self::headers() );
			}
			try {
				$summary = Jobs::fail( $id, self::code( $error->getMessage() ), $error->getMessage() );
			} catch ( \Throwable $again ) {
				return self::error( 'export_failed', $error->getMessage(), 500 );
			}
		}
		return new \WP_REST_Response( array( 'export' => $summary ), 200, self::headers() );
	}

	/**
	 * Remove a remote export and its files; false when a request holds it, or
	 * when it was read within READ_GUARD seconds: a download is many requests,
	 * and a lock held by one of them does not cover the gaps between them.
	 */
	private static function discard( $id ) {
		$dir = Files::dir( $id );
		$read = @filemtime( $dir . '/' . self::READ_MARK ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing marker means never read.
		if ( false !== $read && time() - $read < self::READ_GUARD ) {
			return false;
		}
		$lock = fopen( $dir . '/lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native advisory lock; filesystem API has no locking primitive.
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( $lock ) {
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native advisory lock handle.
			}
			return false;
		}
		try {
			Files::remove( $dir );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native advisory lock handle.
		}
		update_user_meta( get_current_user_id(), self::META . get_current_blog_id(), array_values( array_diff( self::ids(), array( $id ) ) ) );
		return true;
	}

	/** A stable code for a step's failure; the message stays for people. */
	private static function code( $message ) {
		if ( false !== strpos( $message, 'content changed during export' ) ) {
			return 'content_changed';
		}
		if ( preg_match( '/exceeds|More than/', $message ) ) {
			return 'too_large';
		}
		return 'export_failed';
	}

	private static function ids() {
		$ids = get_user_meta( get_current_user_id(), self::META . get_current_blog_id(), true );
		return is_array( $ids ) ? array_values( array_filter( $ids, static function ( $id ) { return is_string( $id ) && preg_match( '/^[a-f0-9]{32}$/D', $id ); } ) ) : array();
	}

	/** Readable jobs only; an expired or deleted one leaves the list. */
	private static function jobs() {
		$jobs = array();
		$kept = array();
		foreach ( self::ids() as $id ) {
			try {
				$jobs[] = Jobs::read( $id );
				$kept[] = $id;
			} catch ( \Throwable $gone ) {
				continue;
			}
		}
		if ( $kept !== self::ids() ) {
			update_user_meta( get_current_user_id(), self::META . get_current_blog_id(), $kept );
		}
		return $jobs;
	}

	/** One start at a time per user: two concurrent requests cannot both create. */
	private static function locked( $callback ) {
		$lock = fopen( Files::root() . '/remote-' . get_current_user_id() . '.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native advisory lock; filesystem API has no locking primitive.
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new \RuntimeException( 'Cannot lock export start.' );
		}
		try {
			return $callback();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native advisory lock handle.
		}
	}

	private static function sorted( $values ) {
		$values = array_values( array_unique( array_map( 'strval', (array) $values ) ) );
		sort( $values, SORT_STRING );
		return $values;
	}

	private static function headers() {
		return array( 'Cache-Control' => 'private, no-store' );
	}

	private static function error( $code, $message, $status ) {
		return new \WP_Error( 'bridge_' . $code, $message, array( 'status' => $status ) );
	}
}
