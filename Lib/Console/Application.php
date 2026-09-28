<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console;

use Noirapi\Config;
use Noirapi\Lib\Console\Commands\CacheClear;
use Noirapi\Lib\Console\Commands\ConfigCheck;
use Noirapi\Lib\Console\Commands\DbMigrate;
use Noirapi\Lib\Console\Commands\KeyGenerate;
use Noirapi\Lib\Console\Commands\MakeController;
use Noirapi\Lib\Console\Commands\MakeModel;
use Noirapi\Lib\Console\Commands\RoutesList;
use Throwable;

/**
 * `noirapi <command> [args]`: the framework's commands plus the app's App\Commands\* classes
 * (files in app/commands/ implementing Command).
 *
 * @psalm-api
 */
final class Application
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(private readonly Output $output = new Output())
    {
        $builtIn = [
            new CacheClear(),
            new ConfigCheck(),
            new DbMigrate(),
            new KeyGenerate(),
            new MakeController(),
            new MakeModel(),
            new RoutesList(),
        ];

        foreach ($builtIn as $command) {
            $this->add($command);
        }
    }

    public function add(Command $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    /**
     * Registers every App\Commands\<File> in app/commands/ that implements Command.
     */
    public function discover(): void
    {
        foreach (glob(Config::getAppRoot() . '/commands/*.php') ?: [] as $file) {
            $class = 'App\\Commands\\' . basename($file, '.php');
            if (! class_exists($class)) {
                require_once $file;
            }

            if (class_exists($class) && is_subclass_of($class, Command::class)) {
                $this->add(new $class());
            }
        }
    }

    /**
     * @param list<string> $argv without the script name
     */
    public function run(array $argv): int
    {
        $name = array_shift($argv);

        if ($name === null || in_array($name, ['list', 'help', '--help', '-h'], true)) {
            $this->usage();

            return $name === null ? 1 : 0;
        }

        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $this->output->error("Unknown command: $name");
            $this->usage();

            return 1;
        }

        try {
            return $command->run($argv, $this->output);
        } catch (Throwable $exception) {
            $this->output->error($exception::class . ': ' . $exception->getMessage());

            return 1;
        }
    }

    private function usage(): void
    {
        $this->output->line('Usage: noirapi <command> [args]');
        $this->output->line();

        $rows = [
            ['dev-server', 'Start/stop the PHP dev server (start|stop|restart|status)'],
            ['latte-lint', 'Lint Latte templates'],
            ['latte-check', 'Check templates against their controllers'],
        ];
        ksort($this->commands);
        foreach ($this->commands as $command) {
            $rows[] = [$command->name(), $command->description()];
        }

        $this->output->table($rows);
    }
}
