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

        if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $m)) {
            return array('host' => '[' . $m[1] . ']', 'port' => (int) $m[2], 'socket' => null);
        }

        if (preg_match('/^\[(.+)\]$/', $host, $m)) {
            return array('host' => '[' . $m[1] . ']', 'port' => null, 'socket' => null);
        }

        if (substr_count($host, ':') === 1 && preg_match('/^(.+):(\d+)$/', $host, $m) && strpos($host, '/') === false) {
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
        $charset = (defined('DB_CHARSET') && DB_CHARSET !== '') ? DB_CHARSET : 'utf8mb4';
        $parsed = self::parse_host($host);
        if ($parsed['socket'] !== null && $parsed['socket'] !== '') {
            return 'mysql:unix_socket=' . $parsed['socket'] . ';dbname=' . $name . ';charset=' . $charset;
        }

        $dsn = 'mysql:host=' . $parsed['host'] . ';dbname=' . $name . ';charset=' . $charset;
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
                array(
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT            => 5,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                )
            );
            // phpcs:enable WordPress.DB.RestrictedClasses.mysql__PDO
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $msg = $e->getMessage();
                if (defined('DB_HOST') && DB_HOST !== '') {
                    $msg = str_replace((string) DB_HOST, '[host]', $msg);
                }
                if (defined('DB_USER') && DB_USER !== '') {
                    $msg = str_replace((string) DB_USER, '[user]', $msg);
                }
                if (defined('DB_PASSWORD') && DB_PASSWORD !== '') {
                    $msg = str_replace((string) DB_PASSWORD, '[pass]', $msg);
                }
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Topology probe diagnostics only when debugging.
                error_log('[Multisite Migrate] Topology DB connect failed: ' . $msg);
            }
            return null;
        }
    }

    /**
     * Whether a table exists. Uses SELECT/sqlite_master — not SHOW TABLES LIKE with a bound
     * placeholder, which MySQL/MariaDB reject when PDO::ATTR_EMULATE_PREPARES is false.
     */
    public static function table_exists(PDO $pdo, string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        if (self::is_sqlite_pdo($pdo)) {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
            $stmt->execute(array($table));

            return (bool) $stmt->fetchColumn();
        }

        try {
            $quoted = self::quote_sql_identifier($table);
            $result = $pdo->query('SELECT 1 FROM ' . $quoted . ' LIMIT 1');
            if ($result === false) {
                return false;
            }
            $result->fetch();

            return true;
        } catch (Throwable $e) {
            unset($e);

            return false;
        }
    }

    /**
     * Tables whose names start with a literal prefix (not SQL LIKE wildcards).
     *
     * @return string[]
     */
    public static function list_tables_with_prefix(PDO $pdo, string $prefix): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return array();
        }

        $len = strlen($prefix);
        $out = array();
        foreach (self::list_all_tables($pdo) as $table) {
            if (strncmp($table, $prefix, $len) === 0) {
                $out[] = $table;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    public static function list_all_tables(PDO $pdo): array
    {
        // phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Injected topology PDO for peer DB table listing; wpdb is not used here.
        if (self::is_sqlite_pdo($pdo)) {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : array();
        } else {
            $stmt = $pdo->query('SHOW TABLES');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : array();
        }
        // phpcs:enable WordPress.DB.RestrictedClasses.mysql__PDO

        $tables = array();
        foreach ($rows as $row) {
            $name = (string) ($row[0] ?? '');
            if ($name !== '') {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    private static function is_sqlite_pdo(PDO $pdo): bool
    {
        try {
            // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Driver probe on injected topology PDO connection.
            return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (Throwable $e) {
            unset($e);

            return false;
        }
    }

    private static function quote_sql_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
