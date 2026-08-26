<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stream handle returned by Rmmigrate_Filesystem::open().
 * Uses file_get_contents / file_put_contents only (Plugin Check compliant).
 */
final class Rmmigrate_Filesystem_Stream
{
    /** @var string */
    private $path;

    /** @var string */
    private $mode;

    /** @var int */
    private $position = 0;

    /** @var bool */
    private $truncate_pending = false;

    public function __construct(string $path, string $mode)
    {
        $this->path   = $path;
        $this->mode   = $mode;
        $this->truncate_pending = (strpos($mode, 'w') !== false && strpos($mode, '+') === false);
    }

    /**
     * @return string|false
     */
    public function read(int $length)
    {
        if (strpos($this->mode, 'r') === false && strpos($this->mode, '+') === false) {
            return false;
        }
        if (!self::path_exists($this->path)) {
            return false;
        }
        $data = file_get_contents($this->path, false, null, $this->position, $length);
        if ($data === false) {
            return false;
        }
        $this->position += strlen($data);
        return $data;
    }

    /**
     * @return int|false
     */
    public function write(string $data)
    {
        $len = strlen($data);
        if ($len === 0) {
            return 0;
        }

        if (!Rmmigrate_Filesystem::ensure_parent_dir($this->path)) {
            return false;
        }

        if ($this->truncate_pending && $this->position === 0) {
            // No LOCK_EX: workers are single-writer per path; exclusive locks hang under AV/NFS and can blow max_execution_time.
            if (file_put_contents($this->path, $data) === false) {
                return false;
            }
            $this->truncate_pending = false;
            $this->position         = $len;
            return $len;
        }

        if (strpos($this->mode, 'a') !== false) {
            if (file_put_contents($this->path, $data, FILE_APPEND) === false) {
                return false;
            }
            $this->position += $len;
            return $len;
        }

        // Mid-file write: use native fopen/fseek/fwrite to avoid loading the
        // entire file into memory (OOM risk on large SQL dumps).
        // No flock: single-writer workers; blocking LOCK_EX hangs under AV (Laragon)
        // and can exceed PHP max_execution_time mid-extract.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Plugin: centralized filesystem gateway.
        $fh = @fopen($this->path, 'c+b');
        if ($fh === false) {
            return false;
        }
        fseek($fh, $this->position);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Plugin: centralized filesystem gateway.
        $written = fwrite($fh, $data);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
        fclose($fh);
        if ($written === false) {
            return false;
        }
        $this->position += $len;
        return $len;
    }

    public function close(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): int
    {
        $size = self::path_exists($this->path) ? (int) filesize($this->path) : 0;
        if ($whence === SEEK_CUR) {
            $this->position += $offset;
        } elseif ($whence === SEEK_END) {
            $this->position = $size + $offset;
        } else {
            $this->position = $offset;
        }
        if ($this->position < 0) {
            $this->position = 0;
        }
        return 0;
    }

    /**
     * @return int|false
     */
    public function tell()
    {
        return $this->position;
    }

    public function eof(): bool
    {
        if (!self::path_exists($this->path)) {
            return true;
        }
        return $this->position >= (int) filesize($this->path);
    }

    public function truncate(int $size): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Plugin: centralized filesystem gateway.
        $fh = @fopen($this->path, 'c+b');
        if ($fh === false) {
            return false;
        }
        if (flock($fh, LOCK_EX)) {
            $result = ftruncate($fh, $size);
            flock($fh, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
            fclose($fh);
            if ($result && $this->position > $size) {
                $this->position = $size;
            }
            return $result;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
        fclose($fh);
        return false;
    }

    /**
     * @return resource|null Legacy callers using stream_copy_to_stream().
     */
    public function resource()
    {
        return null;
    }

    private static function path_exists(string $path): bool
    {
        return file_exists($path) && is_file($path);
    }
}

/**
 * WordPress filesystem wrapper — WP_Filesystem first, file_get/put_contents fallback.
 */
class Rmmigrate_Filesystem
{
    /** @var bool */
    private static $initialized = false;

    /** @var bool */
    private static $available = false;

    public static function init(): bool
    {
        if (self::$initialized) {
            return self::$available;
        }
        self::$initialized = true;

        global $wp_filesystem;
        $file_admin = ABSPATH . 'wp-admin/includes/file.php';
        if (!function_exists('WP_Filesystem') && is_readable($file_admin)) {
            require_once $file_admin;
        }
        if (!function_exists('WP_Filesystem')) {
            self::$available = false;
            return false;
        }

        ob_start();
        $booted = WP_Filesystem();
        ob_end_clean();

        self::$available = $booted && is_object($wp_filesystem);
        return self::$available;
    }

    /**
     * @return WP_Filesystem_Base|null
     */
    private static function fs()
    {
        if (!self::init()) {
            return null;
        }
        global $wp_filesystem;
        return is_object($wp_filesystem) ? $wp_filesystem : null;
    }

    public static function exists(string $path): bool
    {
        $fs = self::fs();
        if ($fs !== null) {
            return $fs->exists($path);
        }
        return file_exists($path);
    }

    public static function is_readable(string $path): bool
    {
        $fs = self::fs();
        if ($fs !== null) {
            return $fs->is_readable($path);
        }
        return is_readable($path);
    }

    public static function filesize(string $path): int
    {
        $fs = self::fs();
        if ($fs !== null) {
            return (int) $fs->size($path);
        }
        if (!file_exists($path)) {
            return 0;
        }
        return (int) filesize($path);
    }

    public static function file_md5(string $path): string
    {
        if (!self::exists($path) || !self::is_file($path)) {
            return '';
        }
        $hash = md5_file($path);
        return is_string($hash) ? $hash : '';
    }

    public static function files_identical(string $source, string $destination): bool
    {
        if (!self::is_file($source) || !self::is_file($destination)) {
            return false;
        }

        $size = self::filesize($source);
        if ($size !== self::filesize($destination)) {
            return false;
        }
        if ($size === 0) {
            return true;
        }

        $source_hash = self::file_md5($source);
        return $source_hash !== '' && $source_hash === self::file_md5($destination);
    }

    public static function delete(string $path): bool
    {
        $fs = self::fs();
        if ($fs !== null) {
            if (!$fs->exists($path)) {
                return true;
            }
            return (bool) $fs->delete($path, false, 'f');
        }
        if (!file_exists($path)) {
            return true;
        }
        if (function_exists('wp_delete_file')) {
            return (bool) wp_delete_file($path);
        }
        return false;
    }

    public static function ensure_parent_dir(string $path): bool
    {
        $dir = dirname($path);
        if ($dir === '' || $dir === '.' || is_dir($dir)) {
            return true;
        }
        return self::ensure_directory($dir);
    }

    /**
     * Create a directory, removing a same-path file left by a broken install.
     */
    public static function ensure_directory(string $path): bool
    {
        $path = wp_normalize_path($path);
        if ($path === '' || $path === '.' || $path === '/') {
            return false;
        }
        if (file_exists($path) && !is_dir($path)) {
            self::delete($path);
        }
        if (is_dir($path)) {
            return true;
        }
        if (!function_exists('wp_mkdir_p')) {
            return false;
        }
        return (bool) wp_mkdir_p($path);
    }

    /**
     * @return int|false Bytes written.
     */
    public static function put_contents(string $path, string $data, int $flags = 0)
    {
        if (!self::ensure_parent_dir($path)) {
            return false;
        }

        // WP_Filesystem does not natively support FILE_APPEND.
        // Buffering the entire file into memory causes fatal memory exhaustion for large DB exports.
        // We must use native PHP file_put_contents for appending.
        if ($flags & FILE_APPEND) {
            return file_put_contents($path, $data, $flags);
        }

        $fs = self::fs();
        if ($fs !== null) {
            $ok = $fs->put_contents($path, $data, FS_CHMOD_FILE);
            return $ok ? strlen($data) : false;
        }
        return file_put_contents($path, $data, $flags);
    }

    /**
     * @return string|false
     */
    public static function get_contents(string $path, int $offset = 0, ?int $length = null)
    {
        if ($offset === 0 && $length === null) {
            $fs = self::fs();
            if ($fs !== null) {
                $contents = $fs->get_contents($path);
                return $contents !== false ? $contents : false;
            }
        }

        if ($length === null) {
            return file_get_contents($path);
        }
        return file_get_contents($path, false, null, $offset, $length);
    }

    /**
     * @return Rmmigrate_Filesystem_Stream|false
     */
    public static function open(string $path, string $mode)
    {
        if (!self::ensure_parent_dir($path)) {
            return false;
        }
        return new Rmmigrate_Filesystem_Stream($path, $mode);
    }

    public static function move(string $source, string $destination): bool
    {
        $fs = self::fs();
        if ($fs !== null) {
            return (bool) $fs->move($source, $destination, true);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Plugin: WP_Filesystem::move fallback in filesystem gateway.
        return @rename($source, $destination);
    }

    public static function copy(string $source, string $destination): bool
    {
        try {
            $fs = self::fs();
            if ($fs !== null && $fs->copy($source, $destination, true, FS_CHMOD_FILE)) {
                return true;
            }
            // Small-file fallback: WP_Filesystem::copy can fail on root dotfiles
            // (.htaccess) under some hosts / AV while get/put still works. Cap size
            // so multi-GB archives never load into memory here.
            $size = @filesize($source);
            if ($size === false || $size < 0 || $size > 2 * 1024 * 1024) {
                return false;
            }
            $contents = self::get_contents($source);
            if ($contents === false) {
                return false;
            }
            return self::put_contents($destination, $contents) !== false;
        } catch (\Throwable $e) {
            unset($e);
            return false;
        }
    }

    /**
     * Read raw HTTP request body (chunked import uploads).
     *
     * @return string|false
     */
    public static function read_request_body()
    {
        return file_get_contents('php://input');
    }

    /**
     * Store a PHP upload temp file into a destination path.
     */
    public static function store_uploaded_file(string $tmp_path, string $destination): bool
    {
        if ($tmp_path === '' || !Rmmigrate_Request_Input::is_uploaded_tmp($tmp_path)) {
            return false;
        }
        return self::copy($tmp_path, $destination);
    }

    public static function is_writable(string $path): bool
    {
        $fs = self::fs();
        if ($fs !== null) {
            return (bool) $fs->is_writable($path);
        }
        return false;
    }

    public static function is_dir(string $path): bool
    {
        $fs = self::fs();
        if ($fs !== null) {
            return (bool) $fs->is_dir($path);
        }
        return is_dir($path);
    }

    public static function is_file(string $path): bool
    {
        $fs = self::fs();
        if ($fs !== null) {
            return (bool) $fs->is_file($path);
        }
        return is_file($path);
    }

    /**
     * Stream a local file to stdout without readfile().
     */
    public static function stream_to_stdout(string $path): bool
    {
        $size = self::filesize($path);
        if ($size <= 0) {
            $contents = self::get_contents($path);
            if ($contents === false) {
                return false;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary archive stream to stdout.
            echo $contents;
            return true;
        }

        // Archives are already compressed; re-compressing them in PHP/the SAPI
        // wastes large amounts of CPU and is the main reason downloads crawl.
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Disable output compression for a binary passthrough only.
        @ini_set('zlib.output_compression', 'Off');
        // Tell any downstream proxy/SAPI not to re-encode the (already-compressed)
        // archive body, matching the disabled gzip above.
        if (!headers_sent()) {
            header('Content-Encoding: identity');
        }
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large downloads must not be killed by the default time limit.
            @set_time_limit(0);
        }

        // Open the handle once and stream sequentially. The previous
        // implementation re-opened the file for every 1 MB chunk via
        // file_get_contents() with an offset, which is O(n) opens/seeks on large
        // archives and made multi-GB downloads extremely slow.
        $handle = self::fopen_raw($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $chunk = 8 * 1024 * 1024;
        $ok = true;
        while (true) {
            $slice = self::fread_raw($handle, $chunk);
            if ($slice === false) {
                $ok = false;
                break;
            }
            if ($slice === '') {
                break;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary archive stream to stdout.
            echo $slice;
            // Push each chunk to the client immediately instead of buffering the
            // whole file in memory before sending.
            if (function_exists('ob_get_level')) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            flush();
        }
        self::fclose_raw($handle);

        return $ok;
    }

    /**
     * Check if a directory is a protected core/system directory that must never be deleted.
     */
    public static function is_forbidden_root_directory(string $dir): bool
    {
        $normalized = function_exists('wp_normalize_path') ? wp_normalize_path(rtrim($dir, '/\\')) : rtrim(str_replace('\\', '/', $dir), '/');
        if ($normalized === '' || $normalized === '/' || $normalized === '.') {
            return true;
        }

        $forbidden = array();
        if (defined('ABSPATH')) {
            $forbidden[] = function_exists('wp_normalize_path') ? wp_normalize_path(rtrim(ABSPATH, '/\\')) : rtrim(str_replace('\\', '/', ABSPATH), '/');
        }
        if (defined('WP_CONTENT_DIR')) {
            $forbidden[] = function_exists('wp_normalize_path') ? wp_normalize_path(rtrim(WP_CONTENT_DIR, '/\\')) : rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/');
        }
        if (defined('WP_PLUGIN_DIR')) {
            $forbidden[] = function_exists('wp_normalize_path') ? wp_normalize_path(rtrim(WP_PLUGIN_DIR, '/\\')) : rtrim(str_replace('\\', '/', WP_PLUGIN_DIR), '/');
        }
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir(null, false);
            if (is_array($uploads) && !empty($uploads['basedir'])) {
                $forbidden[] = function_exists('wp_normalize_path') ? wp_normalize_path(rtrim($uploads['basedir'], '/\\')) : rtrim(str_replace('\\', '/', (string) $uploads['basedir']), '/');
            }
        }
        if (function_exists('get_theme_root')) {
            $theme_root = get_theme_root();
            if (is_string($theme_root) && $theme_root !== '') {
                $forbidden[] = function_exists('wp_normalize_path') ? wp_normalize_path(rtrim($theme_root, '/\\')) : rtrim(str_replace('\\', '/', $theme_root), '/');
            }
        }
        if (function_exists('get_home_path')) {
            $home = get_home_path();
            if (is_string($home) && $home !== '') {
                $forbidden[] = function_exists('wp_normalize_path') ? wp_normalize_path(rtrim($home, '/\\')) : rtrim(str_replace('\\', '/', $home), '/');
            }
        }

        return in_array($normalized, array_filter($forbidden), true);
    }

    /**
     * Recursively delete a directory and all its contents.
     */
    public static function delete_directory(string $dir): bool
    {
        if (self::is_forbidden_root_directory($dir)) {
            return false;
        }

        $fs = self::fs();
        if ($fs !== null && method_exists($fs, 'delete')) {
            return (bool) $fs->delete($dir, true);
        }

        if (!is_dir($dir)) {
            return true;
        }
        $items = scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                if (!self::delete_directory($path)) {
                    return false;
                }
            } else {
                if (!self::delete($path)) {
                    return false;
                }
            }
        }
        if ($fs !== null) {
            return (bool) $fs->delete($dir, false, 'd');
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Plugin: centralized filesystem gateway.
        return @rmdir($dir);
    }

    /**
     * List immediate entry names in a directory, excluding "." and "..".
     * Centralized here so callers route directory reads through the filesystem
     * gateway instead of calling scandir() directly.
     *
     * @return list<string>
     */
    public static function list_dir(string $dir): array
    {
        if (!is_dir($dir)) {
            return array();
        }
        $items = scandir($dir);
        if ($items === false) {
            return array();
        }

        return array_values(array_filter($items, static function ($name) {
            return $name !== '.' && $name !== '..';
        }));
    }

    /**
     * Compute directory size using SPL iterator (unbounded).
     */
    public static function directory_size(string $path): int
    {
        return self::directory_size_bounded($path, 0.0)['size'];
    }

    /**
     * Walk a directory for size with an optional wall-clock budget.
     * Used by backup start preflight so large wp-content trees cannot stall admin-ajax.
     *
     * @return array{size:int,complete:bool}
     */
    public static function directory_size_bounded(string $path, float $budget_sec = 0.25): array
    {
        if (!is_dir($path)) {
            return array('size' => 0, 'complete' => true);
        }
        $size = 0;
        $complete = true;
        $deadline = $budget_sec > 0.0 ? (microtime(true) + $budget_sec) : 0.0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if ($deadline > 0.0 && microtime(true) >= $deadline) {
                    $complete = false;
                    break;
                }
                $size += (int) $file->getSize();
            }
        } catch (Throwable $e) {
            // Ignore iterator exceptions; treat as incomplete so callers stay conservative.
            $complete = false;
        }
        return array('size' => $size, 'complete' => $complete);
    }

    /**
     * @return resource|false
     */
    public static function open_lock(string $path, string $mode = 'c+')
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Plugin: centralized filesystem gateway.
        $fh = @fopen($path, $mode);

        return $fh !== false ? $fh : false;
    }

    /**
     * @return resource|false
     */
    public static function fopen_raw(string $path, string $mode)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Plugin: centralized filesystem gateway.
        $fh = @fopen($path, $mode);
        return $fh !== false ? $fh : false;
    }

    /**
     * @param resource $handle
     * @return int|false
     */
    public static function fwrite_raw($handle, string $data)
    {
        if (!is_resource($handle)) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Plugin: centralized filesystem gateway.
        return fwrite($handle, $data);
    }

    /**
     * @param resource $handle
     * @return string|false
     */
    public static function fread_raw($handle, int $length)
    {
        if (!is_resource($handle)) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Plugin: centralized filesystem gateway.
        return fread($handle, $length);
    }

    /**
     * @param resource $handle
     */
    public static function fclose_raw($handle): void
    {
        if (is_resource($handle)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    public static function try_exclusive_lock($handle): bool
    {
        return is_resource($handle) && @flock($handle, LOCK_EX | LOCK_NB);
    }

    /**
     * @param resource $handle
     */
    public static function release_lock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
        @fclose($handle);
    }

    /**
     * @param resource $pipe
     * @return string|false
     */
    public static function fread_pipe($pipe, int $length)
    {
        if (!is_resource($pipe)) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Plugin: centralized filesystem gateway.
        return fread($pipe, $length);
    }

    /**
     * @param resource $pipe
     */
    public static function fclose_pipe($pipe): void
    {
        if (is_resource($pipe)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Plugin: centralized filesystem gateway.
            fclose($pipe);
        }
    }
}
