<?php //phpcs:ignore

/**
 * @noinspection PhpUnused
 * @noinspection SpellCheckingInspection
 */

declare(strict_types=1);

use Noirapi\Config;
use Noirapi\Helpers\TracyFileSession;
use Noirapi\Helpers\Utils;
use Tracy\Debugger;
use Tracy\NativeSession;

mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (file_exists(dirname(__DIR__) . '/vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
    /** @psalm-suppress MissingFile */
    require_once(dirname(__DIR__) . '/vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
}

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

/** @noinspection PhpUnhandledExceptionInspection */
Config::init($config);

$_sessionCfg = Config::get('session');
if (is_array($_sessionCfg) && isset($_sessionCfg['driver'])) {
    $handler = \Noirapi\Lib\Session\SessionHandlerFactory::create($_sessionCfg);
    session_set_save_handler($handler, true);
}
unset($_sessionCfg, $handler);


Config::set('is_https', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');

Debugger::$strictMode = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED; // all errors except deprecated notices
Debugger::$showLocation = true;
Debugger::$logSeverity = E_NOTICE | E_WARNING;

// We have to use our own session storage because Tracy's default session storage is notcleaning up old sessions
/** @noinspection PhpUnhandledExceptionInspection */

if (session_save_path() !== false && is_dir(session_save_path())) {
    $session = new TracyFileSession(session_save_path());
} elseif (is_dir(Config::getTemp())) {
    $session = new TracyFileSession(Config::getTemp());
} elseif (ini_get('upload_tmp_dir') !== false && is_dir(ini_get('upload_tmp_dir'))) {
    $session = new TracyFileSession(ini_get('upload_tmp_dir'));
} elseif (is_dir(sys_get_temp_dir())) {
    $session = new TracyFileSession(sys_get_temp_dir());
} else {
    $session = new NativeSession();
}

Debugger::setSessionStorage($session);

if (Utils::isDev($_SERVER["REMOTE_ADDR"] ?? "")) {
    //we are missing some debug events in Tracy, that's why we start session so early
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    Debugger::enable(Debugger::Development, Config::getLogs());
} else {
    Debugger::enable(Debugger::Production, Config::getLogs(), Config::get('dev_email'));
}
