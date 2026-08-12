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

        $root = rtrim(str_replace('\\', '/', $extract_root), '/') . '/';
        $relative = ltrim(str_replace('\\', '/', $entry_name), '/');
        $dest = $root . $relative;

        $root_real = realpath(rtrim($extract_root, '/\\'));
        if ($root_real !== false) {
            $root_prefix = rtrim(str_replace('\\', '/', $root_real), '/') . '/';
            $dest_norm = str_replace('\\', '/', $dest);
            if (strpos($dest_norm, $root_prefix) !== 0) {
                return null;
            }
            return $dest;
        }

        $dest_parts = explode('/', str_replace('\\', '/', $dest));
        $depth = 0;
        foreach ($dest_parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $depth++;
        }

        $root_depth = count(array_filter(explode('/', trim($root, '/')), static function ($p) {
            return $p !== '' && $p !== '.';
        }));
        if ($depth < $root_depth) {
            return null;
        }

        return $dest;
    }
}
