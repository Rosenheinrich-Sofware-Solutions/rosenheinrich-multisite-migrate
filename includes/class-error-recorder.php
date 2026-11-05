<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ring buffer of recent plugin errors for deactivate feedback.
 */
final class Rmmigrate_Error_Recorder
{
    const OPTION_KEY = 'rmmigrate_recent_errors';

    const MAX_ENTRIES = 5;

    const MESSAGE_MAX = 500;

    const DEDUP_SECONDS = 60;

    /** Last PHP fatal raised inside this plugin (for opt-in feedback only). */
    const LAST_FATAL_OPTION = 'rmmigrate_last_fatal';

    /** Fatals older than this are not attached to feedback (30 days). */
    const LAST_FATAL_MAX_AGE = 2592000;

    /**
     * @param array{code?:string,message?:string,job_type?:string,source?:string,time?:string} $entry
     */
    public static function record(array $entry): void
    {
        $code     = sanitize_key((string) ($entry['code'] ?? ''));
        $message  = self::sanitize_message(isset($entry['message']) ? (string) $entry['message'] : '');
        $job_type = sanitize_key((string) ($entry['job_type'] ?? ''));
        $source   = sanitize_key((string) ($entry['source'] ?? 'job'));
        if (!in_array($source, array('job', 'service', 'fatal'), true)) {
            $source = 'job';
        }
        $time = (string) ($entry['time'] ?? '');
        if ($time === '') {
            $time = current_time('mysql', true);
        }

        if ($code === '' && $message === '') {
            return;
        }
        if ($code === '') {
            $code = Rmmigrate_Error_Codes::from_message($message);
        }

        $normalized = array(
            'code'     => $code,
            'message'  => $message,
            'job_type' => $job_type,
            'source'   => $source,
            'time'     => $time,
        );

        $recent = self::get_recent();
        if (self::should_dedup($recent, $normalized)) {
            return;
        }

        array_unshift($recent, $normalized);
        if (count($recent) > self::MAX_ENTRIES) {
            $recent = array_slice($recent, 0, self::MAX_ENTRIES);
        }

        update_site_option(self::OPTION_KEY, $recent);
    }

    public static function record_from_throwable(Throwable $e, string $source, string $job_type = ''): void
    {
        self::record(
            array(
                'code'     => Rmmigrate_Error_Codes::from_throwable($e),
                'message'  => $e->getMessage(),
                'job_type' => $job_type,
                'source'   => $source,
            )
        );
    }

    /**
     * @return list<array{code:string,message:string,job_type:string,source:string,time:string}>
     */
    public static function get_recent(): array
    {
        $raw = get_site_option(self::OPTION_KEY, array());
        if (!is_array($raw)) {
            return array();
        }

        $out = array();
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = sanitize_key((string) ($row['code'] ?? ''));
            $message = self::sanitize_message(isset($row['message']) ? (string) $row['message'] : '');
            if ($code === '' && $message === '') {
                continue;
            }
            $source = sanitize_key((string) ($row['source'] ?? 'job'));
            if (!in_array($source, array('job', 'service', 'fatal'), true)) {
                $source = 'job';
            }
            $out[] = array(
                'code'     => $code !== '' ? $code : Rmmigrate_Error_Codes::from_message($message),
                'message'  => $message,
                'job_type' => sanitize_key((string) ($row['job_type'] ?? '')),
                'source'   => $source,
                'time'     => (string) ($row['time'] ?? ''),
            );
            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return $out;
    }

    public static function clear(): void
    {
        delete_site_option(self::OPTION_KEY);
    }

    public static function register_fatal_capture(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function(array(__CLASS__, 'capture_plugin_fatal'));
    }

    /**
     * Shutdown handler: remember the last fatal error whose file is inside this plugin.
     * Nothing is sent anywhere; feedback attaches it only when the user submits.
     *
     * @param array<string,mixed>|null $error Defaults to error_get_last().
     */
    public static function capture_plugin_fatal(?array $error = null): void
    {
        if ($error === null) {
            $error = error_get_last();
        }
        if (!is_array($error)) {
            return;
        }
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array((int) ($error['type'] ?? 0), $fatal_types, true)) {
            return;
        }
        $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/') . '/';
        $file = str_replace('\\', '/', (string) ($error['file'] ?? ''));
        $prefix_matches = strpos($file, $root) === 0
            || (preg_match('/^[A-Za-z]:\//', $root) === 1 && stripos($file, $root) === 0);
        if (!$prefix_matches || !function_exists('update_site_option')) {
            return;
        }
        try {
            $message = (string) ($error['message'] ?? '');
            $trace_at = strpos($message, 'Stack trace:');
            if ($trace_at !== false) {
                $message = substr($message, 0, $trace_at);
            }
            update_site_option(self::LAST_FATAL_OPTION, array(
                'message' => self::sanitize_message($message),
                'where'   => substr(preg_replace('/[^A-Za-z0-9_.\/-]/', '', substr($file, strlen($root))), 0, 120) . ':' . (int) ($error['line'] ?? 0),
                'version' => defined('RMMIGRATE_VERSION') ? (string) RMMIGRATE_VERSION : '',
                'time'    => gmdate('Y-m-d H:i:s'),
            ));
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * @return array{code:string,message:string,job_type:string,source:string,time:string}|null
     */
    public static function get_last_fatal(): ?array
    {
        $raw = get_site_option(self::LAST_FATAL_OPTION, array());
        if (!is_array($raw) || (string) ($raw['message'] ?? '') === '') {
            return null;
        }
        $time = (string) ($raw['time'] ?? '');
        $ts = strtotime($time . ' UTC');
        if ($ts === false || $ts < time() - self::LAST_FATAL_MAX_AGE) {
            return null;
        }
        $version = sanitize_text_field((string) ($raw['version'] ?? ''));
        $where = sanitize_text_field((string) ($raw['where'] ?? ''));
        $message = trim(
            ($version !== '' ? 'v' . $version . ' ' : '')
            . ($where !== '' ? $where . ' ' : '')
            . self::sanitize_message((string) $raw['message'])
        );
        if (function_exists('mb_substr')) {
            $message = (string) mb_substr($message, 0, self::MESSAGE_MAX);
        } else {
            $message = substr($message, 0, self::MESSAGE_MAX);
        }

        return array(
            'code'     => 'php_fatal',
            'message'  => $message,
            'job_type' => '',
            'source'   => 'fatal',
            'time'     => $time,
        );
    }

    public static function sanitize_message(?string $message): string
    {
        if ($message === null || $message === '') {
            return '';
        }
        $text = wp_strip_all_tags($message);
        $text = self::redact_paths($text);
        $text = self::redact_emails($text);
        $text = RMMIGRATE_IO::redact_log_message($text);
        $text = sanitize_text_field($text);
        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, 0, self::MESSAGE_MAX);
        }

        return substr($text, 0, self::MESSAGE_MAX);
    }

    private static function redact_paths(string $text): string
    {
        // Known roots first: the generic patterns below stop at a space, so
        // "C:\Users\Jane Doe\site" would otherwise leak "Doe\site".
        $roots = array();
        foreach (array(
            defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
            defined('ABSPATH') ? ABSPATH : '',
            isset($_SERVER['DOCUMENT_ROOT']) ? sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])) : '',
            (string) getenv('HOME'),
            (string) getenv('USERPROFILE'),
        ) as $root) {
            $root = rtrim((string) $root, '/\\');
            if (strlen($root) < 2) {
                continue;
            }
            $roots[] = $root;
            $roots[] = str_replace('\\', '/', $root);
            $roots[] = str_replace('/', '\\', $root);
        }
        $roots = array_unique($roots);
        usort($roots, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });
        foreach ($roots as $root) {
            // Inner segments may contain spaces (a separator follows them); the last one may not,
            // so trailing context such as " on line 12" survives.
            $replaced = preg_replace(
                '#' . preg_quote($root, '#') . '(?:[\\\\/][^\\\\/\r\n"\'<>]+(?=[\\\\/]))*(?:[\\\\/][^\s\\\\/"\'<>]*)?#i',
                '[path]',
                $text
            );
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        $patterns = array(
            // Multi-segment absolute paths only (requires at least one extra separator).
            '#/(?:[^\s"\'<]+/[^\s"\'<]+(?:/[^\s"\'<]+)*)#',
            '#\\\\(?:[^\s"\'<]+\\\\[^\s"\'<]+(?:\\\\[^\s"\'<]+)*)#',
            '#[A-Za-z]:\\\\(?:[^\s"\'<]+\\\\[^\s"\'<]+(?:\\\\[^\s"\'<]+)*)#',
        );
        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, '[path]', $text);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        return $text;
    }

    private static function redact_emails(string $text): string
    {
        $replaced = preg_replace(
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
            '[email]',
            $text
        );

        return is_string($replaced) ? $replaced : $text;
    }

    /**
     * @param list<array{code:string,message:string,job_type:string,source:string,time:string}> $recent
     * @param array{code:string,message:string,job_type:string,source:string,time:string} $candidate
     */
    private static function should_dedup(array $recent, array $candidate): bool
    {
        if ($recent === array()) {
            return false;
        }
        $latest = $recent[0];
        if ((string) ($latest['code'] ?? '') !== $candidate['code']) {
            return false;
        }
        if ((string) ($latest['message'] ?? '') !== $candidate['message']) {
            return false;
        }

        $latest_ts = strtotime((string) ($latest['time'] ?? '') . ' UTC');
        $candidate_ts = strtotime($candidate['time'] . ' UTC');
        if ($latest_ts === false || $candidate_ts === false) {
            return false;
        }

        return abs($candidate_ts - $latest_ts) <= self::DEDUP_SECONDS;
    }
}
