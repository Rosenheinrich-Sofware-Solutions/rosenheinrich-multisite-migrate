<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_File_Restorer
{
    private const MISSING_ABORT_THRESHOLD = 50;
    private const QUEUE_FILE = 'restore-queue.json';

    /** @var Rmmigrate_Job */
    private $job;

    public function __construct(Rmmigrate_Job $job)
    {
        $this->job = $job;
    }

    public function run_slice(int $budget_sec): bool
    {
        $extract_dir = trailingslashit($this->job->get_work_dir()) . 'extracted/';
        $files_root = $extract_dir . 'files/';

        if (!Rmmigrate_Filesystem::is_dir($files_root)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::FILES_TREE_MISSING),
                esc_html__('No files/ directory in backup.', 'rosenheinrich-multisite-migrate')
            );
        }

        $progress = $this->job->get_progress();
        $restore = $progress['file_restore'] ?? array();

        $queue = $this->load_queue($restore, $files_root);
        $index = (int) ($restore['index'] ?? 0);
        $missing_count = (int) ($restore['missing_count'] ?? 0);
        $total = count($queue);

        $search_replace = null;
        if ($this->job->get_restore_type() === Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            $search_replace = new Rmmigrate_Search_Replace($this->job->get_migration_map());
        }

        $start = microtime(true);
        while ($index < $total && (microtime(true) - $start) < $budget_sec) {
            $item = $queue[$index];
            $missing_count += $this->copy_item($item, $search_replace, $missing_count);
            $index++;
        }

        $patch = array(
            'index'         => $index,
            'missing_count' => $missing_count,
            'queue_ready'   => true,
        );
        if ($index >= $total) {
            $patch['done'] = true;
        }
        $this->job->update_progress(array('file_restore' => $patch));

        if ($index >= $total && $missing_count > 0) {
            Rmmigrate_Logger::log(
                sprintf(
                    /* translators: %d: number of missing files in backup archive. */
                    __('File restore finished with %d missing file(s) in backup.', 'rosenheinrich-multisite-migrate'),
                    $missing_count
                )
            );
        }

        return $index >= $total;
    }

    /**
     * @param array<string,mixed> $restore
     * @return array<int,array{src:string,dest:string,replace:bool}>
     */
    private function load_queue(array $restore, string $files_root): array
    {
        $path = trailingslashit($this->job->get_work_dir()) . self::QUEUE_FILE;
        if (Rmmigrate_Filesystem::exists($path)) {
            $contents = Rmmigrate_Filesystem::get_contents($path);
            if ($contents !== false) {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        if (!empty($restore['queue']) && is_array($restore['queue'])) {
            $queue = $restore['queue'];
            $this->save_queue($queue);
            return $queue;
        }

        $queue = $this->build_queue($files_root);
        $this->save_queue($queue);
        $this->job->update_progress(array(
            'file_restore' => array(
                'index'         => 0,
                'missing_count' => 0,
                'queue_ready'   => true,
            ),
        ));

        return $queue;
    }

    /**
     * @param array<int,array{src:string,dest:string,replace:bool}> $queue
     */
    private function save_queue(array $queue): void
    {
        Rmmigrate_Filesystem::put_contents(
            trailingslashit($this->job->get_work_dir()) . self::QUEUE_FILE,
            wp_json_encode($queue)
        );
    }

    /**
     * @return array<int,array{src:string,dest:string,replace:bool}>
     */
    private function build_queue(string $files_root): array
    {
        $queue = array();
        $root = $this->job->get_restore_root();

        $wp_config = $files_root . 'wp-config.php';
        $skip_wp_config = $this->should_skip_wp_config_restore();
        if (!$skip_wp_config && Rmmigrate_Filesystem::exists($wp_config)) {
            $queue[] = array('src' => $wp_config, 'dest' => $root . 'wp-config.php', 'replace' => false);
        }

        $htaccess = $files_root . '.htaccess';
        if (Rmmigrate_Filesystem::exists($htaccess)) {
            $queue[] = array('src' => $htaccess, 'dest' => $root . '.htaccess', 'replace' => false);
        }

        if (Rmmigrate_Archive_Index::has_core_files($files_root)) {
            foreach (array('wp-admin', 'wp-includes') as $core_dir) {
                $src_dir = $files_root . $core_dir;
                if (!Rmmigrate_Filesystem::is_dir($src_dir)) {
                    continue;
                }
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($src_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $file) {
                    /** @var SplFileInfo $file */
                    if (!$file->isFile()) {
                        continue;
                    }
                    $src = $file->getPathname();
                    $rel = substr($src, strlen($files_root));
                    $queue[] = array('src' => $src, 'dest' => $root . $rel, 'replace' => false);
                }
            }
            $glob = glob($files_root . '*.php') ?: array();
            foreach ($glob as $src) {
                $base = basename($src);
                if ($base === 'wp-config.php' || !Rmmigrate_Filesystem::is_file($src)) {
                    continue;
                }
                $queue[] = array('src' => $src, 'dest' => $root . $base, 'replace' => false);
            }
        }

        $content_root = $files_root . 'wp-content/';
        if (Rmmigrate_Filesystem::is_dir($content_root)) {
            $uploads_only = $this->should_limit_to_subsite_uploads();
            $allowed_roots = $uploads_only ? $this->subsite_upload_dest_roots() : array();
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($content_root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                $src = $file->getPathname();
                $rel = substr($src, strlen($content_root));
                $dest = trailingslashit(WP_CONTENT_DIR) . $rel;
                if ($uploads_only && !$this->dest_under_allowed_roots($dest, $allowed_roots)) {
                    continue;
                }
                $replace = $this->should_replace_in_file($src);
                $queue[] = array('src' => $src, 'dest' => $dest, 'replace' => $replace);
            }
        }

        return $queue;
    }

    /**
     * Same-server SCOPE_SUBSITE must not overwrite network themes/plugins.
     */
    private function should_limit_to_subsite_uploads(): bool
    {
        $scope = (string) ($this->job->data['scope'] ?? '');
        if ($scope !== Rmmigrate_Multisite_Scope::SCOPE_SUBSITE) {
            return false;
        }
        $restore_type = (string) ($this->job->data['restore_type'] ?? Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER);
        if ($restore_type !== Rmmigrate_Job::RESTORE_TYPE_SAME_SERVER) {
            return false;
        }

        return is_multisite();
    }

    /**
     * @return string[] Absolute upload roots for this blog.
     */
    private function subsite_upload_dest_roots(): array
    {
        $blog_id = (int) ($this->job->data['blog_id'] ?? 0);
        if ($blog_id <= 0) {
            $blog_id = (int) get_current_blog_id();
        }
        $wp_content = wp_normalize_path(WP_CONTENT_DIR);
        $paths = Rmmigrate_Mu_Legacy::subsite_upload_paths($blog_id, $wp_content);
        $roots = array();
        foreach ($paths as $path) {
            $roots[] = trailingslashit(wp_normalize_path($path));
        }

        return $roots;
    }

    /**
     * @param string[] $allowed_roots
     */
    private function dest_under_allowed_roots(string $dest, array $allowed_roots): bool
    {
        if ($allowed_roots === array()) {
            return false;
        }
        $dest_norm = wp_normalize_path($dest);
        foreach ($allowed_roots as $root) {
            $root = rtrim(wp_normalize_path($root), '/\\');
            if ($dest_norm === $root || strpos($dest_norm, $root . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    private function should_replace_in_file(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, array('php', 'css', 'js', 'json', 'html', 'htm'), true);
    }

    /**
     * @param array{src:string,dest:string,replace:bool} $item
     * @return int 1 when file missing, else 0
     */
    private function copy_item(array $item, ?Rmmigrate_Search_Replace $search_replace, int $missing_so_far): int
    {
        $src = $item['src'];
        if (!Rmmigrate_Filesystem::exists($src)) {
            $missing = $missing_so_far + 1;
            if ($missing >= self::MISSING_ABORT_THRESHOLD) {
                $count = (int) $missing;
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                throw Rmmigrate_Job_Exception::raise(
                    sanitize_key(Rmmigrate_Error_Codes::FILE_RESTORE_FAILED),
                    esc_html(
                        sprintf(
                            /* translators: %d: number of missing files before abort. */
                            __('Too many missing files in backup (%d). Restore aborted.', 'rosenheinrich-multisite-migrate'),
                            $count
                        )
                    )
                );
            }
            return 1;
        }

        $dest = $item['dest'];
        $dest_dir = dirname($dest);
        if (!Rmmigrate_Filesystem::is_dir($dest_dir)) {
            wp_mkdir_p($dest_dir);
        }

        if (Rmmigrate_Filesystem::is_dir($src)) {
            if (!Rmmigrate_Filesystem::is_dir($dest)) {
                wp_mkdir_p($dest);
            }
            return 0;
        }

        if ($search_replace !== null && !empty($item['replace'])) {
            $contents = Rmmigrate_Filesystem::get_contents($src);
            if ($contents !== false) {
                Rmmigrate_Filesystem::put_contents($dest, $search_replace->apply($contents));
                return 0;
            }
        }

        if (!Rmmigrate_Filesystem::copy($src, $dest)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(
                sanitize_key(Rmmigrate_Error_Codes::FILE_RESTORE_FAILED),
                esc_html(
                    sprintf(
                        /* translators: %s: file name */
                        __('Failed to restore file: %1$s', 'rosenheinrich-multisite-migrate'),
                        basename($src)
                    )
                )
            );
        }

        return 0;
    }

    private function should_skip_wp_config_restore(): bool
    {
        if ($this->job->get_restore_type() !== Rmmigrate_Job::RESTORE_TYPE_MIGRATION) {
            return false;
        }

        return is_file(ABSPATH . 'wp-config.php');
    }
}
