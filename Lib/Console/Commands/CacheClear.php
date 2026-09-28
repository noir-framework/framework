<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use Noirapi\Config;
use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Override;

/**
 * Deletes the framework's caches in temp/: compiled Latte templates, the config, ACL and
 * route caches. Nothing else in temp/ is touched.
 *
 * @psalm-api
 */
final class CacheClear implements Command
{
    public const array DIRECTORIES = ['config-cache', 'acl-cache', 'route-cache'];

    /**
     * @psalm-pure
     */
    #[Override]
    public function name(): string
    {
        return 'cache:clear';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function description(): string
    {
        return 'Delete compiled templates and the config, ACL and route caches in temp/';
    }

    #[Override]
    public function run(array $args, Output $output): int
    {
        $temp = Config::getTemp();
        $files = glob($temp . '/*.latte--*') ?: [];

        foreach (self::DIRECTORIES as $directory) {
            array_push($files, ...(glob($temp . '/' . $directory . '/*') ?: []));
        }

        $deleted = 0;
        foreach ($files as $file) {
            if (is_file($file) && unlink($file)) {
                $deleted++;
            }
        }

        $output->line("Deleted $deleted cached file(s) in $temp");

        return 0;
    }
}
