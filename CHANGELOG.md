# Changelog

## 1.1.1 (2026-09-28)

- Allow php-curl-class 12 and 13.

## 1.1.0 (2026-09-28)

- `Model` connects lazily: `$db` is a property hook, so the PDO opens on first use, not in the
  constructor. Constructing a model (directly, in a controller constructor or in an `#[AutoWire]`
  attribute) no longer touches the database. `connect()` and `getNewInstance()` still connect
  right away, and `getNewInstance()` no longer opens an unused pooled connection too.
  Connection errors now surface at the first query instead of at `new Model()`.
- The MySQL and SQLite session handlers open their PDO on first use, not at boot on every request.
- `Noirapi\Lib\AclCache::remember()` caches a built Laminas ACL in `temp/acl-cache/`,
  invalidated when the file that builds it changes.
- Quality gates: `composer phpmd` (PHPMD 3, `phpmd.xml`) and `composer phpcs` (PSR-12, phpcs 4)
  run in CI. `pdepend.yml.dist` switches pdepend to its memory cache (its file cache can report
  findings from a file's old content). The shared ruleset for apps is `noirapi/phpmd-ruleset`.
- Code cleanup for those gates, without changes to public method names: short variable and
  parameter names renamed, `@` operators replaced by checks, optional `App\` classes imported,
  `use` statements grouped per PSR-12.

## 1.0.1 (2026-09-28)

- Allow php-curl-class 10 and 11.

## 1.0.0 (2026-09-28)

First release as the Composer package `noirapi/framework` (github.com/noir-framework/framework).

- Composer layout: `Noirapi\Lib\Kernel::run($root)` / `Kernel::boot($root)` entry points,
  `vendor/bin/noirapi` for the dev server and Latte tools.
- Submodule layout keeps working: `kernel.php` and `include.php` are shims over the same kernel.
- Requires `noirapi/database ^5.0` instead of `opis/database`.
- `Config::setRoot()`, `Config::detectRoot()`, `Config::getFrameworkDir()`; bundled templates
  no longer assume the framework lives at `<root>/noirapi/`.
- Bin scripts honour `NOIRAPI_ROOT`.
- Rector sets for apps: `Rector/config/noirapi.php` (Opis\Database to Noirapi\Database, plus
  noirapi/database's set) and `Rector/config/composer-layout.php` (legacy entry points to the kernel).
- CI with automatic Packagist updates.

See [UPGRADE.md](UPGRADE.md).
