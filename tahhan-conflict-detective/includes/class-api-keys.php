<?php
/**
 * API key management for the remote status endpoint.
 *
 * @package TahhanConflictDetective
 * @since   2.7.0
 */

declare( strict_types=1 );

namespace TahhanConflictDetective;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A site holds at most one key. Only its SHA-256 hash is stored; the
 * plaintext is returned once by generate() and never persisted.
 *
 * @since 2.7.0
 */
final class Api_Keys {

	const OPTION         = 'tahcd_api_key';
	/** Kept separate so verify() never rewrites OPTION (a concurrent rotate/revoke would be undone). */
	const LAST_USED      = 'tahcd_api_key_last_used';
	const PREFIX         = 'tahcd_';
	const PATTERN        = '/^tahcd_[a-f0-9]{64}\\z/';
	const EXPIRY_CHOICES = array( 30, 90, 365, 0 );

	/** last_used is written at most this often to avoid a DB write per request. */
	private const LAST_USED_RESOLUTION = 300;

	/**
	 * Creates a new key, replacing any existing one.
	 *
	 * @param  int $expiry_days One of EXPIRY_CHOICES; 0 = never expires.
	 * @return string Plaintext key — show it to the admin once.
	 */
	public static function generate( int $expiry_days ): string {
		if ( ! in_array( $expiry_days, self::EXPIRY_CHOICES, true ) ) {
			$expiry_days = 90;
		}

		$key = self::PREFIX . bin2hex( random_bytes( 32 ) );
		$now = time();

		update_option(
			self::OPTION,
			array(
				'hash'      => hash( 'sha256', $key ),
				'hint'      => substr( $key, -4 ),
				'created'   => $now,
				'expires'   => $expiry_days > 0 ? $now + $expiry_days * DAY_IN_SECONDS : 0,
			),
			false
		);
		delete_option( self::LAST_USED );

		return $key;
	}

	public static function revoke(): void {
		delete_option( self::OPTION );
		delete_option( self::LAST_USED );
	}

	/**
	 * Constant-time verification of a presented key.
	 */
	public static function verify( string $key ): bool {
		if ( 1 !== preg_match( self::PATTERN, $key ) ) {
			return false;
		}

		$data = self::get_raw();
		if ( null === $data || self::is_expired( $data ) ) {
			return false;
		}

		if ( ! hash_equals( $data['hash'], hash( 'sha256', $key ) ) ) {
			return false;
		}

		$now = time();
		if ( $now - (int) get_option( self::LAST_USED, 0 ) > self::LAST_USED_RESOLUTION ) {
			update_option( self::LAST_USED, $now, false );
		}

		return true;
	}

	/**
	 * True when a key exists and has not expired.
	 */
	public static function is_active(): bool {
		$data = self::get_raw();
		return null !== $data && ! self::is_expired( $data );
	}

	/**
	 * Public metadata about the current key (never includes the hash).
	 *
	 * @return array{hint: string, created: int, expires: int, last_used: int, expired: bool}|null
	 */
	public static function get_info(): ?array {
		$data = self::get_raw();
		if ( null === $data ) {
			return null;
		}
		return array(
			'hint'      => $data['hint'],
			'created'   => $data['created'],
			'expires'   => $data['expires'],
			'last_used' => (int) get_option( self::LAST_USED, 0 ),
			'expired'   => self::is_expired( $data ),
		);
	}

	/**
	 * @return array{hash: string, hint: string, created: int, expires: int}|null
	 */
	private static function get_raw(): ?array {
		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) || empty( $data['hash'] ) || ! is_string( $data['hash'] ) ) {
			return null;
		}
		return array(
			'hash'      => $data['hash'],
			'hint'      => isset( $data['hint'] ) ? (string) $data['hint'] : '',
			'created'   => isset( $data['created'] ) ? (int) $data['created'] : 0,
			'expires'   => isset( $data['expires'] ) ? (int) $data['expires'] : 0,
		);
	}

	private static function is_expired( array $data ): bool {
		return $data['expires'] > 0 && time() > $data['expires'];
	}
}
