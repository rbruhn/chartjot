<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as CookieObject;

/**
 * The optional password for self-hosted mode (issue #102). When
 * CHARTJOT_PASSWORD is set, each browser unlocks the journal once and a
 * long-lived cookie remembers it. The cookie holds a hash tied to the
 * current password, so changing the password locks every browser again.
 */
class SelfHostedPassword
{
    public const COOKIE = 'chartjot_unlocked';

    /** Unlocked browsers stay unlocked for a year. */
    private const COOKIE_MINUTES = 60 * 24 * 365;

    public static function isRequired(): bool
    {
        return config('chartjot.self_hosted') && filled(config('chartjot.password'));
    }

    public static function matches(string $password): bool
    {
        return hash_equals((string) config('chartjot.password'), $password);
    }

    public static function isUnlocked(Request $request): bool
    {
        return hash_equals(self::token(), (string) $request->cookie(self::COOKIE));
    }

    public static function unlockCookie(): CookieObject
    {
        return Cookie::make(self::COOKIE, self::token(), self::COOKIE_MINUTES);
    }

    public static function forgetCookie(): CookieObject
    {
        return Cookie::forget(self::COOKIE);
    }

    /** Ties the cookie to the current password without storing the password. */
    private static function token(): string
    {
        return hash_hmac('sha256', (string) config('chartjot.password'), (string) config('app.key'));
    }
}
