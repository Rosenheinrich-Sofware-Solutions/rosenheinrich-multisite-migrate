<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side import chunk size limits (PHP ini, WordPress caps, multipart overhead).
 */
class Rmmigrate_Import_Upload_Limits
{
    /** Headroom for multipart fields, boundary, and nonce on admin-ajax chunk POSTs. */
    public const MULTIPART_OVERHEAD_BYTES = 98304;

    public const MIN_CHUNK_BYTES = 65536;

    /**
     * @return array{
     *     upload_max_bytes:int,
     *     post_max_bytes:int,
     *     wp_max_upload_bytes:int,
     *     recommended_chunk_bytes:int,
     *     limiting_factor:string
     * }
     */
    public static function limits(): array
    {
        $upload_max = self::ini_bytes((string) ini_get('upload_max_filesize'));
        $post_max   = self::ini_bytes((string) ini_get('post_max_size'));
        $wp_max     = (int) wp_max_upload_size();

        $blocking_safe = class_exists('Rmmigrate_Extract_Engine')
            ? (int) Rmmigrate_Extract_Engine::BLOCKING_SAFE_BYTES
            : 20971520;
        $wire_cap = max(self::MIN_CHUNK_BYTES, $blocking_safe - 1048576);

        $candidates = array();
        if ($upload_max > 0) {
            $candidates['upload_max_filesize'] = max(self::MIN_CHUNK_BYTES, $upload_max - self::MULTIPART_OVERHEAD_BYTES);
        }
        if ($post_max > 0) {
            $candidates['post_max_size'] = max(self::MIN_CHUNK_BYTES, $post_max - self::MULTIPART_OVERHEAD_BYTES);
        }
        if ($wp_max > 0) {
            $candidates['wp_max_upload_size'] = max(self::MIN_CHUNK_BYTES, (int) floor($wp_max / 2));
        }
        $candidates['wire_cap'] = $wire_cap;

        $recommended = $wire_cap;
        $limiting    = 'wire_cap';
        foreach ($candidates as $factor => $bytes) {
            if ($bytes < $recommended) {
                $recommended = $bytes;
                $limiting    = $factor;
            }
        }

        return array(
            'upload_max_bytes'          => $upload_max,
            'post_max_bytes'            => $post_max,
            'wp_max_upload_bytes'       => $wp_max,
            'recommended_chunk_bytes'   => (int) $recommended,
            'limiting_factor'           => $limiting,
        );
    }

    public static function recommended_chunk_bytes(?int $settings_chunk = null): int
    {
        $limits = self::limits();
        $cap    = (int) $limits['recommended_chunk_bytes'];
        if ($settings_chunk === null) {
            $settings_chunk = (int) (Rmmigrate_Settings::get()['import_chunk_size'] ?? 2097152);
        }
        $settings_chunk = max(self::MIN_CHUNK_BYTES, $settings_chunk);

        return (int) min($settings_chunk, $cap);
    }

    public static function ini_bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        if (function_exists('wp_convert_hr_to_bytes')) {
            return (int) wp_convert_hr_to_bytes($value);
        }

        $last  = strtolower(substr($value, -1));
        $bytes = (int) $value;
        switch ($last) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
                break;
        }

        return $bytes;
    }

    /**
     * @return array<string,int|string>
     */
    public static function telemetry_fields(int $attempted_chunk_bytes = 0, string $reject_reason = ''): array
    {
        $limits = self::limits();
        $fields = array(
            'php_version'               => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'upload_max_bytes'          => (int) $limits['upload_max_bytes'],
            'post_max_bytes'            => (int) $limits['post_max_bytes'],
            'wp_max_upload_bytes'       => (int) $limits['wp_max_upload_bytes'],
            'recommended_chunk_bytes'   => (int) $limits['recommended_chunk_bytes'],
            'chunk_limit_factor'        => sanitize_key((string) $limits['limiting_factor']),
        );
        if ($attempted_chunk_bytes > 0) {
            $fields['chunk_bytes'] = $attempted_chunk_bytes;
        }
        if ($reject_reason !== '') {
            $fields['chunk_reject_reason'] = sanitize_key($reject_reason);
        }

        return $fields;
    }

    public static function format_bytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = array('B', 'KB', 'MB', 'GB');
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / pow(1024, $power);

        return round($value, 1) . ' ' . $units[$power];
    }

    public static function user_limit_exceeded_message(): string
    {
        $limits = self::limits();
        $factor = (string) $limits['limiting_factor'];
        $bytes  = (int) $limits['recommended_chunk_bytes'];

        $limit_bytes = $bytes;
        if ($factor === 'upload_max_filesize') {
            $label = __('PHP upload_max_filesize', 'rosenheinrich-multisite-migrate');
            $limit_bytes = (int) $limits['upload_max_bytes'];
        } elseif ($factor === 'post_max_size') {
            $label = __('PHP post_max_size', 'rosenheinrich-multisite-migrate');
            $limit_bytes = (int) $limits['post_max_bytes'];
        } elseif ($factor === 'wp_max_upload_size') {
            $label = __('WordPress upload size', 'rosenheinrich-multisite-migrate');
            $limit_bytes = (int) $limits['wp_max_upload_bytes'];
        } else {
            $label = __('HTTP request size', 'rosenheinrich-multisite-migrate');
        }

        return sprintf(
            /* translators: 1: PHP or server limit name, 2: human-readable size */
            __('Import upload blocked: the server %1$s limit (%2$s) is too small for chunked uploads. Ask your host to raise that limit, or use a smaller archive.', 'rosenheinrich-multisite-migrate'),
            $label,
            self::format_bytes($limit_bytes)
        );
    }

    public static function can_downsize_chunk(int $current_chunk_bytes): bool
    {
        return $current_chunk_bytes > self::MIN_CHUNK_BYTES;
    }

    public static function next_chunk_bytes(int $current_chunk_bytes): int
    {
        $recommended = self::recommended_chunk_bytes();
        $halved      = max(self::MIN_CHUNK_BYTES, (int) floor($current_chunk_bytes / 2));
        if ($recommended > 0 && $recommended < $halved) {
            return $recommended;
        }

        return $halved;
    }
}
