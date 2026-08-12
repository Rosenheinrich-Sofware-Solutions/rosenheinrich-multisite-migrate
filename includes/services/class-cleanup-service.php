<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Rmmigrate_Cleanup_Service
{
    /**
     * @return array{found:int,items:array<int,array<string,mixed>>}
     */
    public static function scan_install_files(): array
    {
        $items = array();
        foreach (Rmmigrate_Install_Cleanup::scan() as $item) {
            $items[] = array(
                'path'  => self::public_path($item['path']),
                'label' => $item['label'],
                'size'  => $item['size'],
                'type'  => $item['type'],
            );
        }
        return array(
            'found' => count($items),
            'items' => $items,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function delete_install_files(): array
    {
        $result = Rmmigrate_Install_Cleanup::delete();
        $deleted_count = count($result['deleted']);
        $failed_count = count($result['failed']);

        if ($deleted_count > 0) {
            Rmmigrate_Activity_Log::record(
                'install_cleanup',
                sprintf(
                    /* translators: 1: number of removed paths, 2: number of failures */
                    __('Removed %1$d installation file path(s); %2$d failed.', 'rosenheinrich-multisite-migrate'),
                    $deleted_count,
                    $failed_count
                ),
                $failed_count > 0 ? 'warning' : 'success',
                array(
                    'deleted' => $result['deleted'],
                    'failed'  => $result['failed'],
                )
            );
        }

        return array(
            'deleted'      => $result['deleted'],
            'failed'       => $result['failed'],
            'found_before' => $result['found_before'],
            'message'      => $deleted_count > 0
                ? sprintf(
                    /* translators: %d: number of removed paths */
                    __('Removed %d installation file path(s).', 'rosenheinrich-multisite-migrate'),
                    $deleted_count
                )
                : __('No installation files were removed.', 'rosenheinrich-multisite-migrate'),
        );
    }

    private static function public_path(string $path): string
    {
        $root = trailingslashit(wp_normalize_path(ABSPATH));
        $path = wp_normalize_path($path);
        if (strpos($path, $root) === 0) {
            return ltrim(substr($path, strlen($root)), '/');
        }
        return basename($path);
    }
}
