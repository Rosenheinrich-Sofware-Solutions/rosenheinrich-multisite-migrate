<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth scope helpers for ability permission_callbacks.
 *
 * When no OAuth Bearer context is active (cookie / Application Password),
 * all scope checks pass — App Password auth already maps to a WP user.
 */
final class Rmmigrate_OAuth_Scopes
{
    public const SCOPE_READ = 'mm.abilities.read';
    public const SCOPE_WRITE = 'mm.abilities.write';
    public const SCOPE_OFFLINE = 'offline_access';

    /** @var array<string>|null */
    private static $current_scopes = null;

    /**
     * @param array<string>|null $scopes Null clears OAuth context (session/app-password).
     */
    public static function set_current_scopes(?array $scopes): void
    {
        self::$current_scopes = $scopes;
    }

    public static function has_oauth_context(): bool
    {
        return self::$current_scopes !== null;
    }

    public static function current_token_allows_read(): bool
    {
        if (self::$current_scopes === null) {
            return true;
        }
        return in_array(self::SCOPE_READ, self::$current_scopes, true)
            || in_array(self::SCOPE_WRITE, self::$current_scopes, true);
    }

    public static function current_token_allows_write(): bool
    {
        if (self::$current_scopes === null) {
            return true;
        }
        return in_array(self::SCOPE_WRITE, self::$current_scopes, true);
    }
}
