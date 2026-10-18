<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Rmmigrate_Htaccess_Migration', false)) {
    /**
     * Rewrite multisite .htaccess paths when subdirectory install paths change during migration.
     */
    class Rmmigrate_Htaccess_Migration
    {
        public static function rewrite_for_migration(
            string $content,
            string $old_path,
            string $new_path,
            bool $subdomain_install
        ): string {
            if ($content === '') {
                return $content;
            }

            if ($subdomain_install) {
                return self::rewrite_subdomain_policy($content);
            }

            $old_base = self::rewrite_base_value($old_path);
            $new_base = self::rewrite_base_value($new_path);
            if ($old_base === $new_base) {
                return $content;
            }

            $lines = preg_split("/\r\n|\n|\r/", $content);
            if (!is_array($lines)) {
                return $content;
            }

            $rewrite_base_updated = false;
            $has_rewrite_base = false;
            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*RewriteBase\s+(\S+)/i', $line, $matches)) {
                    $has_rewrite_base = true;
                    $current = (string) ($matches[1] ?? '');
                    if ($current === $old_base || $current === rtrim($old_base, '/')) {
                        $lines[$index] = preg_replace(
                            '/^(\s*RewriteBase)\s+\S+/i',
                            '$1 ' . $new_base,
                            $line,
                            1
                        );
                        $rewrite_base_updated = true;
                    }
                    continue;
                }
                if (preg_match('/^\s*RewriteRule\b/i', $line)) {
                    if ($old_base === '/' && $new_base !== '/') {
                        $lines[$index] = (string) preg_replace(
                            '#(/index\.php)#',
                            rtrim($new_base, '/') . '/index.php',
                            $line,
                            1
                        );
                    } elseif ($old_base !== '/' && $old_base !== '') {
                        $lines[$index] = (string) preg_replace(
                            '#' . preg_quote($old_base, '#') . '#',
                            $new_base,
                            $line,
                            1
                        );
                    }
                }
            }

            if (!$rewrite_base_updated && !$has_rewrite_base && preg_match('/^\s*RewriteEngine\s+On\b/im', $content)) {
                $inserted = array();
                foreach ($lines as $line) {
                    $inserted[] = $line;
                    if (!$rewrite_base_updated && preg_match('/^\s*RewriteEngine\s+On\b/i', $line)) {
                        $inserted[] = 'RewriteBase ' . $new_base;
                        $rewrite_base_updated = true;
                    }
                }
                $lines = $inserted;
            }

            return implode("\n", $lines);
        }

        private static function rewrite_subdomain_policy(string $content): string
        {
            return $content;
        }

        private static function rewrite_base_value(string $path): string
        {
            $path = trim($path);
            if ($path === '' || $path === '/') {
                return '/';
            }

            return '/' . trim($path, '/') . '/';
        }
    }
}
