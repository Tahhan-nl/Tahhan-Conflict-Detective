<?php
/**
 * Encryption at rest for secrets stored in options.
 *
 * @package TahhanConflictDetective
 * @since   2.8.0
 */

declare( strict_types=1 );

namespace TahhanConflictDetective;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * libsodium secretbox with a per-purpose key derived from the AUTH salt.
 * Format: "v1:" . base64( nonce(24) . ciphertext ). There is deliberately no
 * plaintext fallback: when sodium is unavailable, callers must refuse to store.
 *
 * Changing the site's salts makes stored secrets undecryptable (by design).
 *
 * @since 2.8.0
 */
final class Secret_Box {

	public static function available(): bool {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) && file_exists( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_generichash' );
	}

	/**
	 * @param string $context Purpose label; a different context yields a different key.
	 */
	public static function encrypt( string $plain, string $context ): ?string {
		if ( ! self::available() ) {
			return null;
		}
		$nonce = random_bytes( 24 );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key( $context ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext encoding for option storage.
	}

	public static function decrypt( string $stored, string $context ): ?string {
		if ( strlen( $stored ) < 4 || 0 !== strpos( $stored, 'v1:' ) || ! self::available() ) {
			return null;
		}
		$payload = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext decoding.
		if ( false === $payload || strlen( $payload ) <= 24 ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $payload, 24 ), substr( $payload, 0, 24 ), self::key( $context ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_string( $plain ) ? $plain : null;
	}

	private static function key( string $context ): string {
		// Must stay byte-identical for context "tahcd-remote-keys" (keys stored since 2.7.0).
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|' . $context, '', 32 );
	}
}
