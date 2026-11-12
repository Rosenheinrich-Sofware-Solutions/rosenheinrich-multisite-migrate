<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Network archive → one site as a standalone install.
 *
 * Turns a network-scoped archive into the same shape as a single-subsite archive:
 * a demoted manifest, a table keep-list (users, usermeta and the chosen blog's tables)
 * and an uploads filter (only the chosen blog's media). The regular subsite → standalone
 * finish path ({@see Rmmigrate_Restore_Topology_Standalone::finish()}) does the rest.
 *
 * Keep in sync with pro/trunk/includes/shared/restore-topology/class-restore-topology-extract-site.php.
 */
class Rmmigrate_Restore_Topology_Extract_Site
{
    const RESTORE_MODE = 'extract_site_standalone';

    /**
     * @param array<string,mixed> $manifest
     */
    public static function network_scope_ok(array $manifest): bool
    {
        if (empty($manifest['is_multisite'])) {
            return false;
        }
        $scope = (string) ($manifest['scope'] ?? '');

        return in_array($scope, array('network', 'network_filtered', 'network_included'), true);
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function blog_in_manifest(array $manifest, int $blog_id): bool
    {
        if ($blog_id <= 0) {
            return false;
        }
        foreach ((array) ($manifest['blogs'] ?? array()) as $blog) {
            if (is_array($blog) && (int) ($blog['blog_id'] ?? 0) === $blog_id) {
                return true;
            }
        }

        // Blog 1 may be implied by the primary site_url when blogs[] omits it.
        return $blog_id === 1;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>|null
     */
    public static function blog_entry(array $manifest, int $blog_id): ?array
    {
        foreach ((array) ($manifest['blogs'] ?? array()) as $blog) {
            if (is_array($blog) && (int) ($blog['blog_id'] ?? 0) === $blog_id) {
                return $blog;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function base_prefix(array $manifest): string
    {
        $base = (string) ($manifest['source_base_prefix'] ?? '');
        if ($base !== '') {
            return $base;
        }

        return Rmmigrate_Restore_Topology_Standalone::base_table_prefix((string) ($manifest['db_prefix'] ?? 'wp_'));
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public static function blog_prefix(array $manifest, int $blog_id): string
    {
        $entry = self::blog_entry($manifest, $blog_id);
        if (is_array($entry) && (string) ($entry['db_prefix'] ?? '') !== '') {
            return (string) $entry['db_prefix'];
        }
        $base = self::base_prefix($manifest);
        if ($blog_id <= 1) {
            return $base !== '' ? $base : 'wp_';
        }

        return $base . $blog_id . '_';
    }

    /**
     * Whether a dump table belongs to the extracted site (users/usermeta + that blog's tables).
     */
    public static function keeps_table(string $table, string $base_prefix, int $blog_id): bool
    {
        if ($table === '' || $base_prefix === '' || $blog_id <= 0) {
            return false;
        }
        if ($table === $base_prefix . 'users' || $table === $base_prefix . 'usermeta') {
            return true;
        }
        if (in_array($table, Rmmigrate_Restore_Topology_Db_Action::network_global_table_names($base_prefix), true)) {
            return false;
        }
        if (strpos($table, $base_prefix) !== 0) {
            return false;
        }
        $rest = substr($table, strlen($base_prefix));
        if ($blog_id === 1) {
            // Blog 1 lives on the bare base prefix; other blogs use base + N_.
            return !preg_match('/^\d+_/', $rest);
        }

        return strpos($rest, $blog_id . '_') === 0;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return string[] Tables to keep (import); everything else is skipped.
     */
    public static function keep_tables(array $manifest, int $blog_id): array
    {
        $base = self::base_prefix($manifest);
        $keep = array();
        foreach (Rmmigrate_Restore_Topology_Ui::import_table_names($manifest) as $table) {
            if (self::keeps_table($table, $base, $blog_id)) {
                $keep[] = $table;
            }
        }

        return array_values(array_unique($keep));
    }

    /**
     * @param array<string,mixed> $manifest
     * @return string[]
     */
    public static function import_skip_tables(array $manifest, int $blog_id): array
    {
        $all = Rmmigrate_Restore_Topology_Ui::import_table_names($manifest);
        if ($all === array()) {
            return array();
        }

        return array_values(array_diff($all, self::keep_tables($manifest, $blog_id)));
    }

    /**
     * Manifest shape that the standalone finish path treats as a subsite → standalone demote.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public static function demote_manifest(array $manifest, int $blog_id): array
    {
        $base = self::base_prefix($manifest);
        $prefix = self::blog_prefix($manifest, $blog_id);
        $entry = self::blog_entry($manifest, $blog_id);

        $out = $manifest;
        $out['scope'] = 'subsite';
        $out['restore_multisite'] = false;
        unset($out['restore_mode']);
        $out['blog_id'] = $blog_id;
        $out['db_prefix'] = $prefix;
        $out['source_blog_prefix'] = $prefix;
        $out['source_base_prefix'] = $base;
        $out['extracted_from_network'] = true;
        if (is_array($entry)) {
            $site_url = (string) ($entry['site_url'] ?? '');
            if ($site_url !== '') {
                $out['site_url'] = $site_url;
                $out['home_url'] = (string) ($entry['home_url'] ?? $site_url);
            }
            $path = (string) ($entry['path'] ?? '');
            if ($path !== '' && empty($manifest['subdomain_install'])) {
                // Relative links of a subdirectory site start with its own path, not the network path.
                $out['path_current_site'] = $path;
            }
            $out['blogs'] = array($entry);
        } else {
            $out['blogs'] = array();
        }
        $out['included_blogs'] = array($blog_id);
        $out['excluded_blogs'] = array();
        if (isset($manifest['tables']) && is_array($manifest['tables'])) {
            $out['tables'] = self::keep_tables($manifest, $blog_id);
            $out['table_count'] = count($out['tables']);
        }

        return $out;
    }

    /**
     * Where a subsite's media lands on a standalone (single-site) install.
     *
     * A single site reads attachments from uploads/<YYYY>/..., so the subsite's own tree
     * (uploads/sites/<id>/... or legacy blogs.dir/<id>/files/...) moves up to uploads/.
     * Every other path is returned unchanged.
     *
     * @param string $rel Path relative to wp-content/ (may use \ or /).
     */
    public static function standalone_upload_rel(string $rel, int $blog_id): string
    {
        $norm = ltrim(str_replace('\\', '/', $rel), '/');
        if ($blog_id <= 1) {
            return $norm;
        }
        foreach (array('uploads/sites/' . $blog_id . '/', 'blogs.dir/' . $blog_id . '/files/') as $prefix) {
            if (strpos($norm, $prefix) === 0) {
                return 'uploads/' . substr($norm, strlen($prefix));
            }
        }

        return $norm;
    }

    /**
     * URL pairs that move a subsite's media URLs to the standalone uploads root.
     *
     * Runs in the same non-cascading pass as the site URL pair; the longer upload pair wins,
     * so <old site>/wp-content/uploads/sites/<id>/x becomes <new site>/wp-content/uploads/x.
     *
     * @param array<string,mixed> $manifest Source (or demoted) manifest.
     * @return array<int,array{old:string,new:string}>
     */
    public static function standalone_upload_url_pairs(array $manifest, int $blog_id, string $new_site_url): array
    {
        $new_site_url = rtrim($new_site_url, '/');
        if ($blog_id <= 1 || $new_site_url === '') {
            return array();
        }
        $entry = self::blog_entry($manifest, $blog_id);
        $olds = array();
        if (is_array($entry) && (string) ($entry['upload_baseurl'] ?? '') !== '') {
            $olds[] = (string) $entry['upload_baseurl'];
        }
        foreach (array(
            is_array($entry) ? (string) ($entry['site_url'] ?? '') : '',
            (string) ($manifest['site_url'] ?? ''),
            (string) ($manifest['network_site_url'] ?? ''),
        ) as $site) {
            if ($site !== '') {
                $olds[] = rtrim($site, '/') . '/wp-content/uploads/sites/' . $blog_id;
            }
        }
        // Legacy ms-files networks (pre-3.5 installs) serve blogs.dir/<id>/files/ as <site>/files/.
        $subsite_url = is_array($entry) ? rtrim((string) ($entry['site_url'] ?? ''), '/') : '';
        if ($subsite_url !== '' && !empty($manifest['ms_files_rewriting'])) {
            $olds[] = $subsite_url . '/files';
        }
        $new_base = $new_site_url . '/wp-content/uploads';
        $pairs = array();
        $seen = array();
        foreach ($olds as $old) {
            $old = rtrim($old, '/');
            if ($old === '' || isset($seen[$old]) || $old === $new_base) {
                continue;
            }
            $seen[$old] = true;
            $pairs[] = array('old' => $old . '/', 'new' => $new_base . '/');
        }
        // Root-relative links (/wp-content/uploads/sites/<id>/...) in content and blocks.
        $pairs[] = array('old' => '/wp-content/uploads/sites/' . $blog_id . '/', 'new' => '/wp-content/uploads/');

        return $pairs;
    }

    /**
     * Skip other sites' upload trees during an extract restore.
     * Keeps themes/plugins; only filters uploads/ and blogs.dir/.
     *
     * @param string $rel Path relative to wp-content/ (may use \ or /).
     */
    /**
     * Subsite restored into another blog slot of a network (alpha, blog 2 -> gamma, blog 4):
     * its media tree moves from uploads/sites/<source>/ to uploads/sites/<target>/.
     *
     * @param string $rel Path relative to wp-content/ (may use \ or /).
     */
    public static function slot_upload_rel(string $rel, int $source_blog_id, int $target_blog_id): string
    {
        $norm = ltrim(str_replace('\\', '/', $rel), '/');
        if ($source_blog_id <= 1 || $target_blog_id <= 1 || $source_blog_id === $target_blog_id) {
            return $norm;
        }
        foreach (array('uploads/sites/' . $source_blog_id . '/', 'blogs.dir/' . $source_blog_id . '/files/') as $prefix) {
            if (strpos($norm, $prefix) === 0) {
                return 'uploads/sites/' . $target_blog_id . '/' . substr($norm, strlen($prefix));
            }
        }

        return $norm;
    }

    /**
     * URL pairs that follow the slot move of slot_upload_rel(). Same non-cascading pass as
     * the site URL pair; the longer upload pair wins.
     *
     * @param array<string,mixed> $manifest
     * @return array<int,array{old:string,new:string}>
     */
    public static function slot_upload_url_pairs(array $manifest, int $source_blog_id, int $target_blog_id, string $new_site_url): array
    {
        $new_site_url = rtrim($new_site_url, '/');
        if ($source_blog_id <= 1 || $target_blog_id <= 1 || $source_blog_id === $target_blog_id || $new_site_url === '') {
            return array();
        }
        $entry = self::blog_entry($manifest, $source_blog_id);
        $olds = array();
        if (is_array($entry) && (string) ($entry['upload_baseurl'] ?? '') !== '') {
            $olds[] = (string) $entry['upload_baseurl'];
        }
        foreach (array(
            is_array($entry) ? (string) ($entry['site_url'] ?? '') : '',
            (string) ($manifest['site_url'] ?? ''),
            (string) ($manifest['network_site_url'] ?? ''),
        ) as $site) {
            if ($site !== '') {
                $olds[] = rtrim($site, '/') . '/wp-content/uploads/sites/' . $source_blog_id;
            }
        }
        $subsite_url = is_array($entry) ? rtrim((string) ($entry['site_url'] ?? ''), '/') : rtrim((string) ($manifest['site_url'] ?? ''), '/');
        if ($subsite_url !== '' && !empty($manifest['ms_files_rewriting'])) {
            $olds[] = $subsite_url . '/files';
        }
        $new_base = $new_site_url . '/wp-content/uploads/sites/' . $target_blog_id;
        $pairs = array();
        $seen = array();
        foreach ($olds as $old) {
            $old = rtrim($old, '/');
            if ($old === '' || isset($seen[$old]) || $old === $new_base) {
                continue;
            }
            $seen[$old] = true;
            $pairs[] = array('old' => $old . '/', 'new' => $new_base . '/');
        }
        $pairs[] = array(
            'old' => '/wp-content/uploads/sites/' . $source_blog_id . '/',
            'new' => '/wp-content/uploads/sites/' . $target_blog_id . '/',
        );

        return $pairs;
    }

    public static function is_foreign_extract_upload_rel(string $rel, int $source_blog_id): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($source_blog_id < 1) {
            return false;
        }

        if (preg_match('#^(?:uploads/sites|blogs\.dir)/(\d+)(?:/|$)#', $rel, $m)) {
            return (int) $m[1] !== $source_blog_id;
        }

        // Main-site (blog 1) media lives under uploads/ but not uploads/sites/.
        if (strpos($rel, 'uploads/') === 0 || strpos($rel, 'blogs.dir/') === 0) {
            return $source_blog_id !== 1;
        }

        return false;
    }
}
