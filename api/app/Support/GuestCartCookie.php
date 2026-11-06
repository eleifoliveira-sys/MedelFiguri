<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class GuestCartCookie
{
    public const NAME = 'medelfiguri_guest_cart';

    private const LIFETIME_MINUTES = 43200;

    public static function issue(string $token, Request $request): Cookie
    {
        return new Cookie(
            self::NAME,
            $token,
            now()->addMinutes(self::LIFETIME_MINUTES),
            '/',
            config('session.domain'),
            self::secure($request),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    public static function forget(Request $request): Cookie
    {
        return new Cookie(
            self::NAME,
            '',
            now()->subYear(),
            '/',
            config('session.domain'),
            self::secure($request),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    private static function secure(Request $request): bool
    {
        return $request->isSecure() || (bool) config('session.secure');
    }
}
