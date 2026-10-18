<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Rmmigrate_Sql_Import_Statement_Guard', false)) {
    /**
     * Allowlist for SQL statements replayed by a subsite-scoped restore.
     *
     * A subsite restore may only touch the target blog's own tables (after prefix rewrite)
     * plus harmless session SETs. Everything the classifier does not positively recognise
     * is rejected, so the caller can fail the whole import closed.
     *
     * The tokenizer mirrors how MySQL/MariaDB read a statement: string literals are opaque,
     * plain comments and optimizer hints are dropped, and executable comments
     * (`/*!NNNNN … *\/`, `/*M!NNNNNN … *\/`) are treated as executed code.
     *
     * Policy keys:
     * - target_prefix (string, required): table prefix of the target blog, e.g. `wp_5_`.
     * - base_prefix   (string, required): network base prefix, e.g. `wp_`.
     * - global_tables (string[]): network-global table names that must never be written.
     * - extra_allowed (string[]): additional exact table names that are allowed (e.g. user merge).
     * - db_name       (string): current schema; qualified names must use it.
     * - server_version (int): MySQL-style version (80036, 101106). 0 = unknown.
     * - server_is_mariadb (bool)
     * - trigger_table_resolver (callable|null): name => owning table, null when absent, '' when unknown.
     */
    final class Rmmigrate_Sql_Import_Statement_Guard
    {
        const OK = '';

        /** Words that may never appear (outside literals) in a guarded statement. */
        const ALWAYS_BANNED = array(
            'LOAD_FILE', 'OUTFILE', 'DUMPFILE', 'SLEEP', 'BENCHMARK', 'GET_LOCK', 'SYS_EXEC', 'SYS_EVAL',
        );

        /** Session variables a dump may set. */
        const SESSION_VARS = array(
            'NAMES', 'CHARACTER_SET_CLIENT', 'CHARACTER_SET_RESULTS', 'CHARACTER_SET_CONNECTION',
            'COLLATION_CONNECTION', 'SQL_MODE', 'TIME_ZONE', 'FOREIGN_KEY_CHECKS', 'UNIQUE_CHECKS',
            'SQL_NOTES', 'AUTOCOMMIT', 'SQL_QUOTE_SHOW_CREATE', 'SQL_SAFE_UPDATES', 'SQL_AUTO_IS_NULL',
            'INNODB_STRICT_MODE', 'CHECK_CONSTRAINT_CHECKS', 'SQL_BIG_SELECTS', 'SQL_REQUIRE_PRIMARY_KEY',
            'GROUP_CONCAT_MAX_LEN', 'MAX_ALLOWED_PACKET', 'NET_BUFFER_LENGTH', 'INSERT_ID', 'LAST_INSERT_ID',
            'TIMESTAMP', 'NOTE_VERBOSITY', 'SESSION', 'LOCAL',
        );

        const INSERT_MODIFIERS = array('LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE');

        /** Character sets without 0x5C trailing bytes (safe for backslash-escaped literals). */
        const SAFE_CHARSET_PREFIXES = array('utf8mb4', 'utf8mb3', 'utf8', 'latin1', 'latin2', 'ascii', 'binary', 'ucs2', 'utf16', 'utf32');

        /** Storage engines a restored table may use (no FEDERATED/CONNECT/MERGE/SPIDER). */
        const SAFE_ENGINES = array('INNODB', 'MYISAM', 'ARIA', 'MEMORY', 'HEAP', 'ARCHIVE', 'ROCKSDB', 'TOKUDB', 'BLACKHOLE', 'CSV');

        /** Executable comments up to this version run on every supported server. */
        const UNIVERSAL_COMMENT_VERSION = 50700;

        /**
         * @param array<string,mixed> $policy
         * @return string Empty when allowed, otherwise a short reason code.
         */
        public static function check(string $statement, array $policy): string
        {
            if ((string) ($policy['target_prefix'] ?? '') === '' || (string) ($policy['base_prefix'] ?? '') === '') {
                return 'policy_incomplete';
            }
            $tokens = self::tokenize(
                $statement,
                (int) ($policy['server_version'] ?? 0),
                !empty($policy['server_is_mariadb'])
            );
            if ($tokens === null) {
                return 'unterminated_literal_or_comment';
            }
            // A trailing delimiter is fine; a second statement is not.
            while ($tokens !== array() && self::is_punct(end($tokens), ';')) {
                array_pop($tokens);
            }
            if ($tokens === array()) {
                return self::OK;
            }
            $is_trigger = self::is_create_trigger($tokens);
            foreach ($tokens as $t) {
                if (!$is_trigger && self::is_punct($t, ';')) {
                    return 'multiple_statements';
                }
                if ($t['t'] === 'w' && in_array($t['u'], array('DIRECTORY', 'TABLESPACE', 'CONNECTION', 'SOCKET'), true)) {
                    return 'storage_clause_not_allowed';
                }
                if ($t['t'] === 'w' && in_array($t['u'], self::ALWAYS_BANNED, true)) {
                    return 'banned_function';
                }
            }

            if (!$is_trigger && self::has_unsafe_engine($tokens)) {
                return 'engine_not_allowed';
            }
            if (!$is_trigger && self::has_assignment_operator($tokens) && !self::is_word($tokens[0], array('SET'))) {
                return 'assignment_not_allowed';
            }
            $first = $tokens[0];
            if ($first['t'] !== 'w') {
                return 'unrecognized_statement';
            }
            switch ($first['u']) {
                case 'SET':
                    return self::check_set($tokens);
                case 'INSERT':
                case 'REPLACE':
                    return self::check_insert($tokens, $policy);
                case 'UPDATE':
                    return self::check_update($tokens, $policy);
                case 'DELETE':
                    return self::check_delete($tokens, $policy);
                case 'CREATE':
                    return self::check_create($tokens, $policy);
                case 'DROP':
                    return self::check_drop($tokens, $policy);
                case 'ALTER':
                    return self::check_alter($tokens, $policy);
                case 'RENAME':
                    return self::check_rename($tokens, $policy);
                case 'LOCK':
                    return self::check_lock($tokens, $policy);
                case 'UNLOCK':
                    return (count($tokens) === 2 && self::is_word($tokens[1], array('TABLES', 'TABLE'))) ? self::OK : 'unrecognized_statement';
                case 'TRUNCATE':
                    $i = 1;
                    if (self::is_word($tokens[$i] ?? null, array('TABLE'))) {
                        $i++;
                    }
                    $err = self::expect_allowed_table($tokens, $i, $policy);
                    if ($err !== self::OK) {
                        return $err;
                    }
                    return $i === count($tokens) ? self::OK : 'unexpected_tokens';
                case 'START':
                case 'BEGIN':
                case 'COMMIT':
                case 'ROLLBACK':
                    return self::check_transaction($tokens);
                default:
                    return 'statement_type_not_allowed';
            }
        }

        /**
         * Whether a table name belongs to the target blog under the policy.
         *
         * @param array<string,mixed> $policy
         */
        public static function table_allowed(string $name, array $policy): bool
        {
            $lower = strtolower($name);
            foreach ((array) ($policy['extra_allowed'] ?? array()) as $extra) {
                if ($lower === strtolower((string) $extra)) {
                    return true;
                }
            }
            $target = strtolower((string) ($policy['target_prefix'] ?? ''));
            $base = strtolower((string) ($policy['base_prefix'] ?? ''));
            if ($target === '' || strpos($lower, $target) !== 0 || $lower === $target) {
                return false;
            }
            foreach ((array) ($policy['global_tables'] ?? array()) as $global) {
                if ($lower === strtolower((string) $global)) {
                    return false;
                }
            }
            if ($target === $base) {
                // Main-site target: tables of numbered blogs share the base prefix.
                $rest = substr($lower, strlen($base));
                if (preg_match('/^\d+_/', $rest)) {
                    return false;
                }
            }

            return true;
        }

        /**
         * MySQL-faithful tokenizer. Returns null for unterminated literals/comments.
         *
         * @return array<int,array{t:string,v:string,u:string}>|null
         */
        public static function tokenize(string $sql, int $server_version = 0, bool $is_mariadb = false): ?array
        {
            $tokens = array();
            $len = strlen($sql);
            $i = 0;
            $exec_comment_depth = 0;
            while ($i < $len) {
                $c = $sql[$i];
                if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === "\v") {
                    $i++;
                    continue;
                }
                if ($c === '*' && $exec_comment_depth > 0 && ($sql[$i + 1] ?? '') === '/') {
                    $exec_comment_depth--;
                    $i += 2;
                    continue;
                }
                if ($c === '#') {
                    $nl = strpos($sql, "\n", $i);
                    $i = $nl === false ? $len : $nl + 1;
                    continue;
                }
                if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                    $next = $sql[$i + 2] ?? '';
                    if ($next === '' || ctype_space($next) || ctype_cntrl($next)) {
                        $nl = strpos($sql, "\n", $i);
                        $i = $nl === false ? $len : $nl + 1;
                        continue;
                    }
                }
                if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                    $j = $i + 2;
                    $executable = false;
                    $mariadb_only = false;
                    if (($sql[$j] ?? '') === '!') {
                        $executable = true;
                        $j++;
                    } elseif (($sql[$j] ?? '') === 'M' && ($sql[$j + 1] ?? '') === '!') {
                        $executable = true;
                        $mariadb_only = true;
                        $j += 2;
                    }
                    if ($executable) {
                        $digits_start = $j;
                        while ($j < $len && ctype_digit($sql[$j])) {
                            $j++;
                        }
                        $version = $j > $digits_start ? (int) substr($sql, $digits_start, $j - $digits_start) : 0;
                        $runs = self::executable_comment_runs($version, $mariadb_only, $server_version, $is_mariadb);
                        if ($runs === null) {
                            return null; // Version-dependent: cannot tell what the server will execute.
                        }
                        if ($runs) {
                            $exec_comment_depth++;
                            $i = $j;
                            continue;
                        }
                        // Server skips the body: treat it as a plain comment.
                    }
                    if ($exec_comment_depth > 0) {
                        return null; // Nested comment inside an executable comment.
                    }
                    $end = strpos($sql, '*/', $i + 2);
                    if ($end === false) {
                        return null;
                    }
                    $i = $end + 2;
                    continue;
                }
                if ($c === "'" || $c === '"') {
                    $j = $i + 1;
                    $closed = false;
                    while ($j < $len) {
                        $d = $sql[$j];
                        if ($d === '\\') {
                            $j += 2;
                            continue;
                        }
                        if ($d === $c) {
                            if (($sql[$j + 1] ?? '') === $c) {
                                $j += 2;
                                continue;
                            }
                            $closed = true;
                            break;
                        }
                        $j++;
                    }
                    if (!$closed) {
                        return null;
                    }
                    $tokens[] = array('t' => 's', 'v' => substr($sql, $i, $j - $i + 1), 'u' => '');
                    $i = $j + 1;
                    continue;
                }
                if ($c === '`') {
                    $j = $i + 1;
                    $name = '';
                    $closed = false;
                    while ($j < $len) {
                        if ($sql[$j] === '`') {
                            if (($sql[$j + 1] ?? '') === '`') {
                                $name .= '`';
                                $j += 2;
                                continue;
                            }
                            $closed = true;
                            break;
                        }
                        $name .= $sql[$j];
                        $j++;
                    }
                    if (!$closed) {
                        return null;
                    }
                    $tokens[] = array('t' => 'id', 'v' => $name, 'u' => strtoupper($name));
                    $i = $j + 1;
                    continue;
                }
                if ($c === '@') {
                    $j = $i + 1;
                    if (($sql[$j] ?? '') === '@') {
                        $j++;
                    }
                    while ($j < $len && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '.' || $sql[$j] === '$')) {
                        $j++;
                    }
                    $v = substr($sql, $i, $j - $i);
                    $tokens[] = array('t' => 'var', 'v' => $v, 'u' => strtoupper($v));
                    $i = $j;
                    continue;
                }
                if (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80) {
                    $j = $i;
                    while ($j < $len && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$' || ord($sql[$j]) >= 0x80)) {
                        $j++;
                    }
                    $v = substr($sql, $i, $j - $i);
                    $tokens[] = array('t' => ctype_digit($v) ? 'n' : 'w', 'v' => $v, 'u' => strtoupper($v));
                    $i = $j;
                    continue;
                }
                $tokens[] = array('t' => 'p', 'v' => $c, 'u' => $c);
                $i++;
            }
            if ($exec_comment_depth > 0) {
                return null;
            }

            return $tokens;
        }

        /**
         * Whether the server executes a `/*!NNNNN` / `/*M!NNNNNN` body. Null when undecidable.
         */
        private static function executable_comment_runs(int $version, bool $mariadb_only, int $server_version, bool $is_mariadb): ?bool
        {
            if ($server_version > 0) {
                if ($mariadb_only && !$is_mariadb) {
                    return false;
                }

                return $version <= $server_version;
            }
            if ($mariadb_only) {
                return null;
            }

            return $version <= self::UNIVERSAL_COMMENT_VERSION ? true : null;
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         */
        private static function is_create_trigger(array $tokens): bool
        {
            if (!self::is_word($tokens[0] ?? null, array('CREATE'))) {
                return false;
            }
            $count = min(count($tokens), 24);
            for ($i = 1; $i < $count; $i++) {
                if (self::is_word($tokens[$i], array('TRIGGER'))) {
                    return true;
                }
                if (self::is_word($tokens[$i], array('TABLE', 'VIEW', 'PROCEDURE', 'FUNCTION', 'EVENT', 'ON', 'AS'))) {
                    return false;
                }
            }

            return false;
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         */
        private static function has_unsafe_engine(array $tokens): bool
        {
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!self::is_word($tokens[$i], array('ENGINE', 'TYPE'))) {
                    continue;
                }
                $j = $i + 1;
                if (self::is_punct($tokens[$j] ?? null, '=')) {
                    $j++;
                }
                $value = $tokens[$j] ?? null;
                if ($value === null) {
                    continue;
                }
                if (self::is_word($tokens[$i], array('TYPE')) && !self::is_punct($tokens[$i + 1] ?? null, '=')) {
                    continue; // Column/index TYPE (e.g. USING BTREE contexts), not a table option.
                }
                $engine = strtoupper(trim($value['t'] === 's' ? substr($value['v'], 1, -1) : $value['v']));
                if (!in_array($engine, self::SAFE_ENGINES, true)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * `:=` outside SET (user-variable smuggling inside INSERT/UPDATE/DDL).
         *
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         */
        private static function has_assignment_operator(array $tokens): bool
        {
            $count = count($tokens);
            for ($i = 0; $i + 1 < $count; $i++) {
                if (self::is_punct($tokens[$i], ':') && self::is_punct($tokens[$i + 1], '=')) {
                    return true;
                }
            }

            return false;
        }

        private static function literal_value(array $token): string
        {
            if ($token['t'] !== 's') {
                return $token['v'];
            }
            $inner = substr($token['v'], 1, -1);

            return stripslashes(str_replace(array("''", '""'), array("'", '"'), $inner));
        }

        private static function is_safe_charset_value(string $value): bool
        {
            $value = strtolower(trim($value));
            if ($value === '' || $value === 'default') {
                return true;
            }
            foreach (self::SAFE_CHARSET_PREFIXES as $prefix) {
                if ($value === $prefix || strpos($value, $prefix . '_') === 0) {
                    return true;
                }
            }

            return false;
        }

        private static function value_flips_lexer_modes(string $value): bool
        {
            return (bool) preg_match('/NO_BACKSLASH_ESCAPES|ANSI/i', $value);
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         */
        private static function check_set(array $tokens): string
        {
            $count = count($tokens);
            $i = 1;
            while ($i < $count) {
                // Target.
                if (self::is_word($tokens[$i], array('SESSION', 'LOCAL'))) {
                    $i++;
                }
                $t = $tokens[$i] ?? null;
                if ($t === null) {
                    return 'set_syntax';
                }
                $target = '';
                if ($t['t'] === 'var') {
                    if (strpos($t['u'], '@@') === 0) {
                        $name = (string) preg_replace('/^@@(SESSION\.|LOCAL\.)?/', '', $t['u']);
                        if (strpos($name, '.') !== false || !in_array($name, self::SESSION_VARS, true)) {
                            return 'set_variable_not_allowed';
                        }
                        $target = $name;
                    } else {
                        $target = '@';
                    }
                    $i++;
                } elseif ($t['t'] === 'w') {
                    if ($t['u'] === 'CHARACTER' && self::is_word($tokens[$i + 1] ?? null, array('SET'))) {
                        $target = 'CHARSET';
                        $i += 2;
                    } elseif ($t['u'] === 'CHARSET' || $t['u'] === 'NAMES') {
                        $target = $t['u'] === 'NAMES' ? 'NAMES' : 'CHARSET';
                        $i++;
                    } elseif (in_array($t['u'], self::SESSION_VARS, true) && !in_array($t['u'], array('SESSION', 'LOCAL'), true)) {
                        $target = $t['u'];
                        $i++;
                    } else {
                        return 'set_variable_not_allowed';
                    }
                } else {
                    return 'set_variable_not_allowed';
                }
                // Operator (NAMES / CHARACTER SET take none).
                if (self::is_punct($tokens[$i] ?? null, ':') && self::is_punct($tokens[$i + 1] ?? null, '=')) {
                    $i += 2;
                } elseif (self::is_punct($tokens[$i] ?? null, '=')) {
                    $i++;
                } elseif (!in_array($target, array('NAMES', 'CHARSET'), true)) {
                    return 'set_syntax';
                }
                // Single value: literal, number (optionally signed), word, or variable.
                if (self::is_punct($tokens[$i] ?? null, '-') || self::is_punct($tokens[$i] ?? null, '+')) {
                    $i++;
                }
                $v = $tokens[$i] ?? null;
                if ($v === null || !in_array($v['t'], array('s', 'n', 'w', 'var'), true)) {
                    return 'set_value_not_allowed';
                }
                $err = self::check_set_value($target, $v);
                if ($err !== self::OK) {
                    return $err;
                }
                $i++;
                // Decimal values such as 1.5.
                if (self::is_punct($tokens[$i] ?? null, '.') && ($tokens[$i + 1]['t'] ?? '') === 'n') {
                    $i += 2;
                }
                if (in_array($target, array('NAMES', 'CHARSET'), true) && self::is_word($tokens[$i] ?? null, array('COLLATE'))) {
                    $c = $tokens[$i + 1] ?? null;
                    if ($c === null || !in_array($c['t'], array('s', 'w'), true) || !self::is_safe_charset_value(self::literal_value($c))) {
                        return 'set_charset_not_allowed';
                    }
                    $i += 2;
                }
                if ($i >= $count) {
                    return self::OK;
                }
                if (!self::is_punct($tokens[$i], ',')) {
                    return 'set_syntax';
                }
                $i++;
            }

            return 'set_syntax';
        }

        /**
         * @param array{t:string,v:string,u:string} $v
         */
        private static function check_set_value(string $target, array $v): string
        {
            if ($v['t'] === 'var') {
                if (strpos($v['u'], '@@GLOBAL.') === 0 || strpos($v['u'], '@@PERSIST') === 0) {
                    return 'set_not_allowed';
                }

                return self::OK;
            }
            if ($v['t'] === 'w' && in_array($v['u'], array('SELECT', 'FROM', 'PASSWORD'), true)) {
                return 'set_not_allowed';
            }
            $value = self::literal_value($v);
            if (self::value_flips_lexer_modes($value)) {
                return 'set_sql_mode_not_allowed';
            }
            $charset_targets = array('NAMES', 'CHARSET', 'CHARACTER_SET_CLIENT', 'CHARACTER_SET_CONNECTION', 'CHARACTER_SET_RESULTS', 'COLLATION_CONNECTION');
            if (in_array($target, $charset_targets, true) && !($v['t'] === 'w' && $v['u'] === 'NULL') && !self::is_safe_charset_value($value)) {
                return 'set_charset_not_allowed';
            }
            if ($target === '@' && $v['t'] !== 'n' && preg_match('/^(big5|cp932|gbk|sjis|gb18030)/i', trim($value))) {
                return 'set_charset_not_allowed';
            }

            return self::OK;
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function check_insert(array $tokens, array $policy): string
        {
            $i = 1;
            while (self::is_word($tokens[$i] ?? null, self::INSERT_MODIFIERS)) {
                $i++;
            }
            if (self::is_word($tokens[$i] ?? null, array('INTO'))) {
                $i++;
            }
            $err = self::expect_allowed_table($tokens, $i, $policy);
            if ($err !== self::OK) {
                return $err;
            }
            $next = $tokens[$i] ?? null;
            if (!self::is_punct($next, '(') && !self::is_word($next, array('VALUES', 'VALUE', 'SET', 'PARTITION'))) {
                return 'unexpected_tokens';
            }

            return self::reject_words_after($tokens, $i, array('SELECT', 'TABLE', 'FROM', 'JOIN', 'UNION', 'INTO', 'WITH', 'CALL'));
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function check_update(array $tokens, array $policy): string
        {
            $i = 1;
            while (self::is_word($tokens[$i] ?? null, array('LOW_PRIORITY', 'IGNORE'))) {
                $i++;
            }
            $err = self::expect_allowed_table($tokens, $i, $policy);
            if ($err !== self::OK) {
                return $err;
            }
            if (!self::is_word($tokens[$i] ?? null, array('SET'))) {
                return 'multi_table_update';
            }

            return self::reject_words_after($tokens, $i, array('SELECT', 'FROM', 'JOIN', 'UNION', 'INTO', 'TABLE', 'WITH'));
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function check_delete(array $tokens, array $policy): string
        {
            $i = 1;
            while (self::is_word($tokens[$i] ?? null, array('LOW_PRIORITY', 'QUICK', 'IGNORE'))) {
                $i++;
            }
            if (!self::is_word($tokens[$i] ?? null, array('FROM'))) {
                return 'multi_table_delete';
            }
            $i++;
            $err = self::expect_allowed_table($tokens, $i, $policy);
            if ($err !== self::OK) {
                return $err;
            }
            $next = $tokens[$i] ?? null;
            if ($next !== null && !self::is_word($next, array('WHERE', 'ORDER', 'LIMIT', 'PARTITION'))) {
                return 'multi_table_delete';
            }

            return self::reject_words_after($tokens, $i, array('SELECT', 'FROM', 'JOIN', 'USING', 'UNION', 'INTO', 'TABLE', 'WITH'));
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function check_create(array $tokens, array $policy): string
        {
            $count = count($tokens);
            $i = 1;
            // Skip the CREATE preamble: OR REPLACE, TEMPORARY, ALGORITHM=…, DEFINER=…, SQL SECURITY …
            while ($i < $count) {
                $t = $tokens[$i];
                if (self::is_word($t, array('OR')) && self::is_word($tokens[$i + 1] ?? null, array('REPLACE'))) {
                    $i += 2;
                    continue;
                }
                if (self::is_word($t, array('TEMPORARY'))) {
                    $i++;
                    continue;
                }
                if (self::is_word($t, array('ALGORITHM')) && self::is_punct($tokens[$i + 1] ?? null, '=')) {
                    $i += 3;
                    continue;
                }
                if (self::is_word($t, array('DEFINER')) && self::is_punct($tokens[$i + 1] ?? null, '=')) {
                    $i += 2;
                    // user@host / CURRENT_USER[()]: consume until the next keyword.
                    while ($i < $count && !self::is_word($tokens[$i], array('SQL', 'VIEW', 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT'))) {
                        $i++;
                    }
                    continue;
                }
                if (self::is_word($t, array('SQL')) && self::is_word($tokens[$i + 1] ?? null, array('SECURITY'))) {
                    $i += 3;
                    continue;
                }
                break;
            }
            $kind = $tokens[$i] ?? null;
            if (self::is_word($kind, array('TABLE'))) {
                $i++;
                if (self::is_word($tokens[$i] ?? null, array('IF'))) {
                    $i += 3; // IF NOT EXISTS
                }
                $err = self::expect_allowed_table($tokens, $i, $policy);
                if ($err !== self::OK) {
                    return $err;
                }

                return self::reject_words_after($tokens, $i, array('SELECT', 'LIKE', 'UNION', 'TABLE', 'FROM'));
            }
            if (self::is_word($kind, array('VIEW'))) {
                $i++;
                if (self::is_word($tokens[$i] ?? null, array('IF'))) {
                    $i += 3;
                }
                $err = self::expect_allowed_table($tokens, $i, $policy);
                if ($err !== self::OK) {
                    return $err;
                }

                return self::check_body_references($tokens, $i, $policy);
            }
            if (self::is_word($kind, array('TRIGGER'))) {
                $i++;
                if (self::is_word($tokens[$i] ?? null, array('IF'))) {
                    $i += 3;
                }
                $name_end = $i;
                if (self::read_table_name($tokens, $name_end) === null) {
                    return 'trigger_name_invalid';
                }
                $i = $name_end;
                if (!self::is_word($tokens[$i] ?? null, array('BEFORE', 'AFTER'))
                    || !self::is_word($tokens[$i + 1] ?? null, array('INSERT', 'UPDATE', 'DELETE'))
                    || !self::is_word($tokens[$i + 2] ?? null, array('ON'))) {
                    return 'trigger_header_invalid';
                }
                $i += 3;
                $err = self::expect_allowed_table($tokens, $i, $policy);
                if ($err !== self::OK) {
                    return $err;
                }

                return self::check_body_references($tokens, $i, $policy);
            }

            return 'create_kind_not_allowed';
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function check_drop(array $tokens, array $policy): string
        {
            $i = 1;
            if (self::is_word($tokens[$i] ?? null, array('TEMPORARY'))) {
                $i++;
            }
            $kind = $tokens[$i] ?? null;
            if (!self::is_word($kind, array('TABLE', 'TABLES', 'VIEW', 'TRIGGER'))) {
                return 'drop_kind_not_allowed';
            }
            $is_trigger = self::is_word($kind, array('TRIGGER'));
            $i++;
            if (self::is_word($tokens[$i] ?? null, array('IF'))) {
                $i += 2; // IF EXISTS
            }
            $count = count($tokens);
            while (true) {
                if ($is_trigger) {
                    $schema = null;
                    $trigger = self::read_table_name($tokens, $i, $schema);
                    if ($trigger === null) {
                        return 'trigger_name_invalid';
                    }
                    if ($schema !== null && strcasecmp($schema, (string) ($policy['db_name'] ?? '')) !== 0) {
                        return 'foreign_schema';
                    }
                    $resolver = $policy['trigger_table_resolver'] ?? null;
                    if (!is_callable($resolver)) {
                        return 'trigger_owner_unknown';
                    }
                    $owner = $resolver($trigger);
                    if ($owner !== null && ($owner === '' || !self::table_allowed((string) $owner, $policy))) {
                        return 'table_not_allowed'; // '' = ownership could not be determined.
                    }
                } else {
                    $err = self::expect_allowed_table($tokens, $i, $policy);
                    if ($err !== self::OK) {
                        return $err;
                    }
                }
                if (self::is_punct($tokens[$i] ?? null, ',') && !$is_trigger) {
                    $i++;
                    continue;
                }
                break;
            }
            while ($i < $count) {
                if (!self::is_word($tokens[$i], array('RESTRICT', 'CASCADE'))) {
                    return 'unexpected_tokens';
                }
                $i++;
            }

            return self::OK;
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function check_alter(array $tokens, array $policy): string
        {
            $i = 1;
            if (self::is_word($tokens[$i] ?? null, array('ONLINE', 'IGNORE'))) {
                $i++;
            }
            if (!self::is_word($tokens[$i] ?? null, array('TABLE'))) {
                return 'alter_kind_not_allowed';
            }
            $i++;
            $err = self::expect_allowed_table($tokens, $i, $policy);
            if ($err !== self::OK) {
                return $err;
            }
            $count = count($tokens);
            for (; $i < $count; $i++) {
                $t = $tokens[$i];
                if (self::is_word($t, array('EXCHANGE', 'SELECT', 'FROM', 'UNION'))) {
                    return 'alter_clause_not_allowed';
                }
                if (self::is_word($t, array('RENAME'))) {
                    $j = $i + 1;
                    if (self::is_word($tokens[$j] ?? null, array('COLUMN', 'INDEX', 'KEY', 'CONSTRAINT'))) {
                        continue;
                    }
                    if (self::is_word($tokens[$j] ?? null, array('TO', 'AS'))) {
                        $j++;
                    }
                    $err = self::expect_allowed_table($tokens, $j, $policy);
                    if ($err !== self::OK) {
                        return $err;
                    }
                    $i = $j - 1;
                }
                if (self::is_word($t, array('REFERENCES'))) {
                    $j = $i + 1;
                    $err = self::expect_allowed_table($tokens, $j, $policy);
                    if ($err !== self::OK) {
                        return $err;
                    }
                }
            }

            return self::OK;
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function check_rename(array $tokens, array $policy): string
        {
            $i = 1;
            if (!self::is_word($tokens[$i] ?? null, array('TABLE', 'TABLES'))) {
                return 'rename_kind_not_allowed';
            }
            $i++;
            $count = count($tokens);
            while (true) {
                $err = self::expect_allowed_table($tokens, $i, $policy);
                if ($err !== self::OK) {
                    return $err;
                }
                if (!self::is_word($tokens[$i] ?? null, array('TO'))) {
                    return 'rename_syntax';
                }
                $i++;
                $err = self::expect_allowed_table($tokens, $i, $policy);
                if ($err !== self::OK) {
                    return $err;
                }
                if (self::is_punct($tokens[$i] ?? null, ',')) {
                    $i++;
                    continue;
                }
                break;
            }

            return $i === $count ? self::OK : 'unexpected_tokens';
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function check_lock(array $tokens, array $policy): string
        {
            $i = 1;
            if (!self::is_word($tokens[$i] ?? null, array('TABLES', 'TABLE'))) {
                return 'lock_kind_not_allowed';
            }
            $i++;
            $count = count($tokens);
            while ($i < $count) {
                $err = self::expect_allowed_table($tokens, $i, $policy);
                if ($err !== self::OK) {
                    return $err;
                }
                while ($i < $count && !self::is_punct($tokens[$i], ',')) {
                    if (!self::is_word($tokens[$i], array('READ', 'WRITE', 'LOCAL', 'LOW_PRIORITY', 'AS')) && $tokens[$i]['t'] !== 'w' && $tokens[$i]['t'] !== 'id') {
                        return 'unexpected_tokens';
                    }
                    $i++;
                }
                if ($i < $count) {
                    $i++;
                }
            }

            return self::OK;
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         */
        private static function check_transaction(array $tokens): string
        {
            $u = array();
            foreach ($tokens as $t) {
                if ($t['t'] !== 'w') {
                    return 'unrecognized_statement';
                }
                $u[] = $t['u'];
            }
            $s = implode(' ', $u);
            $allowed = array('START TRANSACTION', 'BEGIN', 'BEGIN WORK', 'COMMIT', 'COMMIT WORK', 'ROLLBACK', 'ROLLBACK WORK');

            return in_array($s, $allowed, true) ? self::OK : 'unrecognized_statement';
        }

        /**
         * View/trigger bodies: every table referenced after FROM/JOIN/INTO/UPDATE/TABLE/REFERENCES
         * (including comma-joined FROM lists) must be an allowed table.
         *
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function check_body_references(array $tokens, int $start, array $policy): string
        {
            $count = count($tokens);
            for ($i = $start; $i < $count; $i++) {
                $t = $tokens[$i];
                if ($t['t'] !== 'w') {
                    continue;
                }
                if (in_array($t['u'], array('CALL', 'PREPARE', 'EXECUTE', 'GRANT', 'REVOKE', 'LOAD', 'HANDLER', 'DO', 'CREATE', 'DROP', 'ALTER', 'RENAME', 'TRUNCATE', 'LOCK', 'FLUSH', 'KILL', 'SHUTDOWN', 'USE'), true)) {
                    return 'body_statement_not_allowed';
                }
                if (!in_array($t['u'], array('FROM', 'JOIN', 'STRAIGHT_JOIN', 'INTO', 'UPDATE', 'TABLE', 'REFERENCES', 'INSERT', 'REPLACE'), true)) {
                    continue;
                }
                $j = $i + 1;
                // UPDATE LOW_PRIORITY … / INSERT DELAYED IGNORE [INTO] …
                while (self::is_word($tokens[$j] ?? null, array('LOW_PRIORITY', 'IGNORE', 'DELAYED', 'HIGH_PRIORITY'))) {
                    $j++;
                }
                if (in_array($t['u'], array('INSERT', 'REPLACE'), true) && self::is_word($tokens[$j] ?? null, array('INTO'))) {
                    continue; // The following INTO is checked on its own.
                }
                $next = $tokens[$j] ?? null;
                if ($next === null) {
                    return 'body_reference_invalid';
                }
                if (self::is_punct($next, '(') || self::is_word($next, array('DUAL', 'LATERAL'))) {
                    continue; // Derived table / DUAL — inner FROMs are checked on their own.
                }
                if ($t['u'] === 'INTO' && $next['t'] === 'var') {
                    continue; // SELECT … INTO @var / local variable (trigger bodies).
                }
                while (true) {
                    $err = self::expect_allowed_table($tokens, $j, $policy);
                    if ($err !== self::OK) {
                        return $err;
                    }
                    if (self::is_word($tokens[$j] ?? null, array('AS'))) {
                        $j++;
                    }
                    if (($tokens[$j] ?? null) !== null && ($tokens[$j]['t'] === 'id' || ($tokens[$j]['t'] === 'w' && !self::is_clause_keyword($tokens[$j]['u'])))) {
                        $j++; // alias
                    }
                    if (in_array($t['u'], array('FROM', 'UPDATE'), true) && self::is_punct($tokens[$j] ?? null, ',')) {
                        $j++;
                        if (self::is_punct($tokens[$j] ?? null, '(')) {
                            break;
                        }
                        continue;
                    }
                    break;
                }
            }

            return self::OK;
        }

        private static function is_clause_keyword(string $u): bool
        {
            return in_array($u, array(
                'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'HAVING', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'NATURAL',
                'STRAIGHT_JOIN', 'ON', 'USING', 'SET', 'UNION', 'WINDOW', 'FOR', 'LOCK', 'VALUES', 'VALUE', 'SELECT',
                'INTO', 'FROM', 'PARTITION', 'USE', 'FORCE', 'IGNORE', 'WITH', 'END', 'THEN', 'ELSE', 'WHEN', 'AND', 'OR',
                'EXCEPT', 'INTERSECT', 'RETURNING', 'OUTER', 'FULL',
            ), true);
        }

        /**
         * Reads a (possibly schema-qualified) identifier at $i and checks it against the policy.
         *
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param array<string,mixed> $policy
         */
        private static function expect_allowed_table(array $tokens, int &$i, array $policy): string
        {
            $schema = null;
            $name = self::read_table_name($tokens, $i, $schema);
            if ($name === null) {
                return 'table_name_unrecognized';
            }
            if ($schema !== null) {
                $db = (string) ($policy['db_name'] ?? '');
                if ($db === '' || strcasecmp($schema, $db) !== 0) {
                    return 'foreign_schema';
                }
            }

            return self::table_allowed($name, $policy) ? self::OK : 'table_not_allowed';
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         */
        private static function read_table_name(array $tokens, int &$i, ?string &$schema = null): ?string
        {
            $schema = null;
            $first = $tokens[$i] ?? null;
            if ($first === null || ($first['t'] !== 'id' && $first['t'] !== 'w')) {
                return null;
            }
            $name = $first['v'];
            $i++;
            if (self::is_punct($tokens[$i] ?? null, '.')) {
                $second = $tokens[$i + 1] ?? null;
                if ($second === null || ($second['t'] !== 'id' && $second['t'] !== 'w')) {
                    return null;
                }
                $schema = $name;
                $name = $second['v'];
                $i += 2;
            }

            return $name === '' ? null : $name;
        }

        /**
         * @param array<int,array{t:string,v:string,u:string}> $tokens
         * @param string[] $words
         */
        private static function reject_words_after(array $tokens, int $start, array $words): string
        {
            $count = count($tokens);
            for ($i = $start; $i < $count; $i++) {
                if ($tokens[$i]['t'] === 'w' && in_array($tokens[$i]['u'], $words, true)) {
                    return 'clause_not_allowed';
                }
            }

            return self::OK;
        }

        /**
         * @param array{t:string,v:string,u:string}|null|false $token
         * @param string[] $words
         */
        private static function is_word($token, array $words): bool
        {
            return is_array($token) && $token['t'] === 'w' && in_array($token['u'], $words, true);
        }

        /**
         * @param array{t:string,v:string,u:string}|null|false $token
         */
        private static function is_punct($token, string $char): bool
        {
            return is_array($token) && $token['t'] === 'p' && $token['v'] === $char;
        }
    }
}
