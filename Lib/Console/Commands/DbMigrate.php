<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use Noirapi\Config;
use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Override;

/**
 * Runs noirapi/database's migration tool (vendor/bin/noirapi-database-migrate) with the same
 * arguments.
 *
 * @psalm-api
 */
final class DbMigrate implements Command
{
    /**
     * @psalm-pure
     */
    #[Override]
    public function name(): string
    {
        return 'db:migrate';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function description(): string
    {
        return "Run noirapi/database's migrations (arguments are passed through)";
    }

    #[Override]
    public function run(array $args, Output $output): int
    {
        $tool = Config::getRoot() . '/vendor/bin/noirapi-database-migrate';
        if (! is_file($tool)) {
            $output->error("Not found: $tool (is noirapi/database installed?)");

            return 1;
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        passthru($command, $exitCode);

        return $exitCode;
    }
}
