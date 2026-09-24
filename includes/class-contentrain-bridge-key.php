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
 * - It opens the `contentrain-bridge/v1` routes only, acting as the administrator
 *   who created it, and only while that user still has `export` + `manage_options`.
 *   WordPress's own REST API is not opened by it.
 * - It lives an hour after its last use and 14 days at most, until revoked.
 * - The first request binds it to a pairing (`contentrain_pairing`: Migrate's order
 *   id); from then on it reads only the exports it started, one running at a time.
 * - Ten wrong keys from one address in 15 minutes and that address waits.
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
	const IDLE = 3600;
	const LIFETIME = 1209600;
	const FAILURES = 10;
	const WINDOW = 900;
	const RETIRED = 5;
	const KEPT_EXPORTS = 20;

	/** Whether this request authenticated with the key: the remote routes then show only the key's own exports. */
	private static $active = false;

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

	/** A new key for the current administrator; the previous one stops working. Returned once, never stored. */
	public static function create() {
		if ( ! Admin::permitted() ) {
			throw new \RuntimeException( 'Export and administrator permissions are required.' );
		}
		if ( ! self::secure() ) {
			throw new \RuntimeException( 'Use HTTPS on this site before creating a connection key.' );
		}
		$key = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
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

	/** Stop the current key. A retired key is answered as revoked, not as unknown. */
	public static function revoke() {
		if ( ! Admin::permitted() ) {
			throw new \RuntimeException( 'Export and administrator permissions are required.' );
		}
		update_option( self::OPTION, array( 'retired' => self::retired() ), false );
		delete_option( self::SEEN );
		delete_option( self::EXPORTS );
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
			'active' => ! self::expired( $record, $seen ),
			'secure' => self::secure(),
			'created_at' => gmdate( 'c', (int) $record['created_at'] ),
			'expires_at' => gmdate( 'c', min( $seen['at'] + self::IDLE, (int) $record['created_at'] + self::LIFETIME ) ),
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

	/** Accept the key for this request, as its administrator, or say why not. */
	public static function authorize( $request, $key ) {
		$ip = self::ip();
		$failures = (int) get_transient( self::failures( $ip ) );
		if ( $failures >= self::FAILURES ) {
			return self::error( 'rate_limited', 'Too many wrong connection keys from this address; wait 15 minutes.', 429, array( 'retry_after' => self::WINDOW ) );
		}
		if ( ! self::secure() ) {
			return self::error( 'insecure', 'This site is not served over HTTPS, so a connection key cannot be used.', 403 );
		}
		$hash = hash( 'sha256', $key );
		$record = self::record();
		if ( ! $record || ! hash_equals( $record['hash'], $hash ) ) {
			if ( isset( self::retired()[ $hash ] ) ) {
				return self::error( 'revoked', 'This connection key was revoked or replaced in WordPress. Create a new one on the Contentrain Bridge screen.', 401 );
			}
			set_transient( self::failures( $ip ), $failures + 1, self::WINDOW );
			return self::error( 'invalid', 'The connection key is not valid. Copy it again from the Contentrain Bridge screen.', 401 );
		}
		if ( self::expired( $record, self::seen( $record ) ) ) {
			return self::error( 'expired', 'The connection key expired (an hour unused, or 14 days old). Create a new one on the Contentrain Bridge screen.', 401 );
		}
		wp_set_current_user( (int) $record['user'] );
		if ( ! Admin::permitted() ) {
			wp_set_current_user( 0 );
			return self::error( 'forbidden', 'The administrator who created the connection key can no longer export this site.', 403 );
		}
		$pairing = $request->get_param( self::PAIRING );
		if ( ! is_string( $pairing ) || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/D', $pairing ) ) {
			wp_set_current_user( 0 );
			return self::error( 'pairing_required', 'contentrain_pairing (8 to 64 letters, digits, - or _) is required with a connection key.', 400 );
		}
		if ( null !== $record['pairing'] && ! hash_equals( $record['pairing'], $pairing ) ) {
			wp_set_current_user( 0 );
			return self::error( 'paired_elsewhere', 'This connection key belongs to another Migrate order. Create a new one for this order.', 403 );
		}
		// The export in the route itself, never a body or query field of the same name.
		$id = $request->get_url_params()['id'] ?? null;
		if ( null !== $id && ! in_array( $id, self::exports(), true ) ) {
			wp_set_current_user( 0 );
			return new \WP_Error( 'bridge_not_found', 'No such export for this connection key.', array( 'status' => 404 ) );
		}
		if ( null === $record['pairing'] ) {
			$record['pairing'] = $pairing;
			update_option( self::OPTION, $record, false );
		}
		update_option( self::SEEN, array( 'at' => time(), 'ip' => $ip ), false );
		self::$active = true;
		return true;
	}

	/** The exports this key started or reused, newest last. */
	public static function exports() {
		$ids = get_option( self::EXPORTS );
		return is_array( $ids ) ? array_values( array_filter( $ids, static function ( $id ) { return is_string( $id ) && preg_match( '/^[a-f0-9]{32}$/D', $id ); } ) ) : array();
	}

	/** Record an export as this key's; called under the remote start lock. */
	public static function bind( $id ) {
		$ids = array_values( array_diff( self::exports(), array( $id ) ) );
		$ids[] = $id;
		update_option( self::EXPORTS, array_slice( $ids, -self::KEPT_EXPORTS ), false );
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

	private static function expired( $record, $seen ) {
		$now = time();
		return $now - $seen['at'] > self::IDLE || $now - (int) $record['created_at'] > self::LIFETIME;
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
