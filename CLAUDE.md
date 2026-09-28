# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

NoirAPI is a small PHP 8.4 web framework: Composer package `noirapi/framework`, namespace `Noirapi\` (PSR-4 from the repo root), GitHub `noir-framework/framework` (the old `deba12/noirapi` is archived). It runs in two layouts that share the same code:

- **git submodule** (the ~25 existing apps in sibling dirs like `../qb`, `../voxyhost`): checked out at `<app>/noirapi/`, autoloaded through the app's `classmap: ["noirapi/", "app/"]`, entered via `noirapi/kernel.php` / `noirapi/include.php`. These must keep working with at most the steps in `UPGRADE.md` part A. Don't edit the apps from here.
- **Composer**: installed at `<app>/vendor/noirapi/framework/`, entered via `Noirapi\Lib\Kernel::run($root)` / `::boot($root)`.

Consequences:
- Never hard-code paths. App paths come from `Config::getRoot()` and its helpers (`getTemp()`, `getViews()`, …), framework files from `Config::getFrameworkDir()`. Root resolution order: explicit `Config::setRoot()` (done by the Kernel) → `NOIRAPI_ROOT` env → submodule parent containing `app/` → root of the Composer install that contains `noirapi/framework` (not `InstalledVersions::getRootPackage()`, which can be Rector's bundled vendor).
- The framework calls into app classes that may not exist, always behind `class_exists` guards: `App\Route` (required: `process($method, $uri)` returning a FastRoute dispatch result), `App\Models\<ControllerName>`, `App\Lib\Config::validate()`, `App\Lib\Macros`, `App\Lib\ErrorHandler::handle()`, `App\Controllers\Errors::e404()/e405()/e500()`, `App\App::run()` (CLI), `App\Tasks\*` (Swoole). Static analysers can't see them, hence the `@psalm-suppress UndefinedClass` / `@noinspection` annotations.
- `kernel.php` and `Lib/Kernel.php` differ only by case. That's why the class lives in `Lib/`: a root-level `Kernel.php` would clash on case-insensitive filesystems.
- The apps' `composer phpmd` scans `noirapi/`, including `Rector/` and `tests/`, with the default suffixes (so `.inc` too). Keep code clean under `composer phpmd` (PHPMD 3.x-dev, `phpmd.xml`), which CI runs. PHPMD 3 parses PHP 8.4 syntax (property hooks, `new Foo()->bar()`). Apps still on the PHPMD 2.15 phar (qb's `tools/phpmd.phar`) can't parse hooked files such as `Lib/Controller.php` and `Lib/Model.php`. Fix findings instead of excluding files. When a name is fixed by public API or an interface (`Model::$db`, `Controller::ok()`, `gc()`), use a targeted `@SuppressWarnings("PHPMD.Rule")` with the reason.

## Commands

In this repo:

```bash
composer install
composer test                                        # PHPUnit: Config root detection, lazy DB connections, Rector fixtures
composer phpmd                                       # PHPMD 3 with phpmd.xml (CI gate)
composer phpcs                                       # PSR-12, phpcs 4 (CI gate)
vendor/bin/phpunit --filter LegacyEntryPointRectorTest
```

Rector fixtures are `tests/Rector/*Fixture/*.php.fixture` (input `-----` expected output), not Rector's usual `.php.inc`, so the apps' phpmd skips them. `tests/Rector/FixtureTestCase.php` feeds Rector a temporary `.inc` copy. Rector derives its temp input path by stripping `.inc` and deletes that path afterwards, so pointing it at a non-`.inc` fixture overwrites and **deletes the fixture**.

From an app root (either layout):

```bash
noirapi/bin/dev-server [start|stop|restart|status] [--port 8000] [--host localhost] [--config NAME]
vendor/bin/noirapi dev-server ...                    # Composer layout; also latte-lint, latte-check
noirapi/bin/latte-lint app/views
php noirapi/bin/latte-check.php [--strict] [--no-controller-check]
```

`bin/noirapi` only sets `NOIRAPI_ROOT` (from `COMPOSER_RUNTIME_BIN_DIR` or the cwd) and execs the per-tool scripts, which fall back to `dirname(__DIR__, 2)` for the submodule layout. The dev server writes its pid to `temp/` and logs to `logs/`, and reads optional `<root>/dev-server.ini` settings. `PHP_CLI_SERVER_WORKERS` defaults to 4.

`phpmd.xml` mirrors the shared ruleset in `../phpmd-ruleset` (`noirapi/phpmd-ruleset`); keep the two in sync. PHPMD reads `pdepend.yml.dist` from the working directory, which switches pdepend to its memory cache: the default `~/.pdepend` file cache can report findings from a file's old content. No phpstan/psalm config lives here yet. The app gates (e.g. `../qb`: `composer lint`) use `php ../qb/tools/phpmd.phar <files> text ../qb/phpmd.xml` (PHPMD 2.15) and `../qb/vendor/bin/phpcs --standard=../qb/phpcs.xml <files>`. qb's phpstan autoloads qb's own (older) `noirapi/` submodule, so it reports false "undefined method" errors for new framework APIs.

## Request lifecycle

1. Entry: `htdocs/index.php` → `noirapi/kernel.php` → `include.php` → `Kernel::boot()` then `Kernel::run()` (submodule), or `Kernel::run($root)` directly (Composer). `include.php` finds `vendor/autoload.php` in either layout, requires `Lib/Kernel.php` itself (app classmaps may predate it), and calls `Kernel::loadLegacyDatabaseAliases()`.
2. `Kernel::boot()` (once per process): picks the config name from env `CONFIG`, else `SERVER_NAME`/`HTTP_HOST`, else `default`. `Config::init()` reads `app/config/<name>.neon` (decoded NEON cached as JSON in `temp/config-cache/`, invalidated by mtime). Then it sets the timezone (config `timezone` or `/etc/localtime`), the optional session save handler (`session.driver` → `Lib/Session/*Handler`), and Tracy (needs an existing `logs/`).
3. `Kernel::run()` serves only when `PHP_SELF === '/index.php'`, so CLI scripts that include `kernel.php` just boot. This is also why `bin/router.php` rewrites `PHP_SELF`. `handle()` builds `Route::fromGlobals(...)->serve()` and emits status, cookies, headers, then the body or `readfile($response->getBodyFile())`.
4. `Route::serve()`: strips a language prefix (config `languages`; redirects 307 to a detected/default language if missing), dispatches through `App\Route`, instantiates `new $controller($request, $response, $server)` and calls the action.
5. Exceptions drive non-200 outcomes: `LoginException` (redirect, or 403 JSON `{forward}`), `RestException` (JSON error), `MessageException`, `NotFoundException`/`InternalServerError` (→ app `ErrorHandler` or `Errors::eNNN`), anything else → `ExceptionRenderer`.

Other entry points: `script.php` (CLI → `App\App::run()`) and `swoole.php` (Swoole HTTP server; `Lib/Tasks` for task workers). Both go through `include.php`.

## Database

`Lib/Model.php` uses `noirapi/database` 5 (`Noirapi\Database\*`, a fork of opis/database; sibling checkout `../database`). Apps written against `Opis\Database\*` work through noirapi/database's lazy aliases. PHP doesn't autoload for type checks, though, so the Opis classes the apps type-hint are listed in `Kernel::LEGACY_DATABASE_CLASSES` and loaded up front for the submodule layout. noirapi/database is `5.0.0-beta*`, so consumers need `minimum-stability: beta`. Until it's on Packagist, `composer.json` carries a VCS repository for it (root-only, so apps need their own).

## Rector (app migrations)

- `Rector/config/noirapi.php`: safe in both layouts. `OpisDatabaseNamespaceRector` renames `Opis\Database` to `Noirapi\Database` in `use` statements and in names written fully qualified; short names follow their import. It also includes noirapi/database's `rector/set.php` when installed.
- `Rector/config/composer-layout.php`: `LegacyEntryPointRector` turns `require <root> . '/noirapi/kernel.php'` / `include.php` into `require_once <root> . '/vendor/autoload.php'` plus `\Noirapi\Lib\Kernel::run|boot(<root>)`.

The non-PHP migration steps (composer.json, submodule removal, tool configs) are in `UPGRADE.md`.

## Key framework conventions

- **Controller** (`Lib/Controller.php`): `$this->model` is resolved lazily through a PHP 8.4 property hook to `App\Models\<ShortControllerName>` (or a bare `Model`) on the first configured DB driver. `#[LazyModel]` is deprecated. Dev-mode Tracy panels (PDO, route) are attached in `__destruct`. `forward()` auto-prefixes the current language.
- **Attributes** on actions: `#[AutoWire('modelMethod')]` / `#[AutoWire([Class, 'method'])]` resolve typed, non-builtin parameters from same-named route args (a `_id` suffix is stripped; backed enums use `tryFrom`). When the result is null for a non-nullable parameter, it redirects back with a flash message, customizable via `#[NotFound]`.
- **Model**: `$db` is a property hook, so the PDO opens on first use of `$db`, not in the constructor. `connect()` and `getNewInstance()` still connect right away. Connections are pooled per driver per request (`getNewInstance()` forces a new one). SQLite relative DSNs resolve under `<root>/data/`. The SQL session handlers also open their PDO lazily, because `Kernel::boot()` builds them on every request.
- **View** (`Lib/View.php`): Latte 3 with `StrictParsing` + `StrictTypes`. Filters and tags come from `Lib/View/FilterExtension.php` and `Lib/View/Macros.php` (plus optional `App\Lib\Macros`). `__*.latte` files always resolve from `app/layouts/` (`LatteLoader`). `nocheck` is a real runtime passthrough filter that `latte-check` uses as a marker. Translation uses `EasyTranslator` when `languages` is configured, else `DummyTranslator`.
- **ACL**: apps build a `Laminas\Permissions\Acl\Acl` themselves (usually in their base controller) and pass it to `Controller::hasResource()` / `isAllowed()`. `Lib/AclCache::remember($builder, $key, $sources)` serializes the built ACL to `temp/acl-cache/`. The cache is invalidated by source-file mtimes, which default to the file that defines the builder. An ACL that can't be serialized (closure assertions) is rebuilt on every request.
- **Auth** (`Auth/`): `AuthManager` registers providers (Password, MagicLink, TOTP, OAuth GitHub/Google) implementing `Contracts/AuthProviderInterface`. `SudoMode` handles re-authentication. TOTP QR codes are rendered locally (bacon-qr-code; needs robthree/twofactorauth 2.x or 3.x).
- Style: `declare(strict_types=1)`, `#[Override]`, typed class constants, readonly where possible.

## Releases

`master` plus `vX.Y.Z` tags. `.github/workflows/ci.yml` runs `composer validate`, `php -l` and PHPUnit on PHP 8.4 and 8.5. On a push to `master` or a `v*` tag it then calls Packagist's update-package API, using the `PACKAGIST_USERNAME` / `PACKAGIST_TOKEN` repository secrets (it skips with a warning if they're unset). The `.gitattributes` `export-ignore` rules keep `tests/`, `.github/` and this file out of the dist archive.

## Docs

`docs/` holds guides for app developers (`controller.md`, `db.md`, `view.md`, `layouts.md`, `mail.md`, `sessions.md`). `README.md` covers installation in both layouts, and `UPGRADE.md` covers migrating apps. Update them when public behaviour changes.
