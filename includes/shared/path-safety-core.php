<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Archive extract path safety (no WordPress dependencies).
 * Keep in sync with installer/lib/path-safety-core.php.
 */

class Rmmigrate_Path_Safety_Core
{
    public static function is_valid_upload_id(string $upload_id): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $upload_id);
    }

    public static function is_safe_entry_name(string $name): bool
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            return false;
        }
        if ($name[0] === '/' || $name[0] === '\\') {
            return false;
        }
        if (preg_match('#^[a-zA-Z]:[/\\\\]#', $name)) {
            return false;
        }
        $normalized = str_replace('\\', '/', $name);
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Resolve a safe destination path under extract_root, or null when unsafe.
     */
    public static function resolve_extract_dest(string $extract_root, string $entry_name): ?string
    {
        if (!self::is_safe_entry_name($entry_name)) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $entry_name), '/');

        $root_real = realpath(rtrim($extract_root, '/\\'));
        if ($root_real !== false) {
            $root_prefix = rtrim(str_replace('\\', '/', $root_real), '/') . '/';
            $dest = $root_prefix . $relative;
            $parent = dirname($dest);
            $parent_real = realpath($parent);
            if ($parent_real !== false) {
                $parent_prefix = rtrim(str_replace('\\', '/', $parent_real), '/') . '/';
                if (strpos($parent_prefix, $root_prefix) !== 0) {
                    return null;
                }
            } elseif (strpos(str_replace('\\', '/', $parent) . '/', $root_prefix) !== 0) {
                return null;
            }
            return $dest;
        }

        $root = rtrim(str_replace('\\', '/', $extract_root), '/') . '/';

        // is_safe_entry_name() is the sole traversal guard when extract_root cannot be realpath()'d.
        return $root . $relative;
    }
}
