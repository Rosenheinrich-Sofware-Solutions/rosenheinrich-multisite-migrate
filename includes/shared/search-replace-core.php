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

        foreach ($this->pairs as $pair) {
            $key = $pair['search'];
            $before = $data;
            $data = $this->recursive_replace($data, $pair['search'], $pair['replace']);
            if ($data !== $before) {
                $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
            }
        }

        return $data;
    }

    private function recursive_replace(string $data, string $search, string $replace): string
    {
        if ($search === '') {
            return $data;
        }

        if ($this->is_serialized($data)) {
            $unserialized = @unserialize($data, array('allowed_classes' => false));
            if ($unserialized !== false || $data === 'b:0;') {
                $unserialized = $this->replace_deep($unserialized, $search, $replace);
                return serialize($unserialized);
            }
        }

        return str_replace($search, $replace, $data);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function replace_deep($value, string $search, string $replace)
    {
        if (is_string($value)) {
            if ($this->is_serialized($value)) {
                $inner = @unserialize($value, array('allowed_classes' => false));
                if ($inner !== false || $value === 'b:0;') {
                    return serialize($this->replace_deep($inner, $search, $replace));
                }
            }
            return str_replace($search, $replace, $value);
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->replace_deep($v, $search, $replace);
            }
            return $value;
        }

        if (is_object($value)) {
            if ($value instanceof __PHP_Incomplete_Class) {
                return $this->replace_incomplete_object($value, $search, $replace);
            }
            foreach (get_object_vars($value) as $k => $v) {
                $value->$k = $this->replace_deep($v, $search, $replace);
            }
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
    private function replace_incomplete_object($value, string $search, string $replace)
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
            $serialized .= serialize($this->replace_deep($prop_value, $search, $replace));
        }
        $serialized .= '}';

        $rebuilt = @unserialize($serialized, array('allowed_classes' => false));

        return false === $rebuilt ? $value : $rebuilt;
    }

    private function is_serialized(string $data): bool
    {
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
}
