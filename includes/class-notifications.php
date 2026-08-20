<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Notifications
{
    /**
     * Free edition: email notifications are always available (no license gate).
     */
    public static function can_send(): bool
    {
        return true;
    }

    public static function maybe_send_job(Rmmigrate_Job $job, int $status, ?string $error = null): void
    {
        if (!self::can_send()) {
            return;
        }

        $settings = Rmmigrate_Settings::notification_settings_for_context(self::blog_id_from_job($job));
        if (empty($settings['email_enabled'])) {
            return;
        }

        $is_success = $status === Rmmigrate_Job::STATUS_COMPLETE;
        $is_failure = $status === Rmmigrate_Job::STATUS_ERROR;
        if (!$is_success && !$is_failure) {
            return;
        }

        $context = self::resolve_context($job);
        $mode_key = self::mode_key_for_context($context);
        $mode = $settings[$mode_key] ?? 'failure';

        if (!self::should_send($mode, $is_success, $is_failure)) {
            return;
        }

        $subject = self::build_subject($job, $is_success);
        $body = self::build_body($job, $is_success, $error);
        $activity_url = Rmmigrate_Admin_Router::admin_url(
            'multisite-migrate-activity',
            array(),
            is_multisite() && current_user_can('manage_network')
        );
        self::send(
            $subject,
            $body,
            $settings,
            array(
                'heading'      => self::subject_to_heading($subject),
                'content_html' => self::build_status_content_html(
                    $is_success,
                    $body,
                    $activity_url,
                    __('Open Activity Log', 'rosenheinrich-multisite-migrate')
                ),
                'job_id'       => $job->get_id(),
            )
        );
    }

    public static function maybe_send_event(string $context, string $message, bool $success): void
    {
        if (!self::can_send()) {
            return;
        }

        $blog_id = (class_exists('Rmmigrate_Access', false) && Rmmigrate_Access::is_subsite_admin_context())
            ? (int) get_current_blog_id()
            : null;
        $settings = Rmmigrate_Settings::notification_settings_for_context($blog_id);
        if (empty($settings['email_enabled'])) {
            return;
        }

        $mode_key = self::mode_key_for_context($context);
        $mode = $settings[$mode_key] ?? 'failure';
        if (!self::should_send($mode, $success, !$success)) {
            return;
        }

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] Multisite Migrate — %s', $site, ucfirst($context));
        $home = home_url('/');
        self::send(
            $subject,
            $message,
            $settings,
            array(
                'heading'      => self::subject_to_heading($subject),
                'content_html' => self::build_status_content_html(
                    $success,
                    '',
                    $home,
                    __('Open site', 'rosenheinrich-multisite-migrate'),
                    $message
                ),
            )
        );
    }

    public static function send_test(): bool
    {
        if (!self::can_send()) {
            return false;
        }

        $settings = Rmmigrate_Settings::notification_settings_for_context();
        $posted = Rmmigrate_Request_Input::post_text('email_address');
        if ($posted !== '' && is_email($posted)) {
            $settings['email_address'] = $posted;
        }
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] Multisite Migrate test email', $site);
        $detail = sprintf(
            /* translators: %s: site name */
            __('If you received this message, email delivery is working for %s.', 'rosenheinrich-multisite-migrate'),
            $site
        );
        return self::send(
            $subject,
            $detail,
            $settings,
            array(
                'heading'      => self::subject_to_heading($subject),
                'content_html' => self::build_status_content_html(
                    true,
                    $detail,
                    home_url('/'),
                    __('Open site', 'rosenheinrich-multisite-migrate'),
                    __('This is a test notification from Multisite Migrate.', 'rosenheinrich-multisite-migrate'),
                    'info'
                ),
                'activity'     => 'test',
            )
        );
    }

    public static function notify_schedule_failures(int $count, string $last_error): void
    {
        if (!self::can_send()) {
            return;
        }

        $settings = Rmmigrate_Settings::notification_settings_for_context();
        if (empty($settings['email_enabled'])) {
            return;
        }
        if (!self::should_send($settings['email_schedule_mode'] ?? 'failure', false, true)) {
            return;
        }

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] Multisite Migrate — scheduled backup failures', $site);
        $activity_url = Rmmigrate_Admin_Router::admin_url(
            'multisite-migrate-activity',
            array(),
            is_multisite() && current_user_can('manage_network')
        );
        $body = sprintf(
            __("Failed %1\$d times in a row.\n\nLast error: %2\$s", 'rosenheinrich-multisite-migrate'),
            $count,
            $last_error
        );
        self::send(
            $subject,
            $body,
            $settings,
            array(
                'heading'      => self::subject_to_heading($subject),
                'content_html' => self::build_status_content_html(
                    false,
                    $body,
                    $activity_url,
                    __('Open Activity Log', 'rosenheinrich-multisite-migrate'),
                    __('Scheduled backups failed repeatedly.', 'rosenheinrich-multisite-migrate')
                ),
            )
        );
    }

    private static function resolve_context(Rmmigrate_Job $job): string
    {
        if ($job->get_job_type() === Rmmigrate_Job::JOB_TYPE_RESTORE) {
            return 'restore';
        }
        $progress = $job->get_progress();
        if (!empty($progress['scheduled'])) {
            return 'schedule';
        }
        return 'manual';
    }

    /**
     * @return int|null Blog id for subsite jobs; null for network-scoped mail settings.
     */
    private static function blog_id_from_job(Rmmigrate_Job $job): ?int
    {
        if (!is_multisite()) {
            return null;
        }
        $scope = method_exists($job, 'get_scope') ? (string) $job->get_scope() : '';
        $blog_id = method_exists($job, 'get_blog_id') ? (int) $job->get_blog_id() : 0;
        if ($scope === Rmmigrate_Multisite_Scope::SCOPE_SUBSITE && $blog_id > 0) {
            return $blog_id;
        }

        return null;
    }

    private static function mode_key_for_context(string $context): string
    {
        switch ($context) {
            case 'restore':
                return 'email_restore_mode';
            case 'schedule':
                return 'email_schedule_mode';
            case 'import':
                return 'email_import_mode';
            default:
                return 'email_manual_mode';
        }
    }

    private static function should_send(string $mode, bool $success, bool $failure): bool
    {
        if ($mode === 'never') {
            return false;
        }
        if ($mode === 'always') {
            return true;
        }
        if ($mode === 'success') {
            return $success;
        }
        if ($mode === 'failure') {
            return $failure;
        }
        return false;
    }

    private static function build_subject(Rmmigrate_Job $job, bool $success): string
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $label = $success ? __('completed', 'rosenheinrich-multisite-migrate') : __('failed', 'rosenheinrich-multisite-migrate');
        return sprintf('[%s] Multisite Migrate %s #%d %s', $site, ucfirst($job->get_job_type()), $job->get_id(), $label);
    }

    private static function build_body(Rmmigrate_Job $job, bool $success, ?string $error): string
    {
        $lines = array(
            /* translators: %d: job ID */
            sprintf(__('Job ID: %1$d', 'rosenheinrich-multisite-migrate'), $job->get_id()),
            /* translators: %s: job type */
            sprintf(__('Type: %1$s', 'rosenheinrich-multisite-migrate'), $job->get_job_type()),
            /* translators: %s: job status */
            sprintf(__('Status: %1$s', 'rosenheinrich-multisite-migrate'), $success ? __('Complete', 'rosenheinrich-multisite-migrate') : __('Error', 'rosenheinrich-multisite-migrate')),
        );
        if ($error) {
            /* translators: %s: error message */
            $lines[] = sprintf(__('Error: %1$s', 'rosenheinrich-multisite-migrate'), $error);
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $options
     */
    private static function send(string $subject, string $body, array $settings, array $options = array()): bool
    {
        if (!self::can_send()) {
            return false;
        }

        $to = !empty($settings['email_address']) ? (string) $settings['email_address'] : '';
        if ($to === '' || !is_email($to)) {
            $ctx_blog = isset($settings['_notification_blog_id']) ? (int) $settings['_notification_blog_id'] : null;
            $to = Rmmigrate_Settings::default_admin_email($ctx_blog);
        }
        if ($to === '' || !is_email($to)) {
            self::record_send_result(false, $options, '', __('No valid recipient email address.', 'rosenheinrich-multisite-migrate'));
            return false;
        }

        $heading = isset($options['heading']) ? (string) $options['heading'] : self::subject_to_heading($subject);
        $content_html = isset($options['content_html'])
            ? (string) $options['content_html']
            : self::format_body_html($body);
        $html = self::wrap_html_email($heading, $content_html);
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $from = self::mail_from($to);
        $mail_error = '';
        $on_fail = static function ($error) use (&$mail_error): void {
            if (is_wp_error($error)) {
                $mail_error = $error->get_error_message();
            }
        };
        $from_email = static function () use ($from): string {
            return $from['email'];
        };
        $from_name = static function () use ($from): string {
            return $from['name'];
        };
        add_action('wp_mail_failed', $on_fail, 10, 1);
        add_filter('wp_mail_from', $from_email, 20);
        add_filter('wp_mail_from_name', $from_name, 20);
        $sent = (bool) wp_mail($to, $subject, $html, $headers);
        remove_filter('wp_mail_from_name', $from_name, 20);
        remove_filter('wp_mail_from', $from_email, 20);
        remove_action('wp_mail_failed', $on_fail, 10);

        self::record_send_result($sent, $options, $to, $mail_error);
        return $sent;
    }

    /**
     * @return array{email:string,name:string}
     */
    private static function mail_from(string $fallback_to): array
    {
        $name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $name = trim(str_replace(array("\r", "\n", '<', '>', '"'), '', $name));
        if ($name === '') {
            $name = 'Multisite Migrate';
        }

        $email = Rmmigrate_Settings::default_admin_email();
        if ($email === '' || !is_email($email)) {
            $email = $fallback_to;
        }

        return array(
            'email' => $email,
            'name'  => $name,
        );
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function record_send_result(bool $sent, array $options, string $to, string $mail_error): void
    {
        $kind = isset($options['activity']) ? (string) $options['activity'] : 'notification';
        $data = array();
        if ($to !== '') {
            $data['to'] = $to;
        }
        if (isset($options['job_id'])) {
            $data['job_id'] = (int) $options['job_id'];
        }
        if (!$sent && $mail_error !== '') {
            $data['error'] = $mail_error;
        }

        if ($kind === 'test') {
            if ($sent) {
                $message = __('Test email sent.', 'rosenheinrich-multisite-migrate');
            } elseif ($mail_error !== '') {
                $friendly = self::friendly_mail_error($mail_error);
                $message = sprintf(
                    /* translators: %s: mailer error */
                    __('Test email could not be sent: %s', 'rosenheinrich-multisite-migrate'),
                    $friendly
                );
                set_transient('rmmigrate_test_email_error_' . get_current_user_id(), $friendly, 120);
            } else {
                $message = __('Test email could not be sent. Check your wp_mail / SMTP configuration.', 'rosenheinrich-multisite-migrate');
            }
        } else {
            $message = $sent
                ? __('Notification email sent.', 'rosenheinrich-multisite-migrate')
                : (
                    $mail_error !== ''
                    ? sprintf(
                        /* translators: %s: mailer error */
                        __('Notification email failed to send: %s', 'rosenheinrich-multisite-migrate'),
                        self::friendly_mail_error($mail_error)
                    )
                    : __('Notification email failed to send.', 'rosenheinrich-multisite-migrate')
                );
        }

        Rmmigrate_Activity_Log::record('email', $message, $sent ? 'success' : 'error', $data);
    }

    private static function friendly_mail_error(string $mail_error): string
    {
        $lower = strtolower($mail_error);
        if (
            strpos($lower, 'could not instantiate mail function') !== false
            || strpos($lower, 'failed to connect to mailserver') !== false
        ) {
            return __(
                'PHP mail() is not available on this host. Install an SMTP plugin (or configure sendmail) so WordPress can deliver mail.',
                'rosenheinrich-multisite-migrate'
            );
        }

        return $mail_error;
    }

    private static function subject_to_heading(string $subject): string
    {
        $heading = preg_replace('/^\[[^\]]+\]\s*/', '', $subject);
        if (!is_string($heading) || $heading === '') {
            return __('Notification', 'rosenheinrich-multisite-migrate');
        }

        return $heading;
    }

    private static function build_status_content_html(
        bool $success,
        string $details,
        string $cta_url,
        string $cta_label,
        ?string $lead = null,
        string $tone = ''
    ): string {
        if ($lead === null || $lead === '') {
            $lead = $success
                ? __('The job completed successfully.', 'rosenheinrich-multisite-migrate')
                : __('The job did not complete.', 'rosenheinrich-multisite-migrate');
        }
        if ($tone === '') {
            $tone = $success ? 'success' : 'error';
        }
        $html = self::status_box_html($lead, $tone);
        if (trim($details) !== '') {
            $html .= '<div style="margin:18px 0 0;font-family:\'Nunito\',\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:16px;line-height:26px;color:#1d2327;">';
            $html .= self::format_body_html($details);
            $html .= '</div>';
        }
        if ($cta_url !== '' && $cta_label !== '') {
            $html .= '<div style="padding:16px 0 8px 0;">' . self::cta_button_html($cta_url, $cta_label) . '</div>';
        }

        return $html;
    }

    private static function email_text_link(string $url, string $label): string
    {
        return '<a href="' . esc_url($url) . '" style="color:#0a1220;text-decoration:none;border-bottom:2px solid #14b8a6;font-weight:600;">' . esc_html($label) . '</a>';
    }

    private static function cta_button_html(string $url, string $label): string
    {
        if ($url === '' || $label === '') {
            return '';
        }

        $safe_url = esc_url($url);
        $safe_label = esc_html($label);
        $bg = '#0f766e';
        $vml = '<!--[if mso]>'
            . '<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $safe_url . '" style="height:48px;v-text-anchor:middle;width:280px;" arcsize="17%" strokecolor="' . $bg . '" strokeweight="1px" fillcolor="' . $bg . '">'
            . '<w:anchorlock/>'
            . '<center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;">' . $safe_label . '</center>'
            . '</v:roundrect>'
            . '<![endif]-->';

        return '<table role="presentation" border="0" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto;border-collapse:collapse;">'
            . '<tr><td align="center" class="mm-email-btn-cell" bgcolor="#ffffff" style="padding:0;border:0;background-color:#ffffff !important;">'
            . $vml
            . '<!--[if !mso]><!-- -->'
            . '<a class="mm-email-btn" href="' . $safe_url . '" target="_blank" style="font-family:\'Nunito\',\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:16px;line-height:20px;font-weight:700;color:#ffffff !important;text-decoration:none;display:inline-block;padding:14px 28px;border-radius:8px;background-color:#0f766e !important;border:1px solid #0f766e !important;mso-padding-alt:0;text-align:center;">'
            . $safe_label
            . '</a>'
            . '<!--<![endif]-->'
            . '</td></tr></table>';
    }

    private static function commerce_page_url(string $de_path, string $en_slug, string $campaign): string
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $lang = sanitize_key(substr((string) $locale, 0, 2));
        if ($lang === 'de') {
            $path = $de_path;
        } elseif (in_array($lang, array('fr', 'es', 'it', 'pt', 'nl', 'pl', 'ja', 'zh'), true)) {
            $path = '/' . $lang . '/' . $en_slug . '/';
        } else {
            $path = '/' . $en_slug . '/';
        }

        return Rmmigrate_Capabilities::marketing_url(Rmmigrate_Capabilities::PRICING_BASE_URL . $path, $campaign);
    }

    private static function status_box_html(string $message, string $tone = 'info'): string
    {
        $styles = array(
            'info' => array(
                'bg'     => '#f0fdfa',
                'border' => '#14b8a6',
                'text'   => '#0a1220',
            ),
            'success' => array(
                'bg'     => '#edfaef',
                'border' => '#007017',
                'text'   => '#0a1220',
            ),
            'error' => array(
                'bg'     => '#fcf0f1',
                'border' => '#8a2424',
                'text'   => '#0a1220',
            ),
        );
        $palette = $styles[$tone] ?? $styles['info'];

        return '<div style="margin:0;padding:16px 18px;background:' . $palette['bg'] . ';border-left:4px solid ' . $palette['border'] . ';border-radius:6px;color:' . $palette['text'] . ';font-size:15px;line-height:1.6;">'
            . esc_html($message)
            . '</div>';
    }

    private static function format_body_html(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $chunks = preg_split("/\n\s*\n/", $body);
        if (!is_array($chunks)) {
            $chunks = array($body);
        }

        $html = '';
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (self::is_url_line($chunk)) {
                $html .= '<p style="margin:0 0 14px;">' . self::email_text_link($chunk, $chunk) . '</p>';
                continue;
            }
            $lines = array_map('esc_html', explode("\n", $chunk));
            $html .= '<p style="margin:0 0 14px;line-height:1.6;">' . implode('<br>', $lines) . '</p>';
        }

        return $html;
    }

    private static function is_url_line(string $line): bool
    {
        if (function_exists('wp_http_validate_url')) {
            return (bool) wp_http_validate_url($line);
        }

        return filter_var($line, FILTER_VALIDATE_URL) !== false;
    }

    private static function wrap_html_email(string $heading, string $content_html): string
    {
        $app_name = Rmmigrate_Capabilities::app_name();
        $mark_url = esc_url(RMMIGRATE_URL . 'assets/img/multisite-migrate-mark.png');
        $mark_alt = esc_attr(Rmmigrate_Brand::mark_alt());
        $lang = esc_attr(get_bloginfo('language') ?: 'en');
        $year = esc_html(gmdate('Y'));
        $email_date = function_exists('date_i18n')
            ? date_i18n('M j') . ', ' . gmdate('Y')
            : gmdate('M j, Y');
        $preheader = esc_html($heading !== '' ? $heading : $app_name);
        $privacy = esc_url(Rmmigrate_Capabilities::marketing_url(Rmmigrate_Capabilities::privacy_url(), 'email_notification'));
        $contact = esc_url(Rmmigrate_Capabilities::marketing_url(Rmmigrate_Capabilities::contact_url(), 'email_notification'));
        $terms = esc_url(self::commerce_page_url('/de/agb/', 'terms-of-service', 'email_notification'));
        $legal = esc_url(self::commerce_page_url('/de/impressum/', 'legal-notice', 'email_notification'));
        $heading_html = $heading !== ''
            ? '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><tr><td style="font-family:\'Ubuntu\',\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:24px;line-height:32px;color:#0a1220;font-weight:700;padding:0 0 16px 0;">' . esc_html($heading) . '</td></tr></table>'
            : '';
        $footer_link = static function (string $url, string $label): string {
            return '<a href="' . $url . '" style="color:#0a1220;text-decoration:none;border-bottom:2px solid #14b8a6;font-weight:600;">' . esc_html($label) . '</a>';
        };

        // Portal layout-header/footer chrome (no View in browser — plugin mail has no HTML store).
        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="' . $lang . '" xml:lang="' . $lang . '" style="background-color:#f5f7fa;">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="light only" />
<meta name="supported-color-schemes" content="light only" />
<title>' . esc_html($heading !== '' ? $heading : $app_name) . '</title>
<!--[if mso]>
<style type="text/css">
body, table, td, a { font-family: Arial, Helvetica, sans-serif !important; }
</style>
<![endif]-->
<style type="text/css">
:root { color-scheme: light only; supported-color-schemes: light only; }
html, body { margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f5f7fa !important; }
body { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
img { border: 0 !important; outline: none !important; }
table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
[data-ogsc] .mm-email-btn-cell, [data-ogsb] .mm-email-btn-cell { background-color: #ffffff !important; }
[data-ogsc] .mm-email-btn, [data-ogsb] .mm-email-btn { background-color: #0f766e !important; color: #ffffff !important; border-color: #0f766e !important; }
@media only screen and (max-width: 750px) {
.mm-email-wrap { width: 100% !important; max-width: 100% !important; }
.mm-email-gutter { padding: 0 !important; }
.mm-email-card { border-radius: 0 !important; -webkit-border-radius: 0 !important; max-width: 100% !important; }
.mm-email-side { width: 15px !important; }
.mm-email-pad-header { padding: 12px !important; }
.mm-email-pad-content { padding: 16px 12px 32px !important; }
.mm-email-pad-footer { padding: 13px 16px 32px !important; }
.mm-email-brand { font-size: 22px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;width:100%;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;background-color:#f5f7fa;color:#1d2327;" bgcolor="#f5f7fa">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;color:#f5f7fa;">' . $preheader . '</div>
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f5f7fa" style="border-collapse:collapse;background-color:#f5f7fa;margin:0;padding:0;width:100%;min-width:100%;">
<tr><td align="center" valign="top" bgcolor="#f5f7fa" style="border-collapse:collapse;background-color:#f5f7fa;">
<table role="presentation" align="center" width="750" border="0" cellspacing="0" cellpadding="0" class="mm-email-wrap" bgcolor="#f5f7fa" style="max-width:750px;width:100%;margin:0 auto;table-layout:fixed;border-collapse:collapse;background-color:#f5f7fa;">
<tr><td align="center" valign="middle" bgcolor="#f5f7fa" class="mm-email-gutter" style="padding:8px;border-collapse:collapse;background-color:#f5f7fa;">
<table role="presentation" align="center" width="100%" border="0" cellspacing="0" cellpadding="0" class="mm-email-card" style="max-width:718px;margin:0 auto;border-collapse:collapse;">
<tr><td align="center" valign="middle" bgcolor="#ffffff" class="mm-email-pad-header mm-email-card" style="background-color:#ffffff;padding:16px;border-radius:8px;-webkit-border-radius:8px;border-collapse:collapse;">
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
<tr>
<td class="mm-email-side" width="26" style="width:26px;font-size:1px;line-height:1px;border-collapse:collapse;">&nbsp;</td>
<td align="center" valign="top" style="border-collapse:collapse;">
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
<tr>
<td align="left" valign="middle" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,Arial,sans-serif;font-size:11px;line-height:18px;color:#50575e;font-weight:400;border-collapse:collapse;"><span style="text-decoration:none;color:#50575e;">' . esc_html($email_date) . '</span></td>
<td align="right" valign="middle" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,Arial,sans-serif;font-size:11px;line-height:18px;font-weight:400;border-collapse:collapse;">&nbsp;</td>
</tr>
</table>
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
<tr><td height="12" style="height:12px;font-size:1px;line-height:1px;border-collapse:collapse;">&nbsp;</td></tr>
<tr><td height="1" style="border-top:1px solid #dce1e8;font-size:1px;line-height:1px;height:1px;border-collapse:collapse;">&nbsp;</td></tr>
<tr><td height="20" style="height:20px;font-size:1px;line-height:1px;border-collapse:collapse;">&nbsp;</td></tr>
</table>
<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="display:inline-table;border-collapse:collapse;">
<tr>
<td valign="middle" style="padding-right:8px;border-collapse:collapse;"><img src="' . $mark_url . '" alt="' . $mark_alt . '" width="48" height="48" border="0" style="display:block;width:48px;height:48px;max-width:48px;border:0;outline:none;border-radius:6px;-webkit-border-radius:6px;" /></td>
<td valign="middle" style="border-collapse:collapse;"><span class="mm-email-brand" style="font-family:Arial,Helvetica,sans-serif;font-size:28px;font-weight:700;color:#0a1220;vertical-align:middle;display:inline-block;text-decoration:none;">' . esc_html($app_name) . '</span></td>
</tr>
</table>
</td>
<td class="mm-email-side" width="26" style="width:26px;font-size:1px;line-height:1px;border-collapse:collapse;">&nbsp;</td>
</tr>
</table>
</td></tr>
</table>
</td></tr>
</table>
</td></tr>
</table>
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f5f7fa" style="border-collapse:collapse;background-color:#f5f7fa;width:100%;min-width:100%;">
<tr><td align="center" valign="top" bgcolor="#f5f7fa" style="border-collapse:collapse;background-color:#f5f7fa;">
<table role="presentation" align="center" width="750" border="0" cellspacing="0" cellpadding="0" class="mm-email-wrap" bgcolor="#f5f7fa" style="max-width:750px;width:100%;margin:0 auto;table-layout:fixed;border-collapse:collapse;background-color:#f5f7fa;">
<tr><td align="center" valign="top" bgcolor="#f5f7fa" class="mm-email-gutter" style="padding:0 8px 8px;border-collapse:collapse;background-color:#f5f7fa;">
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#ffffff" class="mm-email-card" style="background-color:#ffffff;border-radius:8px;-webkit-border-radius:8px;max-width:718px;margin:0 auto;border-collapse:collapse;">
<tr><td align="center" valign="top" style="border-collapse:collapse;">
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#14b8a6" class="mm-email-card" style="background-color:#14b8a6;border-top-left-radius:8px;border-top-right-radius:8px;-webkit-border-top-left-radius:8px;-webkit-border-top-right-radius:8px;border-collapse:collapse;">
<tr><td height="12" bgcolor="#14b8a6" class="mm-email-card" style="height:12px;font-size:0;line-height:0;background-color:#14b8a6;border-top-left-radius:8px;border-top-right-radius:8px;-webkit-border-top-left-radius:8px;-webkit-border-top-right-radius:8px;border-collapse:collapse;">&nbsp;</td></tr>
</table>
</td></tr>
<tr><td align="left" valign="top" class="mm-email-pad-content mm-email-card" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,Arial,sans-serif;color:#1d2327;padding:20px 28px 50px;background-color:#ffffff;border-bottom-left-radius:8px;border-bottom-right-radius:8px;-webkit-border-bottom-left-radius:8px;-webkit-border-bottom-right-radius:8px;border-collapse:collapse;font-size:16px;line-height:1.6;">
' . $heading_html . $content_html . '
</td></tr>
</table>
</td></tr>
</table>
</td></tr>
</table>
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#f5f7fa" style="border-collapse:collapse;background-color:#f5f7fa;width:100%;min-width:100%;">
<tr><td align="center" valign="top" bgcolor="#f5f7fa" style="border-collapse:collapse;background-color:#f5f7fa;">
<table role="presentation" align="center" width="750" border="0" cellspacing="0" cellpadding="0" class="mm-email-wrap" bgcolor="#f5f7fa" style="max-width:750px;width:100%;margin:0 auto;border-collapse:collapse;background-color:#f5f7fa;">
<tr><td align="center" class="mm-email-pad-footer" bgcolor="#f5f7fa" style="padding:13px 50px 40px;border-collapse:collapse;background-color:#f5f7fa;">
<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
<tr><td align="center" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,Arial,sans-serif;font-size:11px;line-height:18px;color:#50575e;padding-bottom:12px;border-collapse:collapse;">
' . esc_html(__('You received this email because notifications are enabled for this WordPress site.', 'rosenheinrich-multisite-migrate')) . '
</td></tr>
<tr><td align="center" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,Arial,sans-serif;font-size:11px;line-height:18px;color:#50575e;padding-bottom:12px;border-collapse:collapse;">
' . esc_html(__('Need help?', 'rosenheinrich-multisite-migrate')) . ' ' . $footer_link('mailto:phillip@rosenheinrich.com', 'phillip@rosenheinrich.com') . '
</td></tr>
<tr><td align="center" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,Arial,sans-serif;font-size:11px;line-height:18px;color:#50575e;padding-bottom:12px;border-collapse:collapse;">
<strong style="color:#0a1220;font-weight:700;">Rosenheinrich Software Solutions</strong><br />
' . esc_html(__('Destouchesstr. 3, 80803 Munich, Germany', 'rosenheinrich-multisite-migrate')) . '
</td></tr>
<tr><td align="center" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,Arial,sans-serif;font-size:11px;line-height:18px;color:#50575e;padding-bottom:12px;border-collapse:collapse;">
' . $footer_link($privacy, __('Privacy policy', 'rosenheinrich-multisite-migrate')) . '
&nbsp;&middot;&nbsp;
' . $footer_link($terms, __('Terms of Service', 'rosenheinrich-multisite-migrate')) . '
&nbsp;&middot;&nbsp;
' . $footer_link($legal, __('Legal notice', 'rosenheinrich-multisite-migrate')) . '
&nbsp;&middot;&nbsp;
' . $footer_link($contact, __('Contact support', 'rosenheinrich-multisite-migrate')) . '
</td></tr>
<tr><td align="center" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,Arial,sans-serif;font-size:11px;line-height:18px;color:#50575e;border-collapse:collapse;">
&copy; Rosenheinrich Software Solutions ' . $year . '<br />
' . esc_html(__('Multisite Migrate is a trademark of Rosenheinrich Software Solutions.', 'rosenheinrich-multisite-migrate')) . '
</td></tr>
</table>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
    }
}
