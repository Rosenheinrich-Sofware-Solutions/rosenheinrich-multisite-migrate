<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Crypto
{
    private const MAGIC_GCM = 'MMSECRE1';
    private const GCM_NONCE_LEN = 12;
    private const GCM_TAG_LEN = 16;

    /** @var array<string,bool> */
    private static $failed_decrypt = array();

    public static function is_legacy_cbc(string $encoded): bool
    {
        if ($encoded === '') {
            return false;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return false;
        }
        $magic_len = strlen(self::MAGIC_GCM);

        return strlen($raw) < $magic_len || substr($raw, 0, $magic_len) !== self::MAGIC_GCM;
    }

    private static function decrypt_cache_key(string $encoded): string
    {
        return hash('sha256', $encoded);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = self::key();
        $nonce = random_bytes(self::GCM_NONCE_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::GCM_TAG_LEN
        );
        if ($cipher === false) {
            Rmmigrate_Logger::log('Secret encryption failed (openssl_encrypt returned false).');
            return '';
        }
        return base64_encode(self::MAGIC_GCM . $nonce . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        if (isset(self::$failed_decrypt[self::decrypt_cache_key($encoded)])) {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            self::$failed_decrypt[self::decrypt_cache_key($encoded)] = true;
            return '';
        }
        $magic_len = strlen(self::MAGIC_GCM);
        if (strlen($raw) >= $magic_len && substr($raw, 0, $magic_len) === self::MAGIC_GCM) {
            $plain = self::decrypt_gcm($raw, $magic_len);
            if ($plain === false) {
                self::$failed_decrypt[self::decrypt_cache_key($encoded)] = true;
                return '';
            }
            return $plain;
        }
        if (strlen($raw) < 17) {
            self::$failed_decrypt[self::decrypt_cache_key($encoded)] = true;
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            self::$failed_decrypt[self::decrypt_cache_key($encoded)] = true;
            return '';
        }
        return $plain;
    }

    /**
     * @return string|false
     */
    private static function decrypt_gcm(string $raw, int $magic_len)
    {
        $header_len = $magic_len + self::GCM_NONCE_LEN + self::GCM_TAG_LEN;
        if (strlen($raw) < $header_len) {
            return false;
        }
        $nonce = substr($raw, $magic_len, self::GCM_NONCE_LEN);
        $tag = substr($raw, $magic_len + self::GCM_NONCE_LEN, self::GCM_TAG_LEN);
        $cipher = substr($raw, $header_len);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? false : $plain;
    }

    /**
     * Derive AES key from WP salts. Single SHA-256 is sufficient here because
     * the input material (AUTH_KEY + SECURE_AUTH_KEY) is already high-entropy
     * (64+ random bytes). PBKDF stretching is used separately for user-supplied
     * archive passphrases in Rmmigrate_Crypto_Core::derive_v2_key().
     */
    private static function key(): string
    {
        $material = '';
        if (defined('AUTH_KEY')) {
            $material .= AUTH_KEY;
        }
        if (defined('SECURE_AUTH_KEY')) {
            $material .= SECURE_AUTH_KEY;
        }
        if ($material === '') {
            $material = wp_salt('auth');
        }
        return hash('sha256', $material, true);
    }
}
