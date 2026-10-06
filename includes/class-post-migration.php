<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Post_Migration
{
    /**
     * Purge common caching layers after restore/migration.
     */
    public static function purge_caches(): void
    {
        try {
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }

            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
            }
            if (function_exists('litespeed_purge_all')) {
                $rmmigrate_hook_litespeed_purge_all = 'litespeed_purge_all';
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Plugin: third-party cache plugin hooks detected at runtime.
                do_action($rmmigrate_hook_litespeed_purge_all);
            }
            if (function_exists('w3tc_flush_all')) {
                w3tc_flush_all();
            }
            if (did_action('elementor/loaded') || class_exists('\Elementor\Plugin', false)) {
                if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)
                    && is_object(\Elementor\Plugin::$instance->files_manager)
                    && method_exists(\Elementor\Plugin::$instance->files_manager, 'clear_cache')
                ) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
            }
            if (
                class_exists('SiteGround_Optimizer\Supercacher\Supercacher')
                && method_exists('SiteGround_Optimizer\Supercacher\Supercacher', 'purge_cache')
            ) {
                SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
            }
            if (function_exists('sg_cachepress_purge_cache')) {
                sg_cachepress_purge_cache();
            }
            if (class_exists('WpeCommon')) {
                if (method_exists('WpeCommon', 'purge_memcached')) {
                    WpeCommon::purge_memcached();
                }
                if (method_exists('WpeCommon', 'clear_maxcdn_cache')) {
                    WpeCommon::clear_maxcdn_cache();
                }
            }
            if (function_exists('wpfc_clear_all_cache')) {
                wpfc_clear_all_cache(true);
            }
            if (function_exists('ce_clear_cache')) {
                ce_clear_cache();
            }

            do_action('rmmigrate_post_migration_cache_purge');
        } catch (Throwable $e) {
            Rmmigrate_Logger::log('Post-migration cache flush failed: ' . $e->getMessage());
        }
    }

    /**
     * Remove the MU bridge drop-in after restore/migration (no longer needed).
     */
    public static function cleanup_bridge_mu_plugin(): void
    {
        // The standalone-installer bridge is a separate Pro plugin; nothing to clean up here.
    }

    public static function maybe_revalidate_after_migration(): void
    {
        if (class_exists('Rmmigrate_Activator')) {
            Rmmigrate_Activator::ensure_schema();
        }

        self::maybe_upgrade_wp_database();

        /**
         * Fires after a migration may have changed the site URL. A separate paid
         * edition can hook this to re-validate any remote service binding; the
         * free edition has no such binding and intentionally does nothing.
         */
        do_action('rmmigrate_revalidate_after_migration');
    }

    /**
     * If the imported DB has an older schema than the current WordPress install,
     * execute WordPress core schema upgrade so the user is not greeted by
     * the "Database Update Required" screen on the next wp-admin visit.
     */
    public static function maybe_upgrade_wp_database(): void
    {
        global $wp_db_version;
        if (!isset($wp_db_version)) {
            if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-includes/version.php')) {
                require ABSPATH . 'wp-includes/version.php';
            }
        }
        if (!isset($wp_db_version)) {
            return;
        }

        $current_db_version = (int) get_option('db_version', 0);
        if ($current_db_version > 0 && $current_db_version < (int) $wp_db_version) {
            if (function_exists('wp_upgrade')) {
                wp_upgrade();
                return;
            }
            $upgrade_file = defined('ABSPATH') ? (ABSPATH . 'wp-admin/includes/upgrade.php') : '';
            if ($upgrade_file !== '' && file_exists($upgrade_file)) {
                require_once $upgrade_file;
                if (function_exists('wp_upgrade')) {
                    wp_upgrade();
                }
            }
        }
    }
}

