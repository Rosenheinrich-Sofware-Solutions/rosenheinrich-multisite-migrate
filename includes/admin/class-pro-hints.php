<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contextual, dismissible "this workflow is in the separate Pro plugin" hints.
 *
 * These are informational upsell cards, NOT locked in-place features: the free
 * plugin never renders a disabled control gated behind a purchase (guideline 5).
 * Every card:
 *   - is dismissible per-user (guideline 11) and stays dismissed,
 *   - links out only through Rmmigrate_Links, which UTM-tags on click only and
 *     never tracks the user passively (guideline 7),
 *   - states plainly that the feature lives in a separate download.
 */
final class Rmmigrate_Pro_Hints
{
    const USER_META_KEY = 'rmmigrate_pro_hints_dismissed';

    public static function register(): void
    {
        add_action('wp_ajax_rmmigrate_pro_hint_dismiss', array(__CLASS__, 'ajax_dismiss'));
    }

    /**
     * True when it is appropriate to show a Pro hint to the current user.
     */
    public static function is_available(): bool
    {
        return self::user_can_see();
    }

    /**
     * Render a single contextual Pro hint card.
     *
     * @param string               $slug Stable identifier used for per-user dismissal.
     * @param array<string,mixed>  $args title, text, cta_label, cta_url, placement, dismissible.
     */
    public static function render(string $slug, array $args = array()): void
    {
        if (!self::is_available()) {
            return;
        }

        $slug = sanitize_key($slug);
        if ($slug === '' || self::is_dismissed($slug)) {
            return;
        }

        $placement = isset($args['placement']) && (string) $args['placement'] !== ''
            ? (string) $args['placement']
            : 'hint_' . $slug;

        $rmmigrate_hint_slug        = $slug;
        $rmmigrate_hint_title       = (string) ($args['title'] ?? '');
        $rmmigrate_hint_text        = (string) ($args['text'] ?? '');
        $rmmigrate_hint_cta_label   = (string) ($args['cta_label'] ?? __('See Plus & Pro', 'rosenheinrich-multisite-migrate'));
        $rmmigrate_hint_cta_url     = (string) ($args['cta_url'] ?? Rmmigrate_Links::pricing_url($placement));
        $rmmigrate_hint_dismissible = !array_key_exists('dismissible', $args) || !empty($args['dismissible']);

        include RMMIGRATE_PATH . 'admin/partials/components/pro-hint.php';
    }

    public static function ajax_dismiss(): void
    {
        if (!self::user_can_see()) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rosenheinrich-multisite-migrate')), 403);
        }

        $nonce = Rmmigrate_Request_Input::post_text('nonce');
        if (!wp_verify_nonce($nonce, 'rmmigrate_admin')) {
            wp_send_json_error(array('message' => __('Invalid nonce.', 'rosenheinrich-multisite-migrate')), 403);
        }

        $slug = sanitize_key(Rmmigrate_Request_Input::post_text('slug'));
        if ($slug === '') {
            wp_send_json_error(array('message' => __('Invalid hint.', 'rosenheinrich-multisite-migrate')), 400);
        }

        self::mark_dismissed($slug);
        wp_send_json_success();
    }

    public static function is_dismissed(string $slug): bool
    {
        return in_array($slug, self::dismissed_slugs(), true);
    }

    /**
     * @return string[]
     */
    private static function dismissed_slugs(): array
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return array();
        }

        $stored = get_user_meta($user_id, self::USER_META_KEY, true);
        if (!is_array($stored)) {
            return array();
        }

        return array_values(array_filter(array_map('strval', $stored)));
    }

    private static function mark_dismissed(string $slug): void
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }

        $slugs = self::dismissed_slugs();
        if (in_array($slug, $slugs, true)) {
            return;
        }

        $slugs[] = $slug;
        update_user_meta($user_id, self::USER_META_KEY, $slugs);
    }

    private static function user_can_see(): bool
    {
        return current_user_can('manage_options') || current_user_can('manage_network');
    }
}
