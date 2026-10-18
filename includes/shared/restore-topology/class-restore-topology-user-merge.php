<?php

require_once dirname(__DIR__) . '/safe-logger.php';

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Shared restore topology: PDO required for installer + plugin migration DDL; no $wpdb in standalone installer.

/**
 * Shared users merge for network-landing topologies.
 *
 * Spec (contract):
 * - Eligible restore modes: subsite_on_network, network_to_subsite.
 * - Opt-in via context/progress flag `user_merge` (default ON when mode is eligible).
 * - Full-site overwrite paths (standalone / full network) never run merge.
 * - Protect: rename destination `{base}users` / `{base}usermeta` to staging names
 *   before DB overwrite/import so archive DROP+CREATE cannot wipe network users.
 * - After import + topology finalize: swap staging back, merge source rows into
 *   destination users by `user_login` OR non-empty `user_email`.
 * - Matched: map old→existing ID; keep destination password; ensure blog caps.
 * - Created: INSERT with source password hash; copy usermeta; map old→new ID.
 * - Remap: `posts.post_author` on the operating blog prefix + configurable UID
 *   postmeta fields (default includes WooCommerce `_customer_user`).
 * - Optional cohort password reset (and email when WP mail APIs are available).
 */
class Rmmigrate_Restore_Topology_User_Merge
{
    public const STAGING_DEST_USERS = 'mm_merge_dest_users';
    public const STAGING_DEST_USERMETA = 'mm_merge_dest_usermeta';
    public const STAGING_SRC_USERS = 'mm_merge_src_users';
    public const STAGING_SRC_USERMETA = 'mm_merge_src_usermeta';

    /**
     * @return string[]
     */
    public static function eligible_modes(): array
    {
        return array('subsite_on_network', 'network_to_subsite');
    }

    /**
     * @return string[]
     */
    public static function default_uid_fields(): array
    {
        return array('_customer_user');
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function should_run(array $context): bool
    {
        $mode = (string) ($context['restore_mode'] ?? '');
        if (!in_array($mode, self::eligible_modes(), true)) {
            return false;
        }
        if (array_key_exists('user_merge', $context)) {
            return !empty($context['user_merge']);
        }

        // Default ON for eligible network-landing modes.
        return true;
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    public static function parse_uid_fields($raw): array
    {
        if (is_string($raw)) {
            $parts = array_filter(array_map('trim', explode(',', $raw)));
        } elseif (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = array();
        }
        $fields = array();
        foreach ($parts as $field) {
            $field = trim((string) $field);
            // Do not use sanitize_key — it strips leading underscores from meta keys.
            if ($field === '' || !preg_match('/^[A-Za-z0-9_]+$/', $field)) {
                continue;
            }
            $fields[] = $field;
        }
        $fields = array_values(array_unique(array_merge(self::default_uid_fields(), $fields)));

        return $fields;
    }

    /**
     * Resolve network-global users table prefix from an operating prefix.
     */
    public static function resolve_global_prefix(PDO $pdo, string $operating_prefix): string
    {
        $base = self::base_prefix($operating_prefix);
        if (self::table_exists($pdo, $base . 'users')) {
            return $base;
        }
        if (self::table_exists($pdo, $operating_prefix . 'users')) {
            return $operating_prefix;
        }

        return $base !== '' ? $base : $operating_prefix;
    }

    /**
     * Rename live destination users tables out of the way before overwrite/import.
     *
     * @return array<string,mixed>
     */
    public static function protect_destination(PDO $pdo, string $global_prefix): array
    {
        $report = array(
            'protected' => false,
            'users'     => '',
            'usermeta'  => '',
        );
        $users = $global_prefix . 'users';
        $usermeta = $global_prefix . 'usermeta';
        $dest_users = $global_prefix . self::STAGING_DEST_USERS;
        $dest_meta = $global_prefix . self::STAGING_DEST_USERMETA;

        if (!self::table_exists($pdo, $users)) {
            return $report;
        }
        if (self::table_exists($pdo, $dest_users)) {
            // Already protected (resume / double call).
            $report['protected'] = true;
            $report['users'] = $dest_users;
            $report['usermeta'] = $dest_meta;

            return $report;
        }

        self::rename_table($pdo, $users, $dest_users);
        if (self::table_exists($pdo, $usermeta)) {
            if (self::table_exists($pdo, $dest_meta)) {
                self::drop_table($pdo, $dest_meta);
            }
            self::rename_table($pdo, $usermeta, $dest_meta);
        }

        $report['protected'] = true;
        $report['users'] = $dest_users;
        $report['usermeta'] = $dest_meta;

        return $report;
    }

    /**
     * Restore protected destination user tables after a failed finalize (rollback).
     *
     * @param array<string,mixed> $report
     */
    public static function restore_protected_users(PDO $pdo, array $report, string $global_prefix): void
    {
        if (empty($report['protected'])) {
            return;
        }
        $staging_users = (string) ($report['users'] ?? '');
        $staging_meta = (string) ($report['usermeta'] ?? '');
        $live_users = $global_prefix . 'users';
        $live_meta = $global_prefix . 'usermeta';
        if ($staging_users !== '' && self::table_exists($pdo, $staging_users)) {
            if (self::table_exists($pdo, $live_users)) {
                self::drop_table($pdo, $live_users);
            }
            self::rename_table($pdo, $staging_users, $live_users);
        }
        if ($staging_meta !== '' && self::table_exists($pdo, $staging_meta)) {
            if (self::table_exists($pdo, $live_meta)) {
                self::drop_table($pdo, $live_meta);
            }
            self::rename_table($pdo, $staging_meta, $live_meta);
        }
    }

    /**
     * Merge source users (currently in live tables) into protected destination users.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function run(PDO $pdo, array $manifest, array $context): array
    {
        $report = array(
            'ran'              => false,
            'matched'          => 0,
            'created'          => 0,
            'skipped'          => 0,
            'authors_remapped' => 0,
            'uid_meta_remapped'=> 0,
            'passwords_reset'  => 0,
            'map_size'         => 0,
            'warning'          => '',
        );

        $operating = (string) ($context['db_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
        $global = self::resolve_global_prefix($pdo, $operating);
        $dest_users = $global . self::STAGING_DEST_USERS;
        $dest_meta = $global . self::STAGING_DEST_USERMETA;
        $live_users = $global . 'users';
        $live_meta = $global . 'usermeta';

        if (!self::should_run($context)) {
            if (self::table_exists($pdo, $dest_users)) {
                $src_users = $global . self::STAGING_SRC_USERS;
                $src_meta = $global . self::STAGING_SRC_USERMETA;
                if (self::table_exists($pdo, $src_users)) {
                    self::drop_table($pdo, $src_users);
                }
                if (self::table_exists($pdo, $src_meta)) {
                    self::drop_table($pdo, $src_meta);
                }
                if (self::table_exists($pdo, $live_users)) {
                    self::rename_table($pdo, $live_users, $src_users);
                }
                if (self::table_exists($pdo, $live_meta)) {
                    self::rename_table($pdo, $live_meta, $src_meta);
                }
                self::rename_table($pdo, $dest_users, $live_users);
                if (self::table_exists($pdo, $dest_meta)) {
                    self::rename_table($pdo, $dest_meta, $live_meta);
                }
                $report['quarantined'] = true;
                $report['warning'] = 'user_merge_disabled_quarantined';
                $report['caps_meta_remapped'] = self::remap_caps_meta_without_user_merge($pdo, $manifest, $context);
            }

            return $report;
        }

        if (!self::table_exists($pdo, $dest_users)) {
            // Nothing protected → nothing to merge (fresh install or merge disabled mid-flight).
            $report['warning'] = 'no_destination_staging';

            return $report;
        }
        if (!self::table_exists($pdo, $live_users)) {
            // Archive had no users; restore destination tables.
            self::rename_table($pdo, $dest_users, $live_users);
            if (self::table_exists($pdo, $dest_meta) && !self::table_exists($pdo, $live_meta)) {
                self::rename_table($pdo, $dest_meta, $live_meta);
            }
            $report['ran'] = true;
            $report['warning'] = 'no_source_users';

            return $report;
        }

        $src_users = $global . self::STAGING_SRC_USERS;
        $src_meta = $global . self::STAGING_SRC_USERMETA;
        if (self::table_exists($pdo, $src_users)) {
            self::drop_table($pdo, $src_users);
        }
        if (self::table_exists($pdo, $src_meta)) {
            self::drop_table($pdo, $src_meta);
        }

        self::rename_table($pdo, $live_users, $src_users);
        if (self::table_exists($pdo, $live_meta)) {
            self::rename_table($pdo, $live_meta, $src_meta);
        }
        self::rename_table($pdo, $dest_users, $live_users);
        if (self::table_exists($pdo, $dest_meta)) {
            self::rename_table($pdo, $dest_meta, $live_meta);
        } elseif (!self::table_exists($pdo, $live_meta) && self::table_exists($pdo, $src_meta)) {
            // Destination had users but no usermeta — create empty shell by cloning structure when possible.
            self::ensure_usermeta_table($pdo, $live_meta, $src_meta);
        }

        $map = array();
        $created_ids = array();
        $src_rows = $pdo->query('SELECT * FROM `' . self::qi($src_users) . '`');
        $rows = $src_rows ? $src_rows->fetchAll(PDO::FETCH_ASSOC) : array();
        foreach ($rows as $row) {
            $old_id = (int) ($row['ID'] ?? 0);
            if ($old_id <= 0) {
                $report['skipped']++;
                continue;
            }
            $login = (string) ($row['user_login'] ?? '');
            $email = (string) ($row['user_email'] ?? '');
            $existing = self::find_existing_user_id($pdo, $live_users, $login, $email);
            if ($existing > 0) {
                $map[$old_id] = $existing;
                $report['matched']++;
                self::ensure_blog_caps_from_source(
                    $pdo,
                    $global,
                    $src_meta,
                    $old_id,
                    $existing,
                    $manifest,
                    $context
                );
                continue;
            }
            $new_id = self::insert_user_from_source($pdo, $live_users, $row);
            if ($new_id <= 0) {
                $report['skipped']++;
                continue;
            }
            $map[$old_id] = $new_id;
            $report['created']++;
            $created_ids[] = $new_id;
            self::copy_usermeta($pdo, $src_meta, $live_meta, $old_id, $new_id, $global);
            self::ensure_blog_caps_from_source(
                $pdo,
                $global,
                $src_meta,
                $old_id,
                $new_id,
                $manifest,
                $context
            );
        }

        $uid_fields = self::parse_uid_fields($context['uid_fields'] ?? array());
        $blog_prefixes = self::resolve_blog_posts_prefixes($pdo, $operating, $manifest, $context);
        foreach ($blog_prefixes as $blog_prefix) {
            $report['authors_remapped'] += self::remap_post_authors($pdo, $blog_prefix, $map);
            $report['uid_meta_remapped'] += self::remap_uid_meta($pdo, $blog_prefix, $map, $uid_fields);
        }

        if (!empty($context['user_merge_reset_passwords']) && $created_ids !== array()) {
            $report['passwords_reset'] = self::reset_cohort_passwords(
                $pdo,
                $live_users,
                array_values(array_unique(array_map('intval', $created_ids))),
                !empty($context['user_merge_send_reset_email'])
            );
        }

        self::drop_table($pdo, $src_users);
        if (self::table_exists($pdo, $src_meta)) {
            self::drop_table($pdo, $src_meta);
        }

        $report['ran'] = true;
        $report['map_size'] = count($map);

        return $report;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return string[]
     */
    private static function resolve_blog_posts_prefixes(PDO $pdo, string $operating, array $manifest, array $context): array
    {
        $candidates = array();
        $mode = (string) ($context['restore_mode'] ?? '');
        $source_prefix = (string) ($manifest['db_prefix'] ?? $operating);
        $candidates[] = $operating;
        $candidates[] = $source_prefix;

        if ($mode === 'network_to_subsite') {
            $target_blog_id = (int) ($context['target_blog_id'] ?? 0);
            $base = self::base_prefix($operating);
            if ($target_blog_id > 1) {
                $candidates[] = $base . $target_blog_id . '_';
            }
        }
        if ($mode === 'subsite_on_network') {
            $target_blog_id = self::resolve_target_blog_id_for_caps($pdo, $context, $manifest);
            $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
            $base = self::destination_network_base_prefix($destination, $manifest);
            if ($base === '') {
                $base = Rmmigrate_Restore_Topology_Manifest::source_base_prefix($manifest);
            }
            if ($target_blog_id > 1) {
                $candidates[] = $base . $target_blog_id . '_';
            } elseif ($target_blog_id === 1) {
                $candidates[] = $base;
            }
        }

        $prefixes = array();
        foreach (array_values(array_unique($candidates)) as $prefix) {
            if ($prefix !== '' && self::table_exists($pdo, $prefix . 'posts')) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /**
     * @param array<int,int> $map old_id => new_id
     */
    public static function remap_post_authors(PDO $pdo, string $blog_prefix, array $map): int
    {
        $posts = $blog_prefix . 'posts';
        if ($map === array() || !self::table_exists($pdo, $posts)) {
            // Try source-style prefixes when operating prefix has no posts yet.
            return 0;
        }
        $updated = 0;
        $stmt = $pdo->prepare(
            'UPDATE `' . self::qi($posts) . '` SET post_author = ? WHERE post_author = ?'
        );
        foreach ($map as $old_id => $new_id) {
            $old_id = (int) $old_id;
            $new_id = (int) $new_id;
            if ($old_id <= 0 || $new_id <= 0 || $old_id === $new_id) {
                continue;
            }
            $stmt->execute(array($new_id, $old_id));
            $updated += (int) $stmt->rowCount();
        }

        return $updated;
    }

    /**
     * @param array<int,int> $map
     * @param string[]       $uid_fields
     */
    public static function remap_uid_meta(PDO $pdo, string $blog_prefix, array $map, array $uid_fields): int
    {
        $postmeta = $blog_prefix . 'postmeta';
        if ($map === array() || $uid_fields === array() || !self::table_exists($pdo, $postmeta)) {
            return 0;
        }
        $updated = 0;
        $stmt = $pdo->prepare(
            'UPDATE `' . self::qi($postmeta) . '` SET meta_value = ? WHERE meta_key = ? AND meta_value = ?'
        );
        foreach ($uid_fields as $field) {
            foreach ($map as $old_id => $new_id) {
                $old_id = (int) $old_id;
                $new_id = (int) $new_id;
                if ($old_id <= 0 || $new_id <= 0 || $old_id === $new_id) {
                    continue;
                }
                $stmt->execute(array((string) $new_id, $field, (string) $old_id));
                $updated += (int) $stmt->rowCount();
            }
        }

        return $updated;
    }

    /**
     * @param int[] $user_ids
     */
    public static function reset_cohort_passwords(
        PDO $pdo,
        string $users_table,
        array $user_ids,
        bool $send_email
    ): int {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if ($user_ids === array() || !self::table_exists($pdo, $users_table)) {
            return 0;
        }

        $table_prefix = preg_replace('/users$/', '', $users_table);
        $base_prefix = self::base_prefix($table_prefix);
        $super_admin_logins = array();
        $sitemeta = $base_prefix . 'sitemeta';
        if (self::table_exists($pdo, $sitemeta)) {
            $meta_stmt = $pdo->prepare('SELECT meta_value FROM `' . self::qi($sitemeta) . '` WHERE meta_key = ?');
            $meta_stmt->execute(array('site_admins'));
            $val = $meta_stmt->fetchColumn();
            if (is_string($val) && $val !== '') {
                $unserialized = @unserialize($val, array('allowed_classes' => false));
                if (is_array($unserialized)) {
                    foreach ($unserialized as $admin_login) {
                        if (is_string($admin_login) && $admin_login !== '') {
                            $super_admin_logins[] = strtolower(trim($admin_login));
                        }
                    }
                }
            }
        }

        $dest_staging = $base_prefix . self::STAGING_DEST_USERS;
        $dest_user_ids = array();
        if (self::table_exists($pdo, $dest_staging)) {
            $dest_rows = $pdo->query('SELECT ID FROM `' . self::qi($dest_staging) . '`');
            if ($dest_rows) {
                $dest_user_ids = array_map('intval', $dest_rows->fetchAll(PDO::FETCH_COLUMN));
            }
        }

        $updated = 0;
        $login_stmt = $pdo->prepare('SELECT user_login FROM `' . self::qi($users_table) . '` WHERE ID = ? LIMIT 1');
        $stmt = $pdo->prepare(
            'UPDATE `' . self::qi($users_table) . '` SET user_pass = ? WHERE ID = ?'
        );
        foreach ($user_ids as $user_id) {
            if (in_array($user_id, $dest_user_ids, true)) {
                continue;
            }
            $login_stmt->execute(array($user_id));
            $login = $login_stmt->fetchColumn();
            if (is_string($login) && in_array(strtolower(trim($login)), $super_admin_logins, true)) {
                continue;
            }
            $password = self::generate_password(20);
            $hash = self::hash_password($password);
            $stmt->execute(array($hash, $user_id));
            if ($stmt->rowCount() > 0) {
                $updated++;
            }
            if ($send_email) {
                self::maybe_send_reset_email($pdo, $users_table, $user_id);
            }
        }

        return $updated;
    }

    private static function maybe_send_reset_email(PDO $pdo, string $users_table, int $user_id): void
    {
        if (!function_exists('get_password_reset_key') || !function_exists('wp_mail')) {
            return;
        }
        $stmt = $pdo->prepare(
            'SELECT user_login, user_email FROM `' . self::qi($users_table) . '` WHERE ID = ? LIMIT 1'
        );
        $stmt->execute(array($user_id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }
        $login = (string) ($row['user_login'] ?? '');
        $email = (string) ($row['user_email'] ?? '');
        if ($login === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $user = function_exists('get_user_by') ? get_user_by('id', $user_id) : false;
        if (!$user) {
            return;
        }
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return;
        }
        $message = sprintf(
            "A password reset has been requested for the account %s.\r\n\r\n",
            $login
        );
        $message .= "To reset your password, visit:\r\n\r\n";
        $message .= network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($login), 'login') . "\r\n";
        wp_mail($email, '[Password Reset]', $message);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function insert_user_from_source(PDO $pdo, string $users_table, array $row): int
    {
        $cols = array(
            'user_login',
            'user_pass',
            'user_nicename',
            'user_email',
            'user_url',
            'user_registered',
            'user_activation_key',
            'user_status',
            'display_name',
        );
        $values = array();
        foreach ($cols as $col) {
            $values[] = $row[$col] ?? ($col === 'user_status' ? 0 : '');
        }
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO `' . self::qi($users_table) . '` (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);

            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            rmmigrate_shared_log_best_effort_failure($e);
            unset($e);

            return 0;
        }
    }

    private static function copy_usermeta(
        PDO $pdo,
        string $src_meta,
        string $dest_meta,
        int $old_id,
        int $new_id,
        string $network_base
    ): void {
        if (!self::table_exists($pdo, $src_meta) || !self::table_exists($pdo, $dest_meta)) {
            return;
        }
        $stmt = $pdo->prepare(
            'SELECT meta_key, meta_value FROM `' . self::qi($src_meta) . '` WHERE user_id = ?'
        );
        $stmt->execute(array($old_id));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ins = $pdo->prepare(
            'INSERT INTO `' . self::qi($dest_meta) . '` (user_id, meta_key, meta_value) VALUES (?, ?, ?)'
        );
        foreach ($rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');
            if ($key === '' || self::should_skip_usermeta_copy($key, $network_base)) {
                continue;
            }
            try {
                $ins->execute(array($new_id, $key, (string) ($row['meta_value'] ?? '')));
            } catch (PDOException $e) {
                rmmigrate_shared_log_best_effort_failure($e);
                unset($e);
            }
        }
    }

    private static function should_skip_usermeta_copy(string $meta_key, string $network_base): bool
    {
        if ($meta_key === 'primary_blog' || $meta_key === 'source_domain') {
            return true;
        }
        $pattern = '/^' . preg_quote($network_base, '/') . '(?:\d+_)?(capabilities|user_level)$/';

        return (bool) preg_match($pattern, $meta_key);
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     */
    private static function ensure_blog_caps_from_source(
        PDO $pdo,
        string $global,
        string $src_meta,
        int $old_id,
        int $new_id,
        array $manifest,
        array $context
    ): void {
        if (!self::table_exists($pdo, $src_meta)) {
            return;
        }
        $dest_meta = $global . 'usermeta';
        if (!self::table_exists($pdo, $dest_meta)) {
            return;
        }
        $source_blog_id = (int) ($manifest['blog_id'] ?? 0);
        $target_blog_id = self::resolve_target_blog_id_for_caps($pdo, $context, $manifest);
        if ($source_blog_id <= 0 || $target_blog_id <= 0) {
            return;
        }
        $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
        $network_base = self::destination_network_base_prefix($destination, $manifest);
        $source_base = Rmmigrate_Restore_Topology_Manifest::source_base_prefix($manifest);
        foreach (array('capabilities', 'user_level') as $suffix) {
            self::copy_blog_usermeta_key(
                $pdo,
                $src_meta,
                $dest_meta,
                $old_id,
                $new_id,
                self::blog_caps_meta_key($source_base, $source_blog_id, $suffix),
                self::blog_caps_meta_key($network_base, $target_blog_id, $suffix)
            );
        }
    }

    private static function copy_blog_usermeta_key(
        PDO $pdo,
        string $src_meta,
        string $dest_meta,
        int $old_id,
        int $new_id,
        string $source_key,
        string $target_key
    ): void {
        $stmt = $pdo->prepare(
            'SELECT meta_value FROM `' . self::qi($src_meta) . '` WHERE user_id = ? AND meta_key = ? LIMIT 1'
        );
        $stmt->execute(array($old_id, $source_key));
        $value = $stmt->fetchColumn();
        if ($value === false || $value === '') {
            return;
        }
        $find = $pdo->prepare(
            'SELECT umeta_id FROM `' . self::qi($dest_meta) . '` WHERE user_id = ? AND meta_key = ? LIMIT 1'
        );
        $find->execute(array($new_id, $target_key));
        if ($find->fetchColumn()) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO `' . self::qi($dest_meta) . '` (user_id, meta_key, meta_value) VALUES (?, ?, ?)'
        );
        try {
            $ins->execute(array($new_id, $target_key, (string) $value));
        } catch (PDOException $e) {
            rmmigrate_shared_log_best_effort_failure($e);
            unset($e);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function resolve_target_blog_id_for_caps(PDO $pdo, array $context, array $manifest): int
    {
        $mode = (string) ($context['restore_mode'] ?? '');
        if ($mode === 'network_to_subsite') {
            return (int) ($context['target_blog_id'] ?? 0);
        }
        if ($mode === 'subsite_on_network') {
            $stored = (int) ($context['subsite_on_network_target_blog_id'] ?? 0);
            if ($stored > 0) {
                return $stored;
            }
            $map = is_array($context['site_owr_map'] ?? null) ? $context['site_owr_map'] : array();
            $entry = is_array($map[0] ?? null) ? $map[0] : array();
            $from_map = (int) ($entry['target_blog_id'] ?? 0);
            if ($from_map > 0) {
                return $from_map;
            }
            $target_url = trim((string) ($entry['target_url'] ?? ''));
            if ($target_url === '') {
                return 0;
            }
            $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
            $base = self::destination_network_base_prefix($destination, $manifest);

            return Rmmigrate_Restore_Topology_Db_Action::resolve_blog_id_from_site_url($pdo, $base, $target_url);
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $destination
     * @param array<string,mixed> $manifest
     */
    private static function destination_network_base_prefix(array $destination, array $manifest): string
    {
        if (!empty($destination['base_prefix'])) {
            return (string) $destination['base_prefix'];
        }
        if (!empty($destination['table_prefix'])) {
            return Rmmigrate_Restore_Topology_Standalone::base_table_prefix((string) $destination['table_prefix']);
        }

        return Rmmigrate_Restore_Topology_Manifest::source_base_prefix($manifest);
    }

    private static function blog_caps_meta_key(string $base_prefix, int $blog_id, string $suffix): string
    {
        if ($blog_id > 1) {
            return $base_prefix . $blog_id . '_' . $suffix;
        }

        return $base_prefix . $suffix;
    }

    /**
     * When user merge is off, imported usermeta may still use the source blog caps keys (wp_{src}_capabilities).
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     */
    public static function remap_caps_meta_without_user_merge(PDO $pdo, array $manifest, array $context): int
    {
        if (self::should_run($context)) {
            return 0;
        }
        if ((string) ($context['restore_mode'] ?? '') !== 'subsite_on_network') {
            return 0;
        }
        $source_blog_id = (int) ($manifest['blog_id'] ?? 0);
        if ($source_blog_id <= 0) {
            $source_blog_id = 1;
        }
        $target_blog_id = (int) ($context['subsite_on_network_target_blog_id'] ?? 0);
        if ($target_blog_id <= 0) {
            $target_blog_id = self::resolve_target_blog_id_for_caps($pdo, $context, $manifest);
        }
        if ($target_blog_id <= 0 || $source_blog_id === $target_blog_id) {
            return 0;
        }
        $destination = is_array($context['destination'] ?? null) ? $context['destination'] : array();
        $network_base = self::destination_network_base_prefix($destination, $manifest);
        $global = self::resolve_global_prefix($pdo, $network_base);
        $usermeta = $global . 'usermeta';
        if (!self::table_exists($pdo, $usermeta)) {
            return 0;
        }
        $inserted = 0;
        $qi = self::qi($usermeta);
        $stmt = $pdo->prepare(
            'INSERT INTO `' . $qi . '` (user_id, meta_key, meta_value)
            SELECT src.user_id, ?, src.meta_value
            FROM `' . $qi . '` AS src
            WHERE src.meta_key = ?
            AND NOT EXISTS (
                SELECT 1 FROM `' . $qi . '` AS tgt
                WHERE tgt.user_id = src.user_id AND tgt.meta_key = ?
            )'
        );
        foreach (array('capabilities', 'user_level') as $suffix) {
            $from_key = self::blog_caps_meta_key($network_base, $source_blog_id, $suffix);
            $to_key = self::blog_caps_meta_key($network_base, $target_blog_id, $suffix);
            if ($from_key === $to_key) {
                continue;
            }
            $stmt->execute(array($to_key, $from_key, $to_key));
            $inserted += $stmt->rowCount();
        }

        return $inserted;
    }

    /**
     * Post authors on the imported subsite tables (not the whole network user roster).
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $context
     * @return int[]
     */
    private static function imported_subsite_author_ids(PDO $pdo, array $manifest, array $context): array
    {
        $operating = (string) ($context['db_prefix'] ?? $manifest['db_prefix'] ?? 'wp_');
        $ids = array();
        foreach (self::resolve_blog_posts_prefixes($pdo, $operating, $manifest, $context) as $prefix) {
            $posts = $prefix . 'posts';
            if (!self::table_exists($pdo, $posts)) {
                continue;
            }
            $rows = $pdo->query(
                'SELECT DISTINCT post_author FROM `' . self::qi($posts) . '` WHERE post_author > 0'
            );
            if ($rows === false) {
                continue;
            }
            foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $author_id) {
                $author_id = (int) $author_id;
                if ($author_id > 0) {
                    $ids[] = $author_id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private static function find_existing_user_id(PDO $pdo, string $users_table, string $login, string $email): int
    {
        if ($login !== '') {
            $stmt = $pdo->prepare(
                'SELECT ID FROM `' . self::qi($users_table) . '` WHERE user_login = ? LIMIT 1'
            );
            $stmt->execute(array($login));
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }
        if ($email !== '') {
            $stmt = $pdo->prepare(
                'SELECT ID FROM `' . self::qi($users_table) . '` WHERE user_email = ? AND user_email != \'\' LIMIT 1'
            );
            $stmt->execute(array($email));

            return (int) $stmt->fetchColumn();
        }

        return 0;
    }

    private static function ensure_usermeta_table(PDO $pdo, string $live_meta, string $src_meta): void
    {
        try {
            if (self::is_sqlite($pdo)) {
                $pdo->exec(
                    'CREATE TABLE `' . self::qi($live_meta) . '` AS SELECT * FROM `' . self::qi($src_meta) . '` WHERE 0'
                );

                return;
            }
            $pdo->exec(
                'CREATE TABLE `' . self::qi($live_meta) . '` LIKE `' . self::qi($src_meta) . '`'
            );
        } catch (PDOException $e) {
            rmmigrate_shared_log_best_effort_failure($e);
            unset($e);
        }
    }

    private static function base_prefix(string $prefix): string
    {
        if (preg_match('/^(.+)_\d+_$/', $prefix, $matches)) {
            return $matches[1] . '_';
        }

        return $prefix;
    }

    private static function table_exists(PDO $pdo, string $unquoted): bool
    {
        return Rmmigrate_Topology_Db_Connection::table_exists($pdo, $unquoted);
    }

    private static function rename_table(PDO $pdo, string $from, string $to): void
    {
        if (self::is_sqlite($pdo)) {
            $pdo->exec(
                'ALTER TABLE `' . self::qi($from) . '` RENAME TO `' . self::qi($to) . '`'
            );

            return;
        }
        $pdo->exec(
            'RENAME TABLE `' . self::qi($from) . '` TO `' . self::qi($to) . '`'
        );
    }

    private static function drop_table(PDO $pdo, string $table): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `' . self::qi($table) . '`');
    }

    private static function qi(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }

    private static function is_sqlite(PDO $pdo): bool
    {
        try {
            return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (Throwable $e) {
            rmmigrate_shared_log_best_effort_failure($e);
            return false;
        }
    }

    private static function hash_password(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    private static function generate_password(int $length): string
    {
        if (function_exists('wp_generate_password')) {
            return wp_generate_password($length, true, true);
        }
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_';
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }
}
