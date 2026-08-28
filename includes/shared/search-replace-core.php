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
                if (!empty($blog['old_path']) && !empty($blog['new_path']) && $blog['old_path'] !== $blog['new_path']) {
                    $this->add_pair($blog['old_path'], $blog['new_path']);
                    $this->add_pair(rtrim($blog['old_path'], '/'), rtrim($blog['new_path'], '/'));
                }
            }
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

        return $pairs;
    }

    private function add_pair(string $search, string $replace): void
    {
        if ($search === '' || $search === $replace) {
            return;
        }
        $this->pairs[] = array('search' => $search, 'replace' => $replace);
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

        usort($this->pairs, static function ($a, $b) {
            return strlen($b['search']) <=> strlen($a['search']);
        });

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
        $matches = array();
        /** @var array<int,array{0:int,1:int}> $occupied */
        $occupied = array();
        $occupied_scan = 0;

        foreach ($this->pairs as $pair) {
            $search = $pair['search'];
            if ($search === '') {
                continue;
            }

            $search_len = strlen($search);
            $offset = 0;
            $occupied_scan = 0;
            while (($pos = strpos($original, $search, $offset)) !== false) {
                $end = $pos + $search_len;
                if (!$this->interval_overlaps($occupied, $pos, $end, $occupied_scan)) {
                    $matches[] = array(
                        'pos'        => $pos,
                        'len'        => $search_len,
                        'replace'    => $pair['replace'],
                        'search_key' => $search,
                    );
                    $this->insert_occupied_interval($occupied, $pos, $end);
                }
                $offset = $pos + 1;
            }
        }

        return $matches;
    }

    /**
     * @param array<int,array{0:int,1:int}> $occupied
     */
    private function insert_occupied_interval(array &$occupied, int $start, int $end): void
    {
        $interval = array($start, $end);
        $count = count($occupied);
        for ($i = 0; $i < $count; $i++) {
            if ($start < $occupied[$i][0]) {
                array_splice($occupied, $i, 0, array($interval));
                return;
            }
        }
        $occupied[] = $interval;
    }

    /**
     * @param array<int,array{0:int,1:int}> $occupied
     */
    private function interval_overlaps(array $occupied, int $start, int $end, int &$from = 0): bool
    {
        $count = count($occupied);
        while ($from < $count && $occupied[$from][1] <= $start) {
            $from++;
        }
        for ($i = $from; $i < $count; $i++) {
            if ($occupied[$i][0] >= $end) {
                break;
            }
            if ($start < $occupied[$i][1] && $end > $occupied[$i][0]) {
                return true;
            }
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
