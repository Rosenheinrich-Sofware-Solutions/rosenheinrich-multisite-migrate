<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opt-in product telemetry (wizard, jobs, nudges) → portal REST.
 *
 * No remote HTTP without explicit telemetry consent (wp.org Guideline 6/7).
 */
final class Rmmigrate_Telemetry
{
    const OPTION_KEY           = 'rmmigrate_telemetry';
    const QUEUE_OPTION         = 'rmmigrate_telemetry_queue';
    const FLUSH_CRON_HOOK        = 'rmmigrate_telemetry_flush';
    const SNAPSHOT_CRON_HOOK     = 'rmmigrate_daily_telemetry_snapshot';
    const SNAPSHOT_ONESHOT_HOOK  = 'rmmigrate_telemetry_snapshot_oneshot';
    const CONSENT_REMOTE_HOOK    = 'rmmigrate_telemetry_consent_remote';
    const AJAX_CONSENT           = 'rmmigrate_telemetry_consent';
    const MAX_QUEUE              = 100;
    const MAX_FLUSH_BATCH        = 50;
    const MAX_FLUSH_ATTEMPTS     = 5;
    const FLUSH_RETRY_OPTION     = 'rmmigrate_telemetry_flush_attempts';
    const OPERATION_ERROR_DEDUP_SEC = 120;

    /** @var array<string,true> */
    private static $allowed_events = array(
        'telemetry_consent_granted'  => true,
        'telemetry_consent_declined' => true,
        'telemetry_consent_revoked'  => true,
        'wizard_step'                => true,
        'wizard_completed'           => true,
        'backup_job'                 => true,
        'import_job'                 => true,
        'restore_job'                => true,
        'feedback_modal'             => true,
        'review_nudge'               => true,
        'platform_snapshot'          => true,
        'operation_error'            => true,
    );

    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_CONSENT, array(__CLASS__, 'ajax_consent'));
        add_action(self::FLUSH_CRON_HOOK, array(__CLASS__, 'flush_queue'));
        add_action(self::SNAPSHOT_CRON_HOOK, array(__CLASS__, 'run_snapshot'));
        add_action(self::SNAPSHOT_ONESHOT_HOOK, array(__CLASS__, 'run_snapshot'));
        add_action(self::CONSENT_REMOTE_HOOK, array(__CLASS__, 'run_consent_remote_setup'));
        add_action('shutdown', array(__CLASS__, 'maybe_flush_queue_shutdown'), 20);

        add_action('rmmigrate_job_terminal', array(__CLASS__, 'on_job_terminal'), 10, 3);
        add_action('rmmigrate_import_registered', array(__CLASS__, 'on_import_registered'), 10, 1);

        add_filter('rmmigrate_telemetry_record_event', array(__CLASS__, 'filter_record_event'), 10, 2);
    }

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return array(
            'consent'        => 'unset',
            'consent_at'     => 0,
            'install_id'     => '',
            'snapshot_hash'  => '',
            'snapshot_at'    => 0,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_state(): array
    {
        $stored = get_site_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        return array_merge(self::defaults(), $stored);
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function save_state(array $state): void
    {
        update_site_option(self::OPTION_KEY, array_merge(self::defaults(), $state));
    }

    public static function has_consent(): bool
    {
        return self::get_state()['consent'] === 'granted';
    }

    public static function consent_status(): string
    {
        $consent = (string) self::get_state()['consent'];
        if ($consent === 'granted' || $consent === 'declined') {
            return $consent;
        }

        return 'unset';
    }

    public static function site_hash(): string
    {
        return Rmmigrate_Feedback::site_hash();
    }

    public static function product_build(): string
    {
        return 'free';
    }

    public static function register_url(): string
    {
        $base = Rmmigrate_Capabilities::PRICING_BASE_URL;

        return (string) apply_filters(
            'rmmigrate_telemetry_register_url',
            $base . '/wp-json/multisite-migrate-portal/v1/telemetry/register'
        );
    }

    public static function events_url(): string
    {
        $base = Rmmigrate_Capabilities::PRICING_BASE_URL;

        return (string) apply_filters(
            'rmmigrate_telemetry_events_url',
            $base . '/wp-json/multisite-migrate-portal/v1/telemetry/events'
        );
    }

    /**
     * @param array<string,mixed> $props
     */
    public static function record_event(string $event, array $props = array()): void
    {
        $event = sanitize_key($event);
        if (!isset(self::$allowed_events[$event])) {
            return;
        }

        if (!self::has_consent()) {
            return;
        }

        $install_id = self::ensure_install_id();
        if ($install_id === '') {
            return;
        }

        $queue = self::get_queue();
        $queue[] = array(
            'event' => $event,
            'props' => self::sanitize_props($props),
            'at'    => time(),
        );
        if (count($queue) > self::MAX_QUEUE) {
            $queue = array_slice($queue, -self::MAX_QUEUE);
        }
        self::set_queue($queue);
        self::schedule_flush();
    }

    /**
     * @param array<string,mixed> $props
     */
    public static function filter_record_event(bool $recorded, array $props): bool
    {
        if (empty($props['event']) || !is_string($props['event'])) {
            return $recorded;
        }
        $event = $props['event'];
        unset($props['event']);
        self::record_event($event, $props);
        return true;
    }

    public static function ajax_consent(): void
    {
        $capability = is_multisite() ? 'manage_network' : 'manage_options';
        if (!current_user_can($capability)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rosenheinrich-multisite-migrate')), 403);
        }
        $nonce = Rmmigrate_Request_Input::post_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_admin')) {
            wp_send_json_error(array('message' => __('Invalid nonce.', 'rosenheinrich-multisite-migrate')), 403);
        }

        $grant = Rmmigrate_Request_Input::post_text('grant');
        $source = self::consent_source_from_request();
        if ($grant === '1') {
            if (self::has_consent()) {
                wp_send_json_success(array('consent' => 'granted'));
            }
            self::grant_consent($source);
            wp_send_json_success(array('consent' => 'granted'));
        }

        self::decline_consent($source);
        wp_send_json_success(array('consent' => 'declined'));
    }

    public static function revoke_consent(string $source = 'settings'): void
    {
        self::decline_consent($source);
    }

    private static function consent_source_from_request(): string
    {
        $source = sanitize_key(Rmmigrate_Request_Input::post_text('source'));

        return $source !== '' ? $source : 'setup_wizard_finish';
    }

    public static function grant_consent(string $source = 'settings'): void
    {
        $install_id = self::ensure_install_id();
        $state = self::get_state();
        $already_granted = $state['consent'] === 'granted';
        $state['consent']     = 'granted';
        $state['consent_at']  = time();
        $state['install_id']  = $install_id;
        self::save_state($state);

        $emit_lifecycle = !$already_granted && self::claim_consent_lifecycle_emission($install_id);
        if ($emit_lifecycle) {
            self::record_consent_lifecycle_event('telemetry_consent_granted', array('source' => sanitize_key($source)));
            if (!wp_next_scheduled(self::CONSENT_REMOTE_HOOK)) {
                wp_schedule_single_event(time() + 5, self::CONSENT_REMOTE_HOOK);
            }
        }
    }

    public static function run_consent_remote_setup(): void
    {
        self::flush_pending_lifecycle_events();
        if (!self::has_consent()) {
            return;
        }
        self::maybe_register();
        self::send_on_consent_snapshot();
        self::schedule_snapshot_cron();
        self::schedule_flush();
    }

    public static function decline_consent(string $source = 'settings'): void
    {
        $state = self::get_state();
        $was_granted = $state['consent'] === 'granted';

        if ($was_granted) {
            self::clear_consent_lifecycle_claim(self::ensure_install_id());
            self::set_queue(array());
        } else {
            self::set_queue(array());
        }

        $state['consent']    = 'declined';
        $state['consent_at'] = time();
        self::save_state($state);
        self::unschedule_snapshot_cron();
    }

    public static function maybe_register(): bool
    {
        if (!self::has_consent()) {
            return false;
        }

        $install_id = self::ensure_install_id();
        if ($install_id === '') {
            return false;
        }

        $payload = self::register_payload();
        $response = wp_remote_post(self::register_url(), array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    public static function flush_queue(): void
    {
        if (!self::has_consent()) {
            return;
        }

        if (!self::acquire_flush_lock()) {
            return;
        }

        $queue = self::get_queue();
        if ($queue === array()) {
            self::release_flush_lock();
            return;
        }

        $install_id = self::ensure_install_id();
        if ($install_id === '') {
            self::release_flush_lock();
            return;
        }

        $batch = array_slice($queue, 0, self::MAX_FLUSH_BATCH);
        $events = array();
        foreach ($batch as $row) {
            if (!is_array($row) || empty($row['event'])) {
                continue;
            }
            $events[] = array(
                'event' => sanitize_key((string) $row['event']),
                'props' => is_array($row['props'] ?? null) ? $row['props'] : array(),
            );
        }
        if ($events === array()) {
            self::set_queue(self::queue_after_batch($queue, $batch));
            self::release_flush_lock();
            return;
        }

        $response = wp_remote_post(self::events_url(), array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'install_id'     => $install_id,
                'site_hash'      => self::site_hash(),
                'product_build'  => self::product_build(),
                'events'         => $events,
            )),
        ));

        if (is_wp_error($response)) {
            self::schedule_flush_retry();
            self::release_flush_lock();
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            self::schedule_flush_retry();
            self::release_flush_lock();
            return;
        }

        delete_site_option(self::FLUSH_RETRY_OPTION);
        $remaining = self::queue_after_batch($queue, $batch);
        self::set_queue($remaining);
        if ($remaining !== array()) {
            self::schedule_flush();
        }
        self::release_flush_lock();
    }

    public static function maybe_flush_queue_shutdown(): void
    {
        if (!self::has_consent()) {
            return;
        }
        $queue = self::get_queue();
        if (count($queue) > 5) {
            self::schedule_flush();
        }
    }

    public static function send_on_consent_snapshot(): void
    {
        if (!self::has_consent()) {
            return;
        }
        if (!wp_next_scheduled(self::SNAPSHOT_ONESHOT_HOOK)) {
            wp_schedule_single_event(time() + 5, self::SNAPSHOT_ONESHOT_HOOK);
        }
    }

    public static function schedule_snapshot_cron(): void
    {
        if (!wp_next_scheduled(self::SNAPSHOT_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::SNAPSHOT_CRON_HOOK);
        }
    }

    public static function unschedule_snapshot_cron(): void
    {
        $ts = wp_next_scheduled(self::SNAPSHOT_CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::SNAPSHOT_CRON_HOOK);
        }
        wp_clear_scheduled_hook(self::SNAPSHOT_CRON_HOOK);
        wp_clear_scheduled_hook(self::SNAPSHOT_ONESHOT_HOOK);
    }

    public static function run_snapshot(): void
    {
        if (!self::has_consent()) {
            return;
        }

        $snapshot = self::collect_platform_snapshot();
        $hash     = hash('sha256', wp_json_encode($snapshot) ?: '');
        $state    = self::get_state();
        $last     = (int) ($state['snapshot_at'] ?? 0);
        $stale    = $last <= 0 || (time() - $last) > (7 * DAY_IN_SECONDS);

        if ($hash === (string) ($state['snapshot_hash'] ?? '') && !$stale) {
            return;
        }

        self::record_event('platform_snapshot', array('changed' => $hash !== (string) ($state['snapshot_hash'] ?? '')));

        $payload = self::register_payload();
        $payload['snapshot'] = $snapshot;
        $response = wp_remote_post(self::register_url(), array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            return;
        }

        $state['snapshot_hash'] = $hash;
        $state['snapshot_at']   = time();
        self::save_state($state);
        self::flush_queue();
    }

    /**
     * @return array<string,mixed>
     */
    public static function collect_platform_snapshot(): array
    {
        global $wp_version;

        $theme = wp_get_theme();
        $home  = home_url();
        $scheme = wp_parse_url($home, PHP_URL_SCHEME);

        return array(
            'wp_version'             => (string) $wp_version,
            'wp_locale'              => (string) get_locale(),
            'wp_multisite'           => (bool) is_multisite(),
            'wp_debug'               => (bool) (defined('WP_DEBUG') && WP_DEBUG),
            'wp_cron_disabled'       => (bool) (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
            'site_https'             => 'https' === $scheme,
            'php_version'            => PHP_VERSION,
            'php_sapi'               => (string) (PHP_SAPI ?? ''),
            'php_memory_limit_mb'    => self::parse_memory_limit_mb((string) ini_get('memory_limit')),
            'php_max_execution_time' => (int) ini_get('max_execution_time'),
            'theme_slug'             => $theme instanceof WP_Theme ? (string) $theme->get_stylesheet() : '',
            'theme_version'          => $theme instanceof WP_Theme ? (string) $theme->get('Version') : '',
            'environment_type'       => function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'production',
        );
    }

    /**
     * @return array<int,array{title:string,detail:string}>
     */
    public static function telemetry_disclosure(): array
    {
        return array(
            array(
                'title' => __('Wizard progress', 'rosenheinrich-multisite-migrate'),
                'detail' => __('Which setup steps you complete or skip.', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'title' => __('Backup, import, and restore outcomes', 'rosenheinrich-multisite-migrate'),
                'detail' => __('Success or failure with error category, phase, and a short message (file paths removed) — not backup contents.', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'title' => __('Environment snapshot', 'rosenheinrich-multisite-migrate'),
                'detail' => __('WordPress, PHP, and hosting basics so we can prioritize compatibility work.', 'rosenheinrich-multisite-migrate'),
            ),
        );
    }

    /**
     * Preflight / AJAX operation failure (before or without a terminal job).
     *
     * @param array<string,mixed> $context
     */
    public static function record_operation_error(string $operation, string $message, int $job_id = 0, array $context = array()): void
    {
        $operation = sanitize_key($operation);
        if ($operation === '') {
            return;
        }
        $clean_message = self::sanitize_error_message($message);
        $service_code  = sanitize_key((string) ($context['service_code'] ?? ''));
        if ($service_code === '' && $clean_message !== '') {
            $service_code = self::classify_error_category($clean_message);
        }
        $phase = sanitize_key((string) ($context['phase'] ?? ''));
        if (self::should_skip_operation_error($job_id, $phase)) {
            return;
        }
        if (!self::claim_operation_error_emission($operation, $job_id, $service_code, $phase, $clean_message)) {
            return;
        }
        self::record_event('operation_error', array(
            'operation'     => $operation,
            'outcome'       => 'failed',
            'error_code'    => ($service_code !== '' && $service_code !== 'unknown')
                ? $service_code
                : self::classify_error_category($clean_message),
            'error_message' => $clean_message,
            'error_phase'   => $phase,
            'service_code'  => $service_code,
            'job_id'        => max(0, $job_id),
        ));
    }

    /**
     * Skip operation_error when job terminal telemetry already covers this failure.
     */
    private static function should_skip_operation_error(int $job_id, string $phase): bool
    {
        if ($job_id <= 0) {
            return false;
        }

        $preflight_phases = array(
            'start',
            'preflight',
            'prefetch',
            'cloud_fetch',
            'deploy',
            'recovery_point',
            'download_stub',
            'download_php',
            'license',
        );
        if (in_array($phase, $preflight_phases, true)) {
            return false;
        }

        if (!class_exists('Rmmigrate_Job', false)) {
            return false;
        }

        $job = Rmmigrate_Job::get($job_id);
        if ($job === null) {
            return false;
        }

        return in_array($job->get_status(), array(
            Rmmigrate_Job::STATUS_ERROR,
            Rmmigrate_Job::STATUS_COMPLETE,
            Rmmigrate_Job::STATUS_CANCELLED,
        ), true);
    }

    /**
     * Suppress duplicate operation_error bursts (double-click AJAX, repeated polls).
     */
    private static function claim_operation_error_emission(
        string $operation,
        int $job_id,
        string $service_code,
        string $phase,
        string $message
    ): bool {
        $install_id = self::ensure_install_id();
        if ($install_id === '') {
            return true;
        }

        $fingerprint = hash(
            'sha256',
            implode(
                '|',
                array($install_id, $operation, (string) $job_id, $service_code, $phase, $message)
            )
        );
        $transient_key = 'rmmigrate_telemetry_op_err_' . substr($fingerprint, 0, 40);
        if (get_site_transient($transient_key)) {
            return false;
        }

        set_site_transient($transient_key, 1, self::OPERATION_ERROR_DEDUP_SEC);

        return true;
    }

    private static function flush_lock_key(): string
    {
        return 'rmmigrate_telemetry_flush_lock';
    }

    private static function acquire_flush_lock(): bool
    {
        $key = self::flush_lock_key();
        $now = time();
        if (add_site_option($key, $now)) {
            return true;
        }

        $claimed_at = (int) get_site_option($key, 0);
        if ($claimed_at > 0 && ($now - $claimed_at) < 30) {
            return false;
        }

        delete_site_option($key);

        return add_site_option($key, $now);
    }

    private static function release_flush_lock(): void
    {
        delete_site_option(self::flush_lock_key());
    }

    /**
     * @param Rmmigrate_Job $job
     */
    public static function on_job_terminal(Rmmigrate_Job $job, int $status, ?string $error): void
    {
        if ($job->get_job_type() === Rmmigrate_Job::JOB_TYPE_RESTORE) {
            $type = 'restore_job';
        } elseif ((string) ($job->data['triggered_by'] ?? '') === 'import') {
            $type = 'import_job';
        } else {
            $type = 'backup_job';
        }

        self::record_event($type, self::job_terminal_props($job, $status, $error));
    }

    /**
     * @param Rmmigrate_Job $job
     */
    public static function on_import_registered(Rmmigrate_Job $job): void
    {
        self::record_event('import_job', array(
            'outcome' => 'registered',
            'scope'   => sanitize_key((string) ($job->data['scope'] ?? '')),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private static function register_payload(): array
    {
        global $wp_version;

        return array(
            'install_id'       => self::ensure_install_id(),
            'site_hash'        => self::site_hash(),
            'product_build'    => self::product_build(),
            'plugin_version'   => defined('RMMIGRATE_VERSION') ? (string) RMMIGRATE_VERSION : '',
            'wp_version'       => (string) $wp_version,
            'php_version'      => PHP_VERSION,
            'locale'           => (string) determine_locale(),
            'environment_type' => function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'production',
            'is_multisite'     => is_multisite(),
            'is_test'          => function_exists('wp_get_environment_type') && 'production' !== wp_get_environment_type(),
        );
    }

    private static function ensure_install_id(): string
    {
        $state = self::get_state();
        $id    = (string) ($state['install_id'] ?? '');
        if (strlen($id) === 64 && ctype_xdigit($id)) {
            return $id;
        }

        try {
            $bytes = random_bytes(32);
        } catch (Exception $e) {
            $bytes = wp_generate_password(32, true, true);
        }
        $id = hash('sha256', $bytes . '|' . self::site_hash() . '|' . (string) time());
        $state['install_id'] = $id;
        self::save_state($state);

        return $id;
    }

    /**
     * One lifecycle grant per install_id (parallel AJAX-safe via add_site_option).
     */
    private static function consent_lifecycle_claim_option(string $install_id): string
    {
        return 'rmmigrate_telemetry_lc_' . substr(hash('sha256', $install_id), 0, 40);
    }

    private static function claim_consent_lifecycle_emission(string $install_id): bool
    {
        if ($install_id === '') {
            return false;
        }

        return add_site_option(self::consent_lifecycle_claim_option($install_id), time());
    }

    private static function clear_consent_lifecycle_claim(string $install_id): void
    {
        if ($install_id === '') {
            return;
        }

        delete_site_option(self::consent_lifecycle_claim_option($install_id));
    }

    /**
     * @param array<string,mixed> $props
     */
    private static function record_consent_lifecycle_event(string $event, array $props = array()): void
    {
        $event = sanitize_key($event);
        if (!isset(self::$allowed_events[$event])) {
            return;
        }
        $install_id = self::ensure_install_id();
        if ($install_id === '') {
            return;
        }
        $queue = self::get_queue();
        $queue[] = array(
            'event' => $event,
            'props' => self::sanitize_props($props),
            'at'    => time(),
        );
        if (count($queue) > self::MAX_QUEUE) {
            $queue = array_slice($queue, -self::MAX_QUEUE);
        }
        self::set_queue($queue);
        self::schedule_flush();
    }

    /**
     * @param array<int,array<string,mixed>> $queue
     * @param array<int,array<string,mixed>> $batch
     * @return array<int,array<string,mixed>>
     */
    private static function queue_after_batch(array $queue, array $batch): array
    {
        $sent_count = count($batch);
        if ($sent_count === 0) {
            return $queue;
        }

        $queue_after = self::get_queue();
        $original_tail = array_slice($queue, $sent_count);
        $appended = array_slice($queue_after, count($queue));

        return array_merge($original_tail, $appended);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function get_queue(): array
    {
        $raw = get_site_option(self::QUEUE_OPTION, array());
        return is_array($raw) ? $raw : array();
    }

    /**
     * @param array<int,array<string,mixed>> $queue
     */
    private static function set_queue(array $queue): void
    {
        update_site_option(self::QUEUE_OPTION, $queue);
    }

    private static function schedule_flush(): void
    {
        if (!wp_next_scheduled(self::FLUSH_CRON_HOOK)) {
            wp_schedule_single_event(time() + 30, self::FLUSH_CRON_HOOK);
        }
    }

    private static function schedule_flush_retry(): void
    {
        $attempts = (int) get_site_option(self::FLUSH_RETRY_OPTION, 0) + 1;
        if ($attempts >= self::MAX_FLUSH_ATTEMPTS) {
            delete_site_option(self::FLUSH_RETRY_OPTION);
            return;
        }

        update_site_option(self::FLUSH_RETRY_OPTION, $attempts);
        $delay = min(30 * (2 ** ($attempts - 1)), 900);
        wp_clear_scheduled_hook(self::FLUSH_CRON_HOOK);
        wp_schedule_single_event(time() + $delay, self::FLUSH_CRON_HOOK);
    }

    /**
     * Deliver queued consent lifecycle events without requiring active consent.
     */
    private static function flush_pending_lifecycle_events(): void
    {
        $queue = self::get_queue();
        if ($queue === array()) {
            return;
        }

        $lifecycle_names = array(
            'telemetry_consent_granted'  => true,
            'telemetry_consent_revoked'  => true,
            'telemetry_consent_declined' => true,
        );
        $events = array();
        $remaining = array();
        foreach ($queue as $row) {
            if (!is_array($row) || empty($row['event'])) {
                continue;
            }
            $event = sanitize_key((string) $row['event']);
            if (isset($lifecycle_names[$event])) {
                $events[] = array(
                    'event' => $event,
                    'props' => is_array($row['props'] ?? null) ? self::sanitize_props($row['props']) : array(),
                );
                continue;
            }
            $remaining[] = $row;
        }

        if ($events === array()) {
            return;
        }

        $install_id = self::ensure_install_id();
        if ($install_id === '') {
            return;
        }

        $response = wp_remote_post(self::events_url(), array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'install_id'    => $install_id,
                'site_hash'     => self::site_hash(),
                'product_build' => self::product_build(),
                'events'        => $events,
            )),
        ));

        if (is_wp_error($response)) {
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return;
        }

        self::set_queue($remaining);
    }

    /**
     * @param array<string,mixed> $props
     * @return array<string,mixed>
     */
    private static function sanitize_props(array $props): array
    {
        $clean = array();
        foreach ($props as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            if (is_bool($value)) {
                $clean[$key] = $value;
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $clean[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            $text = sanitize_text_field((string) $value);
            $max_len = ($key === 'error_message') ? 500 : 120;
            if (function_exists('mb_substr')) {
                $text = (string) mb_substr($text, 0, $max_len);
            } else {
                $text = substr($text, 0, $max_len);
            }
            $clean[$key] = $text;
        }

        return $clean;
    }

    /**
     * @param Rmmigrate_Job $job
     * @return array<string,mixed>
     */
    private static function job_terminal_props(Rmmigrate_Job $job, int $status, ?string $error): array
    {
        $raw_error = $error ?? '';
        if ($raw_error === '' && isset($job->data['error_message'])) {
            $raw_error = (string) $job->data['error_message'];
        }
        $clean_message = self::sanitize_error_message($raw_error);
        $progress      = $job->get_progress();
        $props         = array(
            'outcome'      => 'unknown',
            'job_id'       => $job->get_id(),
            'scope'        => sanitize_key((string) ($job->data['scope'] ?? '')),
            'triggered_by' => sanitize_key((string) ($job->data['triggered_by'] ?? '')),
            'destination'  => sanitize_key($job->get_destination()),
            'job_type'     => sanitize_key($job->get_job_type()),
        );
        $backup_profile = sanitize_key((string) ($job->data['backup_profile'] ?? ''));
        if ($backup_profile !== '') {
            $props['backup_profile'] = $backup_profile;
        }
        if ($status === Rmmigrate_Job::STATUS_COMPLETE) {
            $props['outcome'] = $clean_message !== '' ? 'warning' : 'success';
        } elseif ($status === Rmmigrate_Job::STATUS_ERROR) {
            $props['outcome'] = 'failed';
        }
        $service_code = sanitize_key((string) ($progress['service_code'] ?? ''));
        if ($service_code === '' && $clean_message !== '') {
            $service_code = Rmmigrate_Error_Codes::from_message($clean_message);
        }
        if ($service_code !== '') {
            $props['service_code'] = $service_code;
        }
        if ($clean_message !== '') {
            $props['error_code'] = ($service_code !== '' && $service_code !== 'unknown')
                ? $service_code
                : self::classify_error_category($clean_message);
            $props['error_message'] = $clean_message;
        }
        $phase = sanitize_key((string) ($progress['step'] ?? ''));
        if ($phase === '' && isset($progress['phase'])) {
            $phase = sanitize_key((string) $progress['phase']);
        }
        if ($phase !== '') {
            $props['error_phase'] = $phase;
        }
        $duration = self::job_duration_ms($job);
        if ($duration > 0) {
            $props['duration_ms'] = $duration;
        }

        return $props;
    }

    /**
     * @param Rmmigrate_Job $job
     */
    private static function job_duration_ms(Rmmigrate_Job $job): int
    {
        $created   = strtotime((string) ($job->data['created_at'] ?? ''));
        $completed = strtotime((string) ($job->data['completed_at'] ?? ''));
        if ($created <= 0 || $completed <= 0 || $completed < $created) {
            return 0;
        }

        return (int) (($completed - $created) * 1000);
    }

    private static function classify_error_category(string $message): string
    {
        return Rmmigrate_Error_Codes::from_message($message);
    }

    private static function sanitize_error_message(?string $message): string
    {
        return Rmmigrate_Error_Recorder::sanitize_message($message);
    }

    private static function parse_memory_limit_mb(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return 0;
        }
        $unit = strtolower(substr($limit, -1));
        $num  = (float) $limit;
        if ($unit === 'g') {
            $num *= 1024;
        } elseif ($unit === 'k') {
            $num /= 1024;
        } elseif ($unit === 'm') {
            // already MB
        } else {
            // No unit suffix: the value is raw bytes.
            $num /= 1048576;
        }

        return (int) round($num);
    }
}
