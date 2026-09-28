# NoirAPI framework

Small PHP 8.4 web framework: [FastRoute](https://github.com/nikic/FastRoute) routing,
[Latte](https://latte.nette.org) views, [noirapi/database](https://github.com/noir-framework/database)
models, [Tracy](https://tracy.nette.org) debugging, NEON config, and pluggable auth (password,
magic link, TOTP, GitHub/Google OAuth).

Package: `noirapi/framework`, namespace `Noirapi\`, PHP `^8.4`, MIT.

## Installation

There are two supported layouts, and they run the same code (`Noirapi\Lib\Kernel`).

### Composer (new projects)

```bash
composer require noirapi/framework
```

> **Stability:** `noirapi/framework` depends on `noirapi/database ^5.0`, which is in beta until
> 5.0.0 is tagged. Until then the project's `composer.json` needs:
>
> ```json
> "minimum-stability": "beta",
> "prefer-stable": true
> ```

Project layout. Every path is relative to the project root, which the kernel is given:

```
myapp/
  app/
    Route.php            App\Route: process($method, $uri) -> FastRoute dispatch result
    controllers/ models/ views/ layouts/
    config/default.neon  picked by the CONFIG env var, else the virtual host name, else "default"
  htdocs/index.php       web root
  temp/  logs/           must exist and be writable (Latte/config cache, Tracy logs)
  data/                  SQLite databases with relative DSNs
  vendor/
  composer.json          "autoload": {"classmap": ["app/"]}
```

`htdocs/index.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

Noirapi\Lib\Kernel::run(dirname(__DIR__));
```

CLI scripts only boot (config, timezone, sessions, Tracy):

```php
require __DIR__ . '/../../vendor/autoload.php';
Noirapi\Lib\Kernel::boot(__DIR__ . '/../..');
```

Tools are exposed as one Composer binary:

```bash
vendor/bin/noirapi dev-server start [--port 8000] [--host localhost] [--config NAME]
vendor/bin/noirapi dev-server stop|restart|status
vendor/bin/noirapi latte-lint app/views
vendor/bin/noirapi latte-check [--strict] [--no-controller-check]
```

### git submodule (existing projects)

Existing apps keep the framework checked out at `<root>/noirapi/` and use the legacy entry points,
which are thin shims over the same kernel:

```php
require dirname(__DIR__) . '/noirapi/kernel.php';   // htdocs/index.php and CLI scripts
require dirname(__DIR__) . '/noirapi/include.php';  // bootstrap only
```

```bash
noirapi/bin/dev-server start
noirapi/bin/latte-lint app/views
php noirapi/bin/latte-check.php
```

See [UPGRADE.md](UPGRADE.md) for what a submodule app needs when it updates to 1.x, and for
moving it to the Composer layout.

## How the application root is found

`Noirapi\Config::getRoot()` resolves, in order:

1. the root passed to `Kernel::run()` / `Kernel::boot()`;
2. the `NOIRAPI_ROOT` environment variable (`vendor/bin/noirapi` and `dev-server` set it);
3. the submodule layout: the framework's parent directory, when it contains `app/`;
4. the Composer layout: the root of the Composer install that contains `noirapi/framework`.

The framework's own files (bundled templates, bin scripts) are found with `Config::getFrameworkDir()`.

## Documentation

- [Controllers](docs/controller.md), [Views](docs/view.md), [Layouts](docs/layouts.md)
- [Database](docs/db.md), [Sessions](docs/sessions.md), [Mail](docs/mail.md)

## Development

```bash
composer install
composer test        # PHPUnit: root detection + Rector rule fixtures
```

Releases are tagged `vX.Y.Z` on `master`. CI (`.github/workflows/ci.yml`) runs the tests and
then notifies Packagist; it needs the `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` repository secrets.
