<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use Noirapi\Config;
use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Override;

/**
 * make:controller Name: app/controllers/Name.php (extending App\Site when the app has one) and
 * its view app/views/name/index.latte.
 *
 * @psalm-api
 */
final class MakeController implements Command
{
    #[Override]
    public function name(): string
    {
        return 'make:controller';
    }

    #[Override]
    public function description(): string
    {
        return 'Create app/controllers/<Name>.php and its index view';
    }

    #[Override]
    public function run(array $args, Output $output): int
    {
        $name = Generator::className($args, $output, 'noirapi make:controller <Name>');
        if ($name === null) {
            return 1;
        }

        [$namespace, $class] = Generator::split($name);
        $parent = is_file(Config::getAppRoot() . '/Site.php') ? '\\App\\Site' : '\\Noirapi\\Lib\\Controller';

        $code = <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Controllers$namespace;

            use Noirapi\\Lib\\Response;

            final class $class extends $parent
            {
                public function index(): Response
                {
                    return \$this->view->display();
                }
            }

            PHP;

        if (Generator::write('controllers', $name, $code, $output) === null) {
            return 1;
        }

        $view = Config::getViews() . '/' . lcfirst($class) . '/index.latte';
        if (! file_exists($view)) {
            if (! is_dir(dirname($view))) {
                mkdir(dirname($view), 0777, true);
            }
            file_put_contents($view, "{block content}\n<h1>$class</h1>\n{/block}\n");
            $output->line("Created $view");
        }

        $output->line("Route it in app/Route.php, e.g. \$r->get('/" . lcfirst($class) . "', [\\App\\Controllers$namespace\\$class::class, 'index']);"); //phpcs:ignore

        return 0;
    }
}
