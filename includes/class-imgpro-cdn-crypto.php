<?php
/**
 * ImgPro CDN Cryptography Utilities
 *
 * Provides encryption/decryption for sensitive data like API keys.
 * Uses AES-256-GCM with WordPress AUTH_KEY as the encryption key.
 *
 * SECURITY: API keys are encrypted at rest in wp_options to prevent
 * exposure via database dumps, SQL injection, or unauthorized access.
 *
 * @package ImgPro_CDN
 * @since   0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cryptography utility class
 *
 * @since 0.2.0
 */
class ImgPro_CDN_Crypto {

    /**
     * Cipher algorithm
     *
     * AES-256-GCM provides authenticated encryption (integrity + confidentiality)
     *
     * @var string
     */
    const CIPHER = 'aes-256-gcm';

    /**
     * Tag length for GCM mode
     *
     * @var int
     */
    const TAG_LENGTH = 16;

    /**
     * Encrypted data prefix for identification
     *
     * @var string
     */
    const ENCRYPTED_PREFIX = 'enc:';

    /**
     * Encrypt a value
     *
     * Falls back to plaintext storage if encryption is unavailable.
     * This is acceptable as encryption is defense-in-depth, not primary security.
     *
     * @param string $value Plaintext value to encrypt.
     * @return string Encrypted value (base64 encoded with prefix) or original on failure.
     */
    public static function encrypt($value) {
        if (empty($value) || !is_string($value)) {
            return $value;
        }

        // Already encrypted
        if (self::is_encrypted($value)) {
            return $value;
        }

        // Check if OpenSSL is available
        if (!function_exists('openssl_encrypt')) {
            return $value;
        }

        $key = self::get_encryption_key();
        if (empty($key)) {
            return $value;
        }

        // Generate random IV (12 bytes for GCM)
        $iv = openssl_random_pseudo_bytes(12);
        if (false === $iv) {
            return $value;
        }

        $tag = '';
        $encrypted = openssl_encrypt(
            $value,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if (false === $encrypted) {
            return $value;
        }

        // Combine IV + tag + ciphertext and base64 encode
        $combined = $iv . $tag . $encrypted;
        return self::ENCRYPTED_PREFIX . base64_encode($combined);
    }

    /**
     * Decrypt a value
     *
     * @param string $value Encrypted value to decrypt.
     * @return string Decrypted plaintext or original value if not encrypted/on failure.
     */
    public static function decrypt($value) {
        if (empty($value) || !is_string($value)) {
            return $value;
        }

        // Not encrypted
        if (!self::is_encrypted($value)) {
            return $value;
        }

        // Check if OpenSSL is available
        if (!function_exists('openssl_decrypt')) {
            // Can't decrypt without OpenSSL - return empty to prevent using corrupted data
            return '';
        }

        $key = self::get_encryption_key();
        if (empty($key)) {
            return '';
        }

        // Remove prefix and decode
        $data = base64_decode(substr($value, strlen(self::ENCRYPTED_PREFIX)));
        if (false === $data) {
            return '';
        }

        // Extract IV (12 bytes), tag (16 bytes), and ciphertext
        if (strlen($data) < 28) { // 12 + 16 minimum
            return '';
        }

        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, self::TAG_LENGTH);
        $ciphertext = substr($data, 28);

        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $decrypted) {
            // Decryption failed - possible tampering or key change
            return '';
        }

        return $decrypted;
    }

    /**
     * Check if a value is encrypted
     *
     * @param string $value Value to check.
     * @return bool True if encrypted.
     */
    public static function is_encrypted($value) {
        return is_string($value) && strpos($value, self::ENCRYPTED_PREFIX) === 0;
    }

    /**
     * Get the encryption key derived from WordPress AUTH_KEY
     *
     * Uses HKDF to derive a proper 256-bit key from AUTH_KEY.
     * Falls back to SHA-256 hash if hash_hkdf is unavailable.
     *
     * @return string Binary key for encryption.
     */
    private static function get_encryption_key() {
        // Use AUTH_KEY as the master key material
        if (!defined('AUTH_KEY') || AUTH_KEY === 'put your unique phrase here') {
            // AUTH_KEY not properly configured
            return '';
        }

        $ikm = AUTH_KEY;
        $salt = 'imgpro_cdn_encryption_v1';
        $info = 'api_key_encryption';

        // Use HKDF if available (PHP 7.1.2+)
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $ikm, 32, $info, $salt);
        }

        // Fallback: simple derivation using HMAC
        return hash_hmac('sha256', $info . $salt, $ikm, true);
    }

    /**
     * Migrate plaintext API key to encrypted storage
     *
     * Call this during plugin upgrade to encrypt existing keys.
     *
     * @param array $settings Current settings array.
     * @return array Settings with encrypted API key.
     */
    public static function maybe_encrypt_api_key($settings) {
        if (!isset($settings['cloud_api_key']) || empty($settings['cloud_api_key'])) {
            return $settings;
        }

        $api_key = $settings['cloud_api_key'];

        // Already encrypted - no action needed
        if (self::is_encrypted($api_key)) {
            return $settings;
        }

        // Encrypt and update
        $settings['cloud_api_key'] = self::encrypt($api_key);

        return $settings;
    }
}
