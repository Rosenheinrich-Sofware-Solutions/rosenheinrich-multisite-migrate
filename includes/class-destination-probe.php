<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write and delete a tiny probe file to verify local backup storage access.
 */
class Rmmigrate_Destination_Probe
{
    /**
     * @return string|WP_Error Success message.
     */
    public static function run()
    {
        return self::probe_local();
    }

    /**
     * @return string|WP_Error
     */
    private static function probe_local()
    {
        $dir = Rmmigrate_Plugin::backups_dir();
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('local_dir', __('Backup directory is not writable.', 'rosenheinrich-multisite-migrate'));
        }

        $path = trailingslashit($dir) . '.mm-connection-test-' . wp_generate_password(8, false, false) . '.txt';
        $payload = 'Multisite Migrate connection test ' . gmdate('c');

        if (!Rmmigrate_Filesystem::put_contents($path, $payload)) {
            return new WP_Error('local_write', __('Could not write a test file to local storage.', 'rosenheinrich-multisite-migrate'));
        }
        if (!Rmmigrate_Filesystem::exists($path)) {
            Rmmigrate_Filesystem::delete($path);
            return new WP_Error('local_verify', __('Test file was not found after writing.', 'rosenheinrich-multisite-migrate'));
        }
        if (!Rmmigrate_Filesystem::delete($path)) {
            return new WP_Error('local_delete', __('Test file was created but could not be deleted.', 'rosenheinrich-multisite-migrate'));
        }
        clearstatcache(true, $path);
        if (Rmmigrate_Filesystem::exists($path)) {
            return new WP_Error('local_delete', __('Test file still exists after delete.', 'rosenheinrich-multisite-migrate'));
        }

        return __('Local storage OK — test file created and deleted.', 'rosenheinrich-multisite-migrate');
    }
}
