<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display-time formatting helpers.
 *
 * Job timestamps (completed_at, created_at, updated_at) are stored as UTC MySQL
 * strings via current_time('mysql', true). Schedule next_run values are Unix
 * timestamps. Subsite admin renders in that site's timezone. Network admin
 * always renders UTC so a New York main site does not shift 03:00 UTC to
 * 11:15 pm on network-wide lists (filters are UTC-day already).
 */
class Rmmigrate_Time
{
    const DISPLAY_FORMAT = 'Y-m-d g:i a';

    /**
     * Format a UTC MySQL datetime string in the display timezone.
     */
    public static function local(string $gmt_datetime, string $format = self::DISPLAY_FORMAT): string
    {
        $gmt_datetime = trim($gmt_datetime);
        if ($gmt_datetime === '') {
            return '';
        }

        $dt = date_create($gmt_datetime, new DateTimeZone('UTC'));
        if ($dt === false) {
            $timestamp = strtotime($gmt_datetime . ' UTC');

            return $timestamp ? self::local_ts($timestamp, $format) : $gmt_datetime;
        }

        $dt->setTimezone(self::display_timezone());

        return $dt->format($format);
    }

    /**
     * Format a Unix timestamp in the display timezone (translated weekday/month names).
     */
    public static function local_ts(int $timestamp, string $format = self::DISPLAY_FORMAT): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        if (function_exists('wp_date')) {
            return (string) wp_date($format, $timestamp, self::display_timezone());
        }

        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone(self::display_timezone());

        return $dt->format($format);
    }

    /**
     * Format a UTC MySQL datetime string in the display timezone with the timezone
     * label appended, e.g. "2026-06-26 9:18 am (America/New_York)".
     */
    public static function local_label(string $gmt_datetime, string $format = self::DISPLAY_FORMAT): string
    {
        $formatted = self::local($gmt_datetime, $format);

        return $formatted === '' ? '' : $formatted . ' (' . self::tz_label() . ')';
    }

    /**
     * Format a Unix timestamp in the display timezone with the timezone label appended.
     */
    public static function local_ts_label(int $timestamp, string $format = self::DISPLAY_FORMAT): string
    {
        $formatted = self::local_ts($timestamp, $format);

        return $formatted === '' ? '' : $formatted . ' (' . self::tz_label() . ')';
    }

    /**
     * Timezone label for column headers, e.g. "UTC" or "America/New_York".
     */
    public static function tz_label(): string
    {
        if (self::use_utc_display()) {
            return 'UTC';
        }
        if (function_exists('wp_timezone_string')) {
            $label = (string) wp_timezone_string();
            if ($label !== '') {
                return $label;
            }
        }

        return 'UTC';
    }

    /**
     * Timezone used for admin clocks. Network screens stay on UTC.
     */
    public static function display_timezone(): DateTimeZone
    {
        if (defined('RMMIGRATE_UNIT_TEST') && RMMIGRATE_UNIT_TEST && !empty($GLOBALS['mm_test_timezone'])) {
            try {
                return new DateTimeZone((string) $GLOBALS['mm_test_timezone']);
            } catch (Exception $e) {
                unset($e);
            }
        }
        if (self::use_utc_display()) {
            return new DateTimeZone('UTC');
        }
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        return new DateTimeZone('UTC');
    }

    public static function use_utc_display(): bool
    {
        if (defined('RMMIGRATE_UNIT_TEST') && RMMIGRATE_UNIT_TEST && isset($GLOBALS['mm_test_use_utc_display'])) {
            return (bool) $GLOBALS['mm_test_use_utc_display'];
        }
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }
        if (function_exists('is_network_admin') && is_network_admin()) {
            return true;
        }

        return self::request_from_network_admin();
    }

    private static function request_from_network_admin(): bool
    {
        if (function_exists('wp_get_referer')) {
            $ref = (string) wp_get_referer();
            if ($ref !== '' && strpos($ref, '/wp-admin/network/') !== false) {
                return true;
            }
        }
        if (class_exists('Rmmigrate_Request_Input', false)) {
            $flag = (string) Rmmigrate_Request_Input::post_key('is_network');
            if ($flag === '1' || $flag === 'true') {
                return true;
            }
        }

        return false;
    }
}
