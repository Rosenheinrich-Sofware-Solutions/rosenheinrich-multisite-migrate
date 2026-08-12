<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Informational marketing/branding helper for the free edition.
 *
 * This build has NO license layer, NO tiers, NO seats, NO trial, NO quota and
 * NO entitlement store. Every feature that ships in this plugin is fully
 * functional with no restriction of any kind. There is nothing here that can be
 * "unlocked" by a key, purchase, quota or time limit.
 *
 * The methods below only build outbound marketing/documentation links (so we can
 * *point out* the separate, self-hosted Multisite Migrate Pro plugin, which the
 * WordPress.org guidelines explicitly permit) and expose brand strings. They do
 * not gate, disable or limit any behaviour in this plugin.
 */
class Rmmigrate_Capabilities
{
    const PURCHASE_URL = 'https://multisitemigrate.rosenheinrich.com';
    const PRICING_BASE_URL = 'https://multisitemigrate.rosenheinrich.com';

    public static function app_name(): string
    {
        $default = __('Multisite Migrate', 'rosenheinrich-multisite-migrate');

        return (string) apply_filters('rmmigrate_app_name', $default);
    }

    public static function purchase_url(): string
    {
        return (string) apply_filters('rmmigrate_purchase_url', self::PURCHASE_URL);
    }

    public static function pricing_url(): string
    {
        return self::localized_url(
            array('de' => '/de/preise/', 'intl' => '/pricing/', 'default' => '/pricing/'),
            'rmmigrate_pricing_url'
        );
    }

    public static function docs_url(): string
    {
        return self::localized_url(
            array('de' => '/de/dokumentation/', 'intl' => '/docs/', 'default' => '/docs/'),
            'rmmigrate_docs_url'
        );
    }

    public static function docs_restore_url(): string
    {
        return self::localized_url(
            array('de' => '/de/dokumentation-restore/', 'intl' => '/docs-restore/', 'default' => '/docs-restore/'),
            'rmmigrate_docs_restore_url'
        );
    }

    public static function docs_empty_server_url(): string
    {
        return self::docs_restore_url() . '#empty-server';
    }

    public static function contact_url(): string
    {
        return self::localized_url(
            array('de' => '/de/kontakt/', 'intl' => '/contact/', 'default' => '/contact/'),
            'rmmigrate_contact_url'
        );
    }

    public static function privacy_url(): string
    {
        return self::localized_url(
            array('de' => '/de/datenschutz/', 'intl' => '/privacy-policy/', 'default' => '/privacy-policy/'),
            'rmmigrate_privacy_url'
        );
    }

    public static function website_url(): string
    {
        return self::localized_url(
            array('de' => '/de/', 'intl' => '/', 'default' => '/'),
            'rmmigrate_website_url'
        );
    }

    public static function commerce_host(): string
    {
        $host = wp_parse_url(self::PRICING_BASE_URL, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'multisitemigrate.rosenheinrich.com';
    }

    /**
     * The Free build ships no license screen; send any "learn more" CTA to the
     * marketing-site pricing page.
     */
    public static function settings_upgrade_url(bool $is_network = false): string
    {
        unset($is_network);

        if (class_exists('Rmmigrate_Links')) {
            return Rmmigrate_Links::pricing_url('settings');
        }

        return self::marketing_url(self::pricing_url(), 'settings');
    }

    /**
     * Tag a marketing-site URL with UTM params so the website can attribute the
     * funnel back to a specific in-plugin surface.
     */
    public static function with_utm(string $url, string $campaign, string $content = ''): string
    {
        if ($url === '') {
            return $url;
        }

        $args = array(
            'utm_source'   => 'plugin',
            'utm_medium'   => 'admin',
            'utm_campaign' => sanitize_key($campaign) !== '' ? sanitize_key($campaign) : 'plugin',
        );
        $content_key = sanitize_key($content);
        if ($content_key !== '') {
            $args['utm_content'] = $content_key;
        }

        return add_query_arg($args, $url);
    }

    /**
     * UTM-tag an outbound marketing/docs URL from an in-plugin surface.
     */
    public static function marketing_url(string $url, string $campaign): string
    {
        if ($url === '') {
            return $url;
        }

        $slug = sanitize_key($campaign);
        if ($slug === '') {
            $slug = 'plugin';
        }
        $campaign_full = 'free_' . $slug;

        // Preserve URL fragments (e.g. docs-restore/#empty-server) — add_query_arg
        // mishandles hash suffixes and would drop UTM params entirely.
        $fragment = '';
        $hash_pos = strpos($url, '#');
        if ($hash_pos !== false) {
            $fragment = substr($url, $hash_pos);
            $url = substr($url, 0, $hash_pos);
        }

        return self::with_utm($url, $campaign_full, $slug) . $fragment;
    }

    /**
     * @param array{de:string,intl:string,default:string} $paths
     */
    private static function localized_url(array $paths, string $filter): string
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $lang = sanitize_key(substr((string) $locale, 0, 2));

        if ($lang === 'de') {
            $path = $paths['de'];
        } elseif (in_array($lang, array('fr', 'es', 'it', 'pt', 'nl', 'pl', 'ja', 'zh'), true)) {
            $path = '/' . $lang . rtrim($paths['intl'], '/') . '/';
        } else {
            $path = $paths['default'];
        }

        $url = self::PRICING_BASE_URL . $path;

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $filter is always an internal, hardcoded rmmigrate_* hook name passed by the methods in this class.
        return (string) apply_filters($filter, $url, $locale);
    }
}
