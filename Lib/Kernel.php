<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Noirapi\Config;
use Noirapi\Helpers\TracyFileSession;
use Noirapi\Helpers\Utils;
use Noirapi\Lib\Session\SessionHandlerFactory;
use RuntimeException;
use Throwable;
use Tracy\Debugger;
use Tracy\NativeSession;
use Tracy\SessionStorage;

/**
 * Framework entry point, shared by both installation layouts:
 *
 *  - Composer (new projects), htdocs/index.php:
 *        require dirname(__DIR__) . '/vendor/autoload.php';
 *        Noirapi\Lib\Kernel::run(dirname(__DIR__));
 *    and CLI scripts call Noirapi\Lib\Kernel::boot($root) instead.
 *
 *  - git submodule (existing projects): noirapi/kernel.php and noirapi/include.php
 *    are thin shims over run()/boot() and keep working unchanged.
 *
 * @psalm-api
 */
final class Kernel
{
    /**
     * The Opis\Database classes the existing apps reference (type hints on closures such as
     * `function (Expression $expr)`, properties, parameters).
     */
    public const array LEGACY_DATABASE_CLASSES = [
        'Opis\\Database\\Connection',
        'Opis\\Database\\Database',
        'Opis\\Database\\ResultSet',
        'Opis\\Database\\SQL\\ColumnExpression',
        'Opis\\Database\\SQL\\Expression',
        'Opis\\Database\\SQL\\HavingExpression',
        'Opis\\Database\\SQL\\Join',
        'Opis\\Database\\SQL\\Query',
        'Opis\\Database\\SQL\\Subquery',
        'Opis\\Database\\SQL\\WhereStatement',
    ];

    private static bool $booted = false;

    /**
     * noirapi/database resolves Opis\Database\* names to Noirapi\Database\* lazily, on autoload.
     * PHP never autoloads for a type check, so until an alias is loaded a parameter typed with
     * the Opis name rejects the Noirapi object the framework passes. Loading the aliases up
     * front keeps unmodified apps working; Rector/config/noirapi.php removes the need for it.
     *
     * @return void
     */
    public static function loadLegacyDatabaseAliases(): void
    {
        foreach (self::LEGACY_DATABASE_CLASSES as $class) {
            class_exists($class);
        }
    }

    /**
     * Loads the config, sets the timezone, the session handler and Tracy. Idempotent.
     *
     * @param string|null $root application root; detected by Config::detectRoot() when null
     * @return void
     * @throws Throwable
     */
    public static function boot(?string $root = null): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        Config::setRoot($root ?? Config::detectRoot());

        mb_internal_encoding('UTF-8');
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        Config::init(self::configName());

        self::initTimezone();
        self::initSessionHandler();

        Config::set('is_https', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');

        self::initTracy();
    }

    /**
     * Boots and, for web requests routed to /index.php, serves the request.
     * CLI scripts that include this still get just the boot, as noirapi/kernel.php always did.
     *
     * @param string|null $root
     * @return void
     * @throws Throwable
     */
    public static function run(?string $root = null): void
    {
        self::boot($root);

        /**
         * @psalm-suppress RedundantCondition Psalm infers PHP_SELF as always '/index.php'
         * from the literal assignment in bin/router.php (dev server only); the real
         * value at runtime depends on the web server config, so the check is live.
         */
        if (isset($_SERVER['PHP_SELF']) && $_SERVER['PHP_SELF'] === '/index.php') {
            self::handle();
        }
    }

    /**
     * Routes the current request from the PHP globals and emits the response.
     *
     * @return void
     * @throws Throwable
     */
    public static function handle(): void
    {
        $https = isset($_SERVER['HTTPS']);
        Config::set('https', $https);
        /** @noinspection HostnameSubstitutionInspection */
        $domain = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'default';
        Config::set('domain', $domain);

        /** @psalm-suppress PossiblyInvalidArgument */
        $response = Route::fromGlobals($_SERVER, $_GET, $_POST, $_FILES, $_COOKIE)->serve();

        self::emit($response, $https, $domain);

        //Force calling destructors
        unset($response);
    }

    /**
     * @param Response $response
     * @param bool $https
     * @param string $domain
     * @return void
     */
    private static function emit(Response $response, bool $https, string $domain): void
    {
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

        $bodyFile = $response->getBodyFile();
        if ($bodyFile !== null) {
            readfile($bodyFile);
        } else {
            echo $response->getBody();
        }
    }

    /**
     * CONFIG env, else the virtual host name, else 'default'.
     *
     * @return string
     */
    private static function configName(): string
    {
        $config = getenv('CONFIG');

        if (! is_string($config)) {
            $config = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'default';
        }

        if (empty($config)) {
            if (! Config::defaultConfigAvailable()) {
                throw new RuntimeException('CONFIG environment must be set');
            }
            $config = 'default';
        }

        return $config;
    }

    /**
     * PHP's timezone must match the database's (NOW(), CURRENT_TIMESTAMP), or timestamps written by
     * PHP and by MariaDB disagree. Config `timezone` wins; otherwise use the system zone
     * (/etc/localtime), as the apps previously did in their Site controllers.
     *
     * @return void
     */
    private static function initTimezone(): void
    {
        $timezone = Config::get('timezone');
        if (! is_string($timezone) && is_link('/etc/localtime')) {
            $link = (string) readlink('/etc/localtime');
            $pos = strpos($link, 'zoneinfo/');
            $timezone = $pos === false ? null : substr($link, $pos + 9);
        }
        if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * @return void
     */
    private static function initSessionHandler(): void
    {
        $sessionCfg = Config::get('session');
        if (is_array($sessionCfg) && isset($sessionCfg['driver'])) {
            session_set_save_handler(SessionHandlerFactory::create($sessionCfg), true);
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    private static function initTracy(): void
    {
        Debugger::$strictMode = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED; // all errors except deprecated notices
        Debugger::$showLocation = true;
        Debugger::$logSeverity = E_NOTICE | E_WARNING;

        Debugger::setSessionStorage(self::tracySessionStorage());

        if (Utils::isDev($_SERVER['REMOTE_ADDR'] ?? '')) {
            //we are missing some debug events in Tracy, that's why we start session so early
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            Debugger::enable(Debugger::Development, Config::getLogs());
        } else {
            Debugger::enable(Debugger::Production, Config::getLogs(), Config::get('dev_email'));
        }
    }

    /**
     * We have to use our own session storage because Tracy's default session storage is not cleaning up old sessions
     *
     * @return SessionStorage
     */
    private static function tracySessionStorage(): SessionStorage
    {
        $savePath = session_save_path();
        $uploadTmp = ini_get('upload_tmp_dir');

        foreach ([$savePath, Config::getTemp(), $uploadTmp, sys_get_temp_dir()] as $dir) {
            if (is_string($dir) && $dir !== '' && is_dir($dir)) {
                return new TracyFileSession($dir);
            }
        }

        return new NativeSession();
    }
}
