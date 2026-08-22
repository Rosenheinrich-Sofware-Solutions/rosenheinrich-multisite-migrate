<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Rmmigrate_Job_Preflight
{
    public static function assert_can_view_job(Rmmigrate_Job $job): void
    {
        if (!Rmmigrate_Access::can_view_job($job)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw Rmmigrate_Service_Exception::forbidden(esc_html(__('Permission denied.', 'rosenheinrich-multisite-migrate')));
        }
    }

    public static function ensure_local_archive(Rmmigrate_Job $job): void
    {
        if (Rmmigrate_Recovery_Points::is_restorable($job)) {
            return;
        }
        if (is_multisite() && !current_user_can('manage_network')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html(__('This backup file is not available on this server.', 'rosenheinrich-multisite-migrate')),
                array('phase' => 'archive_fetch'), sanitize_key(Rmmigrate_Error_Codes::ARCHIVE_MISSING));
        }
        $fetched = Rmmigrate_Local_Archive::ensure_local_archive($job);
        if (is_wp_error($fetched)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured service exception payload.
            throw new Rmmigrate_Service_Exception(
                esc_html($fetched->get_error_message()),
                array('phase' => 'archive_fetch'), sanitize_key(Rmmigrate_Error_Codes::from_wp_error($fetched)));
        }
    }
}
