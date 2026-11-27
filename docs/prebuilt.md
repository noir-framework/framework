# Prebuilt (sealed) apps

`vendor/bin/noirapi prebuild <config> [--out DIR] [--verbose]` writes every cache the framework would otherwise build
at runtime into `<root>/prebuilt/`, as plain PHP files:

| File | From |
|---|---|
| `config/<config>.php` | `app/config/<config>.neon` (`%env(NAME)%` values stay, see [config](config.md#values-from-the-environment)) |
| `translations/<language>.php` | `app/translations/*.neon` |
| `routes/<key>.php` | `App\Route`'s `Router::dispatcher(..., cache: true)` |
| `acl/<key>.php` and others | whatever `App\Lib\Prebuild::warm()` builds (`AclCache::remember()` calls) |
| `latte/<class>.php` | every `.latte` under `app/` and the framework's `Templates/`, compiled for each engine the app renders with |
| `manifest.php` | written last; its presence switches the prebuilt mode on |

When `prebuilt/manifest.php` exists (`Noirapi\Lib\Prebuilt::active()`), the framework reads those caches and writes
none: no compiled templates (not even Latte's `.lock` files), no route, ACL or config cache in `temp/`. The code can
then run from a read-only place: a signed phar, a container image, an install dir its users cannot write. Without
the folder nothing changes. Rebuild it on every deploy, from the code as it ships (`composer install --no-dev`).

## Latte

Latte names a compiled template's class after a hash of the engine's configuration (its extensions, their cache
keys, features) and of the template's id. So the prebuild must build exactly the engines requests use:

- Every engine comes from `Noirapi\Lib\View\LatteFactory`: `view()` (what `View` renders with) and `basic()` (mail,
  `Noirapi\Helpers\Template`, an app's PDFs). Build your own engines with `basic()`, not `new Latte\Engine()`.
- Template ids are paths relative to the code root (`Noirapi\Lib\View\LatteLoader`), so a template compiled on the
  build machine is found on any install path, a phar included.
- The app's own functions and extensions go in `App\Lib\LatteSetup` (`Noirapi\Interfaces\LatteSetup`):
  `languages()` and `configure(Engine $latte, string $language)`. The app calls `configure()` on its engine (e.g.
  in the base controller, once it knows the UI language), and the prebuild calls it for every language. Latte
  resolves functions while compiling, so a function the build did not register would compile as a PHP call;
  filters are looked up at runtime and need no setup.

A template a request renders with an engine the prebuild did not reproduce is compiled in memory on every request
(it still works, without writing anything) and logged once per process to `warning.log`. `--verbose` lists the
templates that do not compile for some engine (usually page templates and the plain engine, which has no `{_}`).

## Other caches

`App\Lib\Prebuild` (`Noirapi\Interfaces\Prebuild`) is the app's hook for builders the prebuild cannot reach by
itself: `warm()` calls them, and `AclCache::remember()` / `Router::dispatcher()` write their results into the
prebuild. `App\Route` is warmed by the prebuild.

## A phar

```php
$phar = new Phar('app.phar');
$phar->buildFromDirectory($stagedApp, '#/(app|vendor|htdocs|prebuilt)/#');
$phar->setSignatureAlgorithm(Phar::SHA256);
```

Files inside a phar have no time of their own (`filemtime()` is 0): use `prebuilt/manifest.php`'s `built` for
cache-busting URLs. PHP re-opens a phar, and checks its whole signature, once per request (measured: ~20 ms for
23 MB on Linux, ~28 ms on Windows under `php -S`; `phar.cache_list` does not help), even for scripts OPcache has
cached.
