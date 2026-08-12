<?php

if (!defined('ABSPATH')) {
    exit;
}

class RMMIGRATE_IO
{
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
        $written = Rmmigrate_Filesystem::put_contents($tmp, $contents);
        if ($written === false) {
            Rmmigrate_Filesystem::delete($tmp);
            return false;
        }
        if (!Rmmigrate_Filesystem::move($tmp, $path)) {
            Rmmigrate_Filesystem::delete($tmp);
            return false;
        }
        return true;
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
        $patterns = array(
            '/passphrase\s*[=:]\s*\S+/i',
            '/password\s*[=:]\s*\S+/i',
            '/secret\s*[=:]\s*\S+/i',
            '/api_key\s*[=:]\s*\S+/i',
            '/access_token\s*[=:]\s*\S+/i',
            '/worker_token\s*[=:]\s*\S+/i',
            '/nonce\s*[=:]\s*\S+/i',
            '/Bearer\s+[A-Za-z0-9._\-]+/i',
            '/Authorization:\s*\S+/i',
            '/cron_key\s*[=:]\s*\S+/i',
            '/mm_token\s*[=:]\s*\S+/i',
        );
        foreach ($patterns as $pattern) {
            $message = preg_replace($pattern, '[redacted]', $message) ?? $message;
        }
        return $message;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function redact_context(array $context): array
    {
        $redacted = array();
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $redacted[$key] = self::redact_log_message($value);
                continue;
            }
            if (is_array($value)) {
                $redacted[$key] = self::redact_context($value);
                continue;
            }
            $redacted[$key] = $value;
        }
        return $redacted;
    }
}
