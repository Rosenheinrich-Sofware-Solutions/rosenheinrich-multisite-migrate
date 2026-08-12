<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized upgrade/marketing link builder for the Free edition.
 *
 * Every outbound "Pro" link in the Free plugin (menu, plugin-row, teaser cards)
 * routes through here so all marketing-site URLs carry consistent
 * UTM attribution.
 */
class Rmmigrate_Links
{
    /**
     * Pricing page on the marketing site, UTM-tagged for the given placement.
     */
    public static function pricing_url(string $placement): string
    {
        return Rmmigrate_Capabilities::marketing_url(
            Rmmigrate_Capabilities::pricing_url(),
            $placement
        );
    }

    /**
     * Checkout/purchase page on the marketing site, UTM-tagged.
     */
    public static function purchase_url(string $placement): string
    {
        return Rmmigrate_Capabilities::marketing_url(
            Rmmigrate_Capabilities::purchase_url(),
            $placement
        );
    }

    public static function website_url(string $placement): string
    {
        return Rmmigrate_Capabilities::marketing_url(
            Rmmigrate_Capabilities::website_url(),
            $placement
        );
    }

    public static function docs_url(string $placement): string
    {
        return Rmmigrate_Capabilities::marketing_url(
            Rmmigrate_Capabilities::docs_url(),
            $placement
        );
    }

    public static function docs_restore_url(string $placement): string
    {
        return Rmmigrate_Capabilities::marketing_url(
            Rmmigrate_Capabilities::docs_restore_url(),
            $placement
        );
    }

    public static function docs_empty_server_url(string $placement): string
    {
        return Rmmigrate_Capabilities::marketing_url(
            Rmmigrate_Capabilities::docs_empty_server_url(),
            $placement
        );
    }

    public static function contact_url(string $placement): string
    {
        return Rmmigrate_Capabilities::marketing_url(
            Rmmigrate_Capabilities::contact_url(),
            $placement
        );
    }

    public static function review_url(string $placement = 'review_nudge'): string
    {
        unset($placement);

        return Rmmigrate_Review_Nudge::review_url();
    }
}
