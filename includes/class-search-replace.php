<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once RMMIGRATE_PATH . 'includes/shared/search-replace-core.php';

class Rmmigrate_Search_Replace extends Rmmigrate_Search_Replace_Core
{
    /**
     * @return array<int,array{search:string,replace:string}>
     */
    public static function preview_url_pairs(string $old_url, string $new_url): array
    {
        $old_url = esc_url_raw($old_url);
        $new_url = esc_url_raw($new_url);
        if ($old_url === '' || $new_url === '') {
            return array();
        }

        $map = array(
            'site_url' => array('old' => $old_url, 'new' => $new_url),
            'home_url' => array('old' => $old_url, 'new' => $new_url),
        );

        $engine = new self($map);
        return $engine->get_pairs();
    }
}
