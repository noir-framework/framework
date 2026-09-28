<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Override;

/**
 * Prints a new app_secret_key.
 *
 * @psalm-api
 */
final class KeyGenerate implements Command
{
    /**
     * @psalm-pure
     */
    #[Override]
    public function name(): string
    {
        return 'key:generate';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function description(): string
    {
        return 'Print a new random app_secret_key for the NEON config';
    }

    #[Override]
    public function run(array $args, Output $output): int
    {
        $output->line("app_secret_key: '" . base64_encode(random_bytes(32)) . "'");

        return 0;
    }
}
