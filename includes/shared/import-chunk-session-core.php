<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side chunked import upload session (user-bound upload_id + expected file size).
 * Keep in sync with pro/trunk/includes/shared/import-chunk-session-core.php.
 */
if (!class_exists('Rmmigrate_Import_Chunk_Session_Core', false)) {
    final class Rmmigrate_Import_Chunk_Session_Core
    {
    public const TRANSIENT_PREFIX = 'rmmigrate_import_chunk_';

    /** @var int */
    public const MAX_TOTAL_BYTES = 107374182400;

    /** @var int */
    public const MAX_CHUNK_COUNT = 500000;

    public static function mint_upload_id(int $user_id): string
    {
        $user_id = max(0, $user_id);
        $token = bin2hex(random_bytes(16));

        return 'u' . $user_id . '_' . $token;
    }

    public static function upload_id_owned_by_user(string $upload_id, int $user_id): bool
    {
        if (!self::is_valid_upload_id($upload_id)) {
            return false;
        }
        $prefix = 'u' . max(0, $user_id) . '_';

        return strncmp($upload_id, $prefix, strlen($prefix)) === 0;
    }

    public static function is_valid_upload_id(string $upload_id): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $upload_id);
    }

    /**
     * @return array{user_id:int,file_total_bytes:int,total_chunks:int,filename:string,ext:string}|null
     */
    public static function get_session(int $user_id, string $upload_id): ?array
    {
        $raw = get_site_transient(self::transient_key($user_id, $upload_id));
        if (!is_array($raw)) {
            return null;
        }
        if ((int) ($raw['user_id'] ?? 0) !== $user_id) {
            return null;
        }

        return array(
            'user_id'          => $user_id,
            'file_total_bytes' => (int) ($raw['file_total_bytes'] ?? 0),
            'total_chunks'     => (int) ($raw['total_chunks'] ?? 0),
            'filename'         => (string) ($raw['filename'] ?? ''),
            'ext'              => (string) ($raw['ext'] ?? ''),
        );
    }

    /**
     * @return array{ok:true,session:array<string,mixed>}|array{ok:false,code:string}
     */
    public static function begin_or_validate_session(
        int $user_id,
        string $upload_id,
        int $file_total_bytes,
        int $total_chunks,
        string $filename,
        string $ext,
        bool $is_chunk_zero
    ): array {
        if ($user_id <= 0) {
            return array('ok' => false, 'code' => 'user');
        }
        if ($file_total_bytes <= 0 || $file_total_bytes > self::MAX_TOTAL_BYTES) {
            return array('ok' => false, 'code' => 'file_total_bytes');
        }
        if ($total_chunks < 1 || $total_chunks > self::MAX_CHUNK_COUNT) {
            return array('ok' => false, 'code' => 'total_chunks');
        }
        if ($total_chunks > $file_total_bytes) {
            return array('ok' => false, 'code' => 'total_chunks');
        }

        $existing = self::get_session($user_id, $upload_id);
        if ($existing !== null) {
            if ($existing['file_total_bytes'] !== $file_total_bytes
                || $existing['total_chunks'] !== $total_chunks
                || $existing['filename'] !== $filename
                || $existing['ext'] !== $ext) {
                return array('ok' => false, 'code' => 'session_mismatch');
            }

            return array('ok' => true, 'session' => $existing);
        }

        if (!$is_chunk_zero) {
            return array('ok' => false, 'code' => 'session_missing');
        }

        $session = array(
            'user_id'          => $user_id,
            'file_total_bytes' => $file_total_bytes,
            'total_chunks'     => $total_chunks,
            'filename'         => $filename,
            'ext'              => $ext,
        );
        set_site_transient(
            self::transient_key($user_id, $upload_id),
            $session,
            defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600
        );

        return array('ok' => true, 'session' => $session);
    }

    public static function delete_session(int $user_id, string $upload_id): void
    {
        delete_site_transient(self::transient_key($user_id, $upload_id));
    }

    public static function transient_key(int $user_id, string $upload_id): string
    {
        return self::TRANSIENT_PREFIX . md5($user_id . '|' . $upload_id);
    }

    /**
     * @param array{file_total_bytes:int,total_chunks:int} $session
     */
    public static function validate_completed_size(array $session, int $part_bytes): bool
    {
        return $part_bytes === (int) $session['file_total_bytes'];
    }

    /**
     * @param array{file_total_bytes:int,total_chunks:int} $session
     */
    public static function would_exceed_total(array $session, int $part_bytes_after_append): bool
    {
        return $part_bytes_after_append > (int) $session['file_total_bytes'];
    }
}
}
