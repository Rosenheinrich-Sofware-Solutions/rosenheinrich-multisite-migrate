<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inspect MySQL grants for the current WordPress database user.
 */
if (!class_exists('Rmmigrate_Database_Grants', false)) {

class Rmmigrate_Database_Grants
{
    private const GRANT_LITERAL_UNDERSCORE = "\x01GU\x01";
    private const GRANT_LITERAL_PERCENT = "\x01GP\x01";

    /**
     * @param string[] $privileges e.g. CREATE VIEW, CREATE TRIGGER
     * @return string[] Missing privilege names (empty when all present or unknown).
     */
    public static function missing_privileges(array $privileges): array
    {
        global $wpdb;
        $privileges = array_values(array_unique(array_filter(array_map('strval', $privileges))));
        if ($privileges === array()) {
            return array();
        }
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return array();
        }

        $found = array();
        $db_name = defined('DB_NAME') ? (string) DB_NAME : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privilege probe for restore preflight.
        $rows = $wpdb->get_results('SHOW GRANTS', ARRAY_A);
        if (!is_array($rows) || $rows === array()) {
            return array();
        }
        foreach ($rows as $row) {
            $grant = strtoupper((string) (reset($row) ?: ''));
            if ($grant === '') {
                continue;
            }
            if ($db_name !== '' && !self::grant_covers_database($grant, $db_name)) {
                continue;
            }
            foreach ($privileges as $priv) {
                $needle = strtoupper($priv);
                if (strpos($grant, 'ALL PRIVILEGES') !== false || strpos($grant, $needle) !== false) {
                    $found[$needle] = true;
                }
                if ($needle === 'TRIGGER' && preg_match('/\bTRIGGER\b/', $grant)) {
                    $found[$needle] = true;
                }
            }
        }

        $missing = array();
        foreach ($privileges as $priv) {
            $needle = strtoupper($priv);
            if (empty($found[$needle])) {
                $missing[] = $priv;
            }
        }

        return $missing;
    }

    /**
     * Whether a SHOW GRANTS row applies to the current database (or *.*).
     */
    public static function grant_covers_database(string $grant, string $db_name): bool
    {
        if (strpos($grant, ' ON *.*') !== false) {
            return true;
        }
        if (!preg_match('/\sON\s+(.+?)\s+TO\s+/i', $grant, $matches)) {
            return true;
        }
        $pattern = self::normalize_grant_db_pattern((string) ($matches[1] ?? ''));
        if ($pattern === '*.*') {
            return true;
        }

        return self::grant_db_pattern_matches($pattern, $db_name);
    }

    private static function normalize_grant_db_pattern(string $raw): string
    {
        $raw = trim($raw);
        $raw = str_replace('`', '', $raw);
        $raw = str_replace(
            array('\\_', '\\%'),
            array(self::GRANT_LITERAL_UNDERSCORE, self::GRANT_LITERAL_PERCENT),
            $raw
        );

        return strtoupper($raw);
    }

    private static function grant_db_pattern_matches(string $pattern, string $db_name): bool
    {
        $db_name = strtoupper($db_name);
        if ($pattern === $db_name . '.*' || $pattern === $db_name) {
            return true;
        }
        if (
            strpos($pattern, '%') === false
            && strpos($pattern, self::GRANT_LITERAL_UNDERSCORE) === false
            && strpos($pattern, '_') === false
        ) {
            return $pattern === $db_name . '.*' || $pattern === $db_name;
        }
        $db_part = $pattern;
        if (str_ends_with($db_part, '.*')) {
            $db_part = substr($db_part, 0, -2);
        }
        $regex = '/^' . self::grant_pattern_to_regex($db_part) . '$/';

        return (bool) preg_match($regex, $db_name);
    }

    private static function grant_pattern_to_regex(string $db_part): string
    {
        $regex = '';
        $len = strlen($db_part);
        $u_len = strlen(self::GRANT_LITERAL_UNDERSCORE);
        $p_len = strlen(self::GRANT_LITERAL_PERCENT);
        for ($i = 0; $i < $len; $i++) {
            if ($u_len > 0 && substr($db_part, $i, $u_len) === self::GRANT_LITERAL_UNDERSCORE) {
                $regex .= '_';
                $i += $u_len - 1;
                continue;
            }
            if ($p_len > 0 && substr($db_part, $i, $p_len) === self::GRANT_LITERAL_PERCENT) {
                $regex .= '%';
                $i += $p_len - 1;
                continue;
            }
            $ch = $db_part[$i];
            if ($ch === '%') {
                $regex .= '.*';
            } elseif ($ch === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($ch, '/');
            }
        }

        return $regex;
    }

    /**
     * @return string[]
     */
    public static function missing_routine_restore_privileges(): array
    {
        return self::missing_privileges(array('CREATE VIEW', 'TRIGGER'));
    }
}

}
