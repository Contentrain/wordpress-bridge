<?php
/** The Contentrain connection key: Migrate reads an export without an application password (BR-27). @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * A key an administrator creates on the Bridge screen and pastes into
 * Contentrain Migrate, for hosts that strip the Authorization header and so
 * never let an application password through.
 *
 * - Sent as the `X-Contentrain-Key` header and, because a header can be
 *   stripped too, as `contentrain_key` in the body of every POST. Never read
 *   from the query string: an address ends up in access logs and caches.
 * - Only its SHA-256 is stored; the key is shown once. One key at a time: a new
 *   one retires the previous one.
 * - It opens the `contentrain-bridge/v1` routes only, and runs with no WordPress
 *   user: the request is never signed in as anyone (`get_current_user_id()` stays
 *   0), so no capability check anywhere passes on its account. It is valid only
 *   while the administrator who created it still has `export` + `manage_options`,
 *   checked with `user_can()` on their id. WordPress's own REST API is not opened by it.
 * - Unpaired, it lives an hour: the time to paste it into Migrate. The first
 *   request that carries a `contentrain_pairing` (Migrate's order id) pairs it;
 *   paired, it works for that order until 14 days after it was created, with no
 *   idle limit (reruns can be days apart), until it is revoked or replaced —
 *   by the administrator, or by Migrate itself (`DELETE /key`) when the order closes.
 * - It reads only the exports it started, one running at a time. Those exports are
 *   indexed in the plugin's own option (`EXPORTS`), never in any user's meta, and
 *   their state names the key as `via`.
 * - A wrong key counts against its address: after ten in 15 minutes, wrong keys
 *   from there are answered 429. The right key is always compared first, so a
 *   shared address (a proxy, a CDN) cannot lock Migrate out.
 * - Created and accepted over HTTPS only (or on a `local` site), like WordPress's
 *   application passwords.
 */
final class Key {
	const OPTION = 'contentrain_bridge_key';
	const SEEN = 'contentrain_bridge_key_seen';
	const EXPORTS = 'contentrain_bridge_key_exports';
	const HEADER = 'x_contentrain_key';
	const FIELD = 'contentrain_key';
	const PAIRING = 'contentrain_pairing';
	const UNPAIRED = 3600;
	const LIFETIME = 1209600;
	const FAILURES = 10;
	const WINDOW = 900;
	const RETIRED = 5;
	const KEPT_EXPORTS = 20;

	/** The format of a key as `create()` returns it: 32 random bytes, base64url, no padding. */
	const FORMAT = '/^[A-Za-z0-9_-]{43}$/D';

	/** Whether this request authenticated with the key: the remote routes then show only the key's own exports. */
	private static $active = false;

	/** Clear the key after every REST callback: one PHP process can serve several requests, and nothing may leak into the next. */
	public static function register() {
		add_filter( 'rest_request_after_callbacks', array( self::class, 'after_callbacks' ), 1000 );
	}

	public static function after_callbacks( $response ) {
		self::clear();
		return $response;
	}

	public static function secure() {
		return (bool) apply_filters( 'contentrain_bridge_key_secure', is_ssl() || 'local' === wp_get_environment_type() );
	}

	public static function active() {
		return self::$active;
	}

	/** Forget the previous request's key: one PHP process can serve several REST requests. */
	public static function clear() {
		self::$active = false;
	}

	/** A new key for the current administrator; the previous one stops working and its exports are removed. Returned once, never stored. */
	public static function create() {
		if ( ! Admin::permitted() ) {
			throw new \RuntimeException( 'Export and administrator permissions are required.' );
		}
		if ( ! self::secure() ) {
			throw new \RuntimeException( 'Use HTTPS on this site before creating a connection key.' );
		}
		foreach ( self::exports() as $id ) {
			self::remove_export( $id );
		}
		$key = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Random bytes as base64url text: the key itself, nothing encoded to hide.
		update_option( self::OPTION, array(
			'hash' => hash( 'sha256', $key ),
			'user' => get_current_user_id(),
			'created_at' => time(),
			'pairing' => null,
			'retired' => self::retired(),
		), false );
		update_option( self::SEEN, array( 'at' => time(), 'ip' => '' ), false );
		update_option( self::EXPORTS, array(), false );
		return $key;
	}

	/**
	 * Stop the current key — the administrator on the Bridge screen, or Migrate with the key itself (the request
	 * then carries no user, so the key's own authorization stands in for the screen's permission). A retired key is
	 * answered as revoked, not as unknown. The key's exports go with it: nothing else can read them once it is gone.
	 */
	public static function revoke() {
		if ( ! self::$active && ! Admin::permitted() ) {
			throw new \RuntimeException( 'Export and administrator permissions are required.' );
		}
		foreach ( self::exports() as $id ) {
			self::remove_export( $id );
		}
		update_option( self::OPTION, array( 'retired' => self::retired() ), false );
		delete_option( self::SEEN );
		delete_option( self::EXPORTS );
	}

	/** Remove one of the key's export directories unless a request holds it; a held one expires with `Files::cleanup()`. */
	private static function remove_export( $id ) {
		try {
			$dir = Files::dir( $id );
		} catch ( \RuntimeException $invalid ) {
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$lock = fopen( $dir . '/lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native advisory lock; filesystem API has no locking primitive.
		if ( $lock && flock( $lock, LOCK_EX | LOCK_NB ) ) {
			Files::remove( $dir );
			flock( $lock, LOCK_UN );
		}
		if ( $lock ) {
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native advisory lock handle.
		}
	}

	/** What the Bridge screen shows about the current key; never the key or its hash. */
	public static function status() {
		$record = self::record();
		if ( ! $record ) {
			return array( 'active' => false, 'secure' => self::secure() );
		}
		$seen = self::seen( $record );
		$user = get_userdata( (int) $record['user'] );
		return array(
			'active' => time() <= self::ends( $record ),
			'secure' => self::secure(),
			'created_at' => gmdate( 'c', (int) $record['created_at'] ),
			'expires_at' => gmdate( 'c', self::ends( $record ) ),
			'used' => '' !== $seen['ip'],
			'last_used_at' => gmdate( 'c', $seen['at'] ),
			'last_used_ip' => $seen['ip'],
			'pairing' => $record['pairing'],
			'user' => $user ? $user->user_login : '',
		);
	}

	/**
	 * The key a request carries: the header, the body field, or both when they agree.
	 * `null` when there is none (the application-password path then applies).
	 */
	public static function presented( $request ) {
		$header = $request->get_header( self::HEADER );
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		$field = is_array( $body ) && isset( $body[ self::FIELD ] ) ? $body[ self::FIELD ] : null;
		$header = is_string( $header ) && '' !== trim( $header ) ? trim( $header ) : null;
		$field = is_string( $field ) && '' !== trim( $field ) ? trim( $field ) : null;
		if ( null !== $header && null !== $field && ! hash_equals( $header, $field ) ) {
			return self::error( 'mismatch', 'The X-Contentrain-Key header and the contentrain_key field differ; send the same key in both.', 400 );
		}
		return null !== $header ? $header : $field;
	}

	/**
	 * Accept the key for this request, or say why not. Nothing here signs anyone in: the request keeps user 0 for its
	 * whole life, and the export code receives the key's owner explicitly (`Jobs::principal()`). The creator's
	 * continued `export` + `manage_options` is a validity condition of the key, read with `user_can()` on their id.
	 */
	public static function authorize( $request, $key ) {
		if ( ! self::secure() ) {
			return self::error( 'insecure', 'This site is not served over HTTPS, so a connection key cannot be used.', 403 );
		}
		$ip = self::ip();
		// A string that cannot be a key is refused before anything is hashed or compared; it counts as a wrong key.
		if ( ! is_string( $key ) || ! preg_match( self::FORMAT, $key ) ) {
			return self::wrong( $ip );
		}
		$hash = hash( 'sha256', $key );
		$record = self::record();
		// The key is compared before the address is held back: behind a shared address (a proxy, a CDN)
		// someone else's wrong keys must not lock out the right one.
		if ( ! $record || ! hash_equals( $record['hash'], $hash ) ) {
			if ( isset( self::retired()[ $hash ] ) ) {
				return self::error( 'revoked', 'This connection key was revoked or replaced in WordPress. Create a new one on the Contentrain Bridge screen.', 401 );
			}
			return self::wrong( $ip );
		}
		if ( time() > self::ends( $record ) ) {
			return null === $record['pairing']
				? self::error( 'expired', 'The connection key was not used within an hour of being created. Create a new one on the Contentrain Bridge screen.', 401 )
				: self::error( 'expired', 'The connection key is 14 days old. Create a new one on the Contentrain Bridge screen.', 401 );
		}
		$user = (int) $record['user'];
		if ( ! user_can( $user, 'export' ) || ! user_can( $user, 'manage_options' ) ) {
			return self::error( 'forbidden', 'The administrator who created the connection key can no longer export this site.', 403 );
		}
		$pairing = $request->get_param( self::PAIRING );
		if ( ! is_string( $pairing ) || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/D', $pairing ) ) {
			return self::error( 'pairing_required', 'contentrain_pairing (8 to 64 letters, digits, - or _) is required with a connection key.', 400 );
		}
		if ( null !== $record['pairing'] && ! hash_equals( $record['pairing'], $pairing ) ) {
			return self::error( 'paired_elsewhere', 'This connection key belongs to another Migrate order. Create a new one for this order.', 403 );
		}
		// Paired by the first accepted request of any route — Migrate's access check included, which reads an
		// export that does not exist — so a queue before the run cannot outlast the unpaired hour.
		if ( null === $record['pairing'] ) {
			$record['pairing'] = $pairing;
			update_option( self::OPTION, $record, false );
		}
		update_option( self::SEEN, array( 'at' => time(), 'ip' => $ip ), false );
		// The export in the route itself, never a body or query field of the same name.
		$id = $request->get_url_params()['id'] ?? null;
		if ( null !== $id && ! in_array( $id, self::exports(), true ) ) {
			return new \WP_Error( 'bridge_not_found', 'No such export for this connection key.', array( 'status' => 404 ) );
		}
		self::$active = true;
		return true;
	}

	/** A wrong key from `$ip`: counted, and 429 once the address has had its ten in the window. */
	private static function wrong( $ip ) {
		$failures = (int) get_transient( self::failures( $ip ) );
		if ( $failures >= self::FAILURES ) {
			return self::error( 'rate_limited', 'Too many wrong connection keys from this address; wait 15 minutes.', 429, array( 'retry_after' => self::WINDOW ) );
		}
		set_transient( self::failures( $ip ), $failures + 1, self::WINDOW );
		return self::error( 'invalid', 'The connection key is not valid. Copy it again from the Contentrain Bridge screen.', 401 );
	}

	/** The administrator who created the active key: the owner every export it starts is recorded under. */
	public static function owner() {
		$record = self::record();
		return $record ? (int) $record['user'] : 0;
	}

	/** The SHA-256 of the active key, as the option stores it: what a key export's state names. */
	public static function hash() {
		$record = self::record();
		return $record ? (string) $record['hash'] : '';
	}

	/** DELETE /key: Migrate closes its own key when the order is done. Only the key itself can ask. */
	public static function permitted_self( $request ) {
		self::clear();
		$key = self::presented( $request );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( null === $key ) {
			return self::error( 'required', 'Send the connection key to revoke it.', 401 );
		}
		return self::authorize( $request, $key );
	}

	public static function revoke_self() {
		self::revoke();
		return new \WP_REST_Response( array( 'revoked' => true ), 200, array( 'Cache-Control' => 'private, no-store' ) );
	}

	/** The exports this key started or reused, newest last. */
	public static function exports() {
		$ids = get_option( self::EXPORTS );
		return is_array( $ids ) ? array_values( array_filter( $ids, static function ( $id ) { return is_string( $id ) && preg_match( '/^[a-f0-9]{32}$/D', $id ); } ) ) : array();
	}

	/** The key's export list, newest last, at most KEPT_EXPORTS. */
	public static function set( $ids ) {
		update_option( self::EXPORTS, array_slice( array_values( $ids ), -self::KEPT_EXPORTS ), false );
	}

	private static function record() {
		$record = get_option( self::OPTION );
		return is_array( $record ) && isset( $record['hash'], $record['user'], $record['created_at'] ) && is_string( $record['hash'] ) ? $record + array( 'pairing' => null ) : null;
	}

	/** The last keys, current one included, as hash => when retired; at most RETIRED. */
	private static function retired() {
		$stored = get_option( self::OPTION );
		$retired = is_array( $stored ) && isset( $stored['retired'] ) && is_array( $stored['retired'] ) ? $stored['retired'] : array();
		if ( is_array( $stored ) && isset( $stored['hash'] ) && is_string( $stored['hash'] ) ) {
			$retired[ $stored['hash'] ] = time();
		}
		return array_slice( $retired, -self::RETIRED, null, true );
	}

	private static function seen( $record ) {
		$seen = get_option( self::SEEN );
		$at = is_array( $seen ) && isset( $seen['at'] ) ? (int) $seen['at'] : (int) $record['created_at'];
		$ip = is_array( $seen ) && isset( $seen['ip'] ) && is_string( $seen['ip'] ) ? $seen['ip'] : '';
		return array( 'at' => $at, 'ip' => $ip );
	}

	/** When the key stops working: an hour after creation while unpaired, 14 days after creation once paired. */
	private static function ends( $record ) {
		return (int) $record['created_at'] + ( null === $record['pairing'] ? self::UNPAIRED : self::LIFETIME );
	}

	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	private static function failures( $ip ) {
		return 'contentrain_bridge_key_fail_' . md5( $ip );
	}

	private static function error( $code, $message, $status, $data = array() ) {
		return new \WP_Error( 'bridge_key_' . $code, $message, array( 'status' => $status ) + $data );
	}
}
