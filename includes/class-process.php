<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Isolated process spawning for mysqldump and similar subprocess I/O.
 */
final class Rmmigrate_Process
{
    /**
     * @param array<int|string,mixed> $command
     * @param array<int,array<int|string,string>> $descriptors
     * @param array<int,resource>|null $pipes
     * @param array<string,mixed> $options
     * @return resource|false
     */
    public static function open(array $command, array $descriptors, ?array &$pipes = null, $cwd = null, $env = null, array $options = array())
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map(
            'strtolower',
            array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))))
        );
        if (in_array('proc_open', $disabled, true)) {
            return false;
        }

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Isolated subprocess gateway for mysqldump streaming; PHP fallback exists when disabled.
        return @proc_open($command, $descriptors, $pipes, $cwd, $env, $options);
    }

    /**
     * @param resource $process
     * @param array<int,resource> $pipes
     */
    public static function terminate($process, array $pipes = array()): void
    {
        if (is_resource($process)) {
            @proc_terminate($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                Rmmigrate_Filesystem::fclose_pipe($pipe);
            }
        }
        self::close($process);
    }

    /**
     * @param resource $process
     */
    public static function close($process): int
    {
        return is_resource($process) ? (int) proc_close($process) : -1;
    }
}
