<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Human-readable ZipArchive failure context for backup telemetry.
 */
final class Rmmigrate_Zip_Archive_Errors
{
    /**
     * @param ZipArchive $zip      Open archive instance (status read after a failed operation).
     * @param string     $zip_path Absolute path to the archive file.
     * @param int|bool   $open_res Result from ZipArchive::open() when relevant; 0 to omit.
     * @param array<string,mixed> $context Optional slice context (file_index, total_files, added).
     */
    public static function describe_close_failure(ZipArchive $zip, string $zip_path, $open_res = 0, array $context = array()): string
    {
        $parts = array();

        $status_string = self::safe_zip_status_string($zip);
        if ($status_string !== '') {
            $parts[] = $status_string;
        }

        $code = self::safe_zip_status_code($zip);
        if ($code !== 0 && (!defined('ZipArchive::ER_OK') || $code !== ZipArchive::ER_OK)) {
            $parts[] = 'ZipArchive::status=' . $code;
        }

        if (is_int($open_res) && $open_res !== 0 && $open_res !== 1) {
            $parts[] = 'open_code=' . $open_res;
        }

        if ($zip_path !== '') {
            if (Rmmigrate_Filesystem::exists($zip_path)) {
                $parts[] = 'archive_bytes=' . (string) (int) Rmmigrate_Filesystem::filesize($zip_path);
            } else {
                $parts[] = 'archive_missing';
            }
            $parent = dirname($zip_path);
            if ($parent !== '' && $parent !== '.') {
                $free = @disk_free_space($parent);
                if ($free !== false) {
                    $parts[] = 'disk_free=' . Rmmigrate_Local_Storage::format_bytes((int) $free);
                }
            }
        }

        if (isset($context['file_index'], $context['total_files'])) {
            $parts[] = sprintf(
                'queue=%d/%d',
                (int) $context['file_index'],
                (int) $context['total_files']
            );
        }
        if (isset($context['added'])) {
            $parts[] = 'added_this_slice=' . (int) $context['added'];
        }

        if ($parts === array()) {
            return __('ZipArchive close failed with no status string (often disk full or an interrupted prior run left a corrupt partial archive).', 'rosenheinrich-multisite-migrate');
        }

        return implode('; ', $parts);
    }

    private static function safe_zip_status_string(ZipArchive $zip): string
    {
        if (!method_exists($zip, 'getStatusString')) {
            return '';
        }
        try {
            return (string) $zip->getStatusString();
        } catch (\Throwable $e) {
            Rmmigrate_Logger::log('Best-effort operation failed: ' . $e->getMessage());
            return '';
        }
    }

    private static function safe_zip_status_code(ZipArchive $zip): int
    {
        if (!method_exists($zip, 'status')) {
            return 0;
        }
        try {
            return (int) $zip->status();
        } catch (\Throwable $e) {
            Rmmigrate_Logger::log('Best-effort operation failed: ' . $e->getMessage());
            return 0;
        }
    }
}
