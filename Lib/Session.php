<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Noirapi\Config;

use function is_array;
use function is_bool;
use function is_int;
use function is_object;
use function is_string;

/**
 * Session data helpers, plus a hardened start():
 *
 *     session:
 *         cookie:
 *             name: app_session    # default: PHP's session.name
 *             lifetime: 0          # seconds; 0 = until the browser closes
 *             samesite: Lax        # Lax | Strict | None (None forces secure)
 *             secure: auto         # auto = when the request is HTTPS; or true / false
 *             domain: ''           # default: the cookie_domain config, else the host
 *
 * @psalm-api
 */
class Session
{
    /** Session keys holding per-session secrets, dropped by regenerate(). */
    public const array ROTATED_KEYS = ['_csrf_token'];

    /**
     * Starts the session with strict mode, cookie-only IDs and the cookie settings from
     * `session.cookie`. Safe to call when a session is already active.
     *
     * @param array<string, mixed> $cookie overrides for `session.cookie`
     * @return bool whether a session is active afterwards
     */
    public static function start(array $cookie = []): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        $config = Config::get('session.cookie');
        $config = array_replace(is_array($config) ? $config : [], $cookie);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        if (is_string($config['name'] ?? null) && $config['name'] !== '') {
            session_name($config['name']);
        }

        session_set_cookie_params(self::cookieParams($config, (bool) (Config::get('https') ?? Config::get('is_https'))));

        return session_start();
    }

    /**
     * The session cookie parameters for $config (see the class doc).
     *
     * @param array<string, mixed> $config
     * @param bool $https whether the current request is HTTPS
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: true, samesite: string}
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") the request's scheme, not a behaviour switch.
     */
    public static function cookieParams(array $config, bool $https): array
    {
        $samesite = ucfirst(strtolower(is_string($config['samesite'] ?? null) ? $config['samesite'] : 'Lax'));
        if (! in_array($samesite, ['Lax', 'Strict', 'None'], true)) {
            $samesite = 'Lax';
        }

        $secure = $config['secure'] ?? 'auto';
        $secure = is_bool($secure) ? $secure : $https;

        $domain = $config['domain'] ?? Config::get('cookie_domain') ?? '';

        return [
            'lifetime' => is_int($config['lifetime'] ?? null) ? max(0, $config['lifetime']) : 0,
            'path'     => is_string($config['path'] ?? null) ? $config['path'] : '/',
            'domain'   => is_string($domain) ? $domain : '',
            // Browsers drop SameSite=None cookies that aren't Secure.
            'secure'   => $secure || $samesite === 'None',
            'httponly' => true,
            'samesite' => $samesite,
        ];
    }

    /**
     * New session ID, same data. Call it on login, logout and any privilege change, so a
     * session ID fixed or leaked before cannot ride along. Per-session secrets (the CSRF
     * token) are dropped and re-created on next use.
     *
     * @return void
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && ! headers_sent()) {
            session_regenerate_id(true);
        }

        foreach (self::ROTATED_KEYS as $key) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Ends the session: clears the data, expires the cookie and destroys the stored session.
     *
     * @return void
     */
    public static function destroy(): void
    {
        $_SESSION = [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (! headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => 1,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
    }

    /**
     * @param string $namespace
     * @param string|null $key
     * @return mixed
     * Get $_SESSION['namespace']
     * Get $_SESSION['namespace']['key']
     * Get $_SESSION['namespace']->key
     */
    public static function get(string $namespace, ?string $key = null): mixed
    {
        if ($key === null) {
            return $_SESSION[$namespace] ?? null;
        }

        if (! isset($_SESSION[$namespace])) {
            return null;
        }

        if (is_array($_SESSION[$namespace])) {
            return $_SESSION[$namespace][$key] ?? null;
        }

        if (is_object($_SESSION[$namespace])) {
            /** @noinspection PhpExpressionAlwaysNullInspection */
            return $_SESSION[$namespace]->$key ?? null;
        }

        return null;
    }

    /**
     * @param string $namespace
     * @param string|null $key
     * @param mixed $value
     * @return void
     * Set $_SESSION['namespace'] = $value
     * Set $_SESSION['namespace']['key'] = $value
     */
    public static function set(string $namespace, ?string $key, mixed $value): void
    {
        if ($key === null) {
            $_SESSION[$namespace] = $value;
        } elseif (isset($_SESSION[$namespace]) && is_object($_SESSION[$namespace])) {
            $_SESSION[$namespace]->$key = $value;
        } else {
            $_SESSION[$namespace][$key] = $value;
        }
    }

    /**
     * @param string $namespace
     * @param string|null $key
     * @return bool
     */
    public static function has(string $namespace, ?string $key = null): bool
    {
        if ($key === null) {
            return isset($_SESSION[$namespace]);
        }

        if (! isset($_SESSION[$namespace])) {
            return false;
        }

        if (is_array($_SESSION[$namespace])) {
            return isset($_SESSION[$namespace][$key]);
        }

        if (is_object($_SESSION[$namespace])) {
            /** @noinspection PhpExpressionAlwaysNullInspection */
            return isset($_SESSION[$namespace]->$key);
        }

        return false;
    }

    /**
     * @param string $namespace
     * @param string|null $key
     * @return void
     */
    public static function remove(string $namespace, ?string $key = null): void
    {
        if ($key === null) {
            unset($_SESSION[$namespace]);
        } elseif (isset($_SESSION[$namespace]) && is_array($_SESSION[$namespace])) {
            unset($_SESSION[$namespace][$key]);
        } elseif (isset($_SESSION[$namespace]) && is_object($_SESSION[$namespace])) {
            /** @noinspection PhpExpressionAlwaysNullInspection */
            unset($_SESSION[$namespace]->$key);
        }
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public static function all(): array
    {
        return $_SESSION ?? [];
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        $_SESSION = [];
    }
}
