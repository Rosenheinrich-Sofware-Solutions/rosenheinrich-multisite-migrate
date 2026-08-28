<?php

if (!defined('ABSPATH')) {
    exit;
}

class RMMIGRATE_IO
{
    private const REDACT_CONTEXT_MAX_DEPTH = 10;

    /** @var string[] */
    private const SECRET_CONTEXT_KEYS = array(
        'password',
        'passphrase',
        'secret',
        'access_token',
        'refresh_token',
        'client_secret',
        'private_key',
        'authorization_code',
        'api_key',
        'auth_token',
        'token',
        'worker_token',
        'cron_key',
        'mm_token',
        'nonce',
        'client_id',
    );

    /**
     * Write file atomically (temp + rename).
     */
    public static function write_atomic(string $path, string $contents): bool
    {
        $dir = dirname($path);
        if (!Rmmigrate_Filesystem::is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        $tmp = $path . '.tmp.' . wp_generate_password(8, false, false);
        try {
            $written = Rmmigrate_Filesystem::put_contents($tmp, $contents);
            if ($written === false) {
                return false;
            }
            if (!Rmmigrate_Filesystem::move($tmp, $path)) {
                return false;
            }

            return true;
        } finally {
            if (Rmmigrate_Filesystem::exists($tmp)) {
                Rmmigrate_Filesystem::delete($tmp);
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function write_json_atomic(string $path, array $data): bool
    {
        $json = wp_json_encode($data);
        if ($json === false) {
            return false;
        }
        return self::write_atomic($path, $json);
    }

    public static function truncate_file(string $path, int $length): bool
    {
        if (!Rmmigrate_Filesystem::is_file($path)) {
            return false;
        }
        if ($length <= 0) {
            return self::write_atomic($path, '');
        }
        $fh = Rmmigrate_Filesystem::open($path, 'c+b');
        if ($fh === false) {
            return false;
        }
        $ok = $fh->truncate($length);
        $fh->close();
        return $ok;
    }

    /**
     * Strip likely secrets from log lines.
     */
    public static function redact_log_message(string $message): string
    {
        $quoted_val = '(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')';
        $unquoted_val = '[^"\',;}\s]+';
        $val = '(?:' . $quoted_val . '|' . $unquoted_val . ')';
        $keys = array(
            'passphrase',
            'password',
            'secret',
            'refresh_token',
            'client_secret',
            'private_key',
            'authorization_code',
            'api_key',
            'access_token',
            'auth_token',
            'worker_token',
            'mm_token',
            'token',
            'client_id',
            'nonce',
            'cron_key',
        );
        $patterns = array();
        foreach ($keys as $key) {
            $patterns[] = '/' . $key . '\s*["\']?\s*[=:]\s*["\']?' . $val . '/i';
        }
        $patterns[] = '/Bearer\s+[A-Za-z0-9._\-]+/i';
        $patterns[] = '/Authorization:\s*' . $val . '/i';
        foreach ($patterns as $pattern) {
            $message = preg_replace($pattern, '[redacted]', $message) ?? $message;
        }
        return $message;
    }

    private static function is_secret_context_key(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SECRET_CONTEXT_KEYS as $secret_key) {
            if ($normalized === $secret_key || strpos($normalized, $secret_key) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function redact_context(array $context, int $depth = 0): array
    {
        if ($depth >= self::REDACT_CONTEXT_MAX_DEPTH) {
            return array('[redacted]' => '[redacted]');
        }

        $redacted = array();
        foreach ($context as $key => $value) {
            if (is_string($key) && self::is_secret_context_key($key)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            if (is_string($value)) {
                $redacted[$key] = self::redact_log_message($value);
                continue;
            }
            if (is_array($value)) {
                $redacted[$key] = self::redact_context($value, $depth + 1);
                continue;
            }
            $redacted[$key] = $value;
        }
        return $redacted;
    }
}
