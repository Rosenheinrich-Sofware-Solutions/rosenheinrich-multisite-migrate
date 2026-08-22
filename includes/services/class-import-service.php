<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Free wp.org edition: local import only.
 *
 * Remote/cloud/URL import lives in the separate Multisite Migrate Pro plugin
 * (includes/services/class-import-service.php in the commercial build).
 */
final class Rmmigrate_Import_Service
{
    /**
     * Validate an archive already in imports/ and register the job.
     *
     * @return array{job_id:int}
     */
    public static function finalize_import_file(string $path, string $passphrase = ''): array
    {
        return self::register_validated_import($path, $passphrase);
    }

    /**
     * @return array{job_id:int}
     */
    public static function import_local_path(string $source_path, string $passphrase = ''): array
    {
        if ($source_path === '' || !Rmmigrate_Filesystem::exists($source_path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('File not found.', 'rosenheinrich-multisite-migrate')),
                array('phase' => 'import'), sanitize_key(Rmmigrate_Error_Codes::ARCHIVE_MISSING));
        }
        $name = basename($source_path);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, array('zip', 'daf', 'venc'), true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Invalid file extension. Only .zip, .daf, and .venc files are allowed.', 'rosenheinrich-multisite-migrate')),
                array('phase' => 'import'), sanitize_key(Rmmigrate_Error_Codes::VALIDATION));
        }

        Rmmigrate_Plugin::ensure_backup_root();
        $import_dir = Rmmigrate_Plugin::backups_dir() . '/imports/';
        wp_mkdir_p($import_dir);
        $dest = $import_dir . $name;

        if (wp_normalize_path(realpath($source_path) ?: $source_path) === wp_normalize_path($dest)) {
            return self::register_validated_import($dest, $passphrase);
        }

        $size = (int) Rmmigrate_Filesystem::filesize($source_path);
        $disk = Rmmigrate_Validator::validate_import_disk_space($dest, $size);
        if (is_wp_error($disk)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($disk->get_error_message()),
                array(), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($disk)));
        }

        if (!copy($source_path, $dest)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Import copy failed.', 'rosenheinrich-multisite-migrate')),
                array('phase' => 'import'), sanitize_key(Rmmigrate_Error_Codes::IMPORT_REGISTER_FAILED));
        }

        return self::register_validated_import($dest, $passphrase);
    }

    /**
     * @param array<string,mixed> $restore_params
     * @return array<string,mixed>
     */
    public static function import_and_restore(int $job_id, bool $via_worker = false, array $restore_params = array()): array
    {
        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('Imported backup not found.', 'rosenheinrich-multisite-migrate')),
                array('phase' => 'import'), sanitize_key(Rmmigrate_Error_Codes::ARCHIVE_MISSING));
        }
        Rmmigrate_Job_Preflight::assert_can_view_job($job);

        unset($via_worker);

        $params = array_merge(
            Rmmigrate_Restore_Service::quick_restore_defaults(),
            Rmmigrate_Access::subsite_restore_params($restore_params)
        );
        $params['source_job_id'] = $job_id;
        $params['kick_worker'] = true;
        return Rmmigrate_Restore_Service::start_restore($params);
    }

    /**
     * @return true|WP_Error
     */
    public static function validate_import_file(string $path, string $archive_passphrase = '')
    {
        $probe = Rmmigrate_Validator::probe_archive($path);
        if ($probe === null) {
            Rmmigrate_Filesystem::delete($path);
            return new WP_Error('mm_invalid_archive', __('Invalid backup archive. Upload a .zip, .daf, or .venc file.', 'rosenheinrich-multisite-migrate'));
        }

        if (($probe['type'] ?? '') === 'venc') {
            if ($archive_passphrase === '') {
                Rmmigrate_Filesystem::delete($path);
                return new WP_Error('mm_passphrase', __('Enter the archive passphrase for encrypted .venc files.', 'rosenheinrich-multisite-migrate'));
            }
            $plain_path = $path . '.import-probe.tmp';
            Rmmigrate_Archive_Encryption::set_runtime_passphrase($archive_passphrase);
            $decrypted = Rmmigrate_Archive_Encryption::decrypt_file($path, $plain_path);
            Rmmigrate_Archive_Encryption::clear_runtime_passphrase();
            if (!$decrypted || !Rmmigrate_Filesystem::exists($plain_path)) {
                Rmmigrate_Filesystem::delete($plain_path);
                Rmmigrate_Filesystem::delete($path);
                return new WP_Error('mm_passphrase', __('Incorrect archive passphrase.', 'rosenheinrich-multisite-migrate'));
            }
            $readable = Rmmigrate_Validator::validate_archive_readable($plain_path);
            Rmmigrate_Filesystem::delete($plain_path);
            if (is_wp_error($readable)) {
                Rmmigrate_Filesystem::delete($path);
                return $readable;
            }
            return true;
        }

        $readable = Rmmigrate_Validator::validate_archive_readable($path);
        if (is_wp_error($readable)) {
            Rmmigrate_Filesystem::delete($path);
            return $readable;
        }
        return true;
    }

    /**
     * @return array{job_id:int}
     */
    private static function register_validated_import(
        string $path,
        string $passphrase = ''
    ): array {
        $validated = self::validate_import_file($path, $passphrase);
        if (is_wp_error($validated)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($validated->get_error_message()),
                array(), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($validated)));
        }

        Rmmigrate_Access::assert_subsite_import_archive($path);

        $rel = 'imports/' . basename($path);
        $job = Rmmigrate_Job::register_imported($rel, (int) Rmmigrate_Filesystem::filesize($path));

        self::record_import_success($job);
        return array('job_id' => $job->get_id());
    }

    private static function record_import_success(Rmmigrate_Job $job): void
    {
        $msg = sprintf(
            /* translators: 1: job ID, 2: file size in MB */
            __('Import upload & validation completed for job #%1$d (%2$s MB)', 'rosenheinrich-multisite-migrate'),
            $job->get_id(),
            number_format($job->get_file_size() / 1048576, 1)
        );
        Rmmigrate_Activity_Log::record(
            'import',
            $msg,
            'success',
            array('job_id' => $job->get_id())
        );
        Rmmigrate_Logger::log_system(sprintf('Import job #%d registered successfully (%d bytes)', $job->get_id(), $job->get_file_size()));
        Rmmigrate_Logger::log_job($job->get_id(), sprintf('Import archive registered: %s (%d bytes)', $job->get_local_path(), $job->get_file_size()));
        /**
         * Fires after an import archive is registered as a completed job.
         *
         * @param Rmmigrate_Job $job Import job.
         */
        do_action('rmmigrate_import_registered', $job);
        Rmmigrate_Notifications::maybe_send_event(
            'import',
            sprintf(
                /* translators: %d: job ID */
                __('Import completed for job #%1$d', 'rosenheinrich-multisite-migrate'),
                $job->get_id()
            ),
            true
        );
    }
}
