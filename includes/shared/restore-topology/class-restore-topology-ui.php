<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Restore_Topology_Ui
{
    const NETWORK_BLOG_CHOICES_LIMIT = 200;

    /**
     * @return string[]
     */
    public static function wp_config_merge_constant_keys(): array
    {
        return array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'table_prefix');
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $destination
     */
    public static function offers_subsite_on_network(array $manifest, array $destination): bool
    {
        return ($manifest['scope'] ?? '') === 'subsite'
            && !empty($manifest['is_multisite'])
            && !empty($destination['is_multisite'])
            && empty($destination['is_multisite_subsite']);
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $destination
     */
    public static function offers_network_to_subsite(array $manifest, array $destination): bool
    {
        if (!Rmmigrate_Restore_Topology_Manifest::restore_as_multisite($manifest)) {
            return false;
        }

        return !empty($destination['is_multisite'])
            && !empty($destination['is_multisite_subsite']);
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $destination
     * @return array<string,mixed>
     */
    public static function parse_topology_import(array $raw, array $manifest, array $destination): array
    {
        $topology = sanitize_key((string) ($raw['topology_import'] ?? 'auto'));
        $out = array(
            'restore_mode'   => '',
            'site_owr_map'   => array(),
            'target_blog_id' => 0,
            'prefix_policy'  => sanitize_key((string) ($raw['prefix_policy'] ?? 'keep_source')),
        );

        if ($topology === 'subsite_on_network' && self::offers_subsite_on_network($manifest, $destination)) {
            $target_blog_id = (int) ($raw['topology_target_blog_id'] ?? 0);
            $target_url = esc_url_raw((string) ($raw['topology_target_url'] ?? ''));
            if ($target_url === '') {
                return $out;
            }
            $out['restore_mode'] = 'subsite_on_network';
            $out['site_owr_map'] = array(
                array(
                    'target_blog_id' => $target_blog_id,
                    'target_url'     => $target_url,
                ),
            );

            return $out;
        }

        if ($topology === 'network_to_subsite' && self::offers_network_to_subsite($manifest, $destination)) {
            $target_blog_id = (int) ($raw['topology_target_blog_id'] ?? 0);
            if ($target_blog_id <= 0) {
                $target_blog_id = (int) ($destination['destination_blog_id'] ?? 0);
            }
            if ($target_blog_id <= 0) {
                return $out;
            }
            $out['restore_mode'] = 'network_to_subsite';
            $out['target_blog_id'] = $target_blog_id;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return string[]
     */
    public static function import_table_names(array $manifest): array
    {
        $tables = $manifest['tables'] ?? array();
        if (!is_array($tables)) {
            return array();
        }
        $names = array();
        foreach ($tables as $table) {
            if (is_string($table) && $table !== '') {
                $names[] = $table;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string,mixed> $manifest
     * @param string[] $selected_tables Full table names to import (unchecked tables are skipped).
     * @return string[]
     */
    public static function import_skip_tables(array $manifest, array $selected_tables): array
    {
        $all = self::import_table_names($manifest);
        if ($all === array()) {
            return array();
        }
        $selected = array_values(array_unique(array_filter(array_map('strval', $selected_tables))));
        if ($selected === array()) {
            return $all;
        }

        return array_values(array_diff($all, $selected));
    }

    /**
     * @param array<string,mixed> $manifest
     * @param string[] $skip_tables
     * @return string[]
     */
    public static function normalize_import_skip_tables(array $manifest, array $skip_tables): array
    {
        $all = self::import_table_names($manifest);
        if ($all === array()) {
            return array();
        }
        $prefix = (string) ($manifest['db_prefix'] ?? 'wp_');
        $normalized = array();
        foreach ($skip_tables as $table) {
            $table = (string) $table;
            if ($table === '') {
                continue;
            }
            if (in_array($table, $all, true)) {
                $normalized[] = $table;
                continue;
            }
            $candidate = (strpos($table, $prefix) === 0) ? $table : $prefix . $table;
            if (in_array($candidate, $all, true)) {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int,array{blog_id:int,label:string}>
     */
    public static function network_blog_choices(): array
    {
        if (!function_exists('get_sites')) {
            return array();
        }
        $sites = get_sites(array('number' => self::NETWORK_BLOG_CHOICES_LIMIT, 'orderby' => 'id', 'order' => 'ASC'));
        $choices = array();
        foreach ($sites as $site) {
            if (!is_object($site) || !isset($site->blog_id)) {
                continue;
            }
            $blog_id = (int) $site->blog_id;
            $domain = (string) ($site->domain ?? '');
            $path = (string) ($site->path ?? '/');
            $label = $domain !== '' ? $domain . $path : ('#' . $blog_id);
            $choices[] = array(
                'blog_id' => $blog_id,
                'label'   => $label,
            );
        }

        return $choices;
    }

    private static function network_site_count(): int
    {
        if (!function_exists('get_sites')) {
            return 0;
        }

        return (int) get_sites(array('count' => true));
    }

    public static function network_blog_choices_truncated(): bool
    {
        return self::network_site_count() > self::NETWORK_BLOG_CHOICES_LIMIT;
    }

    public static function network_blog_choices_limit_notice(): string
    {
        $total = self::network_site_count();
        if ($total <= self::NETWORK_BLOG_CHOICES_LIMIT) {
            return '';
        }

        return sprintf(
            /* translators: 1: number of sites shown, 2: total network site count. */
            __('Showing the first %1$d network sites (of %2$d). Enter a blog ID manually if yours is not listed.', 'rosenheinrich-multisite-migrate'),
            self::NETWORK_BLOG_CHOICES_LIMIT,
            $total
        );
    }
}
