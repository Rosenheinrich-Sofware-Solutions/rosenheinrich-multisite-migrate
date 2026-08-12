<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Ajax_Cleanup
{
    use Rmmigrate_Ajax_Base;

    public static function register(): void
    {
        add_action('wp_ajax_rmmigrate_scan_install_files', array(__CLASS__, 'scan_install_files'));
        add_action('wp_ajax_rmmigrate_delete_install_files', array(__CLASS__, 'delete_install_files'));
    }

    public static function scan_install_files(): void
    {
        self::verify_request();
        self::assert_network_management();
        wp_send_json_success(Rmmigrate_Cleanup_Service::scan_install_files());
    }

    public static function delete_install_files(): void
    {
        self::verify_request();
        self::assert_network_management();
        wp_send_json_success(Rmmigrate_Cleanup_Service::delete_install_files());
    }
}
