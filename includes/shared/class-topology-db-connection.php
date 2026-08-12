<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared PDO connection for restore topology DDL (plugin context).
 */
final class Rmmigrate_Topology_Db_Connection
{
    /**
     * @return array{host:string,port:?int,socket:?string}
     */
    private static function parse_host(string $host): array
    {
        $host = trim($host);
        if ($host === '') {
            return array('host' => 'localhost', 'port' => null, 'socket' => null);
        }

        if (preg_match('/^(.+):(\d+)$/', $host, $m) && strpos($host, '/') === false) {
            return array('host' => $m[1], 'port' => (int) $m[2], 'socket' => null);
        }

        if (strpos($host, ':') !== false && (strpos($host, '/') !== false || strpos($host, '\\') !== false)) {
            $parts = explode(':', $host, 2);
            return array('host' => 'localhost', 'port' => null, 'socket' => $parts[1]);
        }

        return array('host' => $host, 'port' => null, 'socket' => null);
    }

    private static function build_dsn(string $host, string $name): string
    {
        $parsed = self::parse_host($host);
        if ($parsed['socket'] !== null && $parsed['socket'] !== '') {
            return 'mysql:unix_socket=' . $parsed['socket'] . ';dbname=' . $name . ';charset=utf8mb4';
        }

        $dsn = 'mysql:host=' . $parsed['host'] . ';dbname=' . $name . ';charset=utf8mb4';
        if ($parsed['port'] !== null) {
            $dsn .= ';port=' . $parsed['port'];
        }

        return $dsn;
    }

    public static function connect(): ?PDO
    {
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD')) {
            return null;
        }

        try {
            // phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Native PDO required for topology DB probe when wpdb/mysqldump unavailable; DSN validated.
            return new PDO(
                self::build_dsn(DB_HOST, DB_NAME),
                DB_USER,
                DB_PASSWORD,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
            // phpcs:enable WordPress.DB.RestrictedClasses.mysql__PDO
        } catch (Throwable $e) {
            return null;
        }
    }
}
