<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Crypto
{
    /** @var array<string,bool> */
    private static $failed_decrypt = array();

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = self::key();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return '';
        }
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        if (isset(self::$failed_decrypt[$encoded])) {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            self::$failed_decrypt[$encoded] = true;
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            self::$failed_decrypt[$encoded] = true;
            return '';
        }
        return $plain;
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
