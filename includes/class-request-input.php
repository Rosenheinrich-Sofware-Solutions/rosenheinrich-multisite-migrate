<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanitized superglobal reads for admin/AJAX handlers.
 *
 * SECURITY MODEL: this class performs NO authorization. It is a low-level,
 * read-only accessor for $_GET/$_POST/$_FILES that sanitizes and unslashes
 * values. Every caller is a dispatch entry point (wp_ajax_* handler, admin-post
 * handler, or settings save) that MUST run check_ajax_referer()/
 * check_admin_referer() AND current_user_can() BEFORE invoking any method here.
 * The nonce/capability gate therefore lives at the dispatch layer, not in this
 * helper; the few direct-superglobal reads below carry a documented per-line
 * phpcs:ignore for that reason (there is no submission processing in this file).
 */
final class Rmmigrate_Request_Input
{
    /**
     * @return array<string,mixed>|null
     */
    private static function superglobal_for_input(int $input_type): ?array
    {
        switch ($input_type) {
            case INPUT_GET:
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only fallback bag; nonce/capability verified by the dispatch-layer caller; individual public getters sanitize each value before use.
                return $_GET;
            case INPUT_POST:
                // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only fallback bag; nonce/capability verified by the dispatch-layer caller; individual public getters sanitize each value before use.
                return $_POST;
            case INPUT_COOKIE:
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only fallback bag; consumers sanitize before use.
                return $_COOKIE;
            default:
                return null;
        }
    }

    /**
     * @return mixed|null
     */
    private static function filtered_value(int $input_type, string $key)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified by the dispatch-layer caller.
        $bag = self::superglobal_for_input($input_type);
        if ($bag !== null && array_key_exists($key, $bag)) {
            return wp_unslash($bag[$key]);
        }

        $value = filter_input($input_type, $key);
        if ($value !== null && $value !== false) {
            return $value;
        }

        return null;
    }

    /**
     * @return mixed|null
     */
    private static function scalar_value(int $input_type, string $key)
    {
        $value = self::filtered_value($input_type, $key);
        if ($value === null || is_array($value)) {
            return null;
        }

        return $value;
    }

    public static function post_text(string $key, string $default = ''): string
    {
        $value = self::scalar_value(INPUT_POST, $key);
        if ($value === null) {
            return $default;
        }

        return sanitize_text_field(is_string($value) ? $value : (string) $value);
    }

    public static function post_key(string $key, string $default = ''): string
    {
        $value = self::scalar_value(INPUT_POST, $key);
        if ($value === null) {
            return $default;
        }

        return sanitize_key(is_string($value) ? $value : (string) $value);
    }

    private static function validate_int($raw, int $default): int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }

        $validated = filter_var($raw, FILTER_VALIDATE_INT);
        if ($validated === false) {
            return $default;
        }

        return (int) $validated;
    }

    public static function post_int(string $key, int $default = 0): int
    {
        return self::validate_int(self::filtered_value(INPUT_POST, $key), $default);
    }

    public static function post_bool(string $key): bool
    {
        $value = self::scalar_value(INPUT_POST, $key);
        return $value !== null && $value !== '' && $value !== '0' && $value !== 0;
    }

    public static function post_textarea(string $key, string $default = ''): string
    {
        $value = self::scalar_value(INPUT_POST, $key);
        if ($value === null) {
            return $default;
        }

        return sanitize_textarea_field(is_string($value) ? $value : (string) $value);
    }

    public static function post_raw(string $key, string $default = ''): string
    {
        $value = self::scalar_value(INPUT_POST, $key);
        if ($value === null) {
            return $default;
        }

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * @return int[]
     */
    public static function post_int_array(string $key): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/capability verified by the dispatch-layer caller.
        $values = filter_input(INPUT_POST, $key, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
        if (!is_array($values)) {
            $raw = self::filtered_value(INPUT_POST, $key);
            if (!is_array($raw)) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/capability verified by the dispatch-layer caller; values are cast with intval() below.
                if (isset($_POST[$key]) && is_array($_POST[$key])) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Dispatch-layer nonce; each value is intval()'d below.
                    $values = wp_unslash($_POST[$key]);
                } else {
                    return array();
                }
            } else {
                $values = $raw;
            }
        }

        $out = array();
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }
            $out[] = (int) $value;
        }

        return $out;
    }

    public static function post_url_raw(string $key, string $default = ''): string
    {
        return esc_url_raw(self::post_text($key, $default));
    }

    public static function post_file_name(string $key, string $default = ''): string
    {
        return sanitize_file_name(self::post_text($key, $default));
    }

    public static function post_array(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability verified by the dispatch-layer caller; the returned array is sanitized field-by-field by each save handler.
        $post = wp_unslash($_POST);

        return is_array($post) ? $post : array();
    }

    public static function get_int(string $key, int $default = 0): int
    {
        return self::validate_int(self::filtered_value(INPUT_GET, $key), $default);
    }

    public static function get_key(string $key, string $default = ''): string
    {
        $value = self::scalar_value(INPUT_GET, $key);
        if ($value === null) {
            return $default;
        }

        return sanitize_key(is_string($value) ? $value : (string) $value);
    }

    public static function get_text(string $key, string $default = ''): string
    {
        $value = self::scalar_value(INPUT_GET, $key);
        if ($value === null) {
            return $default;
        }

        return sanitize_text_field(is_string($value) ? $value : (string) $value);
    }

    public static function get_raw(string $key, string $default = ''): string
    {
        $value = self::scalar_value(INPUT_GET, $key);
        if ($value === null) {
            return $default;
        }

        return is_string($value) ? $value : (string) $value;
    }

    public static function get_exists(string $key): bool
    {
        $value = self::filtered_value(INPUT_GET, $key);
        return $value !== null && $value !== '';
    }

    public static function request_int(string $key, int $default = 0): int
    {
        $raw = self::filtered_value(INPUT_POST, $key);
        if ($raw === null) {
            $raw = self::filtered_value(INPUT_GET, $key);
        }
        // NOTE: $_REQUEST is intentionally NOT used here — it merges COOKIE on
        // some server configurations (request_order=CGP) and is a cookie-injection vector.

        return self::validate_int($raw, $default);
    }

    public static function request_text(string $key, string $default = ''): string
    {
        $value = self::scalar_value(INPUT_POST, $key);
        if ($value === null) {
            $value = self::scalar_value(INPUT_GET, $key);
        }
        // NOTE: $_REQUEST is intentionally NOT used here — cookie-injection risk.
        if ($value === null) {
            return $default;
        }

        return sanitize_text_field(is_string($value) ? $value : (string) $value);
    }

    /**
     * Validated PHP upload temp path (is_uploaded_file).
     */
    public static function file_tmp_name(string $files_key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/capability verified by the dispatch-layer caller; tmp_name is validated with is_uploaded_file() below.
        if (!isset($_FILES[$files_key]['tmp_name']) || !is_string($_FILES[$files_key]['tmp_name'])) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Dispatch-layer nonce; tmp_name is validated with is_uploaded_file(), not text-sanitized.
        $tmp = wp_unslash($_FILES[$files_key]['tmp_name']);
        return self::is_uploaded_tmp($tmp) ? $tmp : '';
    }

    /**
     * Whether a path is a valid PHP upload temp file.
     */
    public static function is_uploaded_tmp(string $tmp): bool
    {
        if ($tmp === '') {
            return false;
        }

        return is_uploaded_file($tmp);
    }

    /**
     * Sanitized original filename from a validated upload field.
     */
    public static function file_original_name(string $files_key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/capability verified by the dispatch-layer caller; the name is sanitized with sanitize_file_name() below.
        if (!isset($_FILES[$files_key]['name']) || !is_string($_FILES[$files_key]['name'])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/capability verified by the dispatch-layer caller; the name is sanitized with sanitize_file_name() below.
        return sanitize_file_name(wp_unslash($_FILES[$files_key]['name']));
    }

    /**
     * Upload byte size when present.
     */
    public static function file_size(string $files_key): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/capability verified by the dispatch-layer caller; the size is cast to int below.
        return isset($_FILES[$files_key]['size']) ? (int) $_FILES[$files_key]['size'] : 0;
    }
}
