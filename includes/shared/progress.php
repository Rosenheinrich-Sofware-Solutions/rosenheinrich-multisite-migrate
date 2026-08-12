<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Progress
{
    /**
     * @param array<string,mixed> $progress
     */
    public static function zip_percent(array $progress): int
    {
        $bytes_done = (int) ($progress['zip_bytes_done'] ?? 0);
        $bytes_total = (int) ($progress['zip_bytes_total'] ?? 0);
        if ($bytes_total > 0) {
            $entry_bytes = 0;
            if ((int) ($progress['zip_entry_index'] ?? -1) === (int) ($progress['zip_index'] ?? 0)) {
                $entry_bytes = (int) ($progress['zip_entry_bytes'] ?? 0);
            }

            return (int) min(99, round((($bytes_done + $entry_bytes) / $bytes_total) * 100));
        }

        $index = (int) ($progress['zip_index'] ?? $progress['entry_index'] ?? 0);
        $total = (int) ($progress['zip_total'] ?? $progress['entry_total'] ?? 0);
        if ($total <= 0) {
            return 0;
        }

        return (int) min(99, round(($index / $total) * 100));
    }

    /**
     * @param array<string,mixed> $progress
     */
    public static function extract_percent(array $progress, int $archive_size = 0): int
    {
        if (($progress['format'] ?? '') === 'daf') {
            if ($archive_size > 0) {
                $offset = (int) ($progress['byte_offset'] ?? 0);

                return (int) min(99, round(($offset / $archive_size) * 100));
            }

            return 0;
        }

        return self::zip_percent($progress);
    }

    /**
     * @param array<string,mixed> $progress
     */
    public static function import_percent(array $progress, int $fallback_total = 0): int
    {
        $offset = (int) ($progress['offset'] ?? 0);
        $total = (int) ($progress['total'] ?? 0);
        if ($total <= 0) {
            $total = $fallback_total;
        }
        if ($total <= 0) {
            return 0;
        }

        return (int) min(99, round(($offset / $total) * 100));
    }

    /**
     * @param array<string,mixed> $progress
     */
    public static function files_percent(array $progress): int
    {
        $index = (int) ($progress['index'] ?? 0);
        $queue = $progress['queue'] ?? array();
        $total = is_array($queue) ? count($queue) : 0;
        if ($total <= 0) {
            $total = (int) ($progress['total'] ?? 0);
        }
        if ($total <= 0) {
            return 0;
        }

        return (int) min(99, round(($index / $total) * 100));
    }
}
