<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Rmmigrate_Network_Restore_Orchestration', false)) {
    /**
     * Post-import finalize and failure rollback for full-network restores.
     */
    class Rmmigrate_Network_Restore_Orchestration
    {
        /**
         * @param array<string,mixed> $manifest
         * @param array<string,mixed> $context
         */
        public static function destination_base_prefix(array $manifest, array $context): string
        {
            $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
            if (!empty($destination['base_prefix'])) {
                return (string) $destination['base_prefix'];
            }
            if (!empty($destination['table_prefix'])) {
                $prefix = (string) $destination['table_prefix'];
                return self::topology_standalone()::base_table_prefix($prefix);
            }
            if (!empty($context['db_prefix'])) {
                return self::topology_standalone()::base_table_prefix((string) $context['db_prefix']);
            }

            return self::manifest_class()::source_base_prefix($manifest);
        }

        /**
         * @param array<string,mixed> $manifest
         */
        public static function source_base_prefix(array $manifest): string
        {
            return self::manifest_class()::source_base_prefix($manifest);
        }

        /**
         * @param array<string,mixed> $manifest
         * @param array<string,mixed> $context
         */
        public static function should_remap_prefixes(array $manifest, array $context): bool
        {
            if (!self::is_full_network_restore($manifest, $context)) {
                return false;
            }
            $from = self::source_base_prefix($manifest);
            $to = self::destination_base_prefix($manifest, $context);

            return $from !== '' && $to !== '' && $from !== $to;
        }

        /**
         * @param array<string,mixed> $manifest
         * @param array<string,mixed> $context
         * @return array<string,int>
         */
        public static function remap_imported_prefixes(PDO $pdo, array $manifest, array $context): array
        {
            if (!self::should_remap_prefixes($manifest, $context)) {
                return array();
            }
            $from = self::source_base_prefix($manifest);
            $to = self::destination_base_prefix($manifest, $context);

            return self::prefix_remap_class()::remap_network_prefixes($pdo, $from, $to);
        }

        /**
         * @param array<string,mixed> $manifest
         * @param array<string,mixed> $progress
         */
        /**
         * @param array<string,mixed> $progress
         * @return array<string,mixed>
         */
        public static function after_database_import(PDO $pdo, array $manifest, array $progress): array
        {
            $updates = array();
            $context = array(
                'destination'  => is_array($progress['destination'] ?? null) ? $progress['destination'] : array(),
                'db_prefix'    => (string) ($progress['db_prefix'] ?? $manifest['db_prefix'] ?? ''),
                'restore_mode' => (string) ($progress['restore_mode'] ?? ''),
            );
            $remap_report = self::remap_imported_prefixes($pdo, $manifest, $context);
            if ($remap_report !== array()) {
                $updates['network_prefix_remap'] = $remap_report;
            }
            $rewrite = is_array($progress['sql_table_prefix_rewrite'] ?? null)
                ? $progress['sql_table_prefix_rewrite']
                : array();
            $from_prefix = (string) ($rewrite['from'] ?? '');
            $to_prefix = (string) ($rewrite['to'] ?? '');
            if ($from_prefix !== '' && $to_prefix !== '' && $from_prefix !== $to_prefix) {
                $blog_key_remap = self::prefix_remap_class()::remap_imported_blog_keys($pdo, $from_prefix, $to_prefix);
                if ($blog_key_remap !== array()) {
                    $updates['subsite_on_network_prefix_remap'] = $blog_key_remap;
                }
            }

            $quarantine = is_array($progress['network_quarantine'] ?? null) ? $progress['network_quarantine'] : array();
            if (!empty($quarantine['live_to_quarantine']) && empty($quarantine['committed'])) {
                Rmmigrate_Network_Restore_Quarantine::commit($pdo, $quarantine);
                $quarantine['committed'] = true;
                $updates['network_quarantine'] = $quarantine;
            }

            return $updates;
        }

        /**
         * @param array<string,mixed> $manifest
         * @param array<string,mixed> $progress
         * @return array<string,mixed>
         */
        public static function rollback_failed_import(PDO $pdo, array $manifest, array $progress): array
        {
            $quarantine = is_array($progress['network_quarantine'] ?? null) ? $progress['network_quarantine'] : array();
            if (empty($quarantine['live_to_quarantine']) || !empty($quarantine['committed'])) {
                return array();
            }
            $import_tables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : array();
            $dest_base = self::destination_base_prefix(
                $manifest,
                array('destination' => $progress['destination'] ?? array(), 'db_prefix' => $progress['db_prefix'] ?? '')
            );
            $source_base = self::source_base_prefix($manifest);
            $names = array();
            foreach ($import_tables as $table) {
                $table = (string) $table;
                if ($table === '') {
                    continue;
                }
                if ($source_base !== $dest_base && strncmp($table, $source_base, strlen($source_base)) === 0) {
                    $names[] = $dest_base . substr($table, strlen($source_base));
                } else {
                    $names[] = $table;
                }
            }

            return Rmmigrate_Network_Restore_Quarantine::rollback($pdo, $quarantine, $names);
        }

        /**
         * @param array<string,mixed> $manifest
         * @param array<string,mixed> $context
         */
        /**
         * @param array<string,mixed> $manifest
         * @param array<string,mixed> $context
         */
        private static function is_full_network_restore(array $manifest, array $context): bool
        {
            $manifest_class = self::manifest_class();
            if (!$manifest_class::restore_as_multisite($manifest)) {
                return false;
            }
            if ($manifest_class::is_subsite_standalone_restore($manifest)) {
                return false;
            }

            return $manifest_class::restore_mode($manifest, $context) === 'network';
        }

        /**
         * @return class-string
         */
        private static function manifest_class(): string
        {
            return 'Rmmigrate_Restore_Topology_Manifest';
        }

        /**
         * @return class-string
         */
        private static function prefix_remap_class(): string
        {
            return 'Rmmigrate_Restore_Topology_Prefix_Remap';
        }

        /**
         * @return class-string
         */
        private static function topology_standalone(): string
        {
            return 'Rmmigrate_Restore_Topology_Standalone';
        }
    }
}
