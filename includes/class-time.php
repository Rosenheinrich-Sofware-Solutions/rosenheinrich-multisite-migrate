<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display-time formatting helpers.
 *
 * Job timestamps (completed_at, created_at, updated_at) are stored as UTC MySQL
 * strings via current_time('mysql', true). Schedule next_run values are Unix
 * timestamps. Both must be rendered in the site timezone with a 12-hour am/pm
 * clock for the admin UI.
 */
class Rmmigrate_Time
{
    const DISPLAY_FORMAT = 'Y-m-d g:i a';

    /**
     * Format a UTC MySQL datetime string in the site timezone.
     */
    public static function local(string $gmt_datetime, string $format = self::DISPLAY_FORMAT): string
    {
        $gmt_datetime = trim($gmt_datetime);
        if ($gmt_datetime === '') {
            return '';
        }

        if (function_exists('get_date_from_gmt')) {
            return get_date_from_gmt($gmt_datetime, $format);
        }

        $timestamp = strtotime($gmt_datetime . ' UTC');

        return $timestamp ? gmdate($format, $timestamp) : $gmt_datetime;
    }

    /**
     * Format a Unix timestamp in the site timezone (translated weekday/month names).
     */
    public static function local_ts(int $timestamp, string $format = self::DISPLAY_FORMAT): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        if (function_exists('wp_date')) {
            return (string) wp_date($format, $timestamp);
        }

        return gmdate($format, $timestamp);
    }

    /**
     * Format a UTC MySQL datetime string in the site timezone with the timezone
     * label appended, e.g. "2026-06-26 9:18 am (America/New_York)".
     */
    public static function local_label(string $gmt_datetime, string $format = self::DISPLAY_FORMAT): string
    {
        $formatted = self::local($gmt_datetime, $format);

        return $formatted === '' ? '' : $formatted . ' (' . self::tz_label() . ')';
    }

    /**
     * Format a Unix timestamp in the site timezone with the timezone label appended.
     */
    public static function local_ts_label(int $timestamp, string $format = self::DISPLAY_FORMAT): string
    {
        $formatted = self::local_ts($timestamp, $format);

        return $formatted === '' ? '' : $formatted . ' (' . self::tz_label() . ')';
    }

    /**
     * Site timezone label, e.g. "America/New_York" or "UTC+2".
     */
    public static function tz_label(): string
    {
        if (function_exists('wp_timezone_string')) {
            return (string) wp_timezone_string();
        }

        return 'UTC';
    }
}
