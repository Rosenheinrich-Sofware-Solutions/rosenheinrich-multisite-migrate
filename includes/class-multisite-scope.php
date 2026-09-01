<?php

/**
 * Multisite table and path scoping for backups.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Multisite_Scope
{
    const SCOPE_NETWORK          = 'network';
    const SCOPE_NETWORK_FILTERED = 'network_filtered';
    const SCOPE_NETWORK_INCLUDED = 'network_included';
    const SCOPE_SUBSITE          = 'subsite';

    /** @var string[]|null */
    private $exclude_paths_cache = null;

    /** @var string */
    private $scope;

    /** @var int */
    private $blog_id;

    /** @var int[] */
    private $excluded_blog_ids;

    /** @var int[] */
    private $included_blog_ids;

    /** @var string[]|null */
    private $tables_filter_cache = null;

    /** @var string[] */
    private $excluded_table_patterns = array();

    /**
     * @param int[]    $excluded_blog_ids
     * @param int[]    $included_blog_ids
     * @param string[] $excluded_table_patterns
     */
    public function __construct(string $scope, int $blog_id = 0, array $excluded_blog_ids = array(), array $included_blog_ids = array(), array $excluded_table_patterns = array())
    {
        $this->scope = $scope;
        $this->blog_id = $blog_id;
        $this->excluded_blog_ids = array_values(array_map('intval', $excluded_blog_ids));
        $this->included_blog_ids = array_values(array_map('intval', $included_blog_ids));
        $this->excluded_table_patterns = array_values(array_filter(array_map('strval', $excluded_table_patterns)));
    }

    public static function from_job(Rmmigrate_Job $job): self
    {
        $scope = $job->get_scope();
        $excluded = $job->get_excluded_blogs();
        $included = array();
        if ($scope === self::SCOPE_NETWORK_INCLUDED) {
            $included = $job->get_included_blogs();
        }

        return new self($scope, $job->get_blog_id(), $excluded, $included, $job->get_excluded_table_patterns());
    }

    /**
     * @return int[]
     */
    public static function get_all_site_ids(): array
    {
        if (!is_multisite()) {
            return array((int) get_current_blog_id());
        }

        $ids = array();
        $offset = 0;
        $number = 500;
        do {
            $batch = get_sites(array(
                'number' => $number,
                'offset' => $offset,
                'fields' => 'ids',
            ));
            if (!is_array($batch) || $batch === array()) {
                break;
            }
            foreach ($batch as $id) {
                $ids[] = (int) $id;
            }
            $offset += $number;
        } while (count($batch) === $number);

        return $ids;
    }

    public static function subsite_picker_limit(): int
    {
        return 500;
    }

    /**
     * @return array<int,array{blog_id:int,label:string}>
     */
    public static function search_network_sites(string $search, int $limit = 25, int $offset = 0): array
    {
        if (!is_multisite() || !function_exists('get_sites')) {
            return array();
        }

        $search = trim($search);
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $args = array(
            'number'  => $limit,
            'offset'  => $offset,
            'orderby' => 'id',
            'order'   => 'ASC',
        );
        if ($search !== '') {
            $args['search'] = $search;
        }

        $sites = get_sites($args);
        $results = array();
        foreach ($sites as $site) {
            if (!is_object($site) || !isset($site->blog_id)) {
                continue;
            }
            $blog_id = (int) $site->blog_id;
            $results[] = array(
                'blog_id' => $blog_id,
                'label'   => self::format_blog_label($blog_id),
            );
        }

        return $results;
    }

    /**
     * @param int[] $blog_ids
     * @return array<int,array{blog_id:int,label:string}>
     */
    public static function network_site_labels(array $blog_ids): array
    {
        $results = array();
        foreach (array_values(array_unique(array_map('intval', $blog_ids))) as $blog_id) {
            if ($blog_id <= 0) {
                continue;
            }
            $results[] = array(
                'blog_id' => $blog_id,
                'label'   => self::format_blog_label($blog_id),
            );
        }

        return $results;
    }

    /**
     * @return array{label: string, detail: string, warning: string}
     */
    public static function get_settings_scope_display(array $settings, bool $is_network): array
    {
        $label = '';
        $detail = '';
        $warning = '';
        $scope = (string) ($settings['default_scope'] ?? self::SCOPE_NETWORK);

        if (!is_multisite() || !$is_network) {
            $site_details = get_blog_details(get_current_blog_id());
            $label = $site_details
                ? self::format_blog_label((int) get_current_blog_id())
                : __('This site', 'rosenheinrich-multisite-migrate');

            return array(
                'label'   => $label,
                'detail'  => $detail,
                'warning' => $warning,
            );
        }

        $scope_labels = array(
            self::SCOPE_NETWORK          => __('Full network', 'rosenheinrich-multisite-migrate'),
            self::SCOPE_NETWORK_INCLUDED => __('Only selected subsites', 'rosenheinrich-multisite-migrate'),
            self::SCOPE_NETWORK_FILTERED => __('Network (exclude subsites)', 'rosenheinrich-multisite-migrate'),
        );
        $label = $scope_labels[$scope] ?? $scope_labels[self::SCOPE_NETWORK];

        $blog_ids = array();
        if ($scope === self::SCOPE_NETWORK_INCLUDED) {
            $blog_ids = array_map('intval', (array) ($settings['default_included_blogs'] ?? array()));
            if ($blog_ids === array()) {
                $warning = __('No subsites selected. Go back to Scope and choose at least one site.', 'rosenheinrich-multisite-migrate');
            }
        } elseif ($scope === self::SCOPE_NETWORK_FILTERED) {
            $blog_ids = array_map('intval', (array) ($settings['default_excluded_blogs'] ?? array()));
        }

        if ($blog_ids !== array()) {
            $site_names = array();
            foreach ($blog_ids as $blog_id) {
                $site_label = self::format_blog_label($blog_id);
                if ($site_label !== '') {
                    $site_names[] = $site_label;
                }
            }
            if ($site_names !== array()) {
                $detail = implode('; ', $site_names);
            }
        }

        return array(
            'label'   => $label,
            'detail'  => $detail,
            'warning' => $warning,
        );
    }

    public static function format_blog_label(int $blog_id): string
    {
        $details = get_blog_details($blog_id);
        if (!$details) {
            return '';
        }

        return $details->blogname . ' (' . $details->domain . $details->path . ')';
    }

    /**
     * @param int[] $excluded_blog_ids
     * @param int[] $included_blog_ids
     * @param bool  $trusted_network When true, honor network scope without a logged-in super admin (scheduled cron).
     * @return array{scope:string,excluded_blogs:int[],downgraded:bool}|WP_Error
     */
    public static function resolve_backup_scope(string $scope, array $excluded_blog_ids = array(), array $included_blog_ids = array(), bool $trusted_network = false)
    {
        if (is_multisite() && !$trusted_network && !current_user_can('manage_network')) {
            return array(
                'scope'          => self::SCOPE_SUBSITE,
                'excluded_blogs' => array(),
                'downgraded'     => true,
            );
        }

        $excluded_blog_ids = array_values(array_unique(array_map('intval', array_filter($excluded_blog_ids))));
        $included_blog_ids = array_values(array_unique(array_map('intval', array_filter($included_blog_ids))));

        if ($scope === self::SCOPE_NETWORK_INCLUDED) {
            if ($included_blog_ids === array()) {
                return new WP_Error('mm_scope', __('Select at least one subsite to include.', 'rosenheinrich-multisite-migrate'));
            }

            $all = self::get_all_site_ids();
            $invalid = array_diff($included_blog_ids, $all);
            if ($invalid !== array()) {
                return new WP_Error('mm_scope', __('One or more selected subsites are invalid.', 'rosenheinrich-multisite-migrate'));
            }

            if (count($included_blog_ids) >= count($all)) {
                return array(
                    'scope'           => self::SCOPE_NETWORK,
                    'excluded_blogs'  => array(),
                    'downgraded'      => false,
                );
            }

            return array(
                'scope'          => self::SCOPE_NETWORK_INCLUDED,
                'excluded_blogs' => array_values(array_diff($all, $included_blog_ids)),
                'downgraded'     => false,
            );
        }

        if ($scope === self::SCOPE_NETWORK_FILTERED) {
            if ($excluded_blog_ids === array()) {
                return new WP_Error('mm_scope', __('Select at least one subsite to exclude, or choose Full network.', 'rosenheinrich-multisite-migrate'));
            }

            $all = self::get_all_site_ids();
            if (count($excluded_blog_ids) >= count($all)) {
                return new WP_Error(
                    'mm_scope',
                    __('Cannot exclude every subsite. Use “Only selected subsites” to back up specific sites.', 'rosenheinrich-multisite-migrate')
                );
            }

            return array(
                'scope'          => self::SCOPE_NETWORK_FILTERED,
                'excluded_blogs' => $excluded_blog_ids,
                'downgraded'     => false,
            );
        }

        if ($scope !== self::SCOPE_NETWORK && $scope !== self::SCOPE_SUBSITE) {
            return new WP_Error('mm_scope', __('Invalid backup scope.', 'rosenheinrich-multisite-migrate'));
        }

        return array(
            'scope'          => $scope,
            'excluded_blogs' => array(),
            'downgraded'     => false,
        );
    }

    /**
     * @return string[]
     */
    public function get_tables(): array
    {
        global $wpdb;

        $cache_key = 'mm_all_tables_' . md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->base_prefix);
        $cache_ttl = Rmmigrate_Job::get_active() !== null ? 0 : 3600;
        $all = $cache_ttl > 0 ? wp_cache_get($cache_key, 'rmmigrate') : false;
        if ($all === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin: custom plugin tables; values use prepare().
            $all = $wpdb->get_col('SHOW TABLES');
            if ($cache_ttl > 0) {
                wp_cache_set($cache_key, $all, 'rmmigrate', $cache_ttl);
            }
        }
        if (!is_array($all)) {
            return array();
        }

        $settings = Rmmigrate_Settings::get();
        $exclude_patterns = $this->resolve_table_exclude_patterns($settings);

        if ($this->scope === self::SCOPE_NETWORK) {
            $tables = $all;
            if (!empty($settings['exclude_deleted_subsite_tables'])) {
                $tables = array_values(array_diff($tables, self::get_orphan_subsite_tables($all)));
            }
            return self::apply_table_excludes($tables, $exclude_patterns);
        }

        if ($this->scope === self::SCOPE_NETWORK_FILTERED) {
            $exclude = $this->get_tables_to_filter();
            return self::apply_table_excludes(array_values(array_diff($all, $exclude)), $exclude_patterns);
        }

        if ($this->scope === self::SCOPE_NETWORK_INCLUDED) {
            return self::apply_table_excludes($this->get_included_subsite_tables($all), $exclude_patterns);
        }

        return self::apply_table_excludes($this->get_subsite_tables($all), $exclude_patterns);
    }

    /**
     * @param array<string,mixed>|null $settings
     * @return string[]
     */
    private function resolve_table_exclude_patterns(?array $settings = null): array
    {
        $settings = $settings ?? Rmmigrate_Settings::get();
        $patterns = array_merge($this->excluded_table_patterns, self::plugin_runtime_table_patterns());
        if (!empty($settings['exclude_log_tables'])) {
            $patterns = array_merge($patterns, self::log_table_patterns());
        }
        return array_values(array_unique($patterns));
    }

    /**
     * Plugin job/history tables — never pack into backups (dest UI must start clean).
     *
     * @return string[]
     */
    public static function plugin_runtime_table_patterns(): array
    {
        $defaults = array(
            '*rmmigrate_jobs',
            '*rmmigrate_pro_jobs',
        );
        /**
         * Filter Multisite Migrate runtime table patterns always excluded from backups.
         *
         * @param string[] $patterns fnmatch-style patterns.
         */
        return apply_filters('rmmigrate_plugin_runtime_table_patterns', $defaults);
    }

    /**
     * @return string[]
     */
    public static function log_table_patterns(): array
    {
        $defaults = array(
            '*wfblockediplog',
            '*wfHits',
            '*wfFileMods',
            '*wfauditevents',
            '*wflivetraffictwo',
            '*wfKnownFileList',
            '*wflogins',
            '*actionscheduler_*',
        );
        /**
         * Filter log table name patterns excluded when exclude_log_tables is enabled.
         *
         * @param string[] $patterns fnmatch-style patterns.
         */
        return apply_filters('rmmigrate_log_table_patterns', $defaults);
    }

    /**
     * @param string[] $tables
     * @param string[] $patterns Exact names or fnmatch patterns (e.g. prefix_wf*).
     * @return string[]
     */
    public static function apply_table_excludes(array $tables, array $patterns): array
    {
        if ($patterns === array()) {
            return $tables;
        }
        $patterns = array_values(array_filter(array_map('strval', $patterns)));
        return array_values(array_filter($tables, function ($table) use ($patterns) {
            foreach ($patterns as $pattern) {
                if ($pattern === $table) {
                    return false;
                }
                if (strpos($pattern, '*') !== false && fnmatch($pattern, $table, FNM_CASEFOLD)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * @param string[] $all_tables
     * @return string[]
     */
    private function get_included_subsite_tables(array $all_tables): array
    {
        if ($this->included_blog_ids === array()) {
            return array();
        }

        $merged = array();
        foreach ($this->included_blog_ids as $blog_id) {
            $merged = array_merge($merged, $this->get_subsite_tables($all_tables, (int) $blog_id));
        }

        return $this->filter_tables_to_allowed_blogs(array_values(array_unique($merged)), $this->included_blog_ids);
    }

    /**
     * Core multisite tables always safe to include with any subsite export.
     *
     * @return string[]
     */
    private function network_global_tables(): array
    {
        global $wpdb;

        $globals = array($wpdb->users, $wpdb->usermeta);
        if (is_multisite()) {
            $globals[] = $wpdb->blogs;
            $globals[] = $wpdb->site;
            $globals[] = $wpdb->sitemeta;
        }

        return $globals;
    }

    /**
     * Drop base-prefix plugin tables when exporting non-main subsites only.
     *
     * @param string[] $tables
     * @param int[]    $blog_ids
     * @return string[]
     */
    private function filter_tables_to_allowed_blogs(array $tables, array $blog_ids): array
    {
        global $wpdb;

        $blog_ids = array_values(array_map('intval', $blog_ids));
        if ($blog_ids === array()) {
            return array();
        }

        $globals = $this->network_global_tables();
        $base = $wpdb->base_prefix;
        $prefixes = array();
        foreach ($blog_ids as $blog_id) {
            $prefixes[] = $wpdb->get_blog_prefix($blog_id);
        }

        return array_values(array_filter($tables, static function ($table) use ($globals, $base, $prefixes) {
            if (in_array($table, $globals, true)) {
                return true;
            }
            foreach ($prefixes as $prefix) {
                if ($prefix === '' || strpos($table, $prefix) !== 0) {
                    continue;
                }
                if ($prefix === $base && preg_match('/^' . preg_quote($base, '/') . '\d+_/', $table)) {
                    continue;
                }
                return true;
            }
            return false;
        }));
    }

    /**
     * Tables to exclude from network_filtered backup (network subsite filter logic).
     *
     * @return string[]
     */
    private function get_tables_to_filter(): array
    {
        if ($this->tables_filter_cache !== null) {
            return $this->tables_filter_cache;
        }

        global $wpdb;
        $this->tables_filter_cache = array();

        if (empty($this->excluded_blog_ids)) {
            return $this->tables_filter_cache;
        }

        $prefixes = array();
        foreach ($this->excluded_blog_ids as $site_id) {
            $prefix = $wpdb->get_blog_prefix($site_id);
            if ((int) $site_id === 1) {
                $default_tables = array(
                    'commentmeta', 'comments', 'links', 'postmeta', 'posts',
                    'terms', 'term_relationships', 'term_taxonomy', 'termmeta',
                );
                foreach ($default_tables as $tb) {
                    $this->tables_filter_cache[] = $prefix . $tb;
                }
            } else {
                $prefixes[] = preg_quote($prefix, '/');
            }
        }

        if (!empty($prefixes)) {
            $regex = '^(' . implode('|', $prefixes) . ').+';
            $cache_key = 'mm_sub_tables_' . md5($regex);
            $sub_tables = wp_cache_get($cache_key, 'rmmigrate');
            if ($sub_tables === false) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin: custom plugin tables; values use prepare().
                $all_tables = $wpdb->get_col(
                    $wpdb->prepare(
                        'SHOW TABLES FROM %i',
                        DB_NAME
                    )
                );
                $sub_tables = array();
                if (is_array($all_tables)) {
                    foreach ($all_tables as $table) {
                        if (preg_match('/' . $regex . '/', (string) $table) === 1) {
                            $sub_tables[] = $table;
                        }
                    }
                }
                wp_cache_set($cache_key, $sub_tables, 'rmmigrate', 3600);
            }
            if (is_array($sub_tables)) {
                $this->tables_filter_cache = array_merge($this->tables_filter_cache, $sub_tables);
            }
        }

        return $this->tables_filter_cache;
    }

    /**
     * @param string[] $all_tables
     * @param int|null $blog_id
     * @return string[]
     */
    private function get_subsite_tables(array $all_tables, ?int $blog_id = null): array
    {
        global $wpdb;
        $blog_id = $blog_id ?? ($this->blog_id > 0 ? $this->blog_id : get_current_blog_id());
        $prefix = $wpdb->get_blog_prefix($blog_id);

        $include = array();
        $base_prefix = $wpdb->base_prefix;
        foreach ($all_tables as $table) {
            if (strpos($table, $prefix) === 0) {
                // If prefix is base_prefix (e.g. main site), exclude subsite tables (wp_2_, wp_10_)
                if ($prefix === $base_prefix && preg_match('/^' . preg_quote($base_prefix, '/') . '\d+_/', $table)) {
                    continue;
                }
                $include[] = $table;
            }
        }

        $global = array($wpdb->users, $wpdb->usermeta);
        if (is_multisite()) {
            $global[] = $wpdb->blogs;
            $global[] = $wpdb->site;
            $global[] = $wpdb->sitemeta;
        }
        if ($blog_id === 1 || !is_multisite()) {
            $global[] = $wpdb->options;
        }

        foreach ($global as $g) {
            if (in_array($g, $all_tables, true) && !in_array($g, $include, true)) {
                $include[] = $g;
            }
        }

        $include = array_values(array_unique($include));
        if (is_multisite() && (int) $blog_id !== 1) {
            $include = $this->filter_tables_to_allowed_blogs($include, array((int) $blog_id));
        }

        return $include;
    }

    /**
     * @return string[]
     */
    public function get_content_paths(): array
    {
        $wp_content = wp_normalize_path(WP_CONTENT_DIR);

        if ($this->scope === self::SCOPE_NETWORK_INCLUDED) {
            return $this->get_included_subsite_content_paths($wp_content);
        }

        if ($this->scope === self::SCOPE_SUBSITE) {
            return $this->get_subsite_content_paths($wp_content);
        }

        return array($wp_content);
    }

    /**
     * @return string[]
     */
    private function get_included_subsite_content_paths(string $wp_content_dir): array
    {
        $paths = array();
        foreach ($this->included_blog_ids as $blog_id) {
            $paths = array_merge(
                $paths,
                Rmmigrate_Mu_Legacy::subsite_upload_paths((int) $blog_id, $wp_content_dir)
            );
        }
        $paths[] = $wp_content_dir . '/themes';
        $paths[] = $wp_content_dir . '/plugins';

        return array_values(array_unique($paths));
    }

    /**
     * @return string[]
     */
    private function get_subsite_content_paths(string $wp_content_dir): array
    {
        $blog_id = $this->blog_id > 0 ? $this->blog_id : get_current_blog_id();
        $paths = Rmmigrate_Mu_Legacy::subsite_upload_paths($blog_id, $wp_content_dir);
        $paths[] = $wp_content_dir . '/themes';
        $paths[] = $wp_content_dir . '/plugins';

        return array_values(array_unique($paths));
    }

    /**
     * Paths to exclude from archive (network_filtered subsite scope).
     *
     * @return string[]
     */
    public function get_exclude_paths(): array
    {
        if ($this->exclude_paths_cache !== null) {
            return $this->exclude_paths_cache;
        }

        $wp_content = wp_normalize_path(WP_CONTENT_DIR);
        $excludes = array(
            // Leftover installer safety snapshots under web root (not size-gated).
            // Must match Rmmigrate_Install_Cleanup::STATE_DIR.
            untrailingslashit(wp_normalize_path(ABSPATH)) . '/.multisite-migrate-installer',
            $wp_content . '/duplicator-backups',
            $wp_content . '/cache',
            $wp_content . '/debug.log',
        );

        if (Rmmigrate_Engine_Config::storage_filter_self()) {
            foreach (Rmmigrate_Engine_Config::self_storage_exclude_paths() as $root) {
                $excludes[] = untrailingslashit(wp_normalize_path($root));
            }
        }

        $settings = Rmmigrate_Settings::get();
        $exclude_paths = $settings['exclude_paths'] ?? array();
        if (!is_array($exclude_paths)) {
            $exclude_paths = array();
        }
        foreach ($exclude_paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            $path = trim($path);
            if ($path !== '') {
                if ($path[0] !== '/') {
                    $path = $wp_content . '/' . ltrim($path, '/');
                }
                $excludes[] = wp_normalize_path($path);
            }
        }

        if ($this->scope === self::SCOPE_NETWORK_FILTERED || $this->scope === self::SCOPE_NETWORK_INCLUDED) {
            foreach ($this->get_dirs_to_filter() as $dir) {
                $excludes[] = wp_normalize_path($dir);
            }
        }

        return $this->exclude_paths_cache = array_values(array_unique($excludes));
    }

    /**
     * @return string[]
     */
    private function get_dirs_to_filter(): array
    {
        $path_arr = array();
        if (empty($this->excluded_blog_ids)) {
            return $path_arr;
        }

        $wp_content_dir = wp_normalize_path(WP_CONTENT_DIR);
        foreach ($this->excluded_blog_ids as $site_id) {
            $site_id = (int) $site_id;
            if ($site_id === 1) {
                $uploads_dir = Rmmigrate_Engine_Config::uploads_basedir_for_blog($site_id);
                if (Rmmigrate_Filesystem::is_dir($uploads_dir)) {
                    $nodes = scandir($uploads_dir);
                    if ($nodes !== false) {
                        foreach ($nodes as $node) {
                            if ($node === '.' || $node === '..' || $node === '.htaccess') {
                                continue;
                            }
                            $fullpath = $uploads_dir . '/' . $node;
                            if (Rmmigrate_Filesystem::is_dir($fullpath) && $node !== 'sites') {
                                $path_arr[] = $fullpath;
                            }
                        }
                    }
                }
            } else {
                $sites_path = Rmmigrate_Engine_Config::uploads_basedir_for_blog($site_id);
                if (Rmmigrate_Filesystem::is_dir($sites_path)) {
                    $path_arr[] = $sites_path;
                }
                if (Rmmigrate_Filesystem::is_dir($wp_content_dir . '/blogs.dir/' . $site_id)) {
                    $path_arr[] = $wp_content_dir . '/blogs.dir/' . $site_id;
                }
            }
        }
        return $path_arr;
    }

    public function is_path_excluded(string $absolute_path): bool
    {
        $path = wp_normalize_path($absolute_path);
        if (Rmmigrate_Engine_Config::storage_filter_self()
            && Rmmigrate_Engine_Config::path_looks_like_archive_storage($path)
        ) {
            return true;
        }
        foreach ($this->get_exclude_paths() as $exclude) {
            $exclude = untrailingslashit($exclude);
            if ($path === $exclude || strpos($path, $exclude . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tables prefixed with {prefix}{blog_id}_ where blog_id is not an active site.
     *
     * @param string[] $all_tables
     * @return string[]
     */
    public static function get_orphan_subsite_tables(array $all_tables, ?array $active_site_ids = null): array
    {
        if ($active_site_ids === null && !is_multisite()) {
            return array();
        }

        global $wpdb;
        $active = $active_site_ids ?? self::get_all_site_ids();
        $active_map = array_fill_keys($active, true);
        $base_prefix = $wpdb->base_prefix;
        $orphans = array();

        foreach ($all_tables as $table) {
            if (!is_string($table) || strpos($table, $base_prefix) !== 0) {
                continue;
            }
            $suffix = substr($table, strlen($base_prefix));
            if (!preg_match('/^(\d+)_/', $suffix, $m)) {
                continue;
            }
            $blog_id = (int) $m[1];
            if ($blog_id > 0 && !isset($active_map[$blog_id])) {
                $orphans[] = $table;
            }
        }

        return $orphans;
    }

    public static function host_slug(?string $url = null): string
    {
        $host = wp_parse_url($url ?? network_home_url(), PHP_URL_HOST);
        $slug = (string) preg_replace('/[^a-z0-9]+/i', '-', (string) $host);

        return trim(strtolower($slug), '-') ?: 'site';
    }

    public static function blog_slug(int $blog_id): string
    {
        if ($blog_id <= 0) {
            return 'site';
        }

        $network_host = strtolower((string) wp_parse_url(network_home_url(), PHP_URL_HOST));
        $details = get_blog_details($blog_id, false);
        if (is_object($details)) {
            $site_domain = strtolower((string) ($details->domain ?? ''));
            if ($site_domain !== '' && $network_host !== '' && $site_domain !== $network_host) {
                return self::host_slug('https://' . $site_domain . '/');
            }

            $path = trim((string) ($details->path ?? ''), '/');
            if ($path !== '') {
                $slug = preg_replace('/[^a-z0-9]+/i', '-', str_replace('/', '-', $path));
                $slug = trim(strtolower((string) $slug), '-');
                if ($slug !== '') {
                    return $slug;
                }
            }

            if (function_exists('get_home_url')) {
                $site_host = strtolower((string) wp_parse_url(get_home_url($blog_id), PHP_URL_HOST));
                if ($site_host !== '' && $network_host !== '' && $site_host !== $network_host) {
                    return self::host_slug('https://' . $site_host . '/');
                }
            }
        }

        return 'blog-' . $blog_id;
    }

    public static function storage_path_slug(int $blog_id): string
    {
        if (!is_multisite()) {
            return self::host_slug(home_url());
        }

        if (self::blog_is_network_root_site($blog_id)) {
            return self::host_slug();
        }

        return self::host_slug() . '-' . self::blog_slug($blog_id);
    }

    /**
     * True when the blog is the network's root site (its domain equals the network
     * host and it lives at the root path). For these, the archive name should use
     * the network domain alone rather than appending a redundant "blog-N" segment.
     */
    private static function blog_is_network_root_site(int $blog_id): bool
    {
        if ($blog_id <= 0) {
            return false;
        }

        $details = get_blog_details($blog_id, false);
        if (!is_object($details)) {
            return false;
        }

        $network_host = strtolower((string) wp_parse_url(network_home_url(), PHP_URL_HOST));
        $site_domain = strtolower((string) ($details->domain ?? ''));
        $path = trim((string) ($details->path ?? ''), '/');

        return $network_host !== '' && $site_domain === $network_host && $path === '';
    }

    public static function archive_name_slug(Rmmigrate_Job $job): string
    {
        if (!is_multisite()) {
            return self::host_slug(home_url());
        }

        $network = self::host_slug();
        $scope = $job->get_scope();

        if ($scope === self::SCOPE_NETWORK) {
            return $network . '-network';
        }

        if ($scope === self::SCOPE_SUBSITE) {
            if (self::blog_is_network_root_site($job->get_blog_id())) {
                return $network;
            }
            return $network . '-' . self::blog_slug($job->get_blog_id());
        }

        if ($scope === self::SCOPE_NETWORK_INCLUDED) {
            $included = $job->get_included_blogs();
            if (count($included) === 1) {
                if (self::blog_is_network_root_site((int) $included[0])) {
                    return $network;
                }
                return $network . '-' . self::blog_slug((int) $included[0]);
            }

            if ($included !== array()) {
                $parts = array();
                foreach ($included as $blog_id) {
                    $parts[] = self::blog_slug((int) $blog_id);
                }
                $joined = implode('-', $parts);
                if (strlen($joined) <= 72) {
                    return $network . '-' . $joined;
                }

                return $network . '-selected-' . count($included) . 'sites';
            }

            return $network . '-selected';
        }

        if ($scope === self::SCOPE_NETWORK_FILTERED) {
            $excluded = $job->get_excluded_blogs();
            if (count($excluded) === 1) {
                return $network . '-network-ex-' . self::blog_slug((int) $excluded[0]);
            }

            return $network . '-network-filtered';
        }

        return $network . '-' . str_replace('_', '-', $scope);
    }

    public static function build_archive_file_name(Rmmigrate_Job $job, ?string $extension = null): string
    {
        $ext = $extension;
        if ($ext === null || $ext === '') {
            $ext = Rmmigrate_Hosting_Detection::effective_archive_mode_for_job($job) === 'daf' ? 'daf' : 'zip';
        }

        $slug = str_replace('-', '_', self::archive_name_slug($job));
        $hash = substr(bin2hex(random_bytes(3)), 0, 6);
        return gmdate('Ymd_His') . '_' . $hash . '_' . $slug . '_archive.' . ltrim($ext, '.');
    }
}
