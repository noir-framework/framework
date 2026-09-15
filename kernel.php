<?php

/**
 * @noinspection PhpUnused
 * @noinspection UnknownInspectionInspection
 * @noinspection PhpUnhandledExceptionInspection
 */

declare(strict_types=1);

use Noirapi\Config;
use Noirapi\Lib\Route;

/** @psalm-suppress MissingFile */
include(__DIR__ . '/include.php');

// If the request is for the index.php, use the router
/**
 * @psalm-suppress RedundantCondition Psalm infers PHP_SELF as always '/index.php'
 * from the literal assignment in bin/router.php (dev server only); the real
 * value at runtime depends on the web server config, so the check is live.
 */
if (isset($_SERVER['PHP_SELF']) && $_SERVER['PHP_SELF'] === '/index.php') {
    $https = isset($_SERVER['HTTPS']);
    Config::set('https', $https);
    /** @noinspection HostnameSubstitutionInspection */
    $domain = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'default';
    Config::set('domain', $domain);
    /**
     * @noinspection PhpUnhandledExceptionInspection
     * @psalm-suppress PossiblyInvalidArgument
     */
    $response = Route::fromGlobals($_SERVER, $_GET, $_POST, $_FILES, $_COOKIE)->serve();

    http_response_code($response->getStatus());

    $cookie_domain = Config::get('cookie_domain');

    foreach ($response->getCookies() as $cookie) {
        setcookie(
            $cookie['key'],
            $cookie['value'],
            [
                'expires'  => $cookie['expire'],
                'path'     => '/',
                'domain'   => $cookie_domain ?? $domain,
                'secure'   => $https ? $cookie['secure'] : false,
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'],
            ]
        );
    }

    foreach ($response->getHeaders() as $key => $value) {
        header(ucfirst($key) . ': ' . $value);
    }

    echo $response->getBody();

    //Force calling destructors
    unset($response);
}
