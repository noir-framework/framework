<?php

declare(strict_types=1);

namespace Noirapi\Auth;

use Noirapi\Config;
use Noirapi\Lib\Response;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function explode;
use function hash_equals;
use function hash_hmac;
use function implode;
use function is_string;
use function strtolower;
use function time;
use function trim;

/**
 * Browsers that have signed in as an e-mail before ("device cookies", OWASP's answer to account lockout).
 *
 * After a successful sign-in, remember() adds a token for the e-mail to the browser's `noir_device` cookie: the
 * expiry and an HMAC of the e-mail and expiry with `app_secret_key`, so the cookie names no account and cannot be
 * forged or moved to another e-mail. A browser keeps the tokens of its last MAX e-mails. PasswordProvider::
 * verifyThrottled() lets a known browser past the per-e-mail limit, so whoever knows an e-mail cannot lock its
 * owner out by failing on purpose; the per-IP limits still apply, and the password is still checked.
 *
 * Without `app_secret_key` nothing is remembered and no browser is known.
 *
 * @psalm-api
 */
final class KnownDevices
{
    public const string COOKIE = 'noir_device';

    /** seconds a token lasts after the sign-in that made it */
    public const int LIFETIME = 365 * 86400;

    /** e-mails one browser remembers (a shared office PC) */
    public const int MAX = 5;

    /**
     * Whether $cookie holds an unexpired token for $email.
     *
     * @psalm-impure
     */
    public static function knows(?string $cookie, string $email, ?int $now = null): bool
    {
        $now ??= time();
        foreach (self::tokens($cookie) as $token) {
            [$expires, $mac] = explode('.', $token);
            $expected = self::mac($email, $expires);
            if ($expected !== null && (int) $expires > $now && hash_equals($expected, $mac)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cookie value after signing in as $email: a fresh token for it first, then the browser's other unexpired
     * tokens, at most MAX. Null without `app_secret_key`.
     *
     * @psalm-impure
     */
    public static function add(?string $cookie, string $email, ?int $now = null): ?string
    {
        $now ??= time();
        $expires = (string) ($now + self::LIFETIME);
        $mac = self::mac($email, $expires);
        if ($mac === null) {
            return null;
        }

        $others = array_filter(
            self::tokens($cookie),
            static function (string $token) use ($email, $now): bool {
                [$expires] = explode('.', $token);

                return (int) $expires > $now && ! self::knows($token, $email, $now);
            }
        );

        return implode('~', array_slice([$expires . '.' . $mac, ...array_values($others)], 0, self::MAX));
    }

    /**
     * add(), sent with $response (Secure on HTTPS, HttpOnly, SameSite=Strict).
     *
     * @psalm-impure
     */
    public static function remember(Response $response, ?string $cookie, string $email, ?int $now = null): void
    {
        $value = self::add($cookie, $email, $now);
        if ($value !== null) {
            $response->addCookie(self::COOKIE, $value, ($now ?? time()) + self::LIFETIME);
        }
    }

    /**
     * The well-formed tokens of a cookie value ("expires.mac~expires.mac…"); anything else is ignored.
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    private static function tokens(?string $cookie): array
    {
        if ($cookie === null || $cookie === '') {
            return [];
        }

        $tokens = [];
        foreach (array_slice(explode('~', $cookie), 0, self::MAX) as $token) {
            $parts = explode('.', $token);
            if (count($parts) === 2 && ctype_digit($parts[0]) && strlen($parts[1]) === 64) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @psalm-impure
     */
    private static function mac(string $email, string $expires): ?string
    {
        $key = Config::get('app_secret_key');
        if (! is_string($key) || $key === '') {
            return null;
        }

        return hash_hmac('sha256', 'known-device|' . strtolower(trim($email)) . '|' . $expires, $key);
    }
}
