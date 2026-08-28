<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Archive_Index
{
    /**
     * Whether the files/ tree contains WordPress core paths from a backup.
     *
     * @param string $files_root Absolute path to extracted or work-dir files/ folder.
     */
    public static function has_core_files(string $files_root): bool
    {
        $files_root = rtrim(wp_normalize_path($files_root), '/') . '/';
        if (!Rmmigrate_Filesystem::is_dir($files_root)) {
            return false;
        }

        if (Rmmigrate_Filesystem::is_dir($files_root . 'wp-admin')
            && Rmmigrate_Filesystem::exists($files_root . 'wp-admin/index.php')) {
            return true;
        }

        if (Rmmigrate_Filesystem::exists($files_root . 'index.php')
            && Rmmigrate_Filesystem::exists($files_root . 'wp-settings.php')) {
            return true;
        }

        return Rmmigrate_Filesystem::is_dir($files_root . 'wp-includes')
            && Rmmigrate_Filesystem::exists($files_root . 'wp-includes/version.php');
    }

    /**
     * @param string[] $archive_paths Paths as stored in archive (e.g. files/wp-admin/...).
     */
    public static function has_core_paths(array $archive_paths): bool
    {
        $has_wp_admin_index = false;
        $has_index = false;
        $has_settings = false;
        $has_version = false;

        foreach ($archive_paths as $path) {
            $path = wp_normalize_path((string) $path);
            if ($path === 'files/wp-admin/index.php') {
                $has_wp_admin_index = true;
            }
            if ($path === 'files/index.php') {
                $has_index = true;
            }
            if ($path === 'files/wp-settings.php') {
                $has_settings = true;
            }
            if ($path === 'files/wp-includes/version.php') {
                $has_version = true;
            }
        }

        if ($has_wp_admin_index) {
            return true;
        }
        if ($has_index && $has_settings) {
            return true;
        }

        return $has_version;
    }
}
