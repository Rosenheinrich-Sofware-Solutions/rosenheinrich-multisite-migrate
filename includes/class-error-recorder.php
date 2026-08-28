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
