<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Canonical serialized-safe search-replace (no WordPress dependencies).
 */

class Rmmigrate_Search_Replace_Core
{
    /** @var array<int,array{search:string,replace:string}> */
    private $pairs = array();

    /** @var array<string,bool> */
    private $seen_searches = array();

    /** @var bool */
    private $pairs_sorted = false;

    /** @var array<string,int> */
    private $counts = array();

    /** @var int */
    private $skipped_custom_serializable = 0;

    /**
     * @param array<string,mixed> $migration_map
     */
    public function __construct(array $migration_map)
    {
        $this->build_pairs($migration_map);
    }

    /**
     * @param array<string,mixed> $migration_map
     */
    private function build_pairs(array $migration_map): void
    {
        if (!empty($migration_map['site_url']['old']) && !empty($migration_map['site_url']['new'])) {
            $this->add_url_pair($migration_map['site_url']['old'], $migration_map['site_url']['new']);
        }
        if (!empty($migration_map['home_url']['old']) && !empty($migration_map['home_url']['new'])) {
            $this->add_url_pair($migration_map['home_url']['old'], $migration_map['home_url']['new']);
        }

        if (!empty($migration_map['blogs']) && is_array($migration_map['blogs'])) {
            foreach ($migration_map['blogs'] as $blog) {
                if (!empty($blog['old_domain']) && !empty($blog['new_domain'])) {
                    $this->add_pair($blog['old_domain'], $blog['new_domain']);
                }
            }
        }

        $this->add_json_escaped_url_variants();
    }

    private function add_json_escaped_url_variants(): void
    {
        $extra = array();
        foreach ($this->pairs as $pair) {
            if (strpos($pair['search'], 'http') === false) {
                continue;
            }
            $search = str_replace('/', '\\/', $pair['search']);
            $replace = str_replace('/', '\\/', $pair['replace']);
            if ($search !== $pair['search']) {
                $extra[] = array('search' => $search, 'replace' => $replace);
            }
        }
        foreach ($extra as $pair) {
            $this->add_pair($pair['search'], $pair['replace']);
        }
    }

    private function add_url_pair(string $old, string $new): void
    {
        $variants = $this->url_variants($old, $new);
        foreach ($variants as $pair) {
            $this->add_pair($pair['search'], $pair['replace']);
        }
    }

    /**
     * @return array<int,array{search:string,replace:string}>
     */
    private function url_variants(string $old, string $new): array
    {
        $pairs = array();
        $olds = array(rtrim($old, '/'), rtrim($old, '/') . '/');
        $news = array(rtrim($new, '/'), rtrim($new, '/') . '/');

        foreach ($olds as $i => $o) {
            $pairs[] = array('search' => $o, 'replace' => $news[$i]);
        }

        foreach (array('http', 'https') as $scheme) {
            $o = preg_replace('#^https?://#', $scheme . '://', rtrim($old, '/'));
            $n = preg_replace('#^https?://#', $scheme . '://', rtrim($new, '/'));
            if ($o && $n) {
                $pairs[] = array('search' => $o, 'replace' => $n);
                $pairs[] = array('search' => $o . '/', 'replace' => $n . '/');
            }
        }

        $old_parsed = wp_parse_url($old);
        $new_parsed = wp_parse_url($new);
        if (is_array($old_parsed) && is_array($new_parsed)) {
            $old_host = (string) ($old_parsed['host'] ?? '');
            $new_host = (string) ($new_parsed['host'] ?? '');
            $old_port = isset($old_parsed['port']) ? (int) $old_parsed['port'] : 0;
            if ($old_host !== '' && $new_host !== '' && $old_port > 0) {
                foreach (array('http', 'https') as $scheme) {
                    $with_port = $scheme . '://' . $old_host . ':' . $old_port;
                    $target = $scheme . '://' . $new_host;
                    if (!empty($new_parsed['port'])) {
                        $target .= ':' . (int) $new_parsed['port'];
                    }
                    $pairs[] = array('search' => $with_port, 'replace' => $target);
                    $pairs[] = array('search' => $with_port . '/', 'replace' => $target . '/');
                }
            }
        }

        return $pairs;
    }

    private function add_pair(string $search, string $replace): void
    {
        if ($search === '' || $search === $replace || isset($this->seen_searches[$search])) {
            return;
        }
        $this->seen_searches[$search] = true;
        $this->pairs[] = array('search' => $search, 'replace' => $replace);
        $this->pairs_sorted = false;
    }

    public function append_pair(string $search, string $replace): void
    {
        $this->add_pair($search, $replace);
    }

    /**
     * @return array<int,array{search:string,replace:string}>
     */
    public function get_pairs(): array
    {
        return $this->pairs;
    }

    public function apply(string $data): string
    {
        if ($this->pairs === array()) {
            return $data;
        }

        if (!$this->pairs_sorted) {
            // Longest search first; equal lengths keep insertion order, so the explicit
            // old -> new pair beats its scheme variants (usort is not stable before PHP 8.0).
            $order = array();
            foreach ($this->pairs as $index => $pair) {
                $order[] = array('i' => $index, 'p' => $pair);
            }
            usort($order, static function ($a, $b) {
                $by_len = strlen($b['p']['search']) <=> strlen($a['p']['search']);
                return $by_len !== 0 ? $by_len : $a['i'] <=> $b['i'];
            });
            $this->pairs = array_column($order, 'p');
            $this->pairs_sorted = true;
        }

        return $this->replace_string_or_serialized($data);
    }

    private function replace_string_or_serialized(string $data): string
    {
        if ($this->is_custom_serializable($data)) {
            $this->skipped_custom_serializable++;
            return $data;
        }

        if ($this->is_serialized($data)) {
            $unserialized = @unserialize($data, array('allowed_classes' => false));
            if ($unserialized !== false || $data === 'b:0;') {
                return serialize($this->replace_deep($unserialized));
            }
        }

        return $this->apply_all_pairs_to_string($data);
    }

    private function apply_all_pairs_to_string(string $original): string
    {
        $matches = $this->collect_non_cascading_matches($original);
        if ($matches === array()) {
            return $original;
        }

        usort($matches, static function ($a, $b) {
            return $b['pos'] <=> $a['pos'];
        });

        $result = $original;
        foreach ($matches as $match) {
            $result = substr_replace($result, $match['replace'], $match['pos'], $match['len']);
            $key = $match['search_key'];
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        }

        return $result;
    }

    /**
     * @return array<int,array{pos:int,len:int,replace:string,search_key:string}>
     */
    private function collect_non_cascading_matches(string $original): array
    {
        /** @var array<int,array{pos:int,len:int,end:int,replace:string,search_key:string,ord:int}> $candidates */
        $candidates = array();
        $ord = 0;

        foreach ($this->pairs as $pair) {
            $search = $pair['search'];
            if ($search === '') {
                continue;
            }

            $search_len = strlen($search);
            $offset = 0;
            while (($pos = strpos($original, $search, $offset)) !== false) {
                if ($this->should_skip_prefix_extension_match($original, $pos, $search, $pair['replace'])
                    || $this->should_skip_partial_host_match($original, $pos, $search)) {
                    $offset = $pos + 1;
                    continue;
                }
                $candidates[] = array(
                    'pos'        => $pos,
                    'len'        => $search_len,
                    'end'        => $pos + $search_len,
                    'replace'    => $pair['replace'],
                    'search_key' => $search,
                    'ord'        => $ord,
                );
                $offset = $pos + 1;
            }
            $ord++;
        }

        if ($candidates === array()) {
            return array();
        }

        usort($candidates, static function ($a, $b) {
            if ($a['pos'] !== $b['pos']) {
                return $a['pos'] <=> $b['pos'];
            }
            if ($a['len'] !== $b['len']) {
                return $b['len'] <=> $a['len'];
            }

            return $a['ord'] <=> $b['ord'];
        });

        $matches = array();
        $last_end = 0;
        foreach ($candidates as $c) {
            if ($c['pos'] < $last_end) {
                continue;
            }
            $matches[] = array(
                'pos'        => $c['pos'],
                'len'        => $c['len'],
                'replace'    => $c['replace'],
                'search_key' => $c['search_key'],
            );
            $last_end = $c['end'];
        }

        return $matches;
    }

    /**
     * A bare host pair (old.com -> new.com) must only match a whole host label:
     * "gold.com" or "my-old.com" are different domains and stay untouched.
     * Subdomains (cdn.old.com) and e-mail hosts (info@old.com) still match.
     */
    private function should_skip_partial_host_match(string $original, int $pos, string $search): bool
    {
        if ($pos <= 0 || $search === '') {
            return false;
        }
        if (strpos($search, '://') !== false || strpos($search, ':\\/\\/') !== false) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9]/', $search)) {
            return false;
        }

        // URL-encoded (%2F, %40) and JSON-escaped (\u002F, \u0040) separators end in a letter or digit.
        if (preg_match('/(?:%2F|%40|\\\\u002F|\\\\u0040)$/i', substr($original, max(0, $pos - 6), min(6, $pos)))) {
            return false;
        }

        return self::is_word_char_at($original, self::utf8_char_start($original, $pos - 1));
    }

    /**
     * Byte offset of the first byte of the UTF-8 character that contains $byte.
     */
    private static function utf8_char_start(string $s, int $byte): int
    {
        $limit = max(0, $byte - 3);
        while ($byte > $limit && (ord($s[$byte]) & 0xC0) === 0x80) {
            $byte--;
        }

        return $byte;
    }

    /**
     * Letters, digits (any script), "_" and "-" glue a host to its neighbour.
     * Punctuation and spaces such as "«", "→" or a no-break space do not.
     */
    private static function is_word_char_at(string $s, int $byte): bool
    {
        $lead = ord($s[$byte]);
        if ($lead < 0x80) {
            return (bool) preg_match('/[A-Za-z0-9_-]/', $s[$byte]);
        }
        $len = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : 2);
        $char = substr($s, $byte, $len);
        $hit = @preg_match('/^[\p{L}\p{N}\p{M}]$/u', $char);

        // Invalid UTF-8 (e.g. Latin-1 data): keep the conservative "no partial match" rule.
        return $hit === false ? true : (bool) $hit;
    }

    private function should_skip_prefix_extension_match(string $original, int $pos, string $search, string $replace): bool
    {
        if ($search === '' || $search === $replace) {
            return false;
        }
        $after = $pos + strlen($search);
        if ($after >= strlen($original)) {
            return false;
        }
        $next = $original[$after];

        if (self::is_word_char_at($original, $after)) {
            return true;
        }
        if ($next === ':' && ($after + 1) < strlen($original) && ctype_digit($original[$after + 1])) {
            return true;
        }
        if ($next === '.' && ($after + 1) < strlen($original)) {
            return (bool) preg_match('/[A-Za-z0-9]/', $original[$after + 1]);
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function replace_deep($value, array &$seen_arrays = array(), array &$seen_objects = array())
    {
        if (is_string($value)) {
            if ($this->is_custom_serializable($value)) {
                $this->skipped_custom_serializable++;
                return $value;
            }
            if ($this->is_serialized($value)) {
                $inner = @unserialize($value, array('allowed_classes' => false));
                if ($inner !== false || $value === 'b:0;') {
                    return serialize($this->replace_deep($inner, $seen_arrays, $seen_objects));
                }
            }
            return $this->apply_all_pairs_to_string($value);
        }

        if (is_array($value)) {
            foreach ($seen_arrays as &$seen_array) {
                if ($seen_array === $value) {
                    return $value;
                }
            }
            unset($seen_array);
            $seen_arrays[] = &$value;
            foreach ($value as $k => $v) {
                $value[$k] = $this->replace_deep($v, $seen_arrays, $seen_objects);
            }
            array_pop($seen_arrays);

            return $value;
        }

        if (is_object($value)) {
            if ($value instanceof __PHP_Incomplete_Class) {
                $oid = spl_object_id($value);
                if (isset($seen_objects[$oid])) {
                    return $value;
                }
                $seen_objects[$oid] = true;
                return $this->replace_incomplete_object($value, $seen_arrays, $seen_objects);
            }
            $oid = spl_object_id($value);
            if (isset($seen_objects[$oid])) {
                return $value;
            }
            $seen_objects[$oid] = true;
            foreach (get_object_vars($value) as $k => $v) {
                $value->$k = $this->replace_deep($v, $seen_arrays, $seen_objects);
            }
            unset($seen_objects[$oid]);
            return $value;
        }

        return $value;
    }

    /**
     * Process an object whose class definition is not loaded (e.g. Freemius
     * FS_Plugin in the standalone installer). PHP forbids writing properties on
     * __PHP_Incomplete_Class, so rebuild the serialized object manually while
     * preserving its original class name and property structure.
     *
     * @param __PHP_Incomplete_Class $value
     * @return mixed
     */
    private function replace_incomplete_object($value, array &$seen_arrays = array(), array &$seen_objects = array())
    {
        $vars = (array) $value;
        $class = isset($vars['__PHP_Incomplete_Class_Name']) ? (string) $vars['__PHP_Incomplete_Class_Name'] : '';
        unset($vars['__PHP_Incomplete_Class_Name']);

        if ($class === '') {
            return $value;
        }

        $serialized = 'O:' . strlen($class) . ':"' . $class . '":' . count($vars) . ':{';
        foreach ($vars as $prop_key => $prop_value) {
            $serialized .= serialize((string) $prop_key);
            $serialized .= serialize($this->replace_deep($prop_value, $seen_arrays, $seen_objects));
        }
        $serialized .= '}';

        $rebuilt = @unserialize($serialized, array('allowed_classes' => false));

        return false === $rebuilt ? $value : $rebuilt;
    }

    private function is_custom_serializable(string $data): bool
    {
        return strlen($data) >= 4 && $data[0] === 'C' && $data[1] === ':';
    }

    private function is_serialized(string $data): bool
    {
        if ($this->is_custom_serializable($data)) {
            return false;
        }
        if ($data === 'N;') {
            return true;
        }
        if (strlen($data) < 4 || $data[1] !== ':') {
            return false;
        }
        $last = substr($data, -1);
        if ($last !== ';' && $last !== '}') {
            return false;
        }
        $token = $data[0];
        return in_array($token, array('s', 'a', 'O', 'b', 'i', 'd'), true);
    }

    /**
     * Search-replace inside one SQL statement (INSERT ... VALUES ('...'), ...).
     *
     * Each quoted string literal is unescaped, run through apply() and
     * re-escaped, so PHP-serialized values keep correct length prefixes. A plain
     * replace on the raw statement would corrupt them whenever old and new URL
     * differ in length. Text outside literals (identifiers, keywords, hex) is
     * left untouched. Literals without a match are copied byte for byte.
     */
    public function apply_to_sql_statement(string $statement): string
    {
        if ($this->pairs === array() || !$this->sql_statement_may_match($statement)) {
            return $statement;
        }

        $len = strlen($statement);
        $out = '';
        $i = 0;
        while ($i < $len) {
            $plain = strcspn($statement, "'\"`", $i);
            if ($plain > 0) {
                $out .= substr($statement, $i, $plain);
                $i += $plain;
            }
            if ($i >= $len) {
                break;
            }

            if ($statement[$i] === '`') {
                $close = strpos($statement, '`', $i + 1);
                if ($close === false) {
                    $out .= substr($statement, $i);
                    break;
                }
                $out .= substr($statement, $i, $close - $i + 1);
                $i = $close + 1;
                continue;
            }

            $quote = $statement[$i];
            $j = $i + 1;
            $closed = false;
            while ($j < $len) {
                $j += strcspn($statement, $quote . '\\', $j);
                if ($j >= $len) {
                    break;
                }
                if ($statement[$j] === '\\') {
                    $j += 2;
                    continue;
                }
                if ($j + 1 < $len && $statement[$j + 1] === $quote) {
                    $j += 2;
                    continue;
                }
                $closed = true;
                break;
            }
            if (!$closed) {
                // Unterminated literal: leave the rest exactly as it was.
                $out .= substr($statement, $i);
                break;
            }

            $out .= $quote . $this->replace_sql_literal(substr($statement, $i + 1, $j - $i - 1), $quote) . $quote;
            $i = $j + 1;
        }

        return $out;
    }

    private function sql_statement_may_match(string $statement): bool
    {
        foreach ($this->pairs as $pair) {
            $search = $pair['search'];
            if (strpos($statement, $search) !== false) {
                return true;
            }
            $escaped = self::escape_sql_literal($search);
            if ($escaped !== $search && strpos($statement, $escaped) !== false) {
                return true;
            }
        }

        return false;
    }

    private function replace_sql_literal(string $raw, string $quote): string
    {
        $value = (strpos($raw, '\\') === false && strpos($raw, $quote . $quote) === false)
            ? $raw
            : self::unescape_sql_literal($raw, $quote);

        $hit = false;
        foreach ($this->pairs as $pair) {
            if (strpos($value, $pair['search']) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return $raw;
        }

        $replaced = $this->apply($value);
        if ($replaced === $value) {
            return $raw;
        }

        return self::escape_sql_literal($replaced);
    }

    /**
     * MySQL string-literal unescape (default sql_mode; NO_BACKSLASH_ESCAPES dumps are rejected on import).
     */
    private static function unescape_sql_literal(string $raw, string $quote): string
    {
        $map = array(
            '0'  => "\0",
            "'"  => "'",
            '"'  => '"',
            'b'  => "\x08",
            'n'  => "\n",
            'r'  => "\r",
            't'  => "\t",
            'Z'  => "\x1A",
            '\\' => '\\',
        );
        $out = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $raw[++$i];
                if (isset($map[$next])) {
                    $out .= $map[$next];
                } elseif ($next === '%' || $next === '_') {
                    // MySQL keeps the backslash for \% and \_.
                    $out .= '\\' . $next;
                } else {
                    $out .= $next;
                }
                continue;
            }
            if ($ch === $quote && $i + 1 < $len && $raw[$i + 1] === $quote) {
                $out .= $quote;
                $i++;
                continue;
            }
            $out .= $ch;
        }

        return $out;
    }

    private static function escape_sql_literal(string $value): string
    {
        return strtr($value, array(
            '\\'   => '\\\\',
            "\0"   => '\\0',
            "\n"   => '\\n',
            "\r"   => '\\r',
            "'"    => "\\'",
            '"'    => '\\"',
            "\x1A" => '\\Z',
        ));
    }

    /**
     * @return array<string,int>
     */
    public function get_counts(): array
    {
        return $this->counts;
    }

    public function get_skipped_custom_serializable_count(): int
    {
        return $this->skipped_custom_serializable;
    }
}
