<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Ajax_Import
{
    use Rmmigrate_Ajax_Base;

    public static function register(): void
    {
        add_action('wp_ajax_rmmigrate_import_local', array(__CLASS__, 'import_local'));
        add_action('wp_ajax_rmmigrate_import_local_chunk', array(__CLASS__, 'import_local_chunk'));
        add_action('wp_ajax_rmmigrate_import_restore', array(__CLASS__, 'import_and_restore'));
    }

    public static function import_local(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Early limit detection before nonce verification.
        if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0) {
            wp_send_json_error(array(
                'message'  => __('Upload payload exceeds server PHP limits. Reducing chunk size…', 'rosenheinrich-multisite-migrate'),
                'downsize' => true,
            ), 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Early limit detection before nonce verification.
        if (isset($_FILES['archive']['error']) && in_array((int) $_FILES['archive']['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
            wp_send_json_error(array(
                'message'  => __('File exceeds server upload limit. Switching to chunked upload…', 'rosenheinrich-multisite-migrate'),
                'downsize' => true,
            ), 400);
        }

        self::verify_request();
        self::assert_import_access();

        if (!defined('WP_IMPORTING')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core import flag required during restore/import.
            define('WP_IMPORTING', true);
        }

        $tmp = Rmmigrate_Request_Input::file_tmp_name('archive');
        if ($tmp === '') {
            self::import_error(__('No file uploaded.', 'rosenheinrich-multisite-migrate'), array('source' => 'local'));
        }

        $name = Rmmigrate_Request_Input::file_original_name('archive', 'backup.zip');
        if ($name === '') {
            self::import_error(__('Invalid filename.', 'rosenheinrich-multisite-migrate'), array('source' => 'local'));
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, array('zip', 'daf', 'venc'), true)) {
            self::import_error(__('Invalid file extension. Only .zip, .daf, and .venc files are allowed.', 'rosenheinrich-multisite-migrate'), array('source' => 'local'));
        }

        Rmmigrate_Plugin::ensure_backup_root();
        $import_dir = Rmmigrate_Plugin::backups_dir() . '/imports/';
        wp_mkdir_p($import_dir);
        $upload_id = 'u' . substr(hash('sha256', uniqid((string) wp_rand(), true)), 0, 16);
        $path = $import_dir . $upload_id . '.' . $ext;
        if (!Rmmigrate_Path_Safety::path_within_dir($import_dir, $path)) {
            self::import_error(__('Invalid upload path.', 'rosenheinrich-multisite-migrate'), array('source' => 'local'));
        }

        $size = Rmmigrate_Request_Input::file_size('archive');
        $disk = Rmmigrate_Validator::validate_import_disk_space($path, $size);
        if (is_wp_error($disk)) {
            self::import_error($disk->get_error_message(), array('source' => 'local', 'phase' => 'disk'));
        }

        if (!Rmmigrate_Filesystem::store_uploaded_file($tmp, $path)) {
            self::import_error(__('Upload failed.', 'rosenheinrich-multisite-migrate'), array('source' => 'local', 'phase' => 'upload'));
        }

        try {
            $result = Rmmigrate_Import_Service::finalize_import_file(
                $path,
                Rmmigrate_Request_Input::post_text('archive_passphrase')
            );
            wp_send_json_success($result);
        } catch (Rmmigrate_Service_Exception $e) {
            if (Rmmigrate_Filesystem::exists($path)) {
                Rmmigrate_Filesystem::delete($path);
            }
            self::import_error($e->getMessage(), array('source' => 'local', 'phase' => 'validate'));
        }
    }

    public static function import_local_chunk(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Early limit detection before nonce verification.
        if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0) {
            wp_send_json_error(array(
                'message'  => __('Upload payload exceeds server PHP limits. Reducing chunk size…', 'rosenheinrich-multisite-migrate'),
                'downsize' => true,
            ), 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Early limit detection before nonce verification.
        if (isset($_FILES['chunk']['error']) && in_array((int) $_FILES['chunk']['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
            wp_send_json_error(array(
                'message'  => __('Chunk size exceeds server PHP limits. Reducing chunk size…', 'rosenheinrich-multisite-migrate'),
                'downsize' => true,
            ), 400);
        }

        self::verify_request();
        self::assert_import_access();

        if (!defined('WP_IMPORTING')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core import flag required during restore/import.
            define('WP_IMPORTING', true);
        }

        $upload_id = Rmmigrate_Request_Input::post_text('upload_id');
        $filename = Rmmigrate_Request_Input::post_file_name('filename', 'backup.zip');
        $chunk_index = Rmmigrate_Request_Input::post_int('chunk_index');
        $total_chunks = Rmmigrate_Request_Input::post_int('total_chunks', 1);
        $err_ctx = array('upload_id' => $upload_id, 'filename' => $filename, 'source' => 'local_chunk');

        if ($total_chunks < 1) {
            self::import_error(__('Invalid chunk count.', 'rosenheinrich-multisite-migrate'), $err_ctx);
        }

        if ($filename === '' || !Rmmigrate_Path_Safety::is_valid_upload_id($upload_id)) {
            self::import_error(__('Invalid upload.', 'rosenheinrich-multisite-migrate'), $err_ctx);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, array('zip', 'daf', 'venc'), true)) {
            self::import_error(__('Invalid file extension.', 'rosenheinrich-multisite-migrate'), $err_ctx);
        }

        Rmmigrate_Plugin::ensure_backup_root();
        $import_dir = Rmmigrate_Plugin::backups_dir() . '/imports/';
        wp_mkdir_p($import_dir);
        $part_path = $import_dir . $upload_id . '.part';
        if (!Rmmigrate_Path_Safety::path_within_dir($import_dir, $part_path)) {
            self::import_error(__('Invalid upload path.', 'rosenheinrich-multisite-migrate'), $err_ctx);
        }

        if ($chunk_index === 0) {
            Rmmigrate_Filesystem::delete($part_path);
            Rmmigrate_Logger::log_activity(
                'import',
                sprintf(
                    /* translators: 1: archive filename, 2: total chunk count */
                    __('Started chunked import upload of "%1$s" (%2$d chunks)', 'rosenheinrich-multisite-migrate'),
                    $filename,
                    $total_chunks
                ),
                'info',
                array('upload_id' => $upload_id, 'filename' => $filename, 'total_chunks' => $total_chunks)
            );
        }

        clearstatcache(true, $part_path);
        $expected_offset = Rmmigrate_Request_Input::post_int('expected_offset');

        $chunk = self::resolve_import_chunk_bytes($err_ctx);
        if (strlen($chunk) > Rmmigrate_Extract_Engine::BLOCKING_SAFE_BYTES) {
            $msg = __('Chunk exceeds maximum allowed size.', 'rosenheinrich-multisite-migrate');
            wp_send_json_error(array(
                'message'  => $msg,
                'downsize' => true,
                'logged'   => false,
            ), 400);
        }

        $append = self::append_import_chunk($part_path, $chunk, $chunk_index, $expected_offset);
        if ($append === 'offset_mismatch') {
            clearstatcache(true, $part_path);
            $current_size = Rmmigrate_Filesystem::exists($part_path)
                ? (int) Rmmigrate_Filesystem::filesize($part_path)
                : 0;
            wp_send_json_error(array(
                'message'       => __('Upload out of sync. Retrying from last good offset.', 'rosenheinrich-multisite-migrate'),
                'resume_offset' => $current_size,
                'resume_chunk'  => $chunk_index,
            ));
        }
        if ($append instanceof WP_Error) {
            Rmmigrate_Filesystem::delete($part_path);
            self::import_error($append->get_error_message(), array_merge($err_ctx, array('phase' => 'disk')));
        }
        if ($append === false) {
            self::import_error(__('Could not write upload chunk.', 'rosenheinrich-multisite-migrate'), $err_ctx);
        }
        $bytes_written = (int) $append;

        if ($chunk_index + 1 < $total_chunks) {
            wp_send_json_success(array(
                'complete'      => false,
                'chunk'         => $chunk_index,
                'bytes_written' => $bytes_written,
            ));
        }

        $disk = Rmmigrate_Validator::validate_import_disk_space($part_path);
        if (is_wp_error($disk)) {
            Rmmigrate_Filesystem::delete($part_path);
            self::import_error($disk->get_error_message(), array_merge($err_ctx, array('phase' => 'disk')));
        }

        $final = $import_dir . $upload_id . '.' . $ext;
        if (!Rmmigrate_Path_Safety::path_within_dir($import_dir, $final)) {
            Rmmigrate_Filesystem::delete($part_path);
            self::import_error(__('Invalid upload path.', 'rosenheinrich-multisite-migrate'), $err_ctx);
        }
        if (Rmmigrate_Filesystem::exists($final)) {
            Rmmigrate_Filesystem::delete($final);
        }
        Rmmigrate_Filesystem::move($part_path, $final);

        try {
            $result = Rmmigrate_Import_Service::finalize_import_file(
                $final,
                Rmmigrate_Request_Input::post_text('archive_passphrase')
            );
            wp_send_json_success(array_merge($result, array('complete' => true)));
        } catch (Rmmigrate_Service_Exception $e) {
            if (Rmmigrate_Filesystem::exists($final)) {
                Rmmigrate_Filesystem::delete($final);
            }
            self::import_error($e->getMessage(), array_merge($err_ctx, array('phase' => 'validate')));
        }
    }

    /**
     * Resolve chunk bytes for import_local_chunk.
     *
     * Prefer $_FILES['chunk'] (browser FormData). Only fall back to php://input when
     * the request is not multipart — reading php://input for multipart can yield the
     * entire MIME body (boundaries + fields + file), which falsely trips the max-size
     * guard or skips a valid uploaded temp file.
     *
     * @param array<string,mixed> $err_ctx
     */
    private static function resolve_import_chunk_bytes(array $err_ctx): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by import_local_chunk before this helper runs.
        if (isset($_FILES['chunk']) && is_array($_FILES['chunk'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
            $upload_error = isset($_FILES['chunk']['error']) ? (int) $_FILES['chunk']['error'] : UPLOAD_ERR_NO_FILE;

            if (in_array($upload_error, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
                wp_send_json_error(array(
                    'message'  => __('Chunk size exceeds server PHP limits. Reducing chunk size…', 'rosenheinrich-multisite-migrate'),
                    'downsize' => true,
                    'logged'   => false,
                ), 400);
            }

            if ($upload_error === UPLOAD_ERR_PARTIAL) {
                wp_send_json_error(array(
                    'message'  => __('Chunk upload was truncated. Reducing chunk size…', 'rosenheinrich-multisite-migrate'),
                    'downsize' => true,
                    'logged'   => false,
                ), 400);
            }

            if ($upload_error === UPLOAD_ERR_NO_FILE) {
                self::import_error(
                    __('Empty chunk. No file part received for this upload slice.', 'rosenheinrich-multisite-migrate'),
                    array_merge($err_ctx, array('phase' => 'chunk', 'upload_error' => $upload_error))
                );
            }

            if ($upload_error !== UPLOAD_ERR_OK) {
                self::import_error(
                    sprintf(
                        /* translators: %d: PHP UPLOAD_ERR_* code */
                        __('Chunk upload failed (error code %d).', 'rosenheinrich-multisite-migrate'),
                        $upload_error
                    ),
                    array_merge($err_ctx, array('phase' => 'chunk', 'upload_error' => $upload_error))
                );
            }

            $tmp = Rmmigrate_Request_Input::file_tmp_name('chunk');
            if ($tmp === '') {
                self::import_error(
                    __('Empty chunk. Uploaded temp file was missing or rejected.', 'rosenheinrich-multisite-migrate'),
                    array_merge($err_ctx, array('phase' => 'chunk', 'reason' => 'tmp_missing'))
                );
            }

            // Native read: WP_Filesystem (FTP/SSH) cannot reliably read PHP upload temps.
            $file_chunk = Rmmigrate_Filesystem::read_uploaded_temp($tmp);
            if (!is_string($file_chunk) || $file_chunk === '') {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Size is diagnostic only.
                $declared = isset($_FILES['chunk']['size']) ? (int) $_FILES['chunk']['size'] : 0;
                if ($declared === 0) {
                    self::import_error(
                        __('Empty chunk. Client sent a zero-byte upload slice.', 'rosenheinrich-multisite-migrate'),
                        array_merge($err_ctx, array('phase' => 'chunk', 'reason' => 'zero_bytes'))
                    );
                }
                self::import_error(
                    __('Empty chunk. Could not read the uploaded slice from disk.', 'rosenheinrich-multisite-migrate'),
                    array_merge($err_ctx, array('phase' => 'chunk', 'reason' => 'read_failed', 'declared_size' => $declared))
                );
            }

            return $file_chunk;
        }

        $content_type = '';
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $content_type = strtolower(sanitize_text_field(wp_unslash($_SERVER['CONTENT_TYPE'])));
        } elseif (isset($_SERVER['HTTP_CONTENT_TYPE']) && is_string($_SERVER['HTTP_CONTENT_TYPE'])) {
            $content_type = strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_CONTENT_TYPE'])));
        }

        if ($content_type !== '' && strpos($content_type, 'multipart/') === 0) {
            // Multipart without $_FILES['chunk']: body was stripped or never parsed as a file.
            self::import_error(
                __('Empty chunk. Multipart upload arrived without a usable file part.', 'rosenheinrich-multisite-migrate'),
                array_merge($err_ctx, array('phase' => 'chunk', 'reason' => 'multipart_missing_file'))
            );
        }

        $chunk = Rmmigrate_Filesystem::read_request_body();
        if (!is_string($chunk) || $chunk === '') {
            self::import_error(
                __('Empty chunk. No raw request body and no uploaded file part.', 'rosenheinrich-multisite-migrate'),
                array_merge($err_ctx, array('phase' => 'chunk', 'reason' => 'body_empty'))
            );
        }

        return $chunk;
    }

    /**
     * @return int|'offset_mismatch'|false|WP_Error
     */
    private static function append_import_chunk(string $part_path, string $chunk, int $chunk_index, int $expected_offset)
    {
        $fh = Rmmigrate_Filesystem::open_lock($part_path, 'cb');
        if ($fh === false || !Rmmigrate_Filesystem::try_exclusive_lock($fh)) {
            if (is_resource($fh)) {
                Rmmigrate_Filesystem::release_lock($fh);
            }
            return false;
        }

        clearstatcache(true, $part_path);
        $current_size = (int) @filesize($part_path);
        if ($chunk_index > 0 && $expected_offset <= 0) {
            Rmmigrate_Filesystem::release_lock($fh);
            return 'offset_mismatch';
        }
        if ($chunk_index > 0 && $expected_offset > 0) {
            if ($current_size > $expected_offset) {
                // Duplicate chunk retry or stale bytes after connection timeout: truncate back to expected_offset.
                ftruncate($fh, $expected_offset);
                fseek($fh, $expected_offset, SEEK_SET);
                clearstatcache(true, $part_path);
                $current_size = (int) @filesize($part_path);
            } elseif ($current_size < $expected_offset) {
                Rmmigrate_Filesystem::release_lock($fh);
                return 'offset_mismatch';
            }
        }

        $chunk_len = strlen($chunk);
        $disk = Rmmigrate_Validator::validate_disk_space_for_bytes($current_size + $chunk_len);
        if (is_wp_error($disk)) {
            Rmmigrate_Filesystem::release_lock($fh);
            return $disk;
        }

        if ($current_size > 0) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Plugin: centralized filesystem gateway.
            fseek($fh, 0, SEEK_END);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Plugin: centralized filesystem gateway.
        $written = fwrite($fh, $chunk);
        if ($written === false || $written !== $chunk_len) {
            if ($current_size >= 0) {
                RMMIGRATE_IO::truncate_file($part_path, $current_size);
            }
            Rmmigrate_Filesystem::release_lock($fh);
            return false;
        }
        Rmmigrate_Filesystem::release_lock($fh);

        clearstatcache(true, $part_path);
        return (int) @filesize($part_path);
    }

    public static function import_and_restore(): void
    {
        self::verify_request();
        self::assert_network_management();
        self::assert_import_access();

        if (!defined('WP_IMPORTING')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core import flag required during restore/import.
            define('WP_IMPORTING', true);
        }

        $job_id = Rmmigrate_Request_Input::post_int('job_id');
        Rmmigrate_Logger::log_activity(
            'restore',
            sprintf(
                /* translators: %d: backup job ID */
                __('Initiated restore for imported job #%d', 'rosenheinrich-multisite-migrate'),
                $job_id
            ),
            'info',
            array('job_id' => $job_id)
        );
        Rmmigrate_Logger::log_system(sprintf('Initiated restore for imported job #%d', $job_id));
        try {
            $result = Rmmigrate_Import_Service::import_and_restore($job_id);
            wp_send_json_success($result);
        } catch (Rmmigrate_Service_Exception $e) {
            self::restore_error($e->getMessage(), $job_id, array('phase' => 'start', 'service_code' => $e->get_code_key()));
        }
    }
}
