<?php
namespace App\Support;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Satu-satunya tempat yang mengatur cookie HttpOnly pembawa token Sanctum,
 * agar login, logout dan middleware memakai nama/atribut cookie yang sama.
 */
final class AuthCookie
{
    /** Umur token dan cookie: tujuh hari. */
    public const TTL_MINUTES = 60 * 24 * 7;

    public static function name(): string
    {
        return config('pangantani.auth_cookie.name');
    }

    public static function make(string $token): Cookie
    {
        return cookie(
            name: self::name(),
            value: $token,
            minutes: self::TTL_MINUTES,
            path: '/',
            domain: self::domain(),
            secure: self::secure(),
            httpOnly: true,
            raw: false,
            sameSite: self::sameSite(),
        );
    }

    public static function forget(): Cookie
    {
        return cookie()->forget(self::name(), '/', self::domain());
    }

    private static function domain(): ?string
    {
        return config('pangantani.auth_cookie.domain') ?: null;
    }

    private static function sameSite(): string
    {
        return in_array(config('pangantani.auth_cookie.same_site'), ['lax', 'strict', 'none'], true)
            ? config('pangantani.auth_cookie.same_site')
            : 'lax';
    }

    private static function secure(): bool
    {
        // Browser menolak SameSite=None tanpa Secure.
        if (self::sameSite() === 'none') {
            return true;
        }
        $configured = config('pangantani.auth_cookie.secure');

        return $configured === null || $configured === '' ? app()->environment('production') : (bool) $configured;
    }
}
