<?php

/**
 * Multisite Migrate DAF — resumable streaming archive (DupArchive-inspired, simplified).
 * Format: [uint32 path_len][path][uint64 data_len][data] per entry.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Daf_Archiver
{
    /** Current DAF format version. Bump when the on-disk layout changes. */
    const DAF_VERSION = 3;

    /** Magic header, derived from DAF_VERSION (e.g. "MMIGDAF3"). */
    const MAGIC = 'MMIGDAF' . self::DAF_VERSION;

    /** Older magics this build can recognise but no longer read. */
    const LEGACY_MAGICS = array('MMIGDAF1', 'MMIGDAF2');

    /**
     * Uncompressed bytes per independently-compressed block. Each block is its own
     * deflate stream so extraction can stop and resume at any block boundary —
     * this is what lets huge single entries (multi-GB DB dumps, media) restore
     * across time-limited slices instead of needing one giant slice.
     */
    const BLOCK_BYTES = 4194304;

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

        $daf_name = Rmmigrate_Multisite_Scope::build_archive_file_name($this->job, 'daf');
        $daf_path = trailingslashit($work_dir) . $daf_name;

        if ((int) ($this->job->get_progress()['archive']['file_index'] ?? 0) === 0) {
            $manifest = Rmmigrate_Manifest::build($this->job, $this->scope, count($files));
            $manifest = Rmmigrate_Manifest::with_core_detection($manifest, $this->job);
            $manifest_path = trailingslashit($work_dir) . 'manifest.json';
            Rmmigrate_Manifest::write($manifest_path, $manifest, $daf_name);
            $this->job->update_progress(array('manifest' => $manifest));
        }

        $queue = $this->build_queue($work_dir, $files);
        RMMIGRATE_IO::write_json_atomic(
            trailingslashit($work_dir) . 'daf-queue.json',
            $queue
        );

        $this->job->update_progress(array(
            'archive' => array(
                'format'          => 'daf',
                'file_list_built' => true,
                'total_files'     => count($queue),
                'file_index'      => 0,
                'archive_path'    => $daf_path,
                'archive_name'    => $daf_name,
                'byte_offset'     => strlen(self::MAGIC),
            ),
        ));

        return true;
    }

    public function run_slice(float $budget_sec): bool
    {
        $progress = $this->job->get_progress();
        $archive = $progress['archive'] ?? array();

        if (empty($archive['file_list_built'])) {
            if (!$this->build_file_list($budget_sec)) {
                return false;
            }
            $archive = $this->job->get_progress()['archive'] ?? array();
        }

        $work_dir = $this->job->get_work_dir();
        $daf_path = $archive['archive_path'] ?? '';
        $file_index = (int) ($archive['file_index'] ?? 0);
        $queue = $this->load_queue($work_dir);
        $total = count($queue);

        $this->ensure_valid_archive($daf_path, $file_index, $archive);

        $byte_checkpoint = max(strlen(self::MAGIC), (int) ($archive['byte_offset'] ?? strlen(self::MAGIC)));
        if (Rmmigrate_Filesystem::exists($daf_path) && Rmmigrate_Filesystem::filesize($daf_path) > $byte_checkpoint) {
            RMMIGRATE_IO::truncate_file($daf_path, $byte_checkpoint);
        }

        if ($file_index === 0 && !Rmmigrate_Filesystem::exists($daf_path)) {
            RMMIGRATE_IO::write_atomic($daf_path, self::MAGIC);
        }

        $start = microtime(true);
        $fh = Rmmigrate_Filesystem::fopen_raw($daf_path, 'c+b');
        if ($fh === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::EXTRACT_FAILED),
                esc_html__('Cannot open DAF archive for writing.', 'rosenheinrich-multisite-migrate')
            );
        }
        
        $byte_checkpoint = max(strlen(self::MAGIC), (int) ($archive['byte_offset'] ?? strlen(self::MAGIC)));
        fseek($fh, $byte_checkpoint);

        $can_compress = function_exists('gzdeflate');
        $last_hb = microtime(true);
        $max_file_bytes = Rmmigrate_Settings::get_max_archive_file_bytes();

        while ($file_index < $total && (microtime(true) - $start) < $budget_sec) {
            Rmmigrate_Runner::touch_worker_lease($this->job->get_id());
            $last_hb = microtime(true);
            $item = $queue[$file_index];
            if (!Rmmigrate_Filesystem::exists($item['path'])) {
                $file_index++;
                continue;
            }

            $path_bytes = $item['archive'];
            $path_len = strlen($path_bytes);
            $uncompressed_len = Rmmigrate_Filesystem::filesize($item['path']);
            if ($uncompressed_len < 0) {
                $file_index++;
                continue;
            }

            if ($max_file_bytes > 0
                && $uncompressed_len > $max_file_bytes
                && $path_bytes !== 'database.sql'
                && $path_bytes !== 'manifest.json'
            ) {
                Rmmigrate_Logger::log(sprintf(
                    'Skipped large file: %s (%s)',
                    $item['path'],
                    size_format((int) $uncompressed_len)
                ));
                $archive['skipped_large'] = (int) ($archive['skipped_large'] ?? 0) + 1;
                $file_index++;
                $archive['entry_source_offset'] = 0;
                $archive['entry_total_bytes'] = 0;
                $archive['file_index'] = $file_index;
                continue;
            }

            $entry_start = (int) ftell($fh);
            $source_offset = (int) ($archive['entry_source_offset'] ?? 0);
            // Resume mid-entry only when progress points at this file.
            if ($source_offset > 0 && (int) ($archive['file_index'] ?? 0) !== $file_index) {
                $source_offset = 0;
            }

            if ($source_offset <= 0) {
                // MMIGDAF3 entry header: [uint32 path_len][path][uint8 flag][uint64 uncomp_len]
                // followed by a sequence of independently-(de)compressed blocks:
                //   [uint8 block_flag][uint32 comp_len][uint32 block_uncomp_len][comp_len bytes]
                // block_flag 1 = deflate, 0 = stored. Carrying each block's uncompressed
                // size lets the reader resume and skip at block boundaries without
                // inflating, which is what makes huge entries restorable across slices.
                $flag = $can_compress ? 1 : 0;
                $header = pack('N', $path_len) . $path_bytes . pack('C', $flag) . pack('J', $uncompressed_len);

                if (Rmmigrate_Filesystem::fwrite_raw($fh, $header) === false) {
                    RMMIGRATE_IO::truncate_file($daf_path, $entry_start);
                    break;
                }
            }

            $data_fp = Rmmigrate_Filesystem::fopen_raw($item['path'], 'rb');
            if ($data_fp === false) {
                if ($source_offset <= 0) {
                    RMMIGRATE_IO::truncate_file($daf_path, $entry_start);
                }
                break;
            }

            if ($source_offset > 0) {
                fseek($data_fp, $source_offset);
            }

            $entry_ok = true;
            $paused = false;
            $remaining = $uncompressed_len - max(0, $source_offset);
            while ($remaining > 0 && !feof($data_fp)) {
                if ((microtime(true) - $last_hb) >= 30.0) {
                    Rmmigrate_Runner::touch_worker_lease($this->job->get_id());
                    $last_hb = microtime(true);
                    $this->job->update_progress(array('archive' => array(
                        'file_index'          => $file_index,
                        'byte_offset'         => (int) ftell($fh),
                        'entry_source_offset' => $uncompressed_len - $remaining,
                        'entry_total_bytes'   => (int) $uncompressed_len,
                    )));
                }
                // fread() returns at most one stream chunk (often 8 KB) per call, so
                // loop until a full BLOCK_BYTES buffer is collected. Otherwise blocks
                // would be tiny, exploding block count (and per-block overhead) on
                // huge entries and slowing resume to a crawl.
                $want = (int) min(self::BLOCK_BYTES, $remaining);
                $chunk = '';
                while (strlen($chunk) < $want && !feof($data_fp)) {
                    $part = Rmmigrate_Filesystem::fread_raw($data_fp, $want - strlen($chunk));
                    if ($part === false) {
                        $entry_ok = false;
                        break 2;
                    }
                    if ($part === '') {
                        break;
                    }
                    $chunk .= $part;
                }
                if ($chunk === '') {
                    break;
                }
                $block = $chunk;
                $block_flag = 0;
                if ($can_compress) {
                    $deflated = gzdeflate($chunk, 6);
                    if ($deflated !== false && strlen($deflated) < strlen($chunk)) {
                        $block = $deflated;
                        $block_flag = 1;
                    }
                }
                $block_header = pack('C', $block_flag) . pack('N', strlen($block)) . pack('N', strlen($chunk));
                if (Rmmigrate_Filesystem::fwrite_raw($fh, $block_header) === false
                    || ($block !== '' && Rmmigrate_Filesystem::fwrite_raw($fh, $block) === false)) {
                    $entry_ok = false;
                    break;
                }
                $remaining -= strlen($chunk);
                // Pause after at least one block so entry_source_offset > 0 and the
                // next slice resumes without rewriting the entry header.
                if ($remaining > 0 && (microtime(true) - $start) >= $budget_sec) {
                    $paused = true;
                    break;
                }
            }

            Rmmigrate_Filesystem::fclose_raw($data_fp);

            if ($paused) {
                $byte_offset = (int) ftell($fh);
                Rmmigrate_Filesystem::fclose_raw($fh);
                $this->job->update_progress(array('archive' => array(
                    'file_index'           => $file_index,
                    'byte_offset'          => $byte_offset,
                    'entry_source_offset'  => $uncompressed_len - $remaining,
                    'entry_total_bytes'    => (int) $uncompressed_len,
                    'skipped_large'        => (int) ($archive['skipped_large'] ?? 0),
                )));
                return false;
            }

            if (!$entry_ok || $remaining > 0) {
                RMMIGRATE_IO::truncate_file($daf_path, $entry_start);
                break;
            }

            $file_index++;
            $archive['entry_source_offset'] = 0;
            $archive['entry_total_bytes'] = 0;
            $archive['file_index'] = $file_index;
        }
        $byte_offset = (int) ftell($fh);
        Rmmigrate_Filesystem::fclose_raw($fh);

        $this->job->update_progress(array('archive' => array(
            'file_index'          => $file_index,
            'byte_offset'         => $byte_offset,
            'entry_source_offset' => 0,
            'entry_total_bytes'   => 0,
            'skipped_large'       => (int) ($archive['skipped_large'] ?? 0),
        )));

        if ($file_index >= $total) {
            self::verify_finalized_archive($daf_path, $byte_offset);
            $rel = 'jobs/' . $this->job->get_id() . '/' . basename($daf_path);
            $this->job->save_fields(array(
                'local_path' => $rel,
                'file_size'  => Rmmigrate_Filesystem::filesize($daf_path),
            ));
            return true;
        }

        return false;
    }

    /**
     * Returns the legacy magic string when $head begins with a recognised older
     * DAF magic, or '' when it does not. Used to give a clear "older format"
     * error instead of a generic "corrupt archive" message.
     */
    public static function detect_legacy_magic(string $head): string
    {
        foreach (self::LEGACY_MAGICS as $legacy) {
            if (strncmp($head, $legacy, strlen($legacy)) === 0) {
                return $legacy;
            }
        }

        return '';
    }

    /**
     * User-facing explanation when a backup uses an older DAF magic header.
     */
    public static function legacy_error_message(string $legacy): string
    {
        return sprintf(
            /* translators: %s: legacy archive format identifier, e.g. MMIGDAF2. */
            __('This backup was created with an older archive format (%s) that this version can no longer restore. Please re-create the backup with the current version of Multisite Migrate.', 'rosenheinrich-multisite-migrate'),
            $legacy
        );
    }

    /**
     * Re-open the finished DAF and confirm its magic header and on-disk size
     * match what we wrote. A mismatch means the archive was truncated or
     * corrupted mid-write, so fail loudly rather than hand back a bad backup.
     */
    private static function verify_finalized_archive(string $daf_path, int $byte_offset): void
    {
        $head = Rmmigrate_Filesystem::get_contents($daf_path, 0, strlen(self::MAGIC));
        if ($head !== self::MAGIC) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT),
                esc_html__('DAF archive failed its integrity check (bad header). The backup may be incomplete — please try again.', 'rosenheinrich-multisite-migrate')
            );
        }

        $actual_size = (int) Rmmigrate_Filesystem::filesize($daf_path);
        if ($actual_size !== $byte_offset) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT),
                esc_html__('DAF archive failed its integrity check (size mismatch). The backup may be incomplete — please try again.', 'rosenheinrich-multisite-migrate')
            );
        }
    }

    /**
     * @param array<string,mixed> $archive
     */
    private function ensure_valid_archive(string $daf_path, int $file_index, array $archive): void
    {
        if ($file_index <= 0 || !Rmmigrate_Filesystem::exists($daf_path)) {
            return;
        }
        $head = Rmmigrate_Filesystem::get_contents($daf_path, 0, strlen(self::MAGIC));
        if ($head !== self::MAGIC) {
            $checkpoint = max(strlen(self::MAGIC), (int) ($archive['byte_offset'] ?? strlen(self::MAGIC)));
            RMMIGRATE_IO::truncate_file($daf_path, $checkpoint);
            Rmmigrate_Logger::log('DAF archive truncated to last checkpoint after invalid header.');
        }
    }

    /**
     * @param array<int,array{path:string,archive:string}> $files
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
        $path = trailingslashit($work_dir) . 'daf-queue.json';
        if (!Rmmigrate_Filesystem::exists($path)) {
            return array();
        }
        $decoded = json_decode(Rmmigrate_Filesystem::get_contents($path), true);
        return is_array($decoded) ? $decoded : array();
    }

}
