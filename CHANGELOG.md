# Changelog

## 2.0.0 (unreleased)

- **Trusted proxies.** `X-Forwarded-For/-Proto/-Host` and Cloudflare's `CF-Connecting-IP` are
  only believed from peers listed in the new `trusted_proxies` config (`cloudflare` expands to
  Cloudflare's ranges). **Breaking:** Cloudflare used to be trusted implicitly; apps behind it
  need `trusted_proxies: [cloudflare]` to keep getting the visitor's address.
- `Request::$clientIp`, and `Request::$https` / `$hostname` resolved through the trusted
  proxies. HTTPS detection fixes: `HTTPS=off` is no longer HTTPS, `SERVER_PORT` `'443'` (a
  string) is. Cookies get their `secure` flag from this, so it survives a TLS-terminating proxy.
- `Config::isDev(?string $clientIp)` is the only dev-mode check (`dev: true`, or the client in
  `dev_ips`, which now also takes CIDR ranges). **Fix:** `dev: false` used to switch dev mode on
  in controllers. `Utils::isDev()` / `Utils::getRealIp()` are deprecated.
- **Breaking:** `Request::$uri` is no longer URL-decoded. The path is decoded once, in `Route`,
  before dispatching (it used to be decoded twice, so `%2520` reached routes as a space).
- Request helpers: `input()`, `query()`, `has()`, typed `string()`, `int()`, `float()`,
  `bool()`, `enum()`, `array()`, `json()` / `body()`, `header()`, `isJson()`, `accepts()`,
  `wantsJson()`, `isMethod()`, `isSecure()`, `baseUrl()`. `CONTENT_TYPE` and `CONTENT_LENGTH`
  are now in `$request->headers`.
- `Request::fromSwoole()` works again (it read upper-case keys from Swoole's lower-case server
  array); `swoole.php` passes the raw body.
- **`FileUpload` checks the real content.** `allowedContentType()` and the image checks use the
  MIME type `finfo` detects, not the browser's `Content-Type` (which `getClientContentType()` still
  returns). `getContentType()` now returns the detected type. Wildcards such as `image/*` work,
  extensions compare case-insensitively, and a file `getimagesize()` can't read is rejected
  instead of raising a warning. Requires `ext-fileinfo`. **Breaking:** uploads whose content
  doesn't match the allowed types are now rejected.
- `Session::start()` hardens the session (strict mode, cookie-only IDs, `HttpOnly`, cookie name,
  lifetime, `SameSite` and `Secure` from `session.cookie`); `Session::regenerate()` for login and
  privilege changes (also drops the CSRF token), `Session::destroy()` for logout. The dev-mode
  session Tracy starts goes through it too.
- **`SudoMode` expires.** `grant()` stores an expiry (`auth.sudo_ttl`, default 900 s, or an
  explicit TTL); `isActive()` checks it and `remaining()` returns the seconds left. **Breaking:**
  elevations used to last the whole session; ones granted by the old version count as expired.
- **CSRF protection** (opt-in, `csrf: true`): `Noirapi\Lib\Security\Csrf`, the `{csrf}` and
  `{csrfToken}` Latte tags, the `X-CSRF-Token` header for AJAX, an Origin / Sec-Fetch-Site check,
  and `#[SkipCsrf]` for webhooks and token APIs. See `docs/security.md`.
- **Security headers** (opt-in, `security_headers:`): nosniff, frame options, Referrer-Policy,
  Permissions-Policy, COOP and HSTS (HTTPS only) on every response, each overridable, plus an
  optional Content-Security-Policy with a per-request nonce that views receive as `$nonce`.
- **Rate limiting** on `nikolaposa/rate-limit`: named limits from `rate_limits`, APCu /
  Memcached / PDO / memory storage (`rate_limiter`), an escalating block like aplus-api's, the
  `#[RateLimited]` attribute, `TooManyRequestsException` answered with 429 + `Retry-After`, and
  a Tracy panel. Built-in defaults for `login`, `magic_link` and `totp`.
- Route maps controller exceptions in one place; an empty `NotFoundException` /
  `InternalServerError` message now falls back to the default text.
- Requires the stable `noirapi/database ^5.0` from Packagist: no `minimum-stability: beta` or
  VCS repository entry is needed any more.

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
