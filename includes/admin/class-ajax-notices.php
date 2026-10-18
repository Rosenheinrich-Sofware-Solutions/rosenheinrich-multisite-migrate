<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Ajax_Notices
{
    use Rmmigrate_Ajax_Base;

    const UPGRADE_NOTICE_OPTION = 'rmmigrate_upgrade_notice';

    const DISMISS_MULTISITE_UPGRADE_OPTION = 'rmmigrate_dismiss_multisite_upgrade';

    public static function register(): void
    {
        add_action('wp_ajax_rmmigrate_dismiss_upgrade_notice', array(__CLASS__, 'dismiss_upgrade_notice'));
        add_action('wp_ajax_rmmigrate_dismiss_multisite_upgrade_notice', array(__CLASS__, 'dismiss_multisite_upgrade_notice'));
    }

    public static function dismiss_upgrade_notice(): void
    {
        self::verify_request(0, false, 'system');
        delete_site_option(self::UPGRADE_NOTICE_OPTION);
        wp_send_json_success();
    }

    public static function dismiss_multisite_upgrade_notice(): void
    {
        self::verify_request(0, false, 'system');
        update_site_option(self::DISMISS_MULTISITE_UPGRADE_OPTION, 1);
        wp_send_json_success();
    }
}
