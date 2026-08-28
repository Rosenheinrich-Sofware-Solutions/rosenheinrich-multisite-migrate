<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opt-in in-plugin product feedback (sentiment + free text → portal).
 *
 * Remote HTTP only on explicit user Submit (wp.org Guideline 7).
 */
final class Rmmigrate_Feedback
{
    const USER_META_KEY   = 'rmmigrate_feedback';
    const COOLDOWN_DAYS   = 14;
    const MESSAGE_MAX     = 1000;
    const ERROR_MESSAGE_MAX = 500;
    const AJAX_SUBMIT     = 'rmmigrate_feedback_submit';
    const AJAX_DISMISS    = 'rmmigrate_feedback_dismiss';
    const AJAX_SHOWN      = 'rmmigrate_feedback_shown';
    const AJAX_DEACTIVATE = 'rmmigrate_deactivate_feedback';

    /** @var array<string,true> */
    private static $allowed_sentiments = array(
        'positive' => true,
        'neutral'  => true,
        'negative' => true,
    );

    /** @var array<string,true> */
    private static $allowed_types = array(
        'general' => true,
        'bug'     => true,
    );

    /** @var array<string,true> */
    private static $allowed_contexts = array(
        'backup_success'  => true,
        'import_success'  => true,
        'restore_success' => true,
        'backup_fail'     => true,
        'import_fail'     => true,
        'restore_fail'    => true,
        'help'            => true,
        'review_nudge'    => true,
        'other'           => true,
        'deactivate'      => true,
    );

    /** @var array<string,true> */
    private static $allowed_deactivate_reasons = array(
        'temporary'         => true,
        'not_multisite'     => true,
        'too_complex'       => true,
        'missing_feature'   => true,
        'found_alternative' => true,
        'broke_site'        => true,
        'other'             => true,
    );

    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_SUBMIT, array(__CLASS__, 'ajax_submit'));
        add_action('wp_ajax_' . self::AJAX_DISMISS, array(__CLASS__, 'ajax_dismiss'));
        add_action('wp_ajax_' . self::AJAX_SHOWN, array(__CLASS__, 'ajax_shown'));
        add_action('wp_ajax_' . self::AJAX_DEACTIVATE, array(__CLASS__, 'ajax_deactivate_feedback'));
        add_action('admin_footer', array(__CLASS__, 'render_modal'));
        add_filter('rmmigrate_review_nudge_license_notice_blocks', array(__CLASS__, 'block_review_nudge'), 10, 3);
        add_filter('rmmigrate_admin_footer_links', array(__CLASS__, 'footer_link'));
    }

    /**
     * @param array<int,array{label:string,url:string}> $links
     * @return array<int,array{label:string,url:string}>
     */
    public static function footer_link(array $links): array
    {
        if (!self::user_can_see()) {
            return $links;
        }

        $links[] = array(
            'label' => __('Give feedback', 'rosenheinrich-multisite-migrate'),
            'url'   => '#',
        );

        return $links;
    }

    /**
     * Pause review nudge the same day only after feedback was submitted.
     *
     * Auto-prompt shown/dismissed after backup must not hide the review nudge —
     * that prompt fires on every successful backup and would otherwise make the
     * post-backup review banner impossible to see.
     *
     * @param bool   $blocks
     * @param string $page_slug
     * @param bool   $is_network
     */
    public static function block_review_nudge(bool $blocks, string $page_slug, bool $is_network): bool
    {
        unset($page_slug, $is_network);
        if ($blocks) {
            return true;
        }

        $state  = self::get_user_state();
        $status = (string) ($state['status'] ?? '');
        if ($status !== 'submitted') {
            return false;
        }

        $at = (int) ($state['last_prompt_at'] ?? 0);
        if ($at <= 0) {
            return false;
        }

        return (time() - $at) < DAY_IN_SECONDS;
    }

    public static function should_auto_prompt(): bool
    {
        if (!self::user_can_see()) {
            return false;
        }

        $state = self::get_user_state();
        $cooldown_until = (int) ($state['cooldown_until'] ?? 0);
        if ($cooldown_until > time()) {
            return false;
        }

        return true;
    }

    /**
     * Localize payload for admin JS.
     *
     * @return array<string,mixed>
     */
    public static function localize(): array
    {
        $review_url = class_exists('Rmmigrate_Review_Nudge', false)
            ? Rmmigrate_Review_Nudge::review_url()
            : 'https://wordpress.org/support/plugin/rosenheinrich-multisite-migrate/reviews/#new-post';

        return array(
            'enabled'         => self::user_can_see(),
            'shouldPrompt'    => self::should_auto_prompt(),
            'submitAction'    => self::AJAX_SUBMIT,
            'dismissAction'   => self::AJAX_DISMISS,
            'shownAction'     => self::AJAX_SHOWN,
            'messageMax'      => self::MESSAGE_MAX,
            'reviewUrl'       => $review_url,
            'reviewNudgeFeedbackAction' => 'rmmigrate_review_nudge_feedback',
            'consentNote'     => __(
                'Sends to Rosenheinrich feedback server. Diagnostic fields (error message, error code, job type, plugin version, WordPress version, PHP version, multisite flag, and an anonymized site hash) may also be transmitted. No site URL, no backup files.',
                'rosenheinrich-multisite-migrate'
            ),
            'i18n'            => array(
                'title'            => __('How did that go?', 'rosenheinrich-multisite-migrate'),
                'titleReview'      => __('Enjoying Multisite Migrate?', 'rosenheinrich-multisite-migrate'),
                'titleHelp'        => __('Share feedback', 'rosenheinrich-multisite-migrate'),
                'titleBug'         => __('Report a problem', 'rosenheinrich-multisite-migrate'),
                'reviewBody'       => __(
                    'A short WordPress.org review helps others find Multisite Migrate too.',
                    'rosenheinrich-multisite-migrate'
                ),
                'reviewCta'        => __('Leave a review', 'rosenheinrich-multisite-migrate'),
                'positive'         => __('Great', 'rosenheinrich-multisite-migrate'),
                'neutral'          => __('Okay', 'rosenheinrich-multisite-migrate'),
                'negative'         => __('Not great', 'rosenheinrich-multisite-migrate'),
                'attachedError'    => __('Including last error:', 'rosenheinrich-multisite-migrate'),
                'messageLabel'     => __('What should we know?', 'rosenheinrich-multisite-migrate'),
                'messageRequired'  => __('Please add a short note so we can improve.', 'rosenheinrich-multisite-migrate'),
                'bugLabel'         => __('This is a bug', 'rosenheinrich-multisite-migrate'),
                'submit'           => __('Send feedback', 'rosenheinrich-multisite-migrate'),
                'later'            => __('Maybe later', 'rosenheinrich-multisite-migrate'),
                'sending'          => __('Sending…', 'rosenheinrich-multisite-migrate'),
                'thanks'           => __('Thanks for your feedback!', 'rosenheinrich-multisite-migrate'),
                'error'            => __('Could not send feedback. Try again later.', 'rosenheinrich-multisite-migrate'),
                'close'            => __('Close', 'rosenheinrich-multisite-migrate'),
            ),
        );
    }

    public static function render_modal(): void
    {
        if (!is_admin() || !self::user_can_see()) {
            return;
        }

        $page = Rmmigrate_Request_Input::get_text('page');
        if ($page === '' || strpos($page, 'multisite-migrate') === false) {
            return;
        }

        include RMMIGRATE_PATH . 'admin/partials/feedback-modal.php';
    }

    public static function ajax_submit(): void
    {
        self::verify_ajax();

        $sentiment = sanitize_key(Rmmigrate_Request_Input::post_text('sentiment'));
        if (!isset(self::$allowed_sentiments[$sentiment])) {
            wp_send_json_error(array('message' => __('Invalid sentiment.', 'rosenheinrich-multisite-migrate')), 400);
        }

        $type = sanitize_key(Rmmigrate_Request_Input::post_text('type'));
        if (!isset(self::$allowed_types[$type])) {
            $type = 'general';
        }

        $context = sanitize_key(Rmmigrate_Request_Input::post_text('context'));
        if (!isset(self::$allowed_contexts[$context])) {
            $context = 'help';
        }

        $message = self::truncate_text(
            sanitize_textarea_field(Rmmigrate_Request_Input::post_textarea('message')),
            self::MESSAGE_MAX
        );

        $error_message = self::truncate_text(
            sanitize_textarea_field(Rmmigrate_Request_Input::post_textarea('error_message')),
            self::ERROR_MESSAGE_MAX
        );

        if ($context !== 'deactivate' && ($sentiment === 'neutral' || $sentiment === 'negative' || $type === 'bug') && $message === '') {
            wp_send_json_error(array(
                'message' => __('Please add a short note so we can improve.', 'rosenheinrich-multisite-migrate'),
            ), 400);
        }

        $payload = array(
            'sentiment'      => $sentiment,
            'type'           => $type,
            'message'        => $message,
            'context'        => $context,
            'plugin_version' => defined('RMMIGRATE_VERSION') ? (string) RMMIGRATE_VERSION : '',
            'wp_version'     => (string) get_bloginfo('version'),
            'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'is_multisite'   => is_multisite(),
            'job_type'       => sanitize_key(Rmmigrate_Request_Input::post_text('job_type')),
            'error_code'     => sanitize_key(Rmmigrate_Request_Input::post_text('error_code')),
            'error_message'  => $error_message,
            'site_hash'      => self::site_hash(),
            'product_build'  => 'free',
        );

        $submit_url = self::submit_url();
        if (!self::is_valid_submit_url($submit_url)) {
            wp_send_json_success(array('ok' => false, 'skipped' => 'url'));
        }

        $response = wp_remote_post($submit_url, array(
            'timeout' => 5,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));

        $ok = !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 400;
        if (!$ok) {
            wp_send_json_error(array(
                'message' => __('Could not send feedback. Try again later.', 'rosenheinrich-multisite-migrate'),
            ), 502);
        }

        if ($context !== 'deactivate') {
            self::mark_cooldown('submitted');
        }
        Rmmigrate_Telemetry::record_event('feedback_modal', array(
            'action'  => 'submit',
            'context' => $context,
            'type'    => $type,
        ));
        wp_send_json_success(array('ok' => true));
    }

    /**
     * Opt-in deactivate reason → portal (no cooldown; never blocks deactivate UI).
     */
    public static function ajax_deactivate_feedback(): void
    {
        $nonce = Rmmigrate_Request_Input::post_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_deactivate_feedback')) {
            wp_send_json_success(array('ok' => false, 'skipped' => 'nonce'));
        }

        if (!self::user_can_deactivate_feedback()) {
            wp_send_json_success(array('ok' => false, 'skipped' => 'permission'));
        }

        $reason = sanitize_key(Rmmigrate_Request_Input::post_text('reason'));
        if (!isset(self::$allowed_deactivate_reasons[$reason])) {
            wp_send_json_success(array('ok' => false, 'skipped' => 'reason'));
        }

        $message = self::truncate_text(
            sanitize_textarea_field(Rmmigrate_Request_Input::post_textarea('message')),
            self::MESSAGE_MAX
        );

        if ($reason === 'other' && $message === '') {
            wp_send_json_success(array('ok' => false, 'skipped' => 'other_empty'));
        }

        $contact_email = sanitize_email(Rmmigrate_Request_Input::post_text('contact_email'));
        if ($contact_email !== '' && !is_email($contact_email)) {
            $contact_email = '';
        }

        $sentiment = ($reason === 'temporary') ? 'neutral' : 'negative';

        $payload = array(
            'sentiment'      => $sentiment,
            'type'           => 'general',
            'message'        => $message,
            'context'        => 'deactivate',
            'plugin_version' => defined('RMMIGRATE_VERSION') ? (string) RMMIGRATE_VERSION : '',
            'wp_version'     => (string) get_bloginfo('version'),
            'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'is_multisite'   => is_multisite(),
            'job_type'       => '',
            'error_code'     => $reason,
            'error_message'  => '',
            'site_hash'      => self::site_hash(),
            'product_build'  => 'free',
        );
        $recent = Rmmigrate_Error_Recorder::get_recent();
        if ($recent !== array()) {
            $latest = $recent[0];
            $payload['job_type'] = sanitize_key((string) ($latest['job_type'] ?? ''));
            $latest_code = sanitize_key((string) ($latest['code'] ?? ''));
            if ($latest_code !== '') {
                $payload['recorded_error_code'] = $latest_code;
            }
        }
        if ($contact_email !== '') {
            $payload['contact_email'] = $contact_email;
        }

        $submit_url = self::submit_url();
        if (!self::is_valid_submit_url($submit_url)) {
            wp_send_json_success(array('ok' => false, 'skipped' => 'url'));
        }

        $response = wp_remote_post($submit_url, array(
            'timeout' => 3,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));

        $ok = !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 400;
        wp_send_json_success(array('ok' => $ok));
    }

    public static function ajax_dismiss(): void
    {
        self::verify_ajax();
        self::mark_cooldown('dismissed');
        Rmmigrate_Telemetry::record_event('feedback_modal', array('action' => 'dismiss'));
        wp_send_json_success();
    }

    /**
     * Start shared 14-day cooldown as soon as the auto/manual dialog is shown.
     */
    public static function ajax_shown(): void
    {
        self::verify_ajax();

        $state = self::get_user_state();
        $until = (int) ($state['cooldown_until'] ?? 0);
        if ($until > time()) {
            wp_send_json_success(array('cooldown_until' => $until));
            return;
        }

        self::mark_cooldown('shown');
        Rmmigrate_Telemetry::record_event('feedback_modal', array('action' => 'shown'));
        $state = self::get_user_state();
        wp_send_json_success(array(
            'cooldown_until' => (int) ($state['cooldown_until'] ?? 0),
        ));
    }

    public static function submit_url(): string
    {
        $base = Rmmigrate_Capabilities::PRICING_BASE_URL;

        return (string) apply_filters(
            'rmmigrate_feedback_submit_url',
            $base . '/wp-json/multisite-migrate-portal/v1/feedback/submit'
        );
    }

    private static function is_valid_submit_url(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = wp_parse_url($url);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && strtolower((string) $parts['scheme']) === 'https';
    }

    public static function site_hash(): string
    {
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'rmmigrate';

        return hash('sha256', home_url('/') . '|' . $salt);
    }

    private static function mark_cooldown(string $reason): void
    {
        $state = self::get_user_state();
        $state['status']          = $reason;
        $state['last_prompt_at']  = time();
        $state['cooldown_until']  = time() + (self::COOLDOWN_DAYS * DAY_IN_SECONDS);
        self::set_user_state($state);
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_user_state(): array
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return array();
        }

        $raw = get_user_meta($user_id, self::USER_META_KEY, true);
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array();
        }

        return array();
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function set_user_state(array $state): void
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }

        update_user_meta($user_id, self::USER_META_KEY, $state);
    }

    private static function verify_ajax(): void
    {
        $nonce = Rmmigrate_Request_Input::post_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_admin')) {
            wp_send_json_error(array('message' => __('Invalid nonce.', 'rosenheinrich-multisite-migrate')), 403);
        }

        if (!self::user_can_see()) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rosenheinrich-multisite-migrate')), 403);
        }
    }

    private static function user_can_see(): bool
    {
        if (is_multisite()) {
            return current_user_can('manage_network');
        }

        return current_user_can('manage_options');
    }

    private static function user_can_deactivate_feedback(): bool
    {
        if (is_multisite() && is_network_admin()) {
            return current_user_can('manage_network_plugins');
        }

        return current_user_can('activate_plugins');
    }

    private static function truncate_text(string $text, int $max): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, 0, $max);
        }

        return substr($text, 0, $max);
    }
}
