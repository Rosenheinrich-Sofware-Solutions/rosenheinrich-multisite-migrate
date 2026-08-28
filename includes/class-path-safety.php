<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once RMMIGRATE_PATH . 'includes/shared/path-safety-core.php';

class Rmmigrate_Path_Safety extends Rmmigrate_Path_Safety_Core
{
    /**
     * Ensure a file path stays within a directory (after realpath when possible).
     */
    public static function path_within_dir(string $dir, string $file_path): bool
    {
        $dir = trailingslashit($dir);
        $dir_real = realpath($dir);
        $dir_base = $dir_real !== false ? $dir_real : $dir;
        $prefix = trailingslashit(self::canonicalize_path($dir_base));

        $file_real = realpath($file_path);
        if ($file_real !== false) {
            $candidate = trailingslashit(self::canonicalize_path($file_real));
        } else {
            $file_dir_real = realpath(dirname($file_path));
            if ($file_dir_real !== false) {
                $candidate = trailingslashit(self::canonicalize_path($file_dir_real . '/' . basename($file_path)));
            } else {
                $candidate = trailingslashit(self::canonicalize_path($file_path));
            }
        }

        return strpos($candidate, $prefix) === 0;
    }

    /**
     * Compute a path relative to the WP_CONTENT_DIR root.
     */
    public static function content_relative_path(string $absolute): string
    {
        $content = wp_normalize_path(WP_CONTENT_DIR);
        $path = wp_normalize_path($absolute);
        if (strpos($path, $content . '/') !== 0) {
            return '';
        }

        return ltrim(substr($path, strlen($content) + 1), '/');
    }

    /**
     * Normalize a path for prefix comparison when realpath is unavailable.
     */
    private static function canonicalize_path(string $path): string
    {
        $path = wp_normalize_path($path);
        if ($path === '') {
            return '';
        }

        $prefix = '';
        if (preg_match('#^[a-zA-Z]:/#', $path)) {
            $prefix = substr($path, 0, 3);
            $path = substr($path, 3);
        } elseif ($path[0] === '/') {
            $prefix = '/';
        }

        $parts = explode('/', trim($path, '/'));
        $stack = array();
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($stack !== array()) {
                    array_pop($stack);
                }
                continue;
            }
            $stack[] = $part;
        }

        if ($stack === array()) {
            return $prefix === '' ? '' : $prefix;
        }

        $canonical = $prefix . implode('/', $stack);

        return $canonical;
    }
}
