<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Restore_Topology_Compatibility
{
    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $destination
     * @return array{gate:string,message:string,recovery_hint:string}|null
     */
    public static function validate_gates(array $state, array $manifest, array $destination, bool $installer_context = false): ?array
    {
        $logic_mode = (string) ($state['logic_mode'] ?? 'classic');
        if ($logic_mode === 'recovery') {
            if (($destination['health'] ?? '') === 'partial') {
                return self::gate(
                    'destination_partial',
                    __('Incomplete WordPress installation detected (wp-config.php without wp-admin/wp-includes). Recovery requires a working WordPress site.', 'rosenheinrich-multisite-migrate'),
                    __('Install WordPress completely before using disaster recovery.', 'rosenheinrich-multisite-migrate')
                );
            }
            if (($destination['health'] ?? '') === 'orphan_core') {
                return self::gate(
                    'destination_orphan_core',
                    __('WordPress core files found without wp-config.php. Recovery cannot continue.', 'rosenheinrich-multisite-migrate'),
                    __('Add wp-config.php before launching recovery.', 'rosenheinrich-multisite-migrate')
                );
            }

            return null;
        }

        $install_mode = (string) ($state['install_mode'] ?? 'standard');
        $archive_has_core = self::archive_has_core($state, $manifest);
        $dest_has_core = !empty($destination['has_wp_core']);

        if (($destination['health'] ?? '') === 'partial') {
            return self::gate(
                'destination_partial',
                __('Incomplete WordPress installation detected (wp-config.php without wp-admin/wp-includes). Remove partial files or install WordPress before continuing.', 'rosenheinrich-multisite-migrate'),
                __('Delete wp-config.php and any partial files, or install a complete WordPress copy on this server.', 'rosenheinrich-multisite-migrate')
            );
        }

        if (($destination['health'] ?? '') === 'orphan_core') {
            return self::gate(
                'destination_orphan_core',
                __('WordPress core files found without wp-config.php. Add wp-config.php or remove orphan core files.', 'rosenheinrich-multisite-migrate'),
                __('Remove wp-admin and wp-includes or add a valid wp-config.php.', 'rosenheinrich-multisite-migrate')
            );
        }

        if ($installer_context && $install_mode === 'standard' && !$archive_has_core && !$dest_has_core) {
            return self::gate(
                'pre_files',
                __('This backup does not include WordPress core and the server has no WordPress installed. Enable Include WordPress core and create a new backup, or restore to a server that already has WordPress.', 'rosenheinrich-multisite-migrate'),
                __('Create a migration backup with Include WordPress core enabled, or install WordPress on this server first.', 'rosenheinrich-multisite-migrate')
            );
        }

        if (($manifest['backup_intent'] ?? '') === 'migration' && !self::archive_has_core($state, $manifest)) {
            return self::gate(
                'migration_no_core',
                __('Migration backup is missing WordPress core files. Create a new backup with Include WordPress core enabled.', 'rosenheinrich-multisite-migrate'),
                __('On the source site, create a new backup (migration backups include core automatically) or enable Include WordPress core in Settings.', 'rosenheinrich-multisite-migrate')
            );
        }

        $manifest_core = !empty($manifest['has_wp_core_files']);
        if ($manifest_core && !$archive_has_core) {
            return self::gate(
                'archive_tampered',
                __('Archive manifest claims WordPress core is included but core files are missing. The archive may be incomplete or tampered.', 'rosenheinrich-multisite-migrate'),
                __('Re-download the backup archive from the source site.', 'rosenheinrich-multisite-migrate')
            );
        }

        $topology_gate = self::validate_topology($state, $manifest, $destination);
        if ($topology_gate !== null) {
            return $topology_gate;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $destination
     * @return array{gate:string,message:string,recovery_hint:string}|null
     */
    public static function validate_topology(array $state, array $manifest, array $destination): ?array
    {
        $restore_mode = Rmmigrate_Restore_Topology_Manifest::restore_mode($manifest, $state);
        if ($restore_mode === 'subsite_on_network') {
            $map = is_array($state['site_owr_map'] ?? null) ? $state['site_owr_map'] : array();
            if ($map === array()) {
                return self::gate(
                    'topology_subsite_on_network_map',
                    __('Subsite-on-network import requires a target blog mapping (blog ID and URL).', 'rosenheinrich-multisite-migrate'),
                    __('On the deploy step, choose “Import as subsite on this network” and enter the target URL (and optional existing blog ID).', 'rosenheinrich-multisite-migrate')
                );
            }

            return null;
        }
        if ($restore_mode === 'network_to_subsite') {
            $target_blog_id = (int) ($state['target_blog_id'] ?? $state['destination_blog_id'] ?? 0);
            if ($target_blog_id <= 0) {
                return self::gate(
                    'topology_network_to_subsite_target',
                    __('Network-to-subsite import requires a target blog on this network.', 'rosenheinrich-multisite-migrate'),
                    __('Choose the subsite slot (blog ID) that should receive the imported network backup.', 'rosenheinrich-multisite-migrate')
                );
            }

            return null;
        }

        $dest_multisite = !empty($destination['is_multisite']);
        if (!$dest_multisite) {
            return null;
        }

        $backup_subsite = ($manifest['scope'] ?? '') === 'subsite';
        $restore_network = Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest);

        if ($backup_subsite) {
            return self::gate(
                'topology_subsite_to_network',
                __('This backup is a multisite subsite. Importing into an existing WordPress network is not supported yet. Restore to an empty standalone server instead.', 'rosenheinrich-multisite-migrate'),
                __('Use a fresh standalone WordPress install or empty hosting space, then run the installer again with URL migration enabled.', 'rosenheinrich-multisite-migrate')
            );
        }

        if (!empty($destination['is_multisite_subsite']) && $restore_network) {
            return self::gate(
                'topology_network_to_subsite_slot',
                __('Network backup cannot import into an existing subsite URL on a shared network yet. Use a filtered subsite backup or restore to an empty server.', 'rosenheinrich-multisite-migrate'),
                __('Create a subsite-scoped backup on the source network, or restore the full network to an empty server with its own database.', 'rosenheinrich-multisite-migrate')
            );
        }

        if (!$restore_network) {
            return self::gate(
                'topology_to_network',
                __('The destination is already a WordPress multisite network. This backup cannot be imported as a subsite on that network yet.', 'rosenheinrich-multisite-migrate'),
                __('Restore to a standalone server, or use a network-scope backup when migrating an entire multisite installation.', 'rosenheinrich-multisite-migrate')
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $manifest
     */
    public static function archive_has_core(array $state, array $manifest): bool
    {
        if (!empty($state['archive']['has_wp_core_files'])) {
            return true;
        }

        return !empty($manifest['has_wp_core_files']);
    }

    /**
     * @return array{gate:string,message:string,recovery_hint:string}
     */
    private static function gate(string $gate, string $message, string $recovery_hint): array
    {
        return array(
            'gate'          => $gate,
            'message'       => $message,
            'recovery_hint' => $recovery_hint,
        );
    }
}
