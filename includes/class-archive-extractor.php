<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Rmmigrate_Extract_Engine')) {
    require_once __DIR__ . '/shared/extract-engine.php';
}
if (!class_exists('Rmmigrate_Zip_Extract_Core')) {
    require_once __DIR__ . '/shared/zip-extract-core.php';
}

class Rmmigrate_Archive_Extractor
{
    /** @var Rmmigrate_Job */
    private $job;

    public function __construct(Rmmigrate_Job $job)
    {
        $this->job = $job;
    }

    public function run_slice(float $budget_sec): bool
    {
        $progress = $this->job->get_progress();
        $plan = $progress['extract_plan'] ?? array();
        $plan_index = (int) ($progress['plan_index'] ?? 0);

        if ($plan !== array() && isset($plan[$plan_index])) {
            $zip_path = (string) ($plan[$plan_index]['archive_path'] ?? '');
            $format = (string) ($plan[$plan_index]['format'] ?? 'zip');
        } else {
            $zip_path = (string) ($progress['source']['zip_path'] ?? '');
            $format = strtolower(pathinfo($zip_path, PATHINFO_EXTENSION));
        }

        if ($zip_path === '' || !Rmmigrate_Filesystem::exists($zip_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::ARCHIVE_MISSING), esc_html__('Backup archive not found.', 'rosenheinrich-multisite-migrate'));
        }

        if ($format === 'daf') {
            $done = $this->extract_daf($zip_path, $budget_sec);
        } else {
            $done = $this->extract_zip($zip_path, $budget_sec);
        }

        if ($done && $plan !== array() && $plan_index < count($plan) - 1) {
            $this->job->update_progress(array(
                'plan_index'           => $plan_index + 1,
                'extract_shell_failed' => false,
            ));
            $this->job->replace_progress_section('extract', array());
            return false;
        }

        return $done;
    }

    private function extract_zip(string $zip_path, float $budget_sec): bool
    {
        $extract_dir = trailingslashit($this->job->get_work_dir()) . 'extracted/';
        wp_mkdir_p($extract_dir);

        $only_sql = $this->job->get_restore_mode() === Rmmigrate_Job::RESTORE_MODE_DB;
        $engine_state = $this->engine_state();
        $password = Rmmigrate_Extract_Engine::archive_password($engine_state);
        $throttle = Rmmigrate_Extract_Engine::throttle_enabled($engine_state);
        $slice_budget = Rmmigrate_Extract_Engine::budget_sec($engine_state, $budget_sec);

        $progress = $this->job->get_progress();
        $extract = $progress['extract'] ?? array();
        $chunk_in_progress = $extract !== array()
            && ( ( $extract['format'] ?? '' ) === 'zip' || ! empty( $extract['entries'] ) )
            && ! $this->zip_extract_complete( $extract );

        if ( ! $chunk_in_progress ) {
            $engine = Rmmigrate_Extract_Engine::resolve( $zip_path, $engine_state, 'zip' );

            if ( $engine === Rmmigrate_Extract_Engine::ENGINE_SHELL && ! $only_sql ) {
                try {
                    Rmmigrate_Extract_Engine::run_shell_unzip( $zip_path, $extract_dir, $password );
                    $this->job->update_progress( array(
                        'extract'              => Rmmigrate_Extract_Engine::completed_zip_progress( $zip_path, $extract_dir ),
                        'extract_engine_used'  => Rmmigrate_Extract_Engine::ENGINE_SHELL,
                        'extract_shell_failed' => false,
                    ) );
                    Rmmigrate_Logger::log( 'Extract complete via shell unzip' );
                    return true;
                } catch ( Throwable $shell_error ) {
                    Rmmigrate_Logger::log( 'Shell unzip failed; falling back to PHP chunked extraction: ' . $shell_error->getMessage() );
                    Rmmigrate_Extract_Engine::clear_extract_dir( $extract_dir );
                    $this->job->update_progress( array( 'extract_shell_failed' => true ) );
                    $engine_state['extract_shell_failed'] = true;
                }
            }

            if (
                $engine === Rmmigrate_Extract_Engine::ENGINE_ZIP_ONESHOT
                && ! $only_sql
                && empty( $engine_state['extract_shell_failed'] )
            ) {
                try {
                    Rmmigrate_Extract_Engine::extract_oneshot( $zip_path, $extract_dir, false, $password );
                    $this->job->update_progress( array(
                        'extract'             => Rmmigrate_Extract_Engine::completed_zip_progress( $zip_path, $extract_dir ),
                        'extract_engine_used' => Rmmigrate_Extract_Engine::ENGINE_ZIP_ONESHOT,
                    ) );
                    Rmmigrate_Logger::log( 'Extract complete via one-shot ZipArchive' );
                    return true;
                } catch ( Throwable $oneshot_error ) {
                    Rmmigrate_Logger::log( 'One-shot ZIP extract failed; falling back to chunked extraction: ' . $oneshot_error->getMessage() );
                    Rmmigrate_Extract_Engine::clear_extract_dir( $extract_dir );
                }
            }
        }

        $extractor = new Rmmigrate_Zip_Extractor( $this->job );
        $done = $extractor->run_slice( $slice_budget, $only_sql, $throttle, $password );
        if ($done && (string) ($this->job->get_progress()['extract_engine_used'] ?? '') === '') {
            $this->job->update_progress( array(
                'extract_engine_used' => Rmmigrate_Extract_Engine::ENGINE_ZIP_CHUNK,
            ) );
        }

        return $done;
    }

    /**
     * @param array<string,mixed> $extract
     */
    private function zip_extract_complete( array $extract ): bool
    {
        if ( ( $extract['format'] ?? '' ) === 'zip' && isset( $extract['zip_index'], $extract['zip_total'] ) ) {
            return (int) $extract['zip_index'] >= (int) $extract['zip_total'];
        }
        if ( ! empty( $extract['entries'] ) ) {
            return (int) ( $extract['entry_index'] ?? 0 ) >= count( $extract['entries'] );
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function engine_state(): array
    {
        $progress = $this->job->get_progress();

        return array(
            'extract_engine'       => (string) ( $progress['extract_engine'] ?? Rmmigrate_Extract_Engine::ENGINE_AUTO ),
            'extract_shell_failed' => ! empty( $progress['extract_shell_failed'] ),
            'archive_passphrase'   => (string) ( $progress['archive_passphrase'] ?? ( $this->job->data['archive_passphrase'] ?? '' ) ),
            'extract_throttle'     => Rmmigrate_Extract_Engine::throttle_enabled( array() ),
        );
    }

    private function extract_daf(string $daf_path, float $budget_sec): bool
    {
        $progress = $this->job->get_progress();
        $extract = $progress['extract'] ?? array();
        $extract_dir = trailingslashit($this->job->get_work_dir()) . 'extracted/';
        wp_mkdir_p($extract_dir);

        $mode = $this->job->get_restore_mode();
        $only_sql = $mode === Rmmigrate_Job::RESTORE_MODE_DB;

        $archive_size = (int) Rmmigrate_Filesystem::filesize($daf_path);
        $fh = Rmmigrate_Filesystem::open($daf_path, 'rb');
        if ($fh === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::EXTRACT_FAILED), esc_html__('Cannot open DAF archive.', 'rosenheinrich-multisite-migrate'));
        }

        if ($fh->read(8) !== Rmmigrate_Daf_Archiver::MAGIC) {
            $fh->close();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('Invalid DAF archive.', 'rosenheinrich-multisite-migrate'));
        }

        $byte_offset = (int) ($extract['byte_offset'] ?? 0);
        if ($byte_offset < 8) {
            $byte_offset = 8;
        }
        $fh->seek($byte_offset);

        $start = microtime(true);
        $entries = (int) ($extract['entry_count'] ?? 0);
        $partial = (isset($extract['partial']) && is_array($extract['partial'])) ? $extract['partial'] : null;
        $done = false;
        $timeouts = (int) ($progress['php_execution_timeouts'] ?? 0);
        $had_partial_at_start = $partial !== null;
        $daf_slice_first_block = true;

        // Always run at least one pass: a tiny budget (or a loaded host) can be
        // exhausted during open/seek above, which would otherwise return without
        // advancing byte_offset/partial and stall the caller in a no-progress loop.
        $first_pass = true;
        while ($first_pass || (microtime(true) - $start) < $budget_sec) {
            $first_pass = false;
            if ($partial === null) {
                if ($byte_offset >= $archive_size) {
                    $done = true;
                    break;
                }
                $header = $fh->read(4);
                if ($header === false || strlen($header) < 4) {
                    if ($byte_offset < $archive_size) {
                        $fh->close();
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('Invalid DAF archive entry.', 'rosenheinrich-multisite-migrate'));
                    }
                    $done = true;
                    break;
                }
                $path_len = (int) unpack('N', $header)[1];
                if ($path_len <= 0 || $path_len > 4096) {
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('Invalid DAF archive entry.', 'rosenheinrich-multisite-migrate'));
                }
                $archive_path = $fh->read($path_len);
                if ($archive_path === false || strlen($archive_path) < $path_len) {
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('Invalid DAF archive entry.', 'rosenheinrich-multisite-migrate'));
                }

                // Entry header: [uint8 flag][uint64 uncomp_len]. The per-entry flag is
                // advisory; each block carries its own flag, so we only need the total
                // uncompressed length to know when the entry is complete.
                $sh = $fh->read(9);
                if ($sh === false || strlen($sh) < 9) {
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('Invalid DAF archive entry.', 'rosenheinrich-multisite-migrate'));
                }
                $u = unpack('Cflag/Juncomp', $sh);
                $uncomp_len = (int) $u['uncomp'];
                if ($uncomp_len < 0) {
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('Invalid DAF archive entry.', 'rosenheinrich-multisite-migrate'));
                }

                $dest = null;
                $skip = false;
                if ($only_sql && $archive_path !== 'database.sql' && $archive_path !== 'manifest.json') {
                    $skip = true;
                } elseif (strpos($archive_path, "\0") !== false) {
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('Invalid DAF archive entry (unsafe path).', 'rosenheinrich-multisite-migrate'));
                } else {
                    $dest = Rmmigrate_Path_Safety::resolve_extract_dest($extract_dir, $archive_path);
                    if ($dest === null) {
                        Rmmigrate_Logger::log('Skipped unsafe DAF entry: ' . $archive_path);
                        $skip = true;
                    }
                }

                if (!$skip) {
                    wp_mkdir_p(dirname($dest));
                    $create = Rmmigrate_Filesystem::fopen_raw($dest, 'wb');
                    if ($create !== false) {
                        Rmmigrate_Filesystem::fclose_raw($create);
                    }
                }
                $partial = array(
                    'dest'         => $skip ? null : $dest,
                    'counts'       => !$skip,
                    'uncomp_len'   => $uncomp_len,
                    'bytes_done'   => 0,
                    'block_offset' => (int) $fh->tell(),
                );
            }

            // v3 block extraction (resumable at block boundaries).
            $fh->seek((int) $partial['block_offset']);
            $dest = $partial['dest'];
            $bytes_done = (int) $partial['bytes_done'];
            $uncomp_len = (int) $partial['uncomp_len'];
            $out_fh = null;
            if ($dest !== null) {
                if ($bytes_done > 0) {
                    RMMIGRATE_IO::truncate_file($dest, $bytes_done);
                }
                $out_fh = Rmmigrate_Filesystem::open($dest, 'ab');
                if ($out_fh === false) {
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::EXTRACT_FAILED), esc_html__('Cannot write extracted file.', 'rosenheinrich-multisite-migrate'));
                }
            }

            while ($bytes_done < $uncomp_len) {
                // Yield before every block. After soft PHP timeouts, keep 2s headroom
                // once some extract progress exists — except the first block this slice.
                $headroom = (!$daf_slice_first_block && $timeouts > 0 && ($had_partial_at_start || $bytes_done > 0 || $entries > 0))
                    ? 2.0
                    : 0.0;
                if ((microtime(true) - $start) + $headroom >= $budget_sec) {
                    if ($out_fh !== null) {
                        $out_fh->close();
                    }
                    $partial['bytes_done']   = $bytes_done;
                    $partial['block_offset'] = (int) $fh->tell();
                    $fh->close();
                    $this->job->update_progress(array(
                        'extract' => array(
                            'byte_offset' => $byte_offset,
                            'entry_count' => $entries,
                            'extract_dir' => $extract_dir,
                            'format'      => 'daf',
                            'partial'     => $partial,
                        ),
                        'extract_engine_used' => Rmmigrate_Extract_Engine::ENGINE_DAF,
                    ));
                    return false;
                }

                $bh = $fh->read(9);
                if ($bh === false || strlen($bh) < 9) {
                    if ($out_fh !== null) {
                        $out_fh->close();
                    }
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('Invalid DAF archive entry.', 'rosenheinrich-multisite-migrate'));
                }
                $bu = unpack('Cflag/Ncomp/Nuncomp', $bh);
                $block_flag = (int) $bu['flag'];
                $block_comp = (int) $bu['comp'];
                $block_uncomp = (int) $bu['uncomp'];

                if ($block_comp < 0 || $block_comp > $archive_size || $block_uncomp <= 0) {
                    if ($out_fh !== null) {
                        $out_fh->close();
                    }
                    $fh->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                    throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('DAF archive block is corrupt.', 'rosenheinrich-multisite-migrate'));
                }

                if ($dest === null) {
                    $fh->seek($block_comp, SEEK_CUR);
                } else {
                    $blob = $block_comp > 0 ? $fh->read($block_comp) : '';
                    if ($blob === false || strlen($blob) < $block_comp) {
                        $out_fh->close();
                        $fh->close();
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('Invalid DAF archive entry.', 'rosenheinrich-multisite-migrate'));
                    }
                    if ($block_flag === 1 && !function_exists('gzinflate')) {
                        $out_fh->close();
                        $fh->close();
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::EXTRACT_FAILED), esc_html__('This DAF archive uses compression but the PHP zlib extension (gzinflate) is not available on this server. Enable zlib and try again.', 'rosenheinrich-multisite-migrate'));
                    }
                    $out = $block_flag === 1 ? gzinflate($blob) : $blob;
                    if ($out === false) {
                        $out_fh->close();
                        $fh->close();
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('DAF archive block is corrupt.', 'rosenheinrich-multisite-migrate'));
                    }
                    if (strlen($out) !== $block_uncomp) {
                        $out_fh->close();
                        $fh->close();
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                        throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::DAF_CORRUPT), esc_html__('DAF archive block is corrupt.', 'rosenheinrich-multisite-migrate'));
                    }
                    // After soft timeouts, write in smaller appends to reduce LOCK_EX stalls.
                    $write_chunk = $timeouts > 0 ? 524288 : strlen($out);
                    $out_len = strlen($out);
                    for ($off = 0; $off < $out_len; $off += $write_chunk) {
                        if ($out_fh->write(substr($out, $off, $write_chunk)) === false) {
                            $out_fh->close();
                            $fh->close();
                            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception.
                            throw Rmmigrate_Job_Exception::raise(sanitize_key(Rmmigrate_Error_Codes::EXTRACT_FAILED), esc_html__('Cannot write extracted file.', 'rosenheinrich-multisite-migrate'));
                        }
                    }
                }
                $bytes_done += $block_uncomp;
                $daf_slice_first_block = false;
            }

            if ($out_fh !== null) {
                $out_fh->close();
            }
            if (!empty($partial['counts'])) {
                $entries++;
            }
            $byte_offset = (int) $fh->tell();
            $partial = null;
        }

        if ($partial === null && $byte_offset >= $archive_size) {
            $done = true;
        }

        $fh->close();

        $this->job->update_progress(array(
            'extract' => array(
                'byte_offset'  => $byte_offset,
                'entry_count'  => $entries,
                'extract_dir'  => $extract_dir,
                'format'       => 'daf',
                'partial'      => $partial,
            ),
            'extract_engine_used' => Rmmigrate_Extract_Engine::ENGINE_DAF,
        ));

        return $done;
    }

}
