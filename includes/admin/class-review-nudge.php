<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * wp.org review nudge for happy users after first real backup success.
 */
final class Rmmigrate_Review_Nudge
{
    const USER_META_KEY = 'rmmigrate_review_nudge';
    const COOLDOWN_META_KEY = 'rmmigrate_review_nudge_cooldown_until';
    const ACTIVATED_OPTION = 'rmmigrate_first_activated_at';
    const COOLDOWN_DAYS = 14;
    const MIN_ACTIVE_DAYS = 14;
    const MIN_BACKUPS = 1;
    const WP_ORG_REVIEW_URL = 'https://wordpress.org/support/plugin/rosenheinrich-multisite-migrate/reviews/#new-post';

    /** @var array<string,true> */
    private static $terminal_states = array(
        'dismissed' => true,
        'reviewed'  => true,
        'negative'  => true,
    );

    /** @var bool|null Cached result for the current request. */
    private static $global_notice_will_show;

    public static function register(): void
    {
        add_action('wp_ajax_rmmigrate_review_nudge_dismiss', array(__CLASS__, 'ajax_dismiss'));
        add_action('wp_ajax_rmmigrate_review_nudge_feedback', array(__CLASS__, 'ajax_feedback'));
        add_action('admin_notices', array(__CLASS__, 'render_global_notice'));
        add_action('network_admin_notices', array(__CLASS__, 'render_global_notice'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_global_assets'));
    }

    public static function should_show(string $page_slug, bool $is_network): bool
    {
        if (!self::user_can_see()) {
            return false;
        }

        $is_plugin_page = self::is_plugin_admin_page($page_slug);

        if (!$is_plugin_page && !self::has_been_active_long_enough()) {
            return false;
        }

        $state = self::get_user_state();
        if ($state !== '' && isset(self::$terminal_states[$state])) {
            return false;
        }

        if (self::is_in_cooldown()) {
            return false;
        }

        if (self::completed_backup_count() < self::MIN_BACKUPS) {
            return false;
        }

        if (self::has_recent_failure($is_network)) {
            return false;
        }

        if (self::has_active_job()) {
            return false;
        }

        if ($is_plugin_page && self::has_blocking_banner($page_slug, $is_network)) {
            return false;
        }

        return true;
    }

    public static function render(string $page_slug, bool $is_network): void
    {
        // Banner retired — wp.org review CTA lives in the post-success feedback modal only.
        unset($page_slug, $is_network);
    }

    public static function review_url(): string
    {
        return Rmmigrate_Capabilities::marketing_url(self::WP_ORG_REVIEW_URL, 'review_nudge');
    }

    public static function ajax_dismiss(): void
    {
        self::verify_ajax();
        self::set_user_state('dismissed');
        wp_send_json_success();
    }

    public static function ajax_feedback(): void
    {
        self::verify_ajax();
        $feedback = Rmmigrate_Request_Input::post_text('feedback');
        if ($feedback === 'reviewed' || $feedback === 'negative') {
            self::set_user_state($feedback);
            wp_send_json_success();
        }

        wp_send_json_error(array('message' => __('Invalid feedback.', 'rosenheinrich-multisite-migrate')), 400);
    }

    public static function render_global_notice(): void
    {
        $page_slug = Rmmigrate_Request_Input::get_text('page');
        $is_network = is_multisite() && is_network_admin();

        if (self::is_plugin_admin_page($page_slug)) {
            return;
        }

        self::render($page_slug, $is_network);
    }

    public static function enqueue_global_assets(string $hook): void
    {
        $page_slug = Rmmigrate_Request_Input::get_text('page');

        if (self::is_plugin_admin_page($page_slug)) {
            return;
        }

        $is_network = is_multisite() && is_network_admin();
        self::$global_notice_will_show = self::should_show($page_slug, $is_network);

        if (!self::$global_notice_will_show) {
            return;
        }

        $ver = defined('RMMIGRATE_VERSION') ? (string) RMMIGRATE_VERSION : '1.0.0';

        wp_enqueue_style(
            'rmmigrate-tokens',
            RMMIGRATE_URL . 'assets/css/multisite-migrate-tokens.css',
            array(),
            $ver
        );
        wp_enqueue_style(
            'rmmigrate-components',
            RMMIGRATE_URL . 'assets/css/multisite-migrate-components.css',
            array('rmmigrate-tokens'),
            $ver
        );
        wp_enqueue_script(
            'rmmigrate-review-nudge',
            RMMIGRATE_URL . 'assets/js/review-nudge.js',
            array('jquery'),
            $ver,
            true
        );
        wp_localize_script('rmmigrate-review-nudge', 'rmmigrateReviewNudge', array(
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('rmmigrate_admin'),
            'dismissAction'  => 'rmmigrate_review_nudge_dismiss',
            'feedbackAction' => 'rmmigrate_review_nudge_feedback',
        ));
    }

    public static function ensure_first_activated_timestamp(): void
    {
        if (get_site_option(self::ACTIVATED_OPTION)) {
            return;
        }

        update_site_option(self::ACTIVATED_OPTION, time());
    }

    private static function has_been_active_long_enough(): bool
    {
        if (isset($GLOBALS['mm_test_review_nudge_activated_at'])) {
            $activated_at = (int) $GLOBALS['mm_test_review_nudge_activated_at'];
        } else {
            $activated_at = (int) get_site_option(self::ACTIVATED_OPTION, 0);
        }

        if ($activated_at <= 0) {
            return false;
        }

        return (time() - $activated_at) >= (self::MIN_ACTIVE_DAYS * DAY_IN_SECONDS);
    }

    private static function verify_ajax(): void
    {
        if (!self::user_can_see()) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rosenheinrich-multisite-migrate')), 403);
        }

        $nonce = Rmmigrate_Request_Input::post_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_admin')) {
            wp_send_json_error(array('message' => __('Invalid nonce.', 'rosenheinrich-multisite-migrate')), 403);
        }
    }

    private static function user_can_see(): bool
    {
        if (is_multisite()) {
            return current_user_can('manage_network');
        }

        return current_user_can('manage_options');
    }

    private static function is_plugin_admin_page(string $page_slug): bool
    {
        if ($page_slug === '') {
            return false;
        }

        return isset(Rmmigrate_Admin_Router::all_page_slugs()[$page_slug]);
    }

    private static function is_in_cooldown(): bool
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return false;
        }

        $until = (int) get_user_meta($user_id, self::COOLDOWN_META_KEY, true);

        return $until > time();
    }

    private static function mark_cooldown(): void
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }

        update_user_meta(
            $user_id,
            self::COOLDOWN_META_KEY,
            time() + (self::COOLDOWN_DAYS * DAY_IN_SECONDS)
        );
    }

    private static function completed_backup_count(): int
    {
        if (isset($GLOBALS['mm_test_review_nudge_backup_count'])) {
            return (int) $GLOBALS['mm_test_review_nudge_backup_count'];
        }

        $jobs = Rmmigrate_Job::list_jobs(array(
            'status_group' => 'completed',
            'job_type'     => Rmmigrate_Job::JOB_TYPE_BACKUP,
            'limit'        => max(self::MIN_BACKUPS, 10),
        ));

        $count = 0;
        foreach ($jobs as $job) {
            if ((string) ($job->data['triggered_by'] ?? '') === 'import') {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private static function resolve_admin_last_error_for_nudge(bool $is_network): array
    {
        if (array_key_exists('mm_test_review_nudge_last_error', $GLOBALS)) {
            $error = $GLOBALS['mm_test_review_nudge_last_error'];
            return is_array($error) ? $error : array();
        }

        return Rmmigrate_Job::resolve_admin_last_error($is_network);
    }

    private static function has_recent_failure(bool $is_network): bool
    {
        if (isset($GLOBALS['mm_test_review_nudge_has_recent_failure'])) {
            return (bool) $GLOBALS['mm_test_review_nudge_has_recent_failure'];
        }

        $error = self::resolve_admin_last_error_for_nudge($is_network);
        if (!empty($error['time'])) {
            $timestamp = strtotime((string) $error['time'] . ' UTC');
            if ($timestamp !== false && (time() - $timestamp) < DAY_IN_SECONDS) {
                return true;
            }
        }

        $failed_jobs = Rmmigrate_Job::list_jobs(array(
            'status_group' => 'failed',
            'job_type'     => Rmmigrate_Job::JOB_TYPE_BACKUP,
            'limit'        => 20,
        ));

        $cutoff = time() - DAY_IN_SECONDS;
        foreach ($failed_jobs as $job) {
            $at = (string) ($job->data['completed_at'] ?? $job->data['created_at'] ?? '');
            if ($at === '') {
                continue;
            }
            $timestamp = strtotime($at . ' UTC');
            if ($timestamp !== false && $timestamp >= $cutoff) {
                return true;
            }
        }

        return false;
    }

    private static function has_active_job(): bool
    {
        if (isset($GLOBALS['mm_test_review_nudge_has_active_job'])) {
            return (bool) $GLOBALS['mm_test_review_nudge_has_active_job'];
        }

        return Rmmigrate_Job::get_active() !== null;
    }

    private static function has_blocking_banner(string $page_slug, bool $is_network): bool
    {
        $last_error = self::resolve_admin_last_error_for_nudge($is_network);
        if ((string) ($last_error['message'] ?? '') !== '') {
            return true;
        }

        if (Rmmigrate_Setup_Wizard::needs_setup_banner()) {
            return true;
        }

        if (apply_filters('rmmigrate_review_nudge_license_notice_blocks', false, $page_slug, $is_network)) {
            return true;
        }

        if (!empty($GLOBALS['mm_test_review_nudge_license_blocks'])) {
            return true;
        }

        return false;
    }

    private static function get_user_state(): string
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return '';
        }

        return (string) get_user_meta($user_id, self::USER_META_KEY, true);
    }

    private static function set_user_state(string $state): void
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }

        update_user_meta($user_id, self::USER_META_KEY, $state);
    }
}
