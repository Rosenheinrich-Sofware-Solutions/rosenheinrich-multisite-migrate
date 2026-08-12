<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Zip_Archiver
{
    private const QUEUE_FILE = 'zip-queue.json';

    /** @var Rmmigrate_Job */
    private $job;

    /** @var Rmmigrate_Multisite_Scope */
    private $scope;

    public function __construct(Rmmigrate_Job $job, Rmmigrate_Multisite_Scope $scope)
    {
        $this->job = $job;
        $this->scope = $scope;
    }

    public function build_file_list(int $budget_sec): bool
    {
        if (!Rmmigrate_File_List::build_slice($this->scope, $this->job, $budget_sec)) {
            return false;
        }

        $work_dir = $this->job->get_work_dir();
        $files = Rmmigrate_File_List::load($work_dir);

        if ((int) ($this->job->get_progress()['archive']['file_index'] ?? 0) === 0) {
            $total_bytes = 0;
            foreach ($files as $f) {
                $total_bytes += (int) ($f['size'] ?? 0);
            }
            $sql_path = trailingslashit($work_dir) . 'database.sql';
            if (Rmmigrate_Filesystem::exists($sql_path)) {
                $total_bytes += (int) Rmmigrate_Filesystem::filesize($sql_path);
            }

            $manifest = Rmmigrate_Manifest::build($this->job, $this->scope, count($files), $total_bytes);
            $manifest = Rmmigrate_Manifest::with_core_detection($manifest, $this->job);
            $manifest_path = trailingslashit($work_dir) . 'manifest.json';
            $zip_name = Rmmigrate_Multisite_Scope::build_archive_file_name($this->job, 'zip');
            Rmmigrate_Manifest::write($manifest_path, $manifest, $zip_name);
            $this->job->update_progress(array('manifest' => $manifest));
        }

        $queue = $this->build_queue($work_dir, $files);
        RMMIGRATE_IO::write_json_atomic(
            trailingslashit($work_dir) . self::QUEUE_FILE,
            $queue
        );

        $archive = $this->job->get_progress()['archive'] ?? array();
        $zip_path = $archive['zip_path'] ?? '';
        $zip_name = $archive['zip_name'] ?? '';
        if ($zip_path === '') {
            $zip_name = Rmmigrate_Multisite_Scope::build_archive_file_name($this->job, 'zip');
            $zip_path = trailingslashit($work_dir) . $zip_name;
        }

        $this->job->update_progress(array(
            'archive' => array(
                'file_list_built' => true,
                'total_files'     => count($queue),
                'file_index'      => (int) ($archive['file_index'] ?? 0),
                'zip_path'        => $zip_path,
                'zip_name'        => $zip_name,
            ),
        ));

        return true;
    }

    public function run_slice(int $budget_sec): bool
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException( esc_html__('ZipArchive PHP extension is required.', 'rosenheinrich-multisite-migrate'));
        }

        $progress = $this->job->get_progress();
        $archive = $progress['archive'] ?? array();

        if (empty($archive['file_list_built'])) {
            if (!$this->build_file_list($budget_sec)) {
                return false;
            }
            $archive = $this->job->get_progress()['archive'] ?? array();
        }

        $work_dir = $this->job->get_work_dir();
        $zip_path = $archive['zip_path'] ?? '';
        $file_index = (int) ($archive['file_index'] ?? 0);
        $queue = $this->load_queue($work_dir);
        $total = count($queue);

        if ($total === 0) {
            throw new RuntimeException( esc_html__('Backup archive queue is empty.', 'rosenheinrich-multisite-migrate'));
        }

        $resume = false;
        if (Rmmigrate_Filesystem::exists($zip_path) && $file_index > 0) {
            $probe = new ZipArchive();
            if ($probe->open($zip_path) === true) {
                $probe->close();
                $resume = true;
            } else {
                Rmmigrate_Filesystem::delete($zip_path);
                $file_index = 0;
                $this->job->update_progress(array('archive' => array('file_index' => 0)));
                Rmmigrate_Logger::log('ZIP resume rejected — archive unreadable, restarting from file 0.');
            }
        }

        $zip = new ZipArchive();
        $open_flags = $resume ? ZipArchive::CREATE : (ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($zip->open($zip_path, $open_flags) !== true) {
            throw new RuntimeException( esc_html__('Cannot open backup archive for writing.', 'rosenheinrich-multisite-migrate'));
        }

        $start = microtime(true);
        $max_file_bytes = Rmmigrate_Settings::get_max_archive_file_bytes();
        $skipped_large = (int) ($archive['skipped_large'] ?? 0);
        while ($file_index < $total && (microtime(true) - $start) < $budget_sec) {
            Rmmigrate_Runner::touch_worker_lease($this->job->get_id());
            $item = $queue[$file_index];
            if (Rmmigrate_Filesystem::exists($item['path'])) {
                $size = (int) Rmmigrate_Filesystem::filesize($item['path']);
                $archive_name = (string) ($item['archive'] ?? '');
                if ($max_file_bytes > 0
                    && $size > $max_file_bytes
                    && $archive_name !== 'database.sql'
                    && $archive_name !== 'manifest.json'
                ) {
                    Rmmigrate_Logger::log(sprintf(
                        'Skipped large file: %s (%s)',
                        $item['path'],
                        size_format($size)
                    ));
                    $skipped_large++;
                } else {
                    $zip->addFile($item['path'], $item['archive']);
                }
            }
            $file_index++;
        }

        if ($zip->close() !== true) {
            $status = $zip->getStatusString() ?: 'Unknown error';
            /* translators: %s: ZipArchive error status string */
            throw new RuntimeException( esc_html( sprintf( __('Cannot finalize backup archive. Reason: %s', 'rosenheinrich-multisite-migrate'), $status ) ) );
        }

        $this->job->update_progress(array('archive' => array(
            'file_index'    => $file_index,
            'skipped_large' => $skipped_large,
        )));

        if ($file_index >= $total) {
            $rel = 'jobs/' . $this->job->get_id() . '/' . basename($zip_path);
            $this->job->save_fields(array(
                'local_path' => $rel,
                'file_size'  => (int) Rmmigrate_Filesystem::filesize($zip_path),
            ));
            return true;
        }

        return false;
    }

    /**
     * @param array<int,array{path:string,size:int,archive:string}> $files
     * @return array<int,array{path:string,archive:string}>
     */
    private function build_queue(string $work_dir, array $files): array
    {
        $queue = array();
        $sql_path = trailingslashit($work_dir) . 'database.sql';
        if (Rmmigrate_Filesystem::exists($sql_path)) {
            $queue[] = array('path' => $sql_path, 'archive' => 'database.sql');
        }
        $manifest_path = trailingslashit($work_dir) . 'manifest.json';
        if (Rmmigrate_Filesystem::exists($manifest_path)) {
            $queue[] = array('path' => $manifest_path, 'archive' => 'manifest.json');
        }

        $wp_config = ABSPATH . 'wp-config.php';
        if (Rmmigrate_Filesystem::exists($wp_config)) {
            $queue[] = array('path' => $wp_config, 'archive' => 'files/wp-config.php');
        }
        $htaccess = ABSPATH . '.htaccess';
        if (Rmmigrate_Filesystem::exists($htaccess)) {
            $queue[] = array('path' => $htaccess, 'archive' => 'files/.htaccess');
        }
        foreach ($files as $f) {
            $queue[] = array('path' => $f['path'], 'archive' => $f['archive']);
        }

        return $queue;
    }

    /**
     * @return array<int,array{path:string,archive:string}>
     */
    private function load_queue(string $work_dir): array
    {
        $path = trailingslashit($work_dir) . self::QUEUE_FILE;
        if (!Rmmigrate_Filesystem::exists($path)) {
            $files = Rmmigrate_File_List::load($work_dir);
            return $this->build_queue($work_dir, $files);
        }
        $raw = Rmmigrate_Filesystem::get_contents($path);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : array();
    }

}
