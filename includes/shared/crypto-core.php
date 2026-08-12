<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Archive key derivation and authenticated chunk codec shared by the plugin
 * (encrypt + in-WP restore) and the standalone installer (restore).
 *
 * Two on-disk formats exist:
 *  - MMIGENC1 (legacy): AES-256-CBC, IV-chained, unsalted SHA-256 key, no MAC.
 *    Retained for DECRYPTION ONLY so older backups keep working.
 *  - MMIGENC2 (current): per-archive random salt + PBKDF2-HMAC-SHA256 key
 *    derivation + AES-256-GCM (AEAD) per chunk. Authenticated and salted.
 */
class Rmmigrate_Crypto_Core
{
    const MAGIC_V1 = 'MMIGENC1';
    const MAGIC_V2 = 'MMIGENC2';

    const V2_ITERATIONS = 200000;
    const V2_SALT_LEN   = 16;
    const V2_NONCE_LEN  = 12;
    const V2_TAG_LEN    = 16;

    /**
     * Legacy v1 key derivation (unsalted SHA-256). Do NOT use for new archives;
     * kept only to decrypt pre-v2 backups.
     */
    public static function archive_key(string $passphrase): string
    {
        if ($passphrase !== '') {
            return hash('sha256', $passphrase, true);
        }
        return hash('sha256', 'multisite-migrate', true);
    }

    /**
     * Normalize a passphrase into the key-derivation secret. An empty passphrase
     * falls back to a public constant so that no-passphrase archives stay
     * portable to the standalone installer (which cannot reproduce site salts).
     *
     * Note: no-passphrase archives are therefore obfuscated, not confidential —
     * the v2 salt + AES-GCM still add per-archive uniqueness and tamper
     * detection, but real confidentiality requires a passphrase.
     */
    public static function v2_secret(string $passphrase): string
    {
        return $passphrase !== '' ? $passphrase : 'multisite-migrate';
    }

    /**
     * v2 key: PBKDF2-HMAC-SHA256 over the secret with a per-archive salt.
     */
    public static function derive_v2_key(string $secret, string $salt, int $iterations): string
    {
        if ($iterations <= 0) {
            $iterations = self::V2_ITERATIONS;
        }
        return hash_pbkdf2('sha256', $secret, $salt, $iterations, 32, true);
    }

    /**
     * Build the v2 file header: magic + salt + iteration count.
     */
    public static function v2_header(string $salt, int $iterations): string
    {
        return self::MAGIC_V2 . $salt . pack('N', $iterations);
    }

    public static function header_size_v2(): int
    {
        return strlen(self::MAGIC_V2) + self::V2_SALT_LEN + 4;
    }

    /**
     * Seal a plaintext chunk with AES-256-GCM.
     *
     * @return string nonce(12) . tag(16) . ciphertext, or '' on failure.
     */
    public static function v2_seal(string $plain, string $key): string
    {
        $nonce = random_bytes(self::V2_NONCE_LEN);
        $tag   = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::V2_TAG_LEN);
        if ($cipher === false) {
            return '';
        }
        return $nonce . $tag . $cipher;
    }

    /**
     * Open a sealed blob (nonce . tag . ciphertext). Returns false when the
     * authentication tag does not verify (tampering/wrong key).
     *
     * @return string|false
     */
    public static function v2_open(string $blob, string $key)
    {
        if (strlen($blob) < self::V2_NONCE_LEN + self::V2_TAG_LEN) {
            return false;
        }
        $nonce  = substr($blob, 0, self::V2_NONCE_LEN);
        $tag    = substr($blob, self::V2_NONCE_LEN, self::V2_TAG_LEN);
        $cipher = substr($blob, self::V2_NONCE_LEN + self::V2_TAG_LEN);

        return openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    }
}
