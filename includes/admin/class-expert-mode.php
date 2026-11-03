<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Expert mode: hides server internals (database engine, chunk sizes, storage
 * paths, AI agents, Search & Replace) until an admin asks for them.
 *
 * Hidden settings stay in the form, so saving never resets them.
 */
final class Rmmigrate_Expert_Mode
{
    const OPTION      = 'rmmigrate_expert_mode';
    const AJAX_TOGGLE = 'rmmigrate_toggle_expert_mode';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_TOGGLE, array(__CLASS__, 'ajax_toggle'));
    }

    public static function enabled(): bool
    {
        $value = function_exists('get_site_option') ? get_site_option(self::OPTION, '') : '';

        return (bool) apply_filters('rmmigrate_expert_mode', $value === '1' || $value === 1 || $value === true);
    }

    public static function set(bool $on): void
    {
        update_site_option(self::OPTION, $on ? '1' : '0');
    }

    public static function user_can_toggle(): bool
    {
        if (is_multisite()) {
            return current_user_can('manage_network_options');
        }

        return current_user_can('manage_options');
    }

    public static function ajax_toggle(): void
    {
        $nonce = Rmmigrate_Request_Input::post_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_admin') || !self::user_can_toggle()) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rosenheinrich-multisite-migrate')), 403);
        }

        $on = Rmmigrate_Request_Input::post_text('enabled') === '1';
        self::set($on);
        wp_send_json_success(array('enabled' => $on));
    }
}
