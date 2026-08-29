<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Setup_Wizard
{
    const OPTION_KEY = 'rmmigrate_setup_wizard';
    const PENDING_REDIRECT_OPTION = 'rmmigrate_pending_setup';
    const COMPLETED_EVENT_OPTION = 'rmmigrate_setup_wizard_completed_emitted';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return array(
            'complete'            => false,
            'skipped'             => false,
            'banner_dismissed'    => false,
            'newsletter_opted_in' => false,
            'newsletter_pending'  => false,
            'newsletter_skipped'  => false,
            'steps'               => array(
                'welcome'  => false,
                'features' => false,
                'finish'   => false,
            ),
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
        $stored_steps = $stored['steps'] ?? array();
        if (!is_array($stored_steps)) {
            $stored_steps = array();
        }
        $merged = array_merge(self::defaults(), $stored, array(
            'steps' => array_merge(self::defaults()['steps'], $stored_steps),
        ));
        // Drop legacy keys from older wizard versions.
        unset($merged['test_backup_done']);

        return $merged;
    }

    /**
     * @param array<string,mixed> $rmmigrate_state
     */
    public static function save_state(array $rmmigrate_state): void
    {
        update_site_option(self::OPTION_KEY, $rmmigrate_state);
    }

    public static function is_complete(): bool
    {
        return !empty(self::get_state()['complete']);
    }

    public static function is_finished(): bool
    {
        $rmmigrate_state = self::get_state();

        return !empty($rmmigrate_state['complete']) || !empty($rmmigrate_state['skipped']);
    }

    public static function menu_visible(): bool
    {
        if (!self::can_run() || self::is_finished()) {
            return false;
        }

        return empty(self::get_state()['banner_dismissed']);
    }

    public static function needs_setup_banner(): bool
    {
        if (!self::can_run()) {
            return false;
        }
        $rmmigrate_state = self::get_state();
        if (!empty($rmmigrate_state['complete']) || !empty($rmmigrate_state['banner_dismissed'])) {
            return false;
        }

        return true;
    }

    public static function dismiss_banner(): void
    {
        $rmmigrate_state = self::get_state();
        $rmmigrate_state['banner_dismissed'] = true;
        self::save_state($rmmigrate_state);
        self::clear_pending_post_activation_redirect();
    }

    public static function should_queue_post_activation_redirect(): bool
    {
        if (self::is_finished()) {
            return false;
        }

        return empty(self::get_state()['banner_dismissed']);
    }

    public static function queue_post_activation_redirect(): void
    {
        if (!self::should_queue_post_activation_redirect()) {
            return;
        }

        update_site_option(self::PENDING_REDIRECT_OPTION, '1');
    }

    private static function is_fresh_activation_request(): bool
    {
        global $pagenow;

        if ($pagenow !== 'plugins.php') {
            return false;
        }

        if (Rmmigrate_Request_Input::get_text('activate') === 'true') {
            return true;
        }

        return Rmmigrate_Request_Input::get_text('activated') === 'true';
    }

    public static function clear_pending_post_activation_redirect(): void
    {
        delete_site_option(self::PENDING_REDIRECT_OPTION);
    }

    private static function is_pending_post_activation_redirect(): bool
    {
        return get_site_option(self::PENDING_REDIRECT_OPTION, '') === '1';
    }

    public static function render_banner(bool $rmmigrate_is_network, string $rmmigrate_page_slug = ''): void
    {
        if ($rmmigrate_page_slug === 'multisite-migrate-setup') {
            return;
        }
        if (!self::needs_setup_banner()) {
            return;
        }
        $rmmigrate_setup_state = self::get_state();
        $rmmigrate_setup_url = Rmmigrate_Admin_Router::admin_url('multisite-migrate-setup', array(), $rmmigrate_is_network);
        include RMMIGRATE_PATH . 'admin/partials/components/setup-banner.php';
    }

    public static function progress_percent(): int
    {
        $rmmigrate_state = self::get_state();
        if (!empty($rmmigrate_state['complete'])) {
            return 100;
        }
        $required = array('welcome', 'features', 'finish');
        $done = 0;
        foreach ($required as $step) {
            if (!empty($rmmigrate_state['steps'][$step])) {
                $done++;
            }
        }

        return (int) round(($done / count($required)) * 100);
    }

    public static function mark_step(string $rmmigrate_step): void
    {
        $allowed = array_keys(self::defaults()['steps']);
        if (!in_array($rmmigrate_step, $allowed, true)) {
            return;
        }
        $rmmigrate_state = self::get_state();
        if (!empty($rmmigrate_state['steps'][$rmmigrate_step])) {
            return;
        }
        $rmmigrate_state['steps'][$rmmigrate_step] = true;
        self::save_state($rmmigrate_state);
        Rmmigrate_Telemetry::record_event('wizard_step', array(
            'step'   => sanitize_key($rmmigrate_step),
            'action' => 'completed',
        ));
    }

    /**
     * Newsletter opt-in moved off Welcome — mark progress without a newsletter choice.
     */
    public static function auto_complete_welcome_step(): void
    {
        $rmmigrate_state = self::get_state();
        if (!empty($rmmigrate_state['steps']['welcome'])) {
            return;
        }
        $rmmigrate_state['steps']['welcome'] = true;
        self::save_state($rmmigrate_state);
    }

    public static function skip(): void
    {
        $rmmigrate_state = self::get_state();
        $rmmigrate_state['skipped'] = true;
        $rmmigrate_state['newsletter_skipped'] = true;
        self::save_state($rmmigrate_state);
        self::clear_pending_post_activation_redirect();
    }

    public static function complete(): void
    {
        $rmmigrate_state = self::get_state();
        if (!empty($rmmigrate_state['complete'])) {
            return;
        }
        $emit_completed = add_site_option(self::COMPLETED_EVENT_OPTION, time());
        $rmmigrate_state['complete'] = true;
        $rmmigrate_state['steps']['welcome'] = true;
        $rmmigrate_state['steps']['features'] = true;
        $rmmigrate_state['steps']['finish'] = true;
        self::save_state($rmmigrate_state);
        self::clear_pending_post_activation_redirect();
        if ($emit_completed) {
            Rmmigrate_Telemetry::record_event('wizard_completed', array(
                'newsletter_opted_in' => !empty($rmmigrate_state['newsletter_opted_in']),
            ));
        }
    }

    public static function should_redirect(): bool
    {
        if (self::is_finished()) {
            return false;
        }
        if (!empty(self::get_state()['banner_dismissed'])) {
            return false;
        }
        if (!self::can_run()) {
            return false;
        }
        if (self::is_pending_post_activation_redirect()) {
            return true;
        }
        if (self::is_fresh_activation_request()) {
            return true;
        }
        $rmmigrate_page = Rmmigrate_Request_Input::get_key('page');
        if ($rmmigrate_page === 'multisite-migrate-setup') {
            return false;
        }
        if ($rmmigrate_page === '' || !isset(Rmmigrate_Admin_Router::all_page_slugs()[$rmmigrate_page])) {
            return false;
        }

        return true;
    }

    public static function can_run(): bool
    {
        if (is_multisite() && !is_network_admin()) {
            return false;
        }

        return self::user_can_manage_setup();
    }

    public static function user_can_manage_setup(): bool
    {
        if (is_multisite()) {
            return current_user_can('manage_network');
        }

        return current_user_can('manage_options');
    }

    private static function verify_setup_ajax(): void
    {
        if (!self::user_can_manage_setup()) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rosenheinrich-multisite-migrate')), 403);
        }
        $nonce = Rmmigrate_Request_Input::post_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_admin')) {
            wp_send_json_error(array('message' => __('Invalid nonce.', 'rosenheinrich-multisite-migrate')), 403);
        }
    }

    public static function register(): void
    {
        add_action('admin_init', array(__CLASS__, 'maybe_redirect'), 4);
        add_action('wp_ajax_rmmigrate_setup_wizard_skip', array(__CLASS__, 'ajax_skip'));
        add_action('wp_ajax_rmmigrate_setup_wizard_complete', array(__CLASS__, 'ajax_complete'));
        add_action('wp_ajax_rmmigrate_setup_wizard_step', array(__CLASS__, 'ajax_save_step'));
        add_action('wp_ajax_rmmigrate_setup_wizard_dismiss_banner', array(__CLASS__, 'ajax_dismiss_banner'));
        add_action('wp_ajax_rmmigrate_setup_wizard_newsletter', array(__CLASS__, 'ajax_newsletter'));
        add_action('wp_ajax_rmmigrate_setup_wizard_newsletter_confirm', array(__CLASS__, 'ajax_newsletter_confirm'));
        add_action('wp_ajax_rmmigrate_setup_wizard_newsletter_fallback', array(__CLASS__, 'ajax_newsletter_fallback'));
    }

    public static function maybe_redirect(): void
    {
        if (wp_doing_ajax()) {
            return;
        }
        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';
        if ($request_method !== 'GET') {
            return;
        }
        if (Rmmigrate_Request_Input::get_exists('activate-multi')) {
            return;
        }
        if (self::is_fresh_activation_request() && self::should_queue_post_activation_redirect()) {
            self::queue_post_activation_redirect();
        }
        if (!self::should_redirect()) {
            return;
        }
        self::clear_pending_post_activation_redirect();
        $rmmigrate_setup_url = Rmmigrate_Admin_Router::admin_url(
            'multisite-migrate-setup',
            array(),
            is_multisite() && is_network_admin()
        );
        wp_safe_redirect($rmmigrate_setup_url);
        exit;
    }

    public static function ajax_skip(): void
    {
        self::verify_setup_ajax();
        self::skip();
        wp_send_json_success(array(
            'redirect' => Rmmigrate_Admin_Router::admin_url(
                'multisite-migrate-archives',
                array(),
                is_multisite() && is_network_admin()
            ),
        ));
    }

    public static function ajax_complete(): void
    {
        self::verify_setup_ajax();
        if (self::is_complete()) {
            wp_send_json_success(array(
                'redirect' => Rmmigrate_Admin_Router::admin_url(
                    'multisite-migrate-archives',
                    array(),
                    is_multisite() && is_network_admin()
                ),
            ));
        }
        self::complete();
        wp_send_json_success(array(
            'redirect' => Rmmigrate_Admin_Router::admin_url(
                'multisite-migrate-archives',
                array(),
                is_multisite() && is_network_admin()
            ),
        ));
    }

    public static function ajax_dismiss_banner(): void
    {
        self::verify_setup_ajax();
        self::dismiss_banner();
        wp_send_json_success();
    }

    public static function ajax_save_step(): void
    {
        self::verify_setup_ajax();
        $rmmigrate_step = Rmmigrate_Request_Input::post_key('step');
        self::mark_step($rmmigrate_step);
        wp_send_json_success();
    }

    /**
     * Return subscribe URL + fields for browser→portal fetch (CF country).
     */
    public static function ajax_newsletter(): void
    {
        self::verify_setup_ajax();

        $allow_raw = Rmmigrate_Request_Input::post_text('allow');
        if ($allow_raw !== '1' && $allow_raw !== '0') {
            wp_send_json_error(
                array('message' => __('Invalid newsletter choice.', 'rosenheinrich-multisite-migrate')),
                400
            );
        }
        $allow = $allow_raw === '1';
        $state = self::get_state();

        if (!$allow) {
            $state['newsletter_skipped'] = true;
            $state['newsletter_pending'] = false;
            $state['steps']['welcome'] = true;
            self::save_state($state);
            wp_send_json_success(array('skipped' => true));
        }

        $fields = self::newsletter_payload();
        $subscribe_url = self::newsletter_subscribe_url();
        if (!self::is_valid_newsletter_subscribe_url($subscribe_url)) {
            wp_send_json_error(
                array('message' => __('Newsletter subscription is unavailable right now.', 'rosenheinrich-multisite-migrate')),
                502
            );
        }
        $state['newsletter_pending'] = true;
        $state['newsletter_opted_in'] = false;
        $state['newsletter_skipped'] = false;
        $state['steps']['welcome'] = true;
        self::save_state($state);

        wp_send_json_success(array(
            'subscribeUrl' => $subscribe_url,
            'fields'       => $fields,
        ));
    }

    /**
     * Promote pending newsletter consent after browser subscribe succeeds.
     */
    public static function ajax_newsletter_confirm(): void
    {
        self::verify_setup_ajax();

        $state = self::get_state();
        if (empty($state['newsletter_pending'])) {
            wp_send_json_error(
                array(
                    'message' => esc_html__(
                        'Newsletter opt-in is required before confirming subscription.',
                        'rosenheinrich-multisite-migrate'
                    ),
                ),
                403
            );
        }

        $state['newsletter_opted_in'] = true;
        $state['newsletter_pending'] = false;
        self::save_state($state);

        wp_send_json_success(array('ok' => true));
    }

    /**
     * Server-side fallback when browser CORS subscribe fails (forces DOI / unknown).
     */
    public static function ajax_newsletter_fallback(): void
    {
        self::verify_setup_ajax();

        $state = self::get_state();
        if (empty($state['newsletter_pending'])) {
            wp_send_json_error(
                array(
                    'message' => esc_html__(
                        'Newsletter opt-in is required before subscribing.',
                        'rosenheinrich-multisite-migrate'
                    ),
                ),
                403
            );
        }

        $fields = self::newsletter_payload();
        $fields['consent_mode'] = 'unknown';

        $subscribe_url = self::newsletter_subscribe_url();
        if (!self::is_valid_newsletter_subscribe_url($subscribe_url)) {
            $state['newsletter_pending'] = false;
            self::save_state($state);
            wp_send_json_error(
                array(
                    'message' => esc_html__(
                        'Newsletter subscription could not be completed. Please try again later.',
                        'rosenheinrich-multisite-migrate'
                    ),
                ),
                502
            );
        }

        $response = wp_remote_post($subscribe_url, array(
            'timeout' => 4,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($fields),
        ));

        if (is_wp_error($response)) {
            $state['newsletter_pending'] = false;
            self::save_state($state);
            wp_send_json_error(
                array(
                    'message' => esc_html__(
                        'Newsletter subscription could not be completed. Please try again later.',
                        'rosenheinrich-multisite-migrate'
                    ),
                ),
                502
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $state['newsletter_pending'] = false;
            self::save_state($state);
            wp_send_json_error(
                array(
                    'message' => __(
                        'Newsletter subscription could not be completed. Please try again later.',
                        'rosenheinrich-multisite-migrate'
                    ),
                    'upstream_status' => $status_code,
                ),
                502
            );
        }

        $state['newsletter_opted_in'] = true;
        $state['newsletter_pending'] = false;
        self::save_state($state);

        wp_send_json_success(array('ok' => true));
    }

    /**
     * @return array<string,mixed>
     */
    public static function newsletter_payload(): array
    {
        $user = wp_get_current_user();

        return array(
            'email'          => (string) $user->user_email,
            'first_name'     => (string) $user->first_name,
            'last_name'      => (string) $user->last_name,
            'display_name'   => (string) $user->display_name,
            'product_build'  => 'free',
            'audience'       => 'free',
            'site_url'       => home_url('/'),
            'site_title'     => (string) get_bloginfo('name'),
            'locale'         => function_exists('determine_locale') ? determine_locale() : get_locale(),
            'wp_version'     => (string) get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'plugin_version' => defined('RMMIGRATE_VERSION') ? (string) RMMIGRATE_VERSION : '',
            'source'         => 'setup_wizard',
        );
    }

    public static function newsletter_subscribe_url(): string
    {
        $base = Rmmigrate_Capabilities::PRICING_BASE_URL;

        return (string) apply_filters(
            'rmmigrate_newsletter_subscribe_url',
            $base . '/wp-json/multisite-migrate-portal/v1/newsletter/subscribe'
        );
    }

    private static function is_valid_newsletter_subscribe_url(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            return false;
        }

        return strtolower((string) $parts['host']) === strtolower(Rmmigrate_Capabilities::commerce_host());
    }

    /**
     * Disclosure rows for the expandable “This will allow…” list.
     *
     * @return array<int,array{title:string,detail:string}>
     */
    public static function newsletter_disclosure(): array
    {
        $user = wp_get_current_user();
        $name = trim($user->first_name . ' ' . $user->last_name);
        if ($name === '') {
            $name = (string) $user->display_name;
        }

        return array(
            array(
                'title'  => __('View basic profile info', 'rosenheinrich-multisite-migrate'),
                'detail' => sprintf(
                    /* translators: 1: admin display name, 2: admin email */
                    __('Your WordPress user’s name (%1$s) and email address (%2$s).', 'rosenheinrich-multisite-migrate'),
                    $name,
                    (string) $user->user_email
                ),
            ),
            array(
                'title'  => __('View basic website info', 'rosenheinrich-multisite-migrate'),
                'detail' => sprintf(
                    /* translators: 1: site URL, 2: site title, 3: WP version, 4: PHP version, 5: locale */
                    __('Homepage URL & title (%1$s — %2$s), WP %3$s, PHP %4$s, and site language (%5$s).', 'rosenheinrich-multisite-migrate'),
                    home_url('/'),
                    (string) get_bloginfo('name'),
                    (string) get_bloginfo('version'),
                    PHP_VERSION,
                    function_exists('determine_locale') ? determine_locale() : get_locale()
                ),
            ),
            array(
                'title'  => __('View basic plugin info', 'rosenheinrich-multisite-migrate'),
                'detail' => sprintf(
                    /* translators: %s: plugin version */
                    __('Current Multisite Migrate version (%s) and edition (Free).', 'rosenheinrich-multisite-migrate'),
                    defined('RMMIGRATE_VERSION') ? (string) RMMIGRATE_VERSION : ''
                ),
            ),
        );
    }

    /**
     * @return array<int,array{slug:string,label:string,icon:string,blurb:string}>
     */
    public static function feature_cards(): array
    {
        return array(
            array(
                'slug'  => 'network',
                'label' => __('Network backups', 'rosenheinrich-multisite-migrate'),
                'icon'  => 'dashicons-networking',
                'blurb' => __('Back up the full multisite network, including shared tables and every subsite.', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'slug'  => 'scope',
                'label' => __('Subsite scope control', 'rosenheinrich-multisite-migrate'),
                'icon'  => 'dashicons-filter',
                'blurb' => __('Include or exclude specific subsites when you create a backup from the Backups screen.', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'slug'  => 'import',
                'label' => __('Import & restore', 'rosenheinrich-multisite-migrate'),
                'icon'  => 'dashicons-migrate',
                'blurb' => __('Bring packages onto the network and restore with guided search-replace.', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'slug'  => 'filters',
                'label' => __('File & table filters', 'rosenheinrich-multisite-migrate'),
                'icon'  => 'dashicons-database',
                'blurb' => __('Skip heavy paths and tables so packages stay lean and predictable.', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'slug'  => 'large',
                'label' => __('Large site support', 'rosenheinrich-multisite-migrate'),
                'icon'  => 'dashicons-performance',
                'blurb' => __('Chunked processing designed for big uploads directories and long-running jobs.', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'slug'  => 'local',
                'label' => __('Local storage', 'rosenheinrich-multisite-migrate'),
                'icon'  => 'dashicons-cloud',
                'blurb' => __('Keep packages on the server by default. Cloud destinations ship with Plus & Pro.', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'slug'  => 'recovery',
                'label' => __('Recovery points', 'rosenheinrich-multisite-migrate'),
                'icon'  => 'dashicons-shield',
                'blurb' => __('Quick rollback points for safer migrations — available in the commercial plugin.', 'rosenheinrich-multisite-migrate'),
            ),
            array(
                'slug'  => 'schedule',
                'label' => __('Scheduled backups', 'rosenheinrich-multisite-migrate'),
                'icon'  => 'dashicons-clock',
                'blurb' => __('Automate recurring backups on a schedule with Plus or Pro.', 'rosenheinrich-multisite-migrate'),
            ),
        );
    }

    /**
     * @return array<int,string>
     */
    public static function pro_highlights(): array
    {
        return array(
            __('Scheduled backups', 'rosenheinrich-multisite-migrate'),
            __('Cloud storage destinations', 'rosenheinrich-multisite-migrate'),
            __('Recovery points', 'rosenheinrich-multisite-migrate'),
            __('Secure package encryption', 'rosenheinrich-multisite-migrate'),
            __('Advanced network tooling', 'rosenheinrich-multisite-migrate'),
            __('Priority product updates', 'rosenheinrich-multisite-migrate'),
        );
    }

    /**
     * @return array<int,array{slug:string,label:string}>
     */
    public static function steps(): array
    {
        return array(
            array('slug' => 'welcome', 'label' => __('Welcome', 'rosenheinrich-multisite-migrate')),
            array('slug' => 'features', 'label' => __('Features', 'rosenheinrich-multisite-migrate')),
            array('slug' => 'finish', 'label' => __('Finish', 'rosenheinrich-multisite-migrate')),
        );
    }

    /**
     * @return array<int,array{slug:string,label:string}>
     */
    public static function optional_steps(): array
    {
        return array();
    }
}
