# Upgrading to noirapi/framework 2.0

2.0 adds security features (all off until the config turns them on), named routes, CLI
commands and many fixes. Most apps update without code changes; check each item below.

## Required checks

1. **Behind Cloudflare or a load balancer?** Forwarding headers are now only believed from
   listed proxies. Cloudflare used to be trusted implicitly. Without this, `clientIp`,
   `dev_ips`, HTTPS detection and the `Secure` cookie flag all see the proxy instead of the
   visitor:

   ```neon
   trusted_proxies: [cloudflare]            # and/or your balancers: [10.0.0.0/8]
   ```

2. **`$request->uri` is no longer URL-decoded.** Code that compared it with decoded text (a
   path containing spaces or non-ASCII characters) must decode it itself, or use
   `$request->url_no_lang`, which is the decoded path. Route parameters are decoded once, as
   before; `%2520` in a URL now reaches the action as `%20`, not as a space.

3. **`dev: false` now means off.** A config with `dev: false` used to switch controllers into dev
   mode. Use `dev: true` or `dev_ips` to turn it on.

4. **`SudoMode` expires** after `auth.sudo_ttl` seconds (default 900). Elevations granted
   before the update count as expired. Apps that wrapped it with their own expiry (encode's
   `App\Lib\Security\Sudo`) can use `SudoMode` directly.

5. **Uploads are checked by content.** `FileUpload::allowedContentType()` compares with the MIME
   type `finfo` detects (needs `ext-fileinfo`), and `getContentType()` returns it. Use
   `getClientContentType()` for what the browser claimed.

6. **`{nonce}` prints `nonce="…"`** without a leading space; write `<script {nonce}>`.

7. `Utils::isDev()` and `Utils::getRealIp()` are deprecated: use `Config::isDev($request->clientIp)`
   and `$request->clientIp`.

8. **Submodule apps: new dependency.** The framework now requires `nikolaposa/rate-limit`
   (rate limiting, `verifyThrottled()`, magic-link `send()`) and `ext-fileinfo`. Composer
   installs a submodule's dependencies from the app's own `composer.json`, so add it there, then
   refresh the classmap for the new framework classes:

   ```bash
   composer require nikolaposa/rate-limit:^3.3
   composer dump-autoload
   ```

## Recommended

- Start sessions with `Session::start()` instead of `session_start()`, and call
  `Session::regenerate()` after login ([sessions](docs/sessions.md)).
- Turn on `csrf: true` after adding `{csrf}` to every POST form and the `X-CSRF-Token` header to
  AJAX calls; mark webhooks and token APIs `#[SkipCsrf]` ([security](docs/security.md#csrf-protection)).
- Add `security_headers: true`, then a CSP in report-only mode.
- Rate-limit logins with `PasswordProvider::verifyThrottled()` or `#[RateLimited]`.
- Run `vendor/bin/noirapi config:check`.

## Removed / changed internals

- `composer.json` of the app no longer needs `minimum-stability: beta` or a VCS repository for
  `noirapi/database`: 5.0.0 is on Packagist.
- `Controller::restMessage()` works (it passed an unknown named argument and always threw).
- Schema validators are stricter: `Numeric` / `Port` reject `12abc`, `Cidr` (single) accepts
  valid CIDRs and rejects invalid ones (it was inverted), dates reject impossible values.

---

# Upgrading to noirapi/framework 1.x (from the submodule era)

1.x is the first release published to Packagist as `noirapi/framework`, from
`github.com/noir-framework/framework` (the old `github.com/deba12/noirapi` is archived).
It keeps the git-submodule layout working and adds a Composer layout; both run the same
`Noirapi\Lib\Kernel`.

- [A. Keep the submodule, update to 1.x](#a-keep-the-submodule-update-to-1x): a few steps, no code changes required
- [B. Move to the Composer layout](#b-move-to-the-composer-layout): optional, per app, mostly automated with Rector

## What changed

| Change | Impact on a submodule app |
|---|---|
| **`noirapi/database` replaces `opis/database`** (`Lib/Model.php` now uses `Noirapi\Database\*`) | Required: swap the dependency (A.2). App code using `Opis\Database\*` keeps working through the aliases `noirapi/database` ships (deprecated, removed in noirapi/database 6.0); Rector renames it (A.4). |
| Kernel moved into `Noirapi\Lib\Kernel`; `kernel.php` / `include.php` are shims | None. `include.php` loads the class itself even before `composer dump-autoload`. |
| Kernel boots once per process | Including `kernel.php` / `include.php` twice no longer re-runs the bootstrap. |
| `Config::getRoot()` detection, `Config::setRoot()`, `Config::getFrameworkDir()` | None for the submodule layout. Replace hard-coded `getRoot() . '/noirapi/...'` paths with `getFrameworkDir() . '/...'`. |
| Bundled templates are loaded via `Config::getFrameworkDir()` | None. |
| New dirs `Rector/`, `tests/`, and `composer.json`, `bin/noirapi` | Your phpmd run over `noirapi/` now sees `Rector/` and `tests/` too (clean under the qb ruleset; exclude them if you like). Rector fixtures are `*.php.fixture` so phpmd skips them. |
| `TotpProvider` needs `robthree/twofactorauth` 2.x or 3.x | Already true since the local-QR-code change; apps still on 1.8 must upgrade to use TOTP. |

## A. Keep the submodule, update to 1.x

1. **Point the submodule at the new repository.** `deba12/noirapi` is archived and gets no updates:

   ```bash
   git submodule set-url noirapi git@github.com:noir-framework/framework.git
   git submodule sync noirapi
   git submodule update --remote noirapi
   ```

2. **Swap `opis/database` for `noirapi/database`** in the app's `composer.json`:

   ```json
   "require": {
       "noirapi/database": "^5.0"
   }
   ```

   Remove `opis/database` (and any `repositories` entry for the old fork). They must not be
   installed together: with the real Opis classes present, app type hints such as
   `Opis\Database\Database` stop resolving to the aliases and reject `Model::$db`
   (a `Noirapi\Database\Database`). `noirapi/database` 5.0.0 is on Packagist, so no
   `minimum-stability` or `repositories` entry is needed.

3. `composer update noirapi/database opis/database && composer dump-autoload`

4. **Optional: rename `Opis\Database` to `Noirapi\Database` with Rector.** This also applies
   noirapi/database's own rewrites (`BitMaskWhereRector`, `RawFunctionCallRector`):

   ```bash
   composer require --dev rector/rector
   ```

   ```php
   // rector.php
   use Rector\Config\RectorConfig;

   return RectorConfig::configure()
       ->withPaths([__DIR__ . '/app'])
       ->withSets([__DIR__ . '/noirapi/Rector/config/noirapi.php']);
   ```

   ```bash
   vendor/bin/rector process --dry-run   # review, then run without --dry-run
   ```

## B. Move to the Composer layout

Do part A first. Then:

1. **Require the framework:**

   ```bash
   composer require noirapi/framework
   ```

2. **Rewrite the entry points with Rector** while the submodule is still present:

   ```php
   // rector.php
   use Rector\Config\RectorConfig;

   return RectorConfig::configure()
       ->withPaths([__DIR__ . '/app', __DIR__ . '/htdocs'])
       ->withSets([
           __DIR__ . '/vendor/noirapi/framework/Rector/config/noirapi.php',
           __DIR__ . '/vendor/noirapi/framework/Rector/config/composer-layout.php',
       ]);
   ```

   `LegacyEntryPointRector` rewrites every `require <root> . '/noirapi/kernel.php'` to

   ```php
   require_once <root> . '/vendor/autoload.php';
   \Noirapi\Lib\Kernel::run(<root>);
   ```

   Run it with `--clear-cache`: a stale Rector cache can silently skip files.

   It also rewrites `.../noirapi/include.php` to `Kernel::boot(<root>)`, including forms such as
   `__DIR__ . '/../../noirapi/kernel.php'`. `Kernel::run()` keeps the old behaviour: CLI
   scripts that included `kernel.php` still only boot.

3. **Autoloading:** in `composer.json` change `"classmap": ["noirapi/", "app/"]` to
   `"classmap": ["app/"]`, then `composer dump-autoload`.

4. **Remove the submodule:**

   ```bash
   git submodule deinit -f noirapi
   git rm -f noirapi
   rm -rf .git/modules/noirapi
   ```

5. **Update the remaining references** Rector does not touch. Find them with
   `grep -rn "noirapi/" --exclude-dir=vendor --exclude-dir=node_modules .`:

   | Where | Before | After |
   |---|---|---|
   | scripts, docs, CLAUDE.md, skills | `noirapi/bin/dev-server start` | `vendor/bin/noirapi dev-server start` |
   | | `noirapi/bin/latte-lint app/views` | `vendor/bin/noirapi latte-lint app/views` |
   | | `php noirapi/bin/latte-check.php` | `vendor/bin/noirapi latte-check` |
   | `phpstan.neon` | `scanDirectories: [noirapi]` | remove (vendor is already scanned) |
   | `psalm.xml` | `<directory name="noirapi" />` in `ignoreFiles` and `extraFiles` | remove |
   | `composer.json` phpmd script | `app,noirapi` plus `*noirapi/...` excludes | `app` |
   | app code | `Config::getRoot() . '/noirapi/Templates'` | `Config::getFrameworkDir() . '/Templates'` |

   nginx / PHP-FPM needs no change: the web root is still `htdocs/` and the kernel still
   serves only requests routed to `/index.php`.
