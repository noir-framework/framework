# Command line

One entry point, in either layout:

```bash
vendor/bin/noirapi <command> [args]     # Composer layout
php noirapi/bin/console.php <command>   # submodule layout (dev-server, latte-lint and latte-check
                                        # are noirapi/bin/dev-server, noirapi/bin/latte-lint, ...)
vendor/bin/noirapi list                 # every command, including the app's
```

| Command | |
|---|---|
| `dev-server start\|stop\|restart\|status [--port 8000] [--host localhost] [--config NAME]` | PHP's built-in server with static files, pid in `temp/`, log in `logs/` |
| `latte-lint <paths>` | syntax-check Latte templates |
| `latte-format [--check] [--indent=N\|--tabs] [paths]` | tidy Latte templates in place (indentation, trailing whitespace, blank lines); `--check` for CI |
| `latte-check [--strict] [--no-controller-check]` | check templates against the variables their controllers pass |
| `cache:clear` | delete compiled templates and the config, ACL and route caches in `temp/` |
| `config:check [name]` | load `app/config/<name>.neon` (default: `$CONFIG`, else `default`), run `App\Lib\Config::validate()`, and check `temp/`/`logs/`, `trusted_proxies`, `dev_ips`, `app_secret_key` and `rate_limiter` |
| `routes:list` | list routes, when `App\Route` uses [`Router`](routing.md#named-routes-and-url-generation) |
| `make:controller Name` | `app/controllers/Name.php` (extending `App\Site` when there is one) and `app/views/name/index.latte`; `Admin/Users` makes `App\Controllers\Admin\Users` |
| `make:model Name` | `app/models/Name.php` extending `Noirapi\Lib\Model` |
| `key:generate` | print a new `app_secret_key` |
| `db:migrate [args]` | run noirapi/database's `noirapi-database-migrate` with the same arguments |

`make:*` never overwrite an existing file.

## App commands

Put classes implementing `Noirapi\Lib\Console\Command` in `app/commands/`, namespace
`App\Commands`; they are listed and run like the built-in ones:

```php
namespace App\Commands;

use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Noirapi\Lib\Kernel;

final class PurgeSessions implements Command
{
    public function name(): string
    {
        return 'sessions:purge';
    }

    public function description(): string
    {
        return 'Delete expired sessions';
    }

    public function run(array $args, Output $output): int
    {
        Kernel::boot();              // config, timezone, session handler, Tracy

        $deleted = (new \App\Models\Sessions())->purgeExpired();
        $output->line("Deleted $deleted session(s)");

        return 0;
    }
}
```

```bash
vendor/bin/noirapi sessions:purge
```

Commands get the arguments after their name as a list of strings, and return the exit code.
`Output` has `line()`, `error()` (to stderr) and `table()`. An exception thrown by a command is
printed and exits with 1.

The older `script.php` entry point (`App\App::run()`) still works.
