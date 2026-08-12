<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Batched ZipArchive extraction (shared with installer techniques).
 */

class Rmmigrate_Zip_Extract_Core
{
    private const LARGE_FILE_BYTES = 4194304;
    private const STREAM_CHUNK_BYTES = 2097152;
    private const BATCH_FILE_LIMIT = 300;
    private const BATCH_BYTE_LIMIT = 16777216;

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

    /**
     * @return Rmmigrate_Filesystem_Stream|Rmmigrate_Installer_Filesystem_Stream|false
     */
    private static function filesystem_open(string $path, string $mode)
    {
        if (defined('RMMIGRATE_INSTALLER')) {
            return Rmmigrate_Installer_Filesystem::open($path, $mode);
        }

        return Rmmigrate_Filesystem::open($path, $mode);
    }

    /**
     * @param array<string,mixed> $progress
     * @return array{done:bool,progress:array<string,mixed>}
     */
    public static function extract_slice( string $zip_path, string $extract_dir, array $progress, int $budget_sec, bool $only_sql = false, bool $throttle = false, string $password = '' ): array
    {
        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new RuntimeException( esc_html__('ZipArchive PHP extension is required.', 'rosenheinrich-multisite-migrate') );
        }
        if ( ! is_dir( $extract_dir ) ) {
            wp_mkdir_p( $extract_dir );
        }

        $progress = self::normalize_progress( $progress );

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            throw new RuntimeException( esc_html__('Cannot open backup archive.', 'rosenheinrich-multisite-migrate') );
        }
        if ( $password !== '' ) {
            $zip->setPassword( $password );
        }

        $zip_index = (int) ( $progress['zip_index'] ?? 0 );
        $zip_total = (int) ( $progress['zip_total'] ?? 0 );
        if ( $zip_total <= 0 ) {
            $zip_total = $zip->numFiles;
        }

        $zip_bytes_done = (int) ( $progress['zip_bytes_done'] ?? 0 );
        $zip_bytes_total = (int) ( $progress['zip_bytes_total'] ?? 0 );
        if ( $zip_bytes_total <= 0 ) {
            $zip_bytes_total = self::zip_bytes_total( $zip, $only_sql );
        }

        $zip_entry_bytes = 0;
        /** @var array<int,array{name:string,size:int}> $pending_batch */
        $pending_batch = array();
        $pending_batch_bytes = 0;

        $start = microtime(true);
        while ( $zip_index < $zip_total && ( microtime( true ) - $start ) < $budget_sec ) {
            $name = (string) $zip->getNameIndex( $zip_index );
            if ( $name === '' ) {
                $zip_index++;
                $zip_entry_bytes = 0;
                continue;
            }

            if ( $only_sql && $name !== 'database.sql' && $name !== 'manifest.json' ) {
                $zip_index++;
                $zip_entry_bytes = 0;
                continue;
            }

            if ( substr( $name, -1 ) === '/' ) {
                $zip_index++;
                $zip_entry_bytes = 0;
                continue;
            }

            $dest = Rmmigrate_Path_Safety_Core::resolve_extract_dest( $extract_dir, $name );
            if ( $dest === null ) {
                $zip_index++;
                $zip_entry_bytes = 0;
                continue;
            }

            $stat = $zip->statIndex( $zip_index );
            $size = is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;

            if ( $size >= self::LARGE_FILE_BYTES ) {
                self::flush_batch( $zip, $extract_dir, $pending_batch, $zip_bytes_done, $throttle );
                $pending_batch = array();
                $pending_batch_bytes = 0;

                $dest_dir = dirname( $dest );
                if ( ! is_dir( $dest_dir ) ) {
                    wp_mkdir_p( $dest_dir );
                }

                $src_stream = $zip->getStream( $name );
                if ( $src_stream === false ) {
                    $zip_index++;
                    $zip_entry_bytes = 0;
                    continue;
                }

                $complete = self::stream_to_file( $src_stream, $dest, $budget_sec, $start );
                $fclose = 'fclose';
                call_user_func( $fclose, $src_stream );

                if ( ! $complete ) {
                    if ( self::filesystem_exists( $dest ) ) {
                        $zip_entry_bytes = (int) self::filesystem_filesize( $dest );
                        self::filesystem_delete( $dest );
                    } else {
                        $zip_entry_bytes = 0;
                    }
                    break;
                }

                $zip_bytes_done += $size;
                $zip_index++;
                $zip_entry_bytes = 0;
                continue;
            }

            $pending_batch[] = array(
                'name' => $name,
                'size' => $size,
            );
            $pending_batch_bytes += $size;
            $zip_index++;

            if ( count( $pending_batch ) >= self::BATCH_FILE_LIMIT || $pending_batch_bytes >= self::BATCH_BYTE_LIMIT ) {
                self::flush_batch( $zip, $extract_dir, $pending_batch, $zip_bytes_done, $throttle );
                $pending_batch = array();
                $pending_batch_bytes = 0;
            }
        }

        self::flush_batch( $zip, $extract_dir, $pending_batch, $zip_bytes_done, $throttle );

        $zip->close();

        $progress = array(
            'zip_index'       => $zip_index,
            'zip_total'       => $zip_total,
            'zip_bytes_done'  => $zip_bytes_done,
            'zip_bytes_total' => $zip_bytes_total,
            'format'          => 'zip',
            'extract_dir'     => $extract_dir,
        );
        if ( $zip_entry_bytes > 0 ) {
            $progress['zip_entry_index'] = $zip_index;
            $progress['zip_entry_bytes'] = $zip_entry_bytes;
        }

        return array(
            'done'     => $zip_index >= $zip_total,
            'progress' => $progress,
        );
    }

    /**
     * @param array<int,array{name:string,size:int}> $batch
     */
    private static function flush_batch( ZipArchive $zip, string $extract_dir, array $batch, int &$zip_bytes_done, bool $throttle = false ): void
    {
        if ( $batch === array() ) {
            return;
        }

        $names = array();
        $bytes = 0;
        foreach ( $batch as $entry ) {
            if ( Rmmigrate_Path_Safety_Core::resolve_extract_dest( $extract_dir, $entry['name'] ) === null ) {
                continue;
            }
            $names[] = $entry['name'];
            $bytes += (int) $entry['size'];
        }

        if ( $names === array() ) {
            return;
        }

        if ( $zip->extractTo( $extract_dir, $names ) === false ) {
            foreach ( $names as $name ) {
                if ( $zip->extractTo( $extract_dir, array( $name ) ) === false ) {
                    throw new RuntimeException( esc_html__('Cannot extract archive entry.', 'rosenheinrich-multisite-migrate') );
                }
            }
        }

        $zip_bytes_done += $bytes;

        if ( $throttle && class_exists( 'Rmmigrate_Engine_Config' ) ) {
            Rmmigrate_Engine_Config::apply_throttle();
        }
    }

    /**
     * @param resource $src_stream
     */
    private static function stream_to_file( $src_stream, string $dest, int $budget_sec, float $start ): bool
    {
        $dest_stream = self::filesystem_open( $dest, 'wb' );
        if ( $dest_stream === false ) {
            return false;
        }

        while ( ! feof( $src_stream ) && ( microtime( true ) - $start ) < $budget_sec ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- ZipArchive internal stream, not filesystem path.
            $chunk = fread( $src_stream, self::STREAM_CHUNK_BYTES );
            if ( $chunk === false ) {
                $dest_stream->close();
                return false;
            }
            if ( $chunk === '' ) {
                break;
            }
            if ( $dest_stream->write( $chunk ) === false ) {
                $dest_stream->close();
                return false;
            }
        }

        $dest_stream->close();

        return feof( $src_stream );
    }

    private static function zip_bytes_total( ZipArchive $zip, bool $only_sql ): int
    {
        $total = 0;
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            if ( $name === '' || substr( $name, -1 ) === '/' ) {
                continue;
            }
            if ( $only_sql && $name !== 'database.sql' && $name !== 'manifest.json' ) {
                continue;
            }
            $stat = $zip->statIndex( $i );
            if ( is_array( $stat ) ) {
                $total += (int) ( $stat['size'] ?? 0 );
            }
        }

        return $total;
    }

    /**
     * @param array<string,mixed> $progress
     * @return array<string,mixed>
     */
    private static function normalize_progress( array $progress ): array
    {
        if ( ! empty( $progress['entries'] ) && is_array( $progress['entries'] ) ) {
            return array(
                'zip_index' => (int) ( $progress['entry_index'] ?? $progress['zip_index'] ?? 0 ),
                'zip_total' => count( $progress['entries'] ),
                'format'    => 'zip',
            );
        }

        return $progress;
    }
}
