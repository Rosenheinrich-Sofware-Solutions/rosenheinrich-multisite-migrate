<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared manifest restore-mode helpers for installer and in-plugin restore.
 * Keep in sync with installer/lib/manifest.php restore flags.
 */
class Rmmigrate_Restore_Topology_Manifest
{
    /**
     * @param array<string,mixed> $manifest
     */
    public static function restore_as_multisite(array $manifest): bool
    {
        if (array_key_exists('restore_multisite', $manifest)) {
            return !empty($manifest['restore_multisite']);
        }
        if (empty($manifest['is_multisite'])) {
            return false;
        }

        return ($manifest['scope'] ?? '') !== 'subsite';
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function is_subsite_standalone_restore(array $manifest): bool
    {
        if (empty($manifest['is_multisite'])) {
            return false;
        }

        return ($manifest['scope'] ?? '') === 'subsite' && !self::restore_as_multisite($manifest);
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function restore_mode(array $manifest, array $state = array()): string
    {
        $explicit = (string) ($state['restore_mode'] ?? $manifest['restore_mode'] ?? '');
        if ($explicit !== '') {
            return $explicit;
        }
        if (self::is_subsite_standalone_restore($manifest)) {
            return 'standalone';
        }
        if (self::restore_as_multisite($manifest)) {
            return 'network';
        }

        return 'standalone';
    }

    /**
     * @param array<string,mixed> $manifest
     * @return string[]
     */
    public static function table_prefixes(array $manifest): array
    {
        $prefixes = array();
        $primary = (string) ($manifest['db_prefix'] ?? '');
        if ($primary !== '') {
            $prefixes[] = $primary;
        }

        $blogs = $manifest['blogs'] ?? array();
        if (!is_array($blogs)) {
            return array_values(array_unique($prefixes));
        }

        foreach ($blogs as $blog) {
            if (!is_array($blog)) {
                continue;
            }
            $blog_prefix = (string) ($blog['db_prefix'] ?? '');
            if ($blog_prefix !== '') {
                $prefixes[] = $blog_prefix;
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function source_base_prefix(array $manifest): string
    {
        $blog_prefix = (string) ($manifest['db_prefix'] ?? 'wp_');
        if (($manifest['source_base_prefix'] ?? '') !== '') {
            return (string) $manifest['source_base_prefix'];
        }

        return Rmmigrate_Restore_Topology_Standalone::base_table_prefix($blog_prefix);
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function source_blog_prefix(array $manifest): string
    {
        if (($manifest['source_blog_prefix'] ?? '') !== '') {
            return (string) $manifest['source_blog_prefix'];
        }

        return (string) ($manifest['db_prefix'] ?? 'wp_');
    }

    /**
     * Prefix for SQL import, finalize, plugins, and wp-config (subsite standalone uses source blog prefix).
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     */
    public static function import_table_prefix(array $manifest, array $context = array()): string
    {
        if (self::is_subsite_standalone_restore($manifest)) {
            return self::source_blog_prefix($manifest);
        }

        $from_context = (string) ($context['db_prefix'] ?? '');
        if ($from_context !== '') {
            return $from_context;
        }

        return self::source_blog_prefix($manifest);
    }

    /**
     * Resolve network-to-subsite target blog ID from restore context.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $destination
     */
    public static function resolve_target_blog_id(array $context, array $destination = array()): int
    {
        if ($destination === array() && is_array($context['destination'] ?? null)) {
            $destination = $context['destination'];
        }

        return (int) (
            self::first_positive_blog_id(
                $context['target_blog_id'] ?? null,
                $context['destination_blog_id'] ?? null,
                $destination['destination_blog_id'] ?? null
            )
        );
    }

    /**
     * @param mixed ...$candidates
     */
    private static function first_positive_blog_id(...$candidates): int
    {
        foreach ($candidates as $candidate) {
            $id = (int) $candidate;
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }
}
