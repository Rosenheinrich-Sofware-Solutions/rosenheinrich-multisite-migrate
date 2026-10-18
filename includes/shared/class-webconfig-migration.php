<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Rmmigrate_Webconfig_Migration', false)) {
    /**
     * Rewrite IIS web.config URL paths when subdirectory install paths change during migration.
     */
    class Rmmigrate_Webconfig_Migration
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
                return $content;
            }

            $old_seg = self::path_segment($old_path);
            $new_seg = self::path_segment($new_path);
            if ($old_seg === $new_seg) {
                return $content;
            }

            if ($old_seg === '' && $new_seg !== '') {
                $sub_base = '/' . $new_seg . '/';
                $content = (string) preg_replace(
                    '#url=(["\'])/index\.php#i',
                    'url=$1' . $sub_base . 'index.php',
                    $content
                );
                $content = (string) preg_replace(
                    '#url=(["\'])/wp-admin/#i',
                    'url=$1' . $sub_base . 'wp-admin/',
                    $content
                );
            }

            if ($old_seg === '') {
                return $content;
            }

            $replacements = array(
                '/' . $old_seg . '/' => '/' . $new_seg . '/',
                '/' . $old_seg . '"' => '/' . $new_seg . '"',
                '%2f' . strtolower(rawurlencode($old_seg)) . '%2f' => '%2f' . strtolower(rawurlencode($new_seg)) . '%2f',
            );

            $out = str_replace(array_keys($replacements), array_values($replacements), $content);
            if ($new_seg === '') {
                $out = preg_replace('#(?<!:)/{2,}#', '/', $out) ?? $out;
            }

            return $out;
        }

        private static function path_segment(string $path): string
        {
            $path = trim($path, '/');
            if ($path === '') {
                return '';
            }

            return $path;
        }
    }
}
