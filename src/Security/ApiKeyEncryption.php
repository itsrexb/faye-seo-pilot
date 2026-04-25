<?php
/**
 * Encrypt and decrypt API keys stored in wp_options.
 *
 * Keys are encrypted with libsodium (sodium_crypto_secretbox, XSalsa20-Poly1305).
 * The encryption key is derived from WordPress secret constants defined in wp-config.php,
 * so a database dump alone is not enough to recover the plaintext.
 *
 * Stored format: "enc1:" + base64( nonce[24] || ciphertext )
 * The "enc1:" prefix lets us distinguish encrypted values from legacy plain-text keys and
 * supports forward-compatible versioning.
 *
 * NOTE: If the WordPress secret keys in wp-config.php are regenerated, previously encrypted
 * values will no longer be decryptable. Users must re-enter their API keys in that case.
 *
 * @package FayeSeoPilot
 */

declare( strict_types=1 );

namespace FayeSeoPilot\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApiKeyEncryption {

	private const PREFIX = 'enc1:';

	/**
	 * Derive a 32-byte encryption key from WordPress secret constants.
	 * Falls back to site URL + DB_PASSWORD if no constants are defined.
	 */
	private static function derive_key(): string {
		$material = '';

		foreach ( [ 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'AUTH_KEY', 'SECURE_AUTH_SALT' ] as $const ) {
			if ( defined( $const ) ) {
				$material .= constant( $const );
			}
		}

		if ( $material === '' ) {
			// Last-resort fallback (wp-config.php is missing secret keys entirely).
			$material = site_url() . ( defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '' );
		}

		return hash( 'sha256', $material, true ); // 32 raw bytes
	}

	/**
	 * Encrypt a plaintext API key.
	 * Returns an empty string if $plaintext is empty.
	 * Returns $plaintext as-is if libsodium is unavailable (PHP < 7.2 without polyfill).
	 */
	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return '';
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			// Sodium not available — store plain rather than silently failing.
			return $plaintext;
		}

		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ); // 24 bytes
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::derive_key() );

		// Wipe the plaintext from memory.
		sodium_memzero( $plaintext );

		return self::PREFIX . base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a stored API key value.
	 *
	 * - If $stored is empty, returns ''.
	 * - If $stored does not start with the prefix (legacy plain-text), returns it as-is.
	 * - If decryption fails (wrong key, tampered data), returns '' so the caller
	 *   treats it as "no key configured" rather than leaking garbage.
	 */
	public static function decrypt( string $stored ): string {
		if ( $stored === '' ) {
			return '';
		}

		// Legacy plain-text key — transparently pass through until next save.
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return $stored;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}

		$decoded = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );

		if ( $decoded === false || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::derive_key() );

		if ( $plaintext === false ) {
			// Decryption failed — key likely changed.
			return '';
		}

		return $plaintext;
	}

	/**
	 * Return true if the stored value is an encrypted blob (not plain-text).
	 */
	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}
}
