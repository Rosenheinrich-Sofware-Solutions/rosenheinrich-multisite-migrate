<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scan and remove Multisite Migrate installation artifacts left in the web root.
 */
class Rmmigrate_Install_Cleanup
{
    public const ENTRY_FILE = 'multisite-migrate-installer.php';
    public const STATE_DIR = '.multisite-migrate-installer';
    public const INSTALLER_DIR = 'installer';
    public const README_FILE = 'README.txt';

    /**
     * @return list<array{path:string,label:string,size:int,type:string}>
     */
    public static function scan(string $root = ''): array
    {
        $root = self::normalize_root($root);
        if ($root === '') {
            return array();
        }

        $items = array();
        $has_entry = is_file($root . self::ENTRY_FILE);
        $has_state = is_dir($root . self::STATE_DIR);
        $install_context = $has_entry || $has_state;

        if ($has_entry) {
            $path = $root . self::ENTRY_FILE;
            $items[] = self::make_item($path, self::ENTRY_FILE, 'file');
        }

        $installer_dir = $root . self::INSTALLER_DIR;
        if (is_dir($installer_dir) && self::is_mm_installer_dir($installer_dir)) {
            $items[] = self::make_item($installer_dir, self::INSTALLER_DIR . '/', 'dir');
        }

        if ($has_state) {
            $state_path = $root . self::STATE_DIR;
            $items[] = self::make_item($state_path, self::STATE_DIR . '/', 'dir');
        }

        if ($install_context) {
            self::append_root_archives($root, $items);
            self::append_readme($root, $items);
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp($a['label'], $b['label']);
        });

        return $items;
    }

    /**
     * @return array{deleted:list<string>,failed:list<string>,found_before:int}
     */
    public static function delete(string $root = ''): array
    {
        $items = self::scan($root);
        $deleted = array();
        $failed = array();

        $files = array();
        $dirs = array();
        foreach ($items as $item) {
            if ($item['type'] === 'dir') {
                $dirs[] = $item['path'];
            } else {
                $files[] = $item['path'];
            }
        }

        foreach ($files as $path) {
            if (Rmmigrate_Filesystem::delete($path)) {
                $deleted[] = self::relative_label($path, $root !== '' ? $root : self::normalize_root(''));
            } else {
                $failed[] = self::relative_label($path, $root !== '' ? $root : self::normalize_root(''));
            }
        }

        foreach ($dirs as $path) {
            if (Rmmigrate_Filesystem::delete_directory($path)) {
                $deleted[] = self::relative_label($path, $root !== '' ? $root : self::normalize_root(''));
            } else {
                $failed[] = self::relative_label($path, $root !== '' ? $root : self::normalize_root(''));
            }
        }

        return array(
            'deleted'      => $deleted,
            'failed'       => $failed,
            'found_before' => count($items),
        );
    }

    public static function is_mm_archive_name(string $basename): bool
    {
        return (bool) preg_match('/^backup-[a-z0-9-]+-\d{8}-\d{6}\.(zip|daf)(\.venc)?$/i', $basename);
    }

    public static function is_mm_installer_dir(string $dir): bool
    {
        $bootstrap = rtrim($dir, '/\\') . '/bootstrap.php';
        if (!is_file($bootstrap)) {
            return false;
        }
        $sample = Rmmigrate_Filesystem::get_contents($bootstrap, 0, 2048);
        if ($sample === false || $sample === '') {
            return false;
        }
        return strpos($sample, 'RMMIGRATE_INSTALLER') !== false
            || strpos($sample, 'Rmmigrate_Installer') !== false;
    }

    /**
     * @return string|null Absolute path when safe under root.
     */
    public static function resolve_under_root(string $root, string $relative): ?string
    {
        $root = self::normalize_root($root);
        if ($root === '') {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || strpos($relative, "\0") !== false || strpos($relative, '..') !== false) {
            return null;
        }

        $candidate = $root . $relative;
        $root_real = realpath(rtrim($root, '/\\'));
        $path_real = realpath($candidate);
        if ($root_real === false) {
            return null;
        }
        $root_prefix = rtrim(str_replace('\\', '/', $root_real), '/') . '/';
        if ($path_real !== false) {
            $path_norm = str_replace('\\', '/', $path_real) . (is_dir($path_real) ? '/' : '');
            if (strpos($path_norm, $root_prefix) === 0) {
                return $path_real;
            }
            return null;
        }

        $dest_norm = str_replace('\\', '/', $candidate);
        if (strpos($dest_norm, rtrim($root_prefix, '/')) !== 0) {
            return null;
        }
        return $candidate;
    }

    private static function normalize_root(string $root): string
    {
        if ($root === '') {
            $root = ABSPATH;
        }
        return trailingslashit(wp_normalize_path($root));
    }

    /**
     * @param list<array{path:string,label:string,size:int,type:string}> $items
     */
    private static function append_root_archives(string $root, array &$items): void
    {
        foreach (Rmmigrate_Filesystem::list_dir($root) as $name) {
            if (!self::is_mm_archive_name($name)) {
                continue;
            }
            $path = $root . $name;
            if (!is_file($path)) {
                continue;
            }
            $items[] = self::make_item($path, $name, 'file');
        }
    }

    /**
     * @param list<array{path:string,label:string,size:int,type:string}> $items
     */
    private static function append_readme(string $root, array &$items): void
    {
        $readme = $root . self::README_FILE;
        if (!is_file($readme) || !is_file($root . self::ENTRY_FILE)) {
            return;
        }
        $content = Rmmigrate_Filesystem::get_contents($readme, 0, 4096);
        if ($content === false || strpos($content, 'Multisite Migrate') === false) {
            return;
        }
        $items[] = self::make_item($readme, self::README_FILE, 'file');
    }

    /**
     * @return array{path:string,label:string,size:int,type:string}
     */
    private static function make_item(string $path, string $label, string $type): array
    {
        return array(
            'path'  => $path,
            'label' => $label,
            'size'  => $type === 'dir'
                ? (int) (Rmmigrate_Filesystem::directory_size_bounded($path, 0.25)['size'] ?? 0)
                : Rmmigrate_Filesystem::filesize($path),
            'type'  => $type,
        );
    }

    private static function relative_label(string $path, string $root): string
    {
        $root = trailingslashit(wp_normalize_path($root));
        $path = wp_normalize_path($path);
        if (strpos($path, $root) === 0) {
            return ltrim(substr($path, strlen($root)), '/');
        }
        return basename($path);
    }
}
