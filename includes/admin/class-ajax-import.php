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
        self::verify_request();
        self::assert_import_access();

        if (!defined('WP_IMPORTING')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core import flag required during restore/import.
            define('WP_IMPORTING', true);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via self::verify_request().
        if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0) {
            wp_send_json_error(array(
                'message'  => __('Upload payload exceeds server PHP limits. Reducing chunk size…', 'rosenheinrich-multisite-migrate'),
                'downsize' => true,
            ));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via self::verify_request().
        if (isset($_FILES['chunk']['error']) && $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via self::verify_request().
            $err_code = (int) $_FILES['chunk']['error'];
            if ($err_code === UPLOAD_ERR_INI_SIZE || $err_code === UPLOAD_ERR_FORM_SIZE) {
                wp_send_json_error(array(
                    'message'  => __('Chunk size exceeds server PHP limits. Reducing chunk size…', 'rosenheinrich-multisite-migrate'),
                    'downsize' => true,
                ));
            }
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

        $chunk = Rmmigrate_Filesystem::read_request_body();
        if (!is_string($chunk) || $chunk === '') {
            $chunk = '';
            $tmp = Rmmigrate_Request_Input::file_tmp_name('chunk');
            if ($tmp !== '') {
                $file_chunk = Rmmigrate_Filesystem::get_contents($tmp);
                if (is_string($file_chunk) && $file_chunk !== '') {
                    $chunk = $file_chunk;
                }
            }
        }
        if ($chunk === '') {
            self::import_error(__('Empty chunk.', 'rosenheinrich-multisite-migrate'), $err_ctx);
        }
        if (strlen($chunk) > Rmmigrate_Extract_Engine::BLOCKING_SAFE_BYTES) {
            self::import_error(__('Chunk exceeds maximum allowed size.', 'rosenheinrich-multisite-migrate'), $err_ctx);
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
        if ($chunk_index > 0 && $expected_offset > 0 && $current_size !== $expected_offset) {
            Rmmigrate_Filesystem::release_lock($fh);
            return 'offset_mismatch';
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
