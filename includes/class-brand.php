<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Brand
{
    public static function mark_url(): string
    {
        return RMMIGRATE_URL . 'assets/img/multisite-migrate-mark.svg';
    }

    public static function mark_alt(): string
    {
        return __('Multisite Migrate logo', 'rosenheinrich-multisite-migrate');
    }

    /**
     * Short sidebar menu label.
     */
    public static function menu_title(): string
    {
        return __('MM', 'rosenheinrich-multisite-migrate');
    }

    /**
     * wp-admin sidebar icon — square PNG only (never the rounded SVG mark).
     */
    public static function menu_icon_url(): string
    {
        return RMMIGRATE_URL . 'assets/img/icon-menu.png';
    }

    /**
     * Help links shown in the wp-admin footer bar.
     *
     * @return array<int,array{label:string,url:string}>
     */
    public static function admin_footer_links(): array
    {
        $links = array(
            array(
                'label' => __('User Manual', 'rosenheinrich-multisite-migrate'),
                'url'   => Rmmigrate_Capabilities::marketing_url(
                    Rmmigrate_Capabilities::docs_url(),
                    'admin_footer_docs'
                ),
            ),
            array(
                'label' => __('Help & support', 'rosenheinrich-multisite-migrate'),
                'url'   => Rmmigrate_Capabilities::marketing_url(
                    Rmmigrate_Capabilities::contact_url(),
                    'admin_footer_contact'
                ),
            ),
            array(
                'label' => __('About Us', 'rosenheinrich-multisite-migrate'),
                'url'   => Rmmigrate_Capabilities::marketing_url(
                    Rmmigrate_Capabilities::website_url(),
                    'admin_footer_website'
                ),
            ),
        );

        return (array) apply_filters('rmmigrate_admin_footer_links', $links);
    }
}
