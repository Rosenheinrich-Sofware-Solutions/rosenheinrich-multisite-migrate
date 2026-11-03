<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * wp.org review nudge for happy users after first real backup success.
 */
final class Rmmigrate_Review_Nudge
{
    use Rmmigrate_Ajax_Base;

    const USER_META_KEY = 'rmmigrate_review_nudge';
    const COOLDOWN_META_KEY = 'rmmigrate_review_nudge_cooldown_until';
    const ACTIVATED_OPTION = 'rmmigrate_first_activated_at';
    const COOLDOWN_DAYS = 14;
    const MIN_ACTIVE_DAYS = 14;
    const MIN_BACKUPS = 2;
    const WP_ORG_REVIEW_URL = 'https://wordpress.org/support/plugin/rosenheinrich-multisite-migrate/reviews/#new-post';

    /** @var array<string,true> */
    private static $terminal_states = array(
        'dismissed' => true,
        'reviewed'  => true,
        'negative'  => true,
    );

    public static function register(): void
    {
        add_action('wp_ajax_rmmigrate_review_nudge_dismiss', array(__CLASS__, 'ajax_dismiss'));
        add_action('wp_ajax_rmmigrate_review_nudge_feedback', array(__CLASS__, 'ajax_feedback'));
    }

    public static function should_show(string $page_slug, bool $is_network): bool
    {
        if (!self::user_can_see()) {
            return false;
        }

        $is_plugin_page = self::is_plugin_admin_page($page_slug);

        // Never right after the first success: 14+ days active and 2+ backups.
        if (!self::has_been_active_long_enough()) {
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

    /**
     * A calm moment to ask for a review: active 14+ days, 2+ completed backups,
     * no recent failure, nothing running. Never during or right after a job.
     */
    public static function calm_moment(bool $is_network = false): bool
    {
        if (!self::user_can_see() || !self::has_been_active_long_enough()) {
            return false;
        }
        $state = self::get_user_state();
        if ($state !== '' && isset(self::$terminal_states[$state])) {
            return false;
        }

        return !self::is_in_cooldown()
            && self::completed_backup_count() >= self::MIN_BACKUPS
            && !self::has_recent_failure($is_network)
            && !self::has_active_job();
    }

    /**
     * Inline, non-blocking review card on the Backups page only (no modal).
     */
    public static function render(string $page_slug, bool $is_network): void
    {
        if ($page_slug !== 'multisite-migrate-archives' || Rmmigrate_Request_Input::get_int('job_id') > 0) {
            return;
        }
        if (!self::should_show($page_slug, $is_network)) {
            return;
        }
        ?>
        <div class="mm-notice-card mm-notice-card--info mm-review-card" role="region" aria-label="<?php esc_attr_e('Review request', 'rosenheinrich-multisite-migrate'); ?>">
            <div class="mm-notice-card__body">
                <p class="mm-notice-card__title"><strong><?php esc_html_e('Is Multisite Migrate working well for you?', 'rosenheinrich-multisite-migrate'); ?></strong></p>
                <p class="mm-notice-card__text"><?php esc_html_e('If it saved you time, a short review on WordPress.org helps other admins find it.', 'rosenheinrich-multisite-migrate'); ?></p>
            </div>
            <div class="mm-notice-card__actions">
                <a class="button button-primary mm-btn-teal mm-review-card-yes" href="<?php echo esc_url(self::review_url()); ?>" target="_blank" rel="noopener"><?php esc_html_e('Leave a review', 'rosenheinrich-multisite-migrate'); ?></a>
                <button type="button" class="button mm-review-card-later"><?php esc_html_e('Not now', 'rosenheinrich-multisite-migrate'); ?></button>
            </div>
        </div>
        <?php
    }

    public static function review_url(): string
    {
        return Rmmigrate_Capabilities::marketing_url(self::WP_ORG_REVIEW_URL, 'review_nudge');
    }

    public static function ajax_dismiss(): void
    {
        self::verify_ajax();
        self::mark_cooldown();
        self::set_user_state('dismissed');
        Rmmigrate_Telemetry::record_event('review_nudge', array('action' => 'dismiss'));
        wp_send_json_success();
    }

    public static function ajax_feedback(): void
    {
        self::verify_ajax();
        $feedback = Rmmigrate_Request_Input::post_text('feedback');
        if ($feedback === 'reviewed' || $feedback === 'negative') {
            self::set_user_state($feedback);
            if ($feedback === 'negative') {
                self::mark_cooldown();
            }
            Rmmigrate_Telemetry::record_event('review_nudge', array('action' => $feedback));
            wp_send_json_success();
        }

        self::send_ajax_error(
            __('Invalid feedback.', 'rosenheinrich-multisite-migrate'),
            400,
            'system',
            0,
            array('phase' => 'review_nudge')
        );
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
        if (defined('RMMIGRATE_UNIT_TEST') && RMMIGRATE_UNIT_TEST && array_key_exists('mm_test_review_nudge_activated_at', $GLOBALS)) {
            $activated_at = (int) $GLOBALS['mm_test_review_nudge_activated_at'];
        } else {
            $override = apply_filters('rmmigrate_review_nudge_activated_at', null);
            if ($override !== null) {
                $activated_at = (int) $override;
            } else {
                $activated_at = (int) get_site_option(self::ACTIVATED_OPTION, 0);
            }
        }

        if ($activated_at <= 0) {
            return false;
        }

        return (time() - $activated_at) >= (self::MIN_ACTIVE_DAYS * DAY_IN_SECONDS);
    }

    private static function verify_ajax(): void
    {
        if (!self::user_can_see()) {
            self::send_ajax_error(
                __('Permission denied.', 'rosenheinrich-multisite-migrate'),
                403,
                'system',
                0,
                array('phase' => 'review_nudge'),
                'warning'
            );
        }

        $nonce = Rmmigrate_Request_Input::post_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_admin')) {
            self::send_ajax_error(
                __('Invalid nonce.', 'rosenheinrich-multisite-migrate'),
                403,
                'system',
                0,
                array('phase' => 'review_nudge'),
                'warning'
            );
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
        if (defined('RMMIGRATE_UNIT_TEST') && RMMIGRATE_UNIT_TEST && array_key_exists('mm_test_review_nudge_backup_count', $GLOBALS)) {
            return (int) $GLOBALS['mm_test_review_nudge_backup_count'];
        }

        $override = apply_filters('rmmigrate_review_nudge_backup_count', null);
        if ($override !== null) {
            return (int) $override;
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
        if (defined('RMMIGRATE_UNIT_TEST') && RMMIGRATE_UNIT_TEST && array_key_exists('mm_test_review_nudge_last_error', $GLOBALS)) {
            $override = $GLOBALS['mm_test_review_nudge_last_error'];
            return is_array($override) ? $override : array();
        }

        $override = apply_filters('rmmigrate_review_nudge_last_error', null);
        if ($override !== null) {
            return is_array($override) ? $override : array();
        }

        return Rmmigrate_Job::resolve_admin_last_error($is_network);
    }

    private static function has_recent_failure(bool $is_network): bool
    {
        if (defined('RMMIGRATE_UNIT_TEST') && RMMIGRATE_UNIT_TEST && array_key_exists('mm_test_review_nudge_has_recent_failure', $GLOBALS)) {
            return (bool) $GLOBALS['mm_test_review_nudge_has_recent_failure'];
        }

        $override = apply_filters('rmmigrate_review_nudge_has_recent_failure', null);
        if ($override !== null) {
            return (bool) $override;
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
        if (defined('RMMIGRATE_UNIT_TEST') && RMMIGRATE_UNIT_TEST && array_key_exists('mm_test_review_nudge_has_active_job', $GLOBALS)) {
            return (bool) $GLOBALS['mm_test_review_nudge_has_active_job'];
        }

        $override = apply_filters('rmmigrate_review_nudge_has_active_job', null);
        if ($override !== null) {
            return (bool) $override;
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
