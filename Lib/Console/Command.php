<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console;

/**
 * A `noirapi <name>` command. Apps add their own as App\Commands\* classes in app/commands/.
 *
 * @psalm-api
 */
interface Command
{
    /** e.g. "cache:clear" */
    public function name(): string;

    /** One line for the command list. */
    public function description(): string;

    /**
     * @param list<string> $args the arguments after the command name
     * @return int exit code
     */
    public function run(array $args, Output $output): int;
}
