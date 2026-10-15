<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stateful scanner for mysqldump-style SQL streams (DELIMITER + quoted semicolons).
 */
class Rmmigrate_Sql_Import_Stream_Parser
{
    /** @var string */
    private $delimiter = ';';

    public function __construct(string $delimiter = ';')
    {
        $this->delimiter = self::normalize_delimiter($delimiter);
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    public function set_delimiter(string $delimiter): void
    {
        $this->delimiter = self::normalize_delimiter($delimiter);
    }

    public static function normalize_delimiter(string $delimiter): string
    {
        return $delimiter === '' ? ';' : $delimiter;
    }

    public static function parse_delimiter_directive(string $statement): ?string
    {
        $trim = ltrim($statement);
        if (!preg_match('/^DELIMITER\s+(\S+)/i', $trim, $matches)) {
            return null;
        }

        return self::normalize_delimiter((string) $matches[1]);
    }

    /**
     * @return array{statements:string[],remainder:string}
     */
    public function extract_completed_statements(string $buffer): array
    {
        $statements = array();
        $remainder = $buffer;

        while (true) {
            $directive = $this->try_consume_delimiter_directive_line($remainder);
            if ($directive !== null) {
                $remainder = $directive['remainder'];
                continue;
            }

            $pos = $this->find_statement_end($remainder, 0);
            if ($pos === false) {
                break;
            }
            $statement = trim(substr($remainder, 0, $pos));
            $remainder = substr($remainder, $pos + strlen($this->delimiter));
            if ($statement === '') {
                continue;
            }
            $statements[] = $statement;
        }

        return array(
            'statements' => $statements,
            'remainder'  => $remainder,
        );
    }

    /**
     * Consume a mysqldump `DELIMITER` client directive line at the start of the buffer.
     *
     * @return array{remainder:string}|null
     */
    private function try_consume_delimiter_directive_line(string $buffer): ?array
    {
        if ($buffer === '') {
            return null;
        }

        if (!preg_match(
            '/^(?:[ \t]*(?:\r\n|\n|\r))*(?:[ \t]*)DELIMITER[ \t]+(\S+)[ \t]*(?:(\r\n|\n|\r)|$)/i',
            $buffer,
            $matches
        )) {
            return null;
        }

        $consumed = strlen($matches[0]);
        $terminator = (string) ($matches[2] ?? '');
        if ($terminator === '' && $consumed < strlen($buffer)) {
            return null;
        }

        $this->delimiter = self::normalize_delimiter((string) $matches[1]);

        return array('remainder' => substr($buffer, $consumed));
    }

    /**
     * @return int|false Byte index of the active delimiter, or false when incomplete.
     */
    public function find_statement_end(string $buffer, int $start = 0)
    {
        $len = strlen($buffer);
        $delim = $this->delimiter;
        $delim_len = strlen($delim);
        if ($delim_len === 0) {
            return false;
        }

        $in_string = false;
        $quote = '';
        $escaped = false;
        $in_line_comment = false;
        $in_block_comment = false;

        for ($i = max(0, $start); $i < $len; $i++) {
            $ch = $buffer[$i];
            if ($in_line_comment) {
                if ($ch === "\n") {
                    $in_line_comment = false;
                }
                continue;
            }
            if ($in_block_comment) {
                if ($ch === '*' && $i + 1 < $len && $buffer[$i + 1] === '/') {
                    $in_block_comment = false;
                    $i++;
                }
                continue;
            }
            if ($in_string) {
                if ($quote === '`') {
                    if ($ch === '`') {
                        if ($i + 1 < $len && $buffer[$i + 1] === '`') {
                            $i++;
                            continue;
                        }
                        $in_string = false;
                    }
                    continue;
                }
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === $quote) {
                    $in_string = false;
                }
                continue;
            }
            if ($ch === '#' || ($ch === '-' && $i + 1 < $len && $buffer[$i + 1] === '-')) {
                $in_line_comment = true;
                if ($ch === '-') {
                    $i++;
                }
                continue;
            }
            if ($ch === '/' && $i + 1 < $len && $buffer[$i + 1] === '*') {
                $in_block_comment = true;
                $i++;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $in_string = true;
                $quote = $ch;
                $escaped = false;
                continue;
            }
            if ($delim_len > 0 && $i + $delim_len <= $len && substr($buffer, $i, $delim_len) === $delim) {
                return $i;
            }
        }

        return false;
    }

    /**
     * True when the parser tail is only whitespace and/or SQL comments (no executable SQL).
     */
    public static function is_non_sql_remainder(string $remainder): bool
    {
        if ($remainder === '') {
            return true;
        }
        $len = strlen($remainder);
        $i = 0;
        while ($i < $len) {
            $ch = $remainder[$i];
            if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n") {
                $i++;
                continue;
            }
            if ($ch === '-' && $i + 1 < $len && $remainder[$i + 1] === '-') {
                $i += 2;
                while ($i < $len && $remainder[$i] !== "\n" && $remainder[$i] !== "\r") {
                    $i++;
                }
                continue;
            }
            if ($ch === '#') {
                $i++;
                while ($i < $len && $remainder[$i] !== "\n" && $remainder[$i] !== "\r") {
                    $i++;
                }
                continue;
            }
            if ($ch === '/' && $i + 1 < $len && $remainder[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len) {
                    if ($remainder[$i] === '*' && $remainder[$i + 1] === '/') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            return false;
        }

        return true;
    }
}
