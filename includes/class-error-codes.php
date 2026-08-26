<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stable internal keys for job/telemetry error diagnostics.
 */
final class Rmmigrate_Error_Codes
{
    const ACTIVE_JOB_CONFLICT       = 'active_job_conflict';
    const JOB_TABLE_MISSING         = 'job_table_missing';
    const JOB_CREATE_FAILED         = 'job_create_failed';
    const IMPORT_REGISTER_FAILED    = 'import_register_failed';
    const TIME_LIMIT                = 'time_limit';
    const DISK_SPACE                = 'disk_space';
    const ENCRYPT_FAILED            = 'encrypt_failed';
    const ARCHIVE_VALIDATION_FAILED = 'archive_validation_failed';
    const INCREMENTAL_PRO_ONLY      = 'incremental_pro_only';
    const ARCHIVE_MISSING           = 'archive_missing';
    const DECRYPT_PASSPHRASE         = 'decrypt_passphrase';
    const RESTORE_GATE_BLOCKED      = 'restore_gate_blocked';
    const INVALID_RESTORE_MODE      = 'invalid_restore_mode';
    const MIGRATION_MAP_REQUIRED    = 'migration_map_required';
    const SOURCE_NOT_FOUND          = 'source_not_found';
    const SOURCE_NOT_COMPLETE       = 'source_not_complete';
    const INVALID_SOURCE_JOB        = 'invalid_source_job';
    const RESTORE_SCOPE_DENIED      = 'restore_scope_denied';
    const NETWORK_MIGRATION_DENIED  = 'network_migration_denied';
    const DATABASE_SQL_MISSING      = 'database_sql_missing';
    const DATABASE_SQL_UNREADABLE   = 'database_sql_unreadable';
    const SQL_IMPORT_FAILED         = 'sql_import_failed';
    const EXTRACT_FAILED            = 'extract_failed';
    const DAF_CORRUPT               = 'daf_corrupt';
    const ZIP_EXTENSION             = 'zip_extension';
    const FILES_TREE_MISSING        = 'files_tree_missing';
    const FILE_RESTORE_FAILED       = 'file_restore_failed';
    const WORK_DIR_FAILED           = 'work_dir_failed';
    const STALE_WORKER              = 'stale_worker';
    const WORKER_STOPPED            = 'worker_stopped';
    const MEMORY_LIMIT              = 'memory_limit';
    const PERMISSION_DENIED         = 'permission_denied';
    const LOCK_FAILED               = 'lock_failed';
    const TIMEOUT                   = 'timeout';
    const VALIDATION                = 'validation';
    const ROW_TOO_LARGE                 = 'row_too_large';
    const NETWORK_OVERWRITE_DB_ACTION   = 'network_overwrite_db_action';
    const CLOUD_NOT_CONNECTED           = 'cloud_not_connected';
    const REMOTE_FILE_MISSING           = 'remote_file_missing';
    const INSTALLER_FAILED              = 'installer_failed';
    const STAGING_MULTISITE_ONLY        = 'staging_multisite_only';
    const UPLOAD_FAILED                 = 'upload_failed';
    const UNKNOWN                   = 'unknown';

    public static function from_throwable(Throwable $e): string
    {
        if ($e instanceof Rmmigrate_Job_Exception) {
            return $e->get_service_code();
        }
        if ($e instanceof Rmmigrate_Service_Exception) {
            return sanitize_key($e->get_code_key());
        }

        return self::from_message($e->getMessage());
    }

    /**
     * True when PHP fatal text is a max_execution_time kill (soft-resumable mid-slice).
     */
    public static function is_php_execution_timeout(string $message): bool
    {
        $lower = strtolower(wp_strip_all_tags($message));
        return strpos($lower, 'maximum execution time') !== false
            || strpos($lower, 'max_execution_time') !== false;
    }

    public static function from_wp_error(WP_Error $error): string
    {
        $code = sanitize_key($error->get_error_code());
        if ($code !== '') {
            return $code;
        }

        return self::from_message($error->get_error_message());
    }

    public static function from_message(string $message): string
    {
        if ($message === '') {
            return '';
        }
        $lower = strtolower(wp_strip_all_tags($message));
        if (strpos($lower, 'backup or restore is already in progress') !== false) {
            return self::ACTIVE_JOB_CONFLICT;
        }
        if (strpos($lower, 'backup database table is missing') !== false) {
            return self::JOB_TABLE_MISSING;
        }
        if (strpos($lower, 'failed to create backup job') !== false
            || strpos($lower, 'failed to create restore job') !== false
            || strpos($lower, 'failed to register import') !== false) {
            return self::JOB_CREATE_FAILED;
        }
        if (strpos($lower, 'backup build exceeded maximum time limit') !== false
            || self::is_php_execution_timeout($message)
            || strpos($lower, 'hit php max_execution_time') !== false) {
            return self::TIME_LIMIT;
        }
        if (strpos($lower, 'insufficient disk space') !== false || strpos($lower, 'disk space') !== false) {
            return self::DISK_SPACE;
        }
        if (strpos($lower, 'could not encrypt backup archive') !== false) {
            return self::ENCRYPT_FAILED;
        }
        if (strpos($lower, 'failed to decrypt backup archive') !== false
            || strpos($lower, 'decrypt') !== false
            || strpos($lower, 'passphrase') !== false) {
            return self::DECRYPT_PASSPHRASE;
        }
        if (strpos($lower, 'incremental restore requires') !== false
            || strpos($lower, 'incremental backups can only be restored') !== false) {
            return self::INCREMENTAL_PRO_ONLY;
        }
        if (strpos($lower, 'source backup not found') !== false) {
            return self::SOURCE_NOT_FOUND;
        }
        if (strpos($lower, 'source backup is not complete') !== false
            || strpos($lower, 'backup not found or not complete') !== false) {
            return self::SOURCE_NOT_COMPLETE;
        }
        if (strpos($lower, 'invalid source job') !== false) {
            return self::INVALID_SOURCE_JOB;
        }
        if (strpos($lower, 'invalid restore mode') !== false) {
            return self::INVALID_RESTORE_MODE;
        }
        if (strpos($lower, 'migration requires url mapping') !== false) {
            return self::MIGRATION_MAP_REQUIRED;
        }
        if (strpos($lower, 'cannot restore another subsite backup') !== false) {
            return self::RESTORE_SCOPE_DENIED;
        }
        if (strpos($lower, 'network migration requires super admin') !== false) {
            return self::NETWORK_MIGRATION_DENIED;
        }
        if (strpos($lower, 'database.sql not found') !== false) {
            return self::DATABASE_SQL_MISSING;
        }
        if (strpos($lower, 'cannot read database.sql') !== false) {
            return self::DATABASE_SQL_UNREADABLE;
        }
        if (strpos($lower, 'no files/ directory') !== false || strpos($lower, 'no files directory') !== false) {
            return self::FILES_TREE_MISSING;
        }
        if (strpos($lower, 'daf archive failed its integrity check') !== false
            || strpos($lower, 'daf archive block is corrupt') !== false
            || strpos($lower, 'invalid daf archive') !== false) {
            return self::DAF_CORRUPT;
        }
        if (strpos($lower, 'ziparchive') !== false || strpos($lower, 'zip extension') !== false) {
            return self::ZIP_EXTENSION;
        }
        if (strpos($lower, 'install token') !== false) {
            return 'install_token';
        }
        if (strpos($lower, 'archive not found') !== false
            || strpos($lower, 'no backup archives') !== false
            || strpos($lower, 'backup archive not found') !== false) {
            return self::ARCHIVE_MISSING;
        }
        if (strpos($lower, 'cannot open backup archive') !== false
            || strpos($lower, 'cannot extract backup archive') !== false
            || strpos($lower, 'cannot extract archive entry') !== false
            || strpos($lower, 'shell unzip') !== false
            || strpos($lower, 'unsafe path') !== false) {
            return self::EXTRACT_FAILED;
        }
        if (strpos($lower, 'cannot write extracted file') !== false
            || strpos($lower, 'cannot restore file') !== false
            || strpos($lower, 'failed to restore file') !== false
            || strpos($lower, 'too many missing files') !== false
            || strpos($lower, 'datei konnte nicht wiederhergestellt') !== false) {
            return self::FILE_RESTORE_FAILED;
        }
        if (strpos($lower, 'could not create backup working directory') !== false
            || strpos($lower, 'could not initialize backup storage') !== false) {
            return self::WORK_DIR_FAILED;
        }
        if (strpos($lower, 'connection failed') !== false || strpos($lower, 'mysqli') !== false) {
            return 'db_connection';
        }
        if (strpos($lower, 'memory') !== false || strpos($lower, 'allowed memory size') !== false) {
            return self::MEMORY_LIMIT;
        }
        if (strpos($lower, 'worker stopped responding') !== false) {
            return self::STALE_WORKER;
        }
        if (strpos($lower, 'worker stopped unexpectedly') !== false
            || strpos($lower, 'worker stopped before the job finished') !== false) {
            return self::WORKER_STOPPED;
        }
        if (strpos($lower, 'permission denied') !== false || strpos($lower, 'do not have permission') !== false) {
            return self::PERMISSION_DENIED;
        }
        if (strpos($lower, 'lock') !== false && strpos($lower, 'fail') !== false) {
            return self::LOCK_FAILED;
        }
        if (strpos($lower, 'timeout') !== false || strpos($lower, 'timed out') !== false) {
            return self::TIMEOUT;
        }
        if (strpos($lower, 'invalid backup archive') !== false
            || strpos($lower, 'invalid file extension') !== false
            || strpos($lower, 'database error') !== false) {
            return self::VALIDATION;
        }
        if (strpos($lower, 'database row too large') !== false) {
            return self::ROW_TOO_LARGE;
        }
        if (strpos($lower, 'replacing an entire multisite') !== false
            || strpos($lower, 'empty entire database') !== false) {
            return self::NETWORK_OVERWRITE_DB_ACTION;
        }
        if (strpos($lower, 'google drive not connected') !== false
            || strpos($lower, 'not connected') !== false && strpos($lower, 'drive') !== false) {
            return self::CLOUD_NOT_CONNECTED;
        }
        if (strpos($lower, 'missing remote file') !== false) {
            return self::REMOTE_FILE_MISSING;
        }
        if (strpos($lower, 'installer template is missing') !== false
            || strpos($lower, 'could not build installer') !== false
            || strpos($lower, 'could not create installer package') !== false
            || strpos($lower, 'bootstrapper template is missing') !== false) {
            return self::INSTALLER_FAILED;
        }
        if (strpos($lower, 'staging sites are supported on single-site') !== false) {
            return self::STAGING_MULTISITE_ONLY;
        }
        if (strpos($lower, 'could not copy archive for installer') !== false
            || strpos($lower, 'could not write installer entry') !== false
            || strpos($lower, 'installer deploy') !== false) {
            return self::INSTALLER_FAILED;
        }
        if (strpos($lower, 'cloud upload failed') !== false || strpos($lower, 'remote upload failed') !== false) {
            return self::UPLOAD_FAILED;
        }
        if (strpos($lower, 'sql import') !== false || strpos($lower, 'database import') !== false
            || strpos($lower, 'database restore failed') !== false) {
            return self::SQL_IMPORT_FAILED;
        }

        return self::UNKNOWN;
    }
}
