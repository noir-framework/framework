<?php

declare(strict_types=1);

namespace Noirapi\Lib\Mcp;

use LogicException;
use Noirapi\Config;
use Noirapi\Lib\Mcp\OAuth\Authorization;
use Noirapi\Lib\Mcp\OAuth\ClientDocuments;
use Noirapi\Lib\Mcp\OAuth\Registration;
use Noirapi\Lib\Mcp\OAuth\Tokens;
use Noirapi\Lib\Request;

/**
 * The project's McpApplication (set by Routes::register() or use(), or named by the config's `mcp.application`, which
 * a project with a cached route dispatcher needs) and the services built from it: the framework's controllers use
 * them, and so does the project's consent page (authorization()).
 */
final class Mcp
{
    /** @var class-string<McpApplication>|McpApplication|null the class until first use, then the instance */
    private static string|McpApplication|null $application = null;

    /**
     * @param class-string<McpApplication>|McpApplication $application made on first use
     *
     * @psalm-impure
     */
    public static function use(string|McpApplication $application): void
    {
        self::$application = $application;
    }

    /**
     * @psalm-impure
     */
    public static function application(): McpApplication
    {
        // A cached route dispatcher skips Routes::register(): the config names the class then.
        self::$application ??= self::configured();
        if (is_string(self::$application)) {
            self::$application = new (self::$application)();
        }

        return self::$application ?? throw new LogicException('No MCP application: set `mcp.application` in the'
            . ' config, or call Routes::register() or Mcp::use()');
    }

    /**
     * The `mcp.application` config value, when it names an McpApplication class (the project's own config file,
     * checked against the interface).
     *
     * @return class-string<McpApplication>|null
     *
     * @psalm-taint-escape callable
     *
     * @psalm-impure
     */
    private static function configured(): ?string
    {
        /** @var mixed $class */
        $class = Config::get('mcp.application');

        return is_string($class) && is_subclass_of($class, McpApplication::class) ? $class : null;
    }

    /** The authorization endpoint behind the project's consent page. */
    public static function authorization(Request $request): Authorization
    {
        $app = self::application();
        $store = $app->store();

        return new Authorization($app->oauth($request), $store, new ClientDocuments($store));
    }

    public static function tokens(Request $request): Tokens
    {
        $app = self::application();

        return new Tokens(
            $app->oauth($request),
            $app->store(),
            static fn (string $subject): bool => $app->account($subject) !== null,
        );
    }

    public static function registration(Request $request): Registration
    {
        $app = self::application();

        return new Registration($app->store(), $app->oauth($request));
    }
}
