<?php

/**
 * Legacy multisite upload path detection.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Mu_Legacy
{
    public static function get_mu_generation(): int
    {
        if (!is_multisite()) {
            return 0;
        }
        if (defined('UPLOADS')) {
            return 1;
        }
        if (Rmmigrate_Filesystem::is_dir(WP_CONTENT_DIR . '/blogs.dir')) {
            return 2;
        }
        return 3;
    }

    /**
     * @return string[]
     */
    public static function subsite_upload_paths(int $blog_id, string $wp_content_dir): array
    {
        $paths = array();
        $gen = self::get_mu_generation();

        if ((int) $blog_id === 1) {
            $uploads_dir = Rmmigrate_Engine_Config::uploads_basedir_for_blog($blog_id);
            if (Rmmigrate_Filesystem::is_dir($uploads_dir)) {
                foreach (scandir($uploads_dir) as $node) {
                    if ($node === '.' || $node === '..' || $node === 'sites') {
                        continue;
                    }
                    $full = $uploads_dir . '/' . $node;
                    if (Rmmigrate_Filesystem::is_dir($full) || Rmmigrate_Filesystem::is_file($full)) {
                        $paths[] = $full;
                    }
                }
            }
            return $paths;
        }

        switch ($gen) {
            case 1:
                if (defined('UPLOADS')) {
                    $paths[] = ABSPATH . UPLOADS;
                }
                break;
            case 2:
                $blogs_dir = $wp_content_dir . '/blogs.dir/' . $blog_id;
                if (Rmmigrate_Filesystem::is_dir($blogs_dir)) {
                    $paths[] = $blogs_dir;
                }
                break;
            default:
                $sites_path = Rmmigrate_Engine_Config::uploads_basedir_for_blog($blog_id);
                if (Rmmigrate_Filesystem::is_dir($sites_path)) {
                    $paths[] = $sites_path;
                }
                $legacy = $wp_content_dir . '/blogs.dir/' . $blog_id;
                if (Rmmigrate_Filesystem::is_dir($legacy)) {
                    $paths[] = $legacy;
                }
                break;
        }

        return $paths;
    }
}
