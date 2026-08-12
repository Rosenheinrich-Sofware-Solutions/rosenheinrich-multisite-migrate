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
        wp_mkdir_p($dir);
        $dir_real = realpath($dir);
        if ($dir_real === false) {
            $prefix = rtrim(str_replace('\\', '/', $dir), '/') . '/';
            return strpos(str_replace('\\', '/', $file_path), $prefix) === 0;
        }
        $file_real = realpath(dirname($file_path));
        if ($file_real === false) {
            $prefix = rtrim(str_replace('\\', '/', $dir_real), '/') . '/';
            return strpos(str_replace('\\', '/', $file_path), $prefix) === 0;
        }
        $prefix = rtrim(str_replace('\\', '/', $dir_real), '/') . '/';
        return strpos(str_replace('\\', '/', $file_real . '/' . basename($file_path)), $prefix) === 0;
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
}
