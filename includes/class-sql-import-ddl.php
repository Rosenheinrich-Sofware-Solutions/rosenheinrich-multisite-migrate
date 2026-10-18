<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detect mysqldump routine objects (views, triggers, etc.) in import streams.
 */
if (!class_exists('Rmmigrate_Sql_Import_Ddl', false)) {

class Rmmigrate_Sql_Import_Ddl
{
    /**
     * Optional mysqldump / MySQL clauses between CREATE and the object kind.
     */
    private const CREATE_ROUTINE_PREFIX = '(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*(?:`[^`]+`@`[^`]+`|CURRENT_USER|[^\s]+)\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?(?:TEMPORARY\s+)?';

    /**
     * Unwrap mysqldump versioned comments to inner SQL before matching.
     */
    public static function strip_definer_clauses(string $statement): string
    {
        $normalized = self::normalize_mysql_version_comments($statement);
        $trimmed = ltrim($normalized);
        if ($trimmed === '') {
            return $statement;
        }

        $definer = 'DEFINER\s*=\s*(?:`[^`]+`@`[^`]+`|\'[^\']+\'@\'[^\']+\'|CURRENT_USER(?:\(\))?|[^\s]+)\s+';
        $leading = '/^(' . $definer . ')(?=\s*(?:SQL\s+SECURITY\s+\w+\s+)?CREATE\b)/i';
        $next = preg_replace($leading, '', $trimmed, 1);
        if (is_string($next) && $next !== $trimmed) {
            return $next;
        }

        if (!preg_match('/^CREATE\s+/i', $trimmed)) {
            return $statement;
        }

        $pattern = '/^(\s*CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?)' . $definer
            . '(?=(?:SQL\s+SECURITY\s+\w+\s+)?(?:VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b)/i';
        $next = preg_replace($pattern, '$1', $trimmed, 1);
        if (is_string($next) && $next !== $trimmed) {
            return $next;
        }

        $pattern = '/^(\s*CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?)' . $definer . '/i';
        $next = preg_replace($pattern, '$1', $trimmed, 1);

        return is_string($next) ? $next : $statement;
    }

    public static function sanitize_executable_statement(string $statement): string
    {
        if (self::skipped_routine_kind($statement) === null) {
            return $statement;
        }
        $statement = self::normalize_mysql_version_comments($statement);
        $statement = self::strip_definer_clauses($statement);

        return ltrim($statement);
    }

    public static function normalize_mysql_version_comments(string $statement): string
    {
        $prev = null;
        while ($prev !== $statement) {
            $prev = $statement;
            $next = preg_replace('/\/\*!\d+\s*(.*?)\*\//s', '$1', $statement);
            $statement = is_string($next) ? $next : $statement;
        }

        return ltrim($statement);
    }

    /**
     * @return string|null Human-readable object kind (VIEW, TRIGGER, …) when the statement should not be executed.
     */
    public static function skipped_routine_kind(string $statement): ?string
    {
        $statement = self::normalize_mysql_version_comments($statement);
        $statement = self::strip_definer_clauses($statement);
        if ($statement === '' || $statement[0] === '-' || $statement[0] === '#') {
            return null;
        }
        if (preg_match('/^\s*CREATE\s+' . self::CREATE_ROUTINE_PREFIX . '/i', $statement)) {
            foreach (array('VIEW', 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT') as $kind) {
                if (preg_match(
                    '/^\s*CREATE\s+' . self::CREATE_ROUTINE_PREFIX . $kind . '\b/i',
                    $statement
                )) {
                    return $kind;
                }
            }
        }
        if (preg_match(
            '/^\s*DROP\s+(VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)(?:\s+IF\s+EXISTS)?\b/i',
            $statement,
            $m
        )) {
            return strtoupper((string) ($m[1] ?? ''));
        }

        return null;
    }

    /**
     * Whether a failed routine DDL statement may be skipped (warn + continue).
     */
    public static function is_skippable_routine_error(string $message): bool
    {
        $message = strtolower($message);
        if ($message === '') {
            return false;
        }
        if (strpos($message, 'access denied') !== false) {
            return true;
        }
        if (strpos($message, 'command denied') !== false) {
            return true;
        }
        if (strpos($message, 'definer') !== false) {
            return true;
        }
        if (preg_match('/\b(1142|1143|1146|1227|1356|1370)\b/', $message)) {
            return true;
        }
        if (preg_match('/create\s+(view|trigger|procedure|function|event|routine)/', $message)) {
            return true;
        }

        return false;
    }

    /**
     * Quick scan for routine DDL (used for privilege preflight).
     */
    public static function sql_text_contains_routines(string $sql): bool
    {
        if ($sql === '') {
            return false;
        }
        $sql = self::strip_sql_string_literals($sql);
        $sql = self::normalize_mysql_version_comments($sql);

        return (bool) preg_match(
            '/\bCREATE\s+' . self::CREATE_ROUTINE_PREFIX . '(VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i',
            $sql
        );
    }

    /**
     * @param string $sql_path
     */
    public static function file_contains_routines(string $sql_path): bool
    {
        if (!is_readable($sql_path)) {
            return false;
        }
        $head = (string) file_get_contents($sql_path, false, null, 0, 262144);
        if (self::sql_text_contains_routines($head)) {
            return true;
        }
        $size = (int) filesize($sql_path);
        if ($size <= strlen($head)) {
            return false;
        }
        $tail = (string) file_get_contents($sql_path, false, null, max(0, $size - 262144));
        return self::sql_text_contains_routines($tail);
    }

    private static function strip_sql_string_literals(string $sql): string
    {
        $sql = preg_replace("/'([^'\\\\]|\\\\.)*'/", "''", $sql) ?? $sql;
        $sql = preg_replace('/"([^"\\\\]|\\\\.)*"/', '""', $sql) ?? $sql;

        return $sql;
    }
}

}
