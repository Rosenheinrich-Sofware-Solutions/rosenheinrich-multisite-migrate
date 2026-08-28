<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth consent screen HTML.
 */
final class Rmmigrate_OAuth_Authorize_UI
{
    /**
     * @param array<string,mixed> $ctx
     */
    public static function render_consent(array $ctx): void
    {
        $client = is_array($ctx['client'] ?? null) ? $ctx['client'] : array();
        $name = (string) ($client['name'] ?? 'AI client');
        $client_id = (string) ($ctx['client_id'] ?? '');
        $redirect_uri = (string) ($ctx['redirect_uri'] ?? '');
        $state = (string) ($ctx['state'] ?? '');
        $scope = (string) ($ctx['scope'] ?? '');
        $challenge = (string) ($ctx['challenge'] ?? '');
        $method = (string) ($ctx['method'] ?? 'S256');

        $action = rest_url('multisite-migrate/v1/oauth/authorize');
        $common = array(
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'state'                 => $state,
            'scope'                 => $scope,
            'code_challenge'        => $challenge,
            'code_challenge_method' => $method,
            'response_type'         => 'code',
            '_wpnonce'              => wp_create_nonce(Rmmigrate_OAuth_Server::consent_nonce_action(array(
                'client_id'    => $client_id,
                'redirect_uri' => $redirect_uri,
                'scope'        => $scope,
                'challenge'    => $challenge,
                'method'       => $method,
            ))),
        );

        nocache_headers();
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=utf-8');
        $title = __('Authorize Multisite Migrate', 'rosenheinrich-multisite-migrate');
        $site = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
        $scope_val = $scope !== '' ? $scope : 'mm.abilities.read offline_access';

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . esc_html($title) . '</title>';
        echo '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;line-height:1.5}';
        echo '.card{border:1px solid #c3c4c7;border-radius:4px;padding:1.25rem}';
        echo '.actions{display:flex;gap:.75rem;margin-top:1.25rem;align-items:flex-start}';
        echo 'form{margin:0}';
        echo 'button.btn{display:inline-block;padding:.5rem 1rem;border:none;border-radius:3px;cursor:pointer;font:inherit}';
        echo 'button.allow{background:#2271b1;color:#fff}button.deny{background:#f0f0f1;color:#1d2327}</style></head><body>';
        echo '<div class="card"><h1>' . esc_html($title) . '</h1>';
        echo '<p>' . sprintf(
            /* translators: 1: client name, 2: site host */
            esc_html__('Allow %1$s to call Multisite Migrate backup abilities on %2$s?', 'rosenheinrich-multisite-migrate'),
            esc_html($name),
            esc_html($site)
        ) . '</p>';
        echo '<p><strong>' . esc_html__('Scopes', 'rosenheinrich-multisite-migrate') . ':</strong> <code>' . esc_html($scope_val) . '</code></p>';
        if ($redirect_uri !== '') {
            echo '<p><strong>' . esc_html__('Redirect URI', 'rosenheinrich-multisite-migrate') . ':</strong> <code>' . esc_html($redirect_uri) . '</code></p>';
        }
        echo '<div class="actions">';
        self::render_decision_form($action, $common, 'allow', __('Allow', 'rosenheinrich-multisite-migrate'), 'allow');
        self::render_decision_form($action, $common, 'deny', __('Deny', 'rosenheinrich-multisite-migrate'), 'deny');
        echo '</div></div></body></html>';
    }

    /**
     * @param array<string,string> $common
     */
    private static function render_decision_form(string $action, array $common, string $decision, string $label, string $class): void
    {
        echo '<form method="post" action="' . esc_url($action) . '">';
        foreach ($common as $key => $value) {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }
        echo '<input type="hidden" name="decision" value="' . esc_attr($decision) . '">';
        echo '<button type="submit" class="btn ' . esc_attr($class) . '">' . esc_html($label) . '</button>';
        echo '</form>';
    }
}
