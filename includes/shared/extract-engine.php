<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared archive extraction engine (plugin restore + installer).
 * Keep behavior aligned with installer/lib/extract-engine.php.
 */

class Rmmigrate_Extract_Engine
{
    public const ENGINE_AUTO        = 'auto';
    public const ENGINE_SHELL       = 'shell_unzip';
    public const ENGINE_ZIP_CHUNK   = 'zip_chunk';
    public const ENGINE_ZIP_ONESHOT = 'zip_oneshot';
    public const ENGINE_DAF         = 'daf';

    public const THROTTLE_BUDGET_SEC = 8;
    public const ONESHOT_MAX_BYTES   = 209715200;
    public const ONESHOT_MAX_FILES   = 500;
    public const BLOCKING_SAFE_BYTES = 20971520; // 20 MB

    /** @var string|null */
    public static $test_unzip_binary = null;

    /** @var bool|null */
    public static $test_shell_available = null;

    private static function filesystem_exists(string $path): bool
    {
        if (defined('RMMIGRATE_INSTALLER')) {
            return Rmmigrate_Installer_Filesystem::exists($path);
        }

        return Rmmigrate_Filesystem::exists($path);
    }

    private static function filesystem_filesize(string $path): int
    {
        if (defined('RMMIGRATE_INSTALLER')) {
            return Rmmigrate_Installer_Filesystem::filesize($path);
        }

        return Rmmigrate_Filesystem::filesize($path);
    }

    private static function filesystem_delete(string $path): bool
    {
        if (defined('RMMIGRATE_INSTALLER')) {
            return Rmmigrate_Installer_Filesystem::delete($path);
        }

        return Rmmigrate_Filesystem::delete($path);
    }

    private static function filesystem_delete_directory(string $dir): bool
    {
        if (defined('RMMIGRATE_INSTALLER')) {
            return Rmmigrate_Installer_Filesystem::delete_directory($dir);
        }

        return Rmmigrate_Filesystem::delete_directory($dir);
    }

    public static function is_shell_unzip_available(): bool
    {
        if (self::$test_shell_available !== null) {
            return self::$test_shell_available;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        if (!self::shell_functions_available()) {
            return false;
        }

        return self::resolve_unzip_binary() !== '';
    }

    public static function resolve_unzip_binary(): string
    {
        if (self::$test_unzip_binary !== null) {
            return self::$test_unzip_binary;
        }

        $candidates = array('unzip');
        if (DIRECTORY_SEPARATOR === '/') {
            $candidates = array_merge($candidates, array('/usr/bin/unzip', '/bin/unzip', '/usr/local/bin/unzip'));
        }

        foreach ($candidates as $candidate) {
            if ($candidate === 'unzip') {
                $path = self::shell_which('unzip');
                if ($path !== '') {
                    return $path;
                }
                continue;
            }
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function resolve(string $archive_path, array $state, string $archive_ext = ''): string
    {
        $requested = (string) ( $state['extract_engine'] ?? self::ENGINE_AUTO );
        $ext = strtolower( $archive_ext !== '' ? $archive_ext : pathinfo( $archive_path, PATHINFO_EXTENSION ) );
        if ( $ext === 'daf' ) {
            return self::ENGINE_DAF;
        }

        if ( ! empty( $state['extract_shell_failed'] ) ) {
            return self::ENGINE_ZIP_CHUNK;
        }

        if ( $requested === self::ENGINE_SHELL ) {
            if ( self::blocking_engine_unsafe( $archive_path ) ) {
                return self::ENGINE_ZIP_CHUNK;
            }
            return self::is_shell_unzip_available() ? self::ENGINE_SHELL : self::ENGINE_ZIP_CHUNK;
        }
        if ( $requested === self::ENGINE_ZIP_ONESHOT ) {
            if ( self::blocking_engine_unsafe( $archive_path ) || ! self::can_oneshot_zip( $archive_path, $state ) ) {
                return self::ENGINE_ZIP_CHUNK;
            }
            return self::ENGINE_ZIP_ONESHOT;
        }
        if ( $requested === self::ENGINE_ZIP_CHUNK ) {
            return self::ENGINE_ZIP_CHUNK;
        }

        return self::ENGINE_ZIP_CHUNK;
    }

    public static function blocking_engine_unsafe(string $archive_path): bool
    {
        if (!self::filesystem_exists($archive_path)) {
            return false;
        }
        $size = (int) self::filesystem_filesize($archive_path);
        if ($size > self::BLOCKING_SAFE_BYTES) {
            if (PHP_SAPI !== 'cli') {
                return true;
            }
        }
        $max_exec = (int) ini_get('max_execution_time');

        return $max_exec !== 0;
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function can_oneshot_zip( string $archive_path, array $state ): bool
    {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return false;
        }
        if ( ! empty( $state['extract_shell_failed'] ) ) {
            return false;
        }
        if ( ! self::filesystem_exists( $archive_path ) ) {
            return false;
        }
        $size = (int) self::filesystem_filesize( $archive_path );
        if ( $size <= 0 || $size > self::ONESHOT_MAX_BYTES ) {
            return false;
        }

        $max_exec = (int) ini_get( 'max_execution_time' );
        if ( $max_exec !== 0 && $max_exec < 60 ) {
            return false;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $archive_path ) !== true ) {
            return false;
        }
        $count = $zip->numFiles;
        $zip->close();

        return $count > 0 && $count <= self::ONESHOT_MAX_FILES;
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function throttle_enabled( array $state ): bool
    {
        if ( ! empty( $state['extract_throttle'] ) ) {
            return true;
        }

        return class_exists( 'Rmmigrate_Engine_Config' )
            && Rmmigrate_Engine_Config::throttle_us() > 0;
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function budget_sec( array $state, int $default_budget ): int
    {
        if ( self::throttle_enabled( $state ) ) {
            return self::THROTTLE_BUDGET_SEC;
        }

        return max( 5, $default_budget );
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function archive_password( array $state ): string
    {
        return (string) ( $state['archive_passphrase'] ?? '' );
    }

    /**
     * @param array<string,mixed> $progress
     */
    public static function zip_percent( array $progress ): int
    {
        if ( ! class_exists( 'Rmmigrate_Progress', false ) ) {
            require_once __DIR__ . '/progress.php';
        }

        return Rmmigrate_Progress::zip_percent( $progress );
    }

    public static function run_shell_unzip( string $archive, string $dest, string $password = '' ): void
    {
        self::assert_safe_shell_path( $archive );
        self::assert_safe_shell_path( $dest );
        self::assert_archive_entries_path_safe( $archive, $dest );

        if ( ! is_dir( $dest ) ) {
            wp_mkdir_p( $dest );
        }

        $binary = self::resolve_unzip_binary();
        if ( $binary === '' ) {
            throw new RuntimeException( esc_html__('Shell unzip is not available.', 'rosenheinrich-multisite-migrate') );
        }

        $command = escapeshellarg( $binary ) . ' -o -qq';
        if ( $password !== '' ) {
            $command .= ' -P ' . escapeshellarg( $password );
        }
        $command .= ' ' . escapeshellarg( $archive ) . ' -d ' . escapeshellarg( $dest ) . ' 2>&1';

        $exit_code = 1;
        $output = self::run_shell_command( $command, $exit_code );
        if ( $output === null ) {
            throw new RuntimeException( esc_html__('Shell unzip command could not be executed.', 'rosenheinrich-multisite-migrate') );
        }

        if ( $exit_code !== 0 && ! self::shell_extract_looks_complete( $dest ) ) {
            throw new RuntimeException(
                esc_html(
                    trim($output) !== ''
                        ? trim($output)
                        : __('Shell unzip did not complete successfully.', 'rosenheinrich-multisite-migrate')
                )
            );
        }
    }

    public static function extract_oneshot( string $archive, string $dest, bool $only_sql = false, string $password = '' ): void
    {
        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new RuntimeException( esc_html__('ZipArchive PHP extension is required.', 'rosenheinrich-multisite-migrate') );
        }
        if ( ! is_dir( $dest ) ) {
            wp_mkdir_p( $dest );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $archive ) !== true ) {
            throw new RuntimeException( esc_html__('Cannot open backup archive.', 'rosenheinrich-multisite-migrate') );
        }
        if ( $password !== '' ) {
            $zip->setPassword( $password );
        }

        if ( $only_sql ) {
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $name = (string) $zip->getNameIndex( $i );
                if ( $name !== 'database.sql' && $name !== 'manifest.json' ) {
                    continue;
                }
                if ( Rmmigrate_Path_Safety_Core::resolve_extract_dest( $dest, $name ) === null ) {
                    continue;
                }
                if ( $zip->extractTo( $dest, array( $name ) ) === false ) {
                    $zip->close();
                    throw new RuntimeException( esc_html__('Cannot extract archive entry.', 'rosenheinrich-multisite-migrate') );
                }
            }
        } else {
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $name = (string) $zip->getNameIndex( $i );
                if ( $name === '' || substr( $name, -1 ) === '/' ) {
                    continue;
                }
                if ( Rmmigrate_Path_Safety_Core::resolve_extract_dest( $dest, $name ) === null ) {
                    continue;
                }
                if ( $zip->extractTo( $dest, array( $name ) ) === false ) {
                    $zip->close();
                    throw new RuntimeException( esc_html__('Cannot extract backup archive.', 'rosenheinrich-multisite-migrate') );
                }
            }
        }

        $zip->close();
    }

    public static function clear_extract_dir( string $extract_dir ): void
    {
        if ( ! is_dir( $extract_dir ) ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $extract_dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            /** @var SplFileInfo $item */
            $path = $item->getPathname();
            if ( $item->isDir() ) {
                self::filesystem_delete_directory( $path );
            } else {
                self::filesystem_delete( $path );
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function completed_zip_progress( string $archive_path, string $extract_dir ): array
    {
        $bytes_total = 0;
        $zip_total = 0;
        if ( class_exists( 'ZipArchive' ) && self::filesystem_exists( $archive_path ) ) {
            $zip = new ZipArchive();
            if ( $zip->open( $archive_path ) === true ) {
                $zip_total = $zip->numFiles;
                for ( $i = 0; $i < $zip_total; $i++ ) {
                    $stat = $zip->statIndex( $i );
                    if ( is_array( $stat ) ) {
                        $bytes_total += (int) ( $stat['size'] ?? 0 );
                    }
                }
                $zip->close();
            }
        }

        return array(
            'zip_index'       => $zip_total,
            'zip_total'       => $zip_total,
            'zip_bytes_done'  => $bytes_total,
            'zip_bytes_total' => $bytes_total,
            'format'          => 'zip',
            'extract_dir'     => $extract_dir,
        );
    }

    private static function shell_functions_available(): bool
    {
        $disabled = (string) ini_get( 'disable_functions' );
        $needed = array( 'shell_exec', 'exec', 'proc_open' );
        foreach ( $needed as $function ) {
            if ( ! function_exists( $function ) ) {
                return false;
            }
            if ( $disabled !== '' && preg_match( '/\b' . preg_quote( $function, '/' ) . '\b/i', $disabled ) ) {
                return false;
            }
        }

        return true;
    }

    private static function shell_which( string $binary ): string
    {
        $command = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg( $binary ) . ' 2>NUL'
            : 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null';

        $output = self::run_shell_command( $command );
        if ( $output === null ) {
            return '';
        }

        $line = trim( strtok( $output, "\r\n" ) );
        if ( $line === '' || ! is_executable( $line ) ) {
            return '';
        }

        return $line;
    }

    private static function run_shell_command( string $command, ?int &$exit_code = null ): ?string
    {
        if ( ! self::shell_functions_available() ) {
            return null;
        }

        $lines = array();
        $code = 1;
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Standalone installer: host capability probe requires shell function check.
        exec( $command, $lines, $code );
        if ( $exit_code !== null ) {
            $exit_code = $code;
        }

        return implode( "\n", $lines );
    }


    /**
     * Reject zip-slip entries before shell unzip writes outside $dest.
     */
    private static function assert_archive_entries_path_safe( string $archive, string $dest ): void
    {
        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new RuntimeException( esc_html__('ZipArchive PHP extension is required to validate archive paths before shell unzip.', 'rosenheinrich-multisite-migrate') );
        }
        if ( ! class_exists( 'Rmmigrate_Path_Safety_Core', false ) ) {
            $core = __DIR__ . '/path-safety-core.php';
            if ( is_file( $core ) ) {
                require_once $core;
            }
        }
        if ( ! class_exists( 'Rmmigrate_Path_Safety_Core', false ) ) {
            throw new RuntimeException( esc_html__('Path safety core is unavailable.', 'rosenheinrich-multisite-migrate') );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $archive ) !== true ) {
            throw new RuntimeException( esc_html__('Cannot open backup archive.', 'rosenheinrich-multisite-migrate') );
        }

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            if ( $name === '' || substr( $name, -1 ) === '/' ) {
                continue;
            }
            if ( Rmmigrate_Path_Safety_Core::resolve_extract_dest( $dest, $name ) === null ) {
                $zip->close();
                throw new RuntimeException( esc_html__('Archive contains an unsafe path.', 'rosenheinrich-multisite-migrate') );
            }
        }
        $zip->close();
    }

    private static function assert_safe_shell_path( string $path ): void

    {
        if ( $path === '' || strpbrk( $path, "\0\r\n" ) !== false ) {
            throw new RuntimeException( esc_html__('Invalid path for shell extraction.', 'rosenheinrich-multisite-migrate') );
        }
    }

    private static function shell_extract_looks_complete( string $dest ): bool
    {
        return self::filesystem_exists( $dest . '/database.sql' )
            || self::filesystem_exists( $dest . '/manifest.json' )
            || self::dest_has_files( $dest );
    }

    private static function dest_has_files( string $dest ): bool
    {
        if ( ! is_dir( $dest ) ) {
            return false;
        }
        $items = scandir( $dest );
        if ( ! is_array( $items ) ) {
            return false;
        }
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            return true;
        }

        return false;
    }
}
