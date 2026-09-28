<?php

declare(strict_types=1);

namespace Noirapi\Lib\Security;

use Noirapi\Config;
use Noirapi\Lib\Request;
use Random\RandomException;

use function in_array;
use function is_array;
use function is_string;

/**
 * Per-session CSRF token and the request check Route runs when `csrf` is enabled:
 *
 *     csrf: true
 *     # or
 *     csrf:
 *         enabled: true
 *         allowed_origins: [https://admin.example.com]   # other origins allowed to POST here
 *
 * A state-changing request (anything but GET/HEAD/OPTIONS/TRACE) passes when
 *  - it is not cross-site: an Origin header must name this host (or an allowed origin), and
 *    without Origin, Sec-Fetch-Site must be same-origin or none when present; and
 *  - it carries the session's token in the `_csrf` field or the `X-CSRF-Token` header.
 *
 * Templates print the field with {csrf}, or the bare token with {csrfToken} (for a
 * <meta> tag that JavaScript copies into the X-CSRF-Token header). Actions or controllers
 * that must accept foreign POSTs (webhooks, token-authenticated APIs) use #[SkipCsrf].
 *
 * @psalm-api
 */
final class Csrf
{
    public const string FIELD = '_csrf';
    public const string HEADER = 'X-CSRF-Token';
    public const string SESSION_KEY = '_csrf_token';
    public const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * Whether Route enforces the check: `csrf: true`, or a `csrf:` block without `enabled: false`.
     */
    public static function enabled(): bool
    {
        $config = Config::get('csrf');

        return $config === true || (is_array($config) && ($config['enabled'] ?? true) === true);
    }

    /**
     * The session's token, created on first use. The session must be started for it to persist.
     *
     * @throws RandomException
     */
    public static function token(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    /**
     * Drops the token; the next token() creates a new one. Session::regenerate() does this.
     */
    public static function reset(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function isValid(mixed $submitted): bool
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($token) && $token !== '' && is_string($submitted) && hash_equals($token, $submitted);
    }

    /**
     * The full check for one request: safe methods always pass; others must be same-origin
     * and carry the token.
     */
    public static function check(Request $request): bool
    {
        if (in_array($request->method, self::SAFE_METHODS, true)) {
            return true;
        }

        return self::isSameOrigin($request)
            && self::isValid($request->post[self::FIELD] ?? $request->getHeader(self::HEADER));
    }

    /**
     * Origin (when sent) must be this host or an allowed origin; without Origin, a
     * Sec-Fetch-Site of cross-site / same-site is rejected. Requests with neither header
     * (old browsers, non-browser clients) rely on the token alone.
     */
    public static function isSameOrigin(Request $request): bool
    {
        $origin = $request->getHeader('Origin');

        if ($origin !== null) {
            return self::originAllowed(strtolower(rtrim(trim($origin), '/')), strtolower($request->hostname));
        }

        $site = $request->getHeader('Sec-Fetch-Site');

        return $site === null || in_array(strtolower($site), ['same-origin', 'none'], true);
    }

    /**
     * @psalm-external-mutation-free
     */
    private static function originAllowed(string $origin, string $hostname): bool
    {
        $parts = parse_url($origin);
        if ($origin === 'null' || ! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }

        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($hostname !== '' && $host === $hostname) {
            return true;
        }

        $allowed = Config::get('csrf.allowed_origins');

        return is_array($allowed)
            && in_array($origin, array_map(static fn (mixed $item): string => strtolower(rtrim((string) $item, '/')), $allowed), true); //phpcs:ignore
    }
}
