<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rewrite mysqldump table prefixes during import (subsite-on-network cross-blog slot).
 */
// phpcs:ignore Generic.Classes.DuplicateClassName -- Same helper is vendored in Pro trunk for standalone installs.
if (!class_exists('Rmmigrate_Sql_Import_Prefix_Rewrite', false)) {

class Rmmigrate_Sql_Import_Prefix_Rewrite
{
    /** @var string[] */
    private static $network_global_suffixes = array(
        'users',
        'usermeta',
        'blogs',
        'blogmeta',
        'blog_versions',
        'site',
        'sitemeta',
        'sitecategories',
        'registration_log',
        'signups',
    );

    public static function apply(string $sql, string $from_prefix, string $to_prefix): string
    {
        if ($from_prefix === '' || $to_prefix === '' || $from_prefix === $to_prefix) {
            return $sql;
        }

        $sql = self::strip_leading_sql_comments($sql);
        $sql = self::unwrap_leading_versioned_comment($sql);
        $selective_blog_one = self::is_base_blog_prefix($from_prefix);

        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*INSERT(?:\s+IGNORE)?\s+INTO\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*REPLACE\s+INTO\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*DROP\s+VIEW(?:\s+IF\s+EXISTS)?\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*DROP\s+TRIGGER(?:\s+IF\s+EXISTS)?\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*ALTER\s+TABLE\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*TRUNCATE(?:\s+TABLE)?\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*DELETE\s+FROM\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        $sql = self::rewrite_head_pattern(
            $sql,
            '/^\s*UPDATE\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i',
            $from_prefix,
            $to_prefix,
            $selective_blog_one
        );
        if (self::is_values_only_insert($sql)) {
            return $sql;
        }

        $sql = self::rewrite_lock_tables($sql, $from_prefix, $to_prefix, $selective_blog_one);
        $sql = self::rewrite_create_view_trigger_names($sql, $from_prefix, $to_prefix, $selective_blog_one);
        $sql = self::rewrite_identifiers_outside_literals($sql, $from_prefix, $to_prefix, $selective_blog_one);

        return $sql;
    }

    private static function is_values_only_insert(string $sql): bool
    {
        if (preg_match('/^\s*INSERT(?:\s+IGNORE)?\s+INTO\s+/i', $sql)) {
            return !preg_match('/\bSELECT\b/i', $sql);
        }
        if (preg_match('/^\s*REPLACE\s+INTO\s+/i', $sql)) {
            return !preg_match('/\bSELECT\b/i', $sql);
        }

        return false;
    }

    private static function strip_leading_sql_comments(string $statement): string
    {
        $trim = ltrim($statement);
        $len = strlen($trim);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ($trim[$i] === ' ' || $trim[$i] === "\t" || $trim[$i] === "\n" || $trim[$i] === "\r")) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($trim[$i] === '#' || ($trim[$i] === '-' && $i + 1 < $len && $trim[$i + 1] === '-')) {
                while ($i < $len && $trim[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($trim[$i] === '/' && $i + 2 < $len && $trim[$i + 1] === '*' && $trim[$i + 2] === '!') {
                break;
            }
            if ($trim[$i] === '/' && $i + 1 < $len && $trim[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len && !($trim[$i] === '*' && $trim[$i + 1] === '/')) {
                    $i++;
                }
                if ($i + 1 < $len) {
                    $i += 2;
                }
                continue;
            }
            break;
        }

        return substr($trim, $i);
    }

    private static function unwrap_leading_versioned_comment(string $sql): string
    {
        if (preg_match('/^\s*\/\*!\d+\s*(.*?)\s*\*\/\s*(.*)$/s', $sql, $m)) {
            $inner = (string) ($m[1] ?? '');
            $rest = (string) ($m[2] ?? '');
            if ($rest === '') {
                return $inner;
            }

            return rtrim($inner) . ' ' . ltrim($rest);
        }

        return $sql;
    }

    private static function rewrite_head_pattern(
        string $sql,
        string $pattern,
        string $from_prefix,
        string $to_prefix,
        bool $selective_blog_one
    ): string {
        if (!preg_match($pattern, $sql, $m)) {
            return $sql;
        }
        $table = (string) ($m[2] ?? '');
        $new_table = self::rewrite_table_name($table, $from_prefix, $to_prefix, $selective_blog_one);
        if ($new_table === $table) {
            return $sql;
        }

        return (string) preg_replace('/' . preg_quote($table, '/') . '/', $new_table, $sql, 1);
    }

    private static function rewrite_lock_tables(
        string $sql,
        string $from_prefix,
        string $to_prefix,
        bool $selective_blog_one
    ): string {
        if (!preg_match('/^\s*LOCK\s+TABLES\s+/i', $sql)) {
            return $sql;
        }

        return (string) preg_replace_callback(
            '/(`?)([a-zA-Z0-9_$-]+)(`?)(\s+(?:READ|WRITE)\b)/i',
            static function (array $m) use ($from_prefix, $to_prefix, $selective_blog_one): string {
                $table = (string) ($m[2] ?? '');
                $new_table = self::rewrite_table_name($table, $from_prefix, $to_prefix, $selective_blog_one);
                if ($new_table === $table) {
                    return (string) ($m[0] ?? '');
                }

                return (string) ($m[1] ?? '') . $new_table . (string) ($m[3] ?? '') . (string) ($m[4] ?? '');
            },
            $sql
        );
    }

    private static function rewrite_create_view_trigger_names(
        string $sql,
        string $from_prefix,
        string $to_prefix,
        bool $selective_blog_one
    ): string {
        if (preg_match('/^\s*CREATE\s+TRIGGER\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i', $sql, $m)) {
            $table = (string) ($m[2] ?? '');
            $new_table = self::rewrite_table_name($table, $from_prefix, $to_prefix, $selective_blog_one);
            if ($new_table !== $table) {
                $sql = (string) preg_replace('/' . preg_quote($table, '/') . '/', $new_table, $sql, 1);
            }
        }
        $view_head = '/^\s*CREATE\s+(?:OR\s+REPLACE\s+)?'
            . '(?:(?:ALGORITHM\s*=\s*\w+)\s+)?'
            . '(?:(?:DEFINER\s*=\s*(?:`[^`]+`|[^\s]+)@(?:`[^`]+`|[^\s]+))\s+)?'
            . '(?:(?:SQL\s+SECURITY\s+\w+)\s+)?'
            . 'VIEW\s+(`?)([a-zA-Z0-9_$-]+)(`?)/i';
        if (preg_match($view_head, $sql, $m)) {
            $table = (string) ($m[2] ?? '');
            $new_table = self::rewrite_table_name($table, $from_prefix, $to_prefix, $selective_blog_one);
            if ($new_table !== $table) {
                $sql = (string) preg_replace('/' . preg_quote($table, '/') . '/', $new_table, $sql, 1);
            }
        }

        return $sql;
    }

    /**
     * Rewrite table identifiers in VIEW/TRIGGER bodies and DML, skipping strings and comments.
     *
     * Only identifiers in a table position are touched: directly after a table keyword
     * (FROM, JOIN, INTO, UPDATE, REFERENCES, ...) or used as a qualifier (`tbl`.col).
     * Column and index names are never rewritten, including ON DUPLICATE KEY UPDATE lists.
     */
    private static function rewrite_identifiers_outside_literals(
        string $sql,
        string $from_prefix,
        string $to_prefix,
        bool $selective_blog_one
    ): string {
        if ($sql === '' || stripos($sql, $from_prefix) === false) {
            return $sql;
        }

        $ident = '(?:`(?:[^`]|``)+`|[A-Za-z_][A-Za-z0-9_$]*)';
        $pattern = '/'
            // 1: string literals and comments (copied verbatim).
            . '(\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|#[^\n]*|--[^\n]*|\/\*.*?\*\/)'
            // 2: column-list contexts that look like table keywords (copied verbatim).
            . '|(\bON\s+DUPLICATE\s+KEY\s+UPDATE\b|\bON\s+(?:UPDATE|DELETE)\b|\bFOR\s+UPDATE\b)'
            // 3: table keyword + whitespace, 4: optional schema qualifier, 5: table identifier.
            . '|(\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|DELETE\s+FROM|(?:(?:INNER|CROSS|NATURAL|(?:LEFT|RIGHT)(?:\s+OUTER)?)\s+)?JOIN|STRAIGHT_JOIN|UPDATE|FROM|INTO|ON|REFERENCES)\s+)'
            . '(' . $ident . '\s*\.\s*)?(' . $ident . ')'
            // 6: qualifier identifier directly followed by a dot (`tbl`.`col`).
            . '|(' . $ident . ')(?=\s*\.)'
            . '/is';

        $result = preg_replace_callback(
            $pattern,
            static function (array $m) use ($from_prefix, $to_prefix, $selective_blog_one): string {
                if (isset($m[1]) && $m[1] !== '') {
                    return $m[0];
                }
                if (isset($m[2]) && $m[2] !== '') {
                    return $m[0];
                }
                if (isset($m[3]) && $m[3] !== '') {
                    $qualifier = (string) ($m[4] ?? '');
                    if ($qualifier !== '' && preg_match('/^ON\s/i', (string) $m[3])) {
                        // ON tbl.col (join condition): the qualifier is the table, the tail a column.
                        $qualifier_ident = (string) preg_replace('/\s*\.\s*$/', '', $qualifier);
                        $dot = substr($qualifier, strlen($qualifier_ident));

                        return $m[3]
                            . self::rewrite_identifier_token($qualifier_ident, $from_prefix, $to_prefix, $selective_blog_one)
                            . $dot
                            . $m[5];
                    }

                    return $m[3] . $qualifier . self::rewrite_identifier_token((string) $m[5], $from_prefix, $to_prefix, $selective_blog_one);
                }
                if (isset($m[6]) && $m[6] !== '') {
                    return self::rewrite_identifier_token((string) $m[6], $from_prefix, $to_prefix, $selective_blog_one);
                }

                return $m[0];
            },
            $sql
        );

        return is_string($result) ? $result : $sql;
    }

    private static function rewrite_identifier_token(
        string $token,
        string $from_prefix,
        string $to_prefix,
        bool $selective_blog_one
    ): string {
        if ($token !== '' && $token[0] === '`') {
            $name = str_replace('``', '`', substr($token, 1, -1));
            $new = self::rewrite_table_name($name, $from_prefix, $to_prefix, $selective_blog_one);

            return $new === $name ? $token : '`' . str_replace('`', '``', $new) . '`';
        }

        return self::rewrite_table_name($token, $from_prefix, $to_prefix, $selective_blog_one);
    }

    private static function rewrite_table_name(
        string $table,
        string $from_prefix,
        string $to_prefix,
        bool $selective_blog_one
    ): string {
        $from_len = strlen($from_prefix);
        if ($from_len === 0 || strncmp($table, $from_prefix, $from_len) !== 0) {
            return $table;
        }
        // Idempotent: when the target prefix extends the source prefix (wp_ -> wp_3_),
        // a name already in the target namespace was rewritten before; never rewrite twice.
        $to_len = strlen($to_prefix);
        if (
            $to_len > $from_len
            && strncmp($to_prefix, $from_prefix, $from_len) === 0
            && strncmp($table, $to_prefix, $to_len) === 0
        ) {
            return $table;
        }
        $suffix = substr($table, $from_len);
        if ($suffix === '') {
            return $table;
        }
        if ($selective_blog_one && !self::is_blog_one_owned_table($table, $from_prefix)) {
            return $table;
        }

        return $to_prefix . $suffix;
    }

    private static function is_base_blog_prefix(string $prefix): bool
    {
        return $prefix !== '' && !preg_match('/_\d+_$/', $prefix);
    }

    private static function is_blog_one_owned_table(string $table, string $base_prefix): bool
    {
        if (strncmp($table, $base_prefix, strlen($base_prefix)) !== 0) {
            return false;
        }
        $suffix = substr($table, strlen($base_prefix));
        if ($suffix === '') {
            return false;
        }
        if (preg_match('/^_?\d+/', $suffix)) {
            return false;
        }
        $clean_suffix = ltrim($suffix, '_');
        if (in_array($clean_suffix, self::$network_global_suffixes, true)) {
            return false;
        }

        return true;
    }
}

}
