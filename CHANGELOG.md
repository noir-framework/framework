# Changelog

## 2.7.0 (2026-11-27)

**SQLite for desktop apps: pragmas, schema migrations and a MariaDB dialect**, all opt-in per database in config, so
existing SQLite databases are untouched.

- **`pragmas`** on a SQLite `db` driver or on `session` (driver sqlite), e.g. `{foreign_keys: on, busy_timeout: 5000,
  journal_mode: wal}`, run on every new connection (`Noirapi\Lib\Db\SqliteSetup`). SQLite leaves foreign keys off
  unless each connection asks, so without `foreign_keys: on` an `ON DELETE CASCADE` does nothing.
- **`migrations`** on a SQLite `db` driver (a folder under the code root, e.g. `sql/sqlite`): the first connection of
  a request that finds files pending applies them.
- **`Noirapi\Lib\Db\Migrator`**: applies the numbered `.sql` files of a folder (`001_users.sql`, `002-orders.sql`)
  that a database has not had, records them in `schema_migrations` (filename, applied_at), and reads the folder with
  `scandir()` so it works inside a phar. On SQLite one `BEGIN IMMEDIATE` transaction covers the run (a second process
  waits, then finds nothing to do; a failing file changes nothing). On MySQL/MariaDB each file is one multi-statement
  query whose results are all read, so an error in any statement is reported; DDL commits implicitly there.

**SQLite that speaks MariaDB** (`dialect: mariadb` on a SQLite `db` driver, or `Connector::open(…, 'mariadb')`;
docs/sqlite-mariadb.md). Off by default: a connection without it is plain SQLite, as before.

- MariaDB's date, string, logic, pattern, hash, JSON and `GROUP_CONCAT` functions as SQLite functions, and MariaDB
  syntax rewritten before SQLite sees it (`INTERVAL`, `CURRENT_*`, string literals and `"…"`, `||`/`&&`/`<=>`,
  `FOR UPDATE`, `COLLATE`, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`, `INSERT … SET`). Every answer is checked
  against MariaDB 11 (`tests/Db/MariaDb/`).
- `DECIMAL(p,s)` columns come back as text with their scale (`'50.00'`), and `SUM`/`AVG` over them are exact.
- The query builder writes MySQL SQL on these connections (`Noirapi\Lib\Db\MariaDb\Connection`).
- Queries only: the schema (`CREATE`/`ALTER TABLE`) is not translated, so an app keeps SQLite migrations of its own.
  Known differences (Unicode `=` on text, arithmetic, `rowCount()`) are listed in the doc.

Also:

- **`PdoRateLimitStore` checks its table name**: letters, digits and `_` only, else `InvalidArgumentException`
  (it is written into the queries).
- `Noirapi\Lib\PDO\PDOStatement::setFetchMode()` returns `true` (PHP's own signature); `fetch()`, `fetchAll()`,
  `fetchColumn()` and `fetchObject()` are overridden for the MariaDB dialect's DECIMAL columns and behave as PHP's
  everywhere else.

## 2.6.0 (2026-11-27)

**Desktop app compatibility.** From this version a noirapi app can also ship as a desktop app, the same code as
the hosted one: a native shell (Tauri or similar) starts PHP's built-in server on `127.0.0.1` and shows it in a
webview. The code can run read-only, sealed in a signed phar (`NOIRAPI_ROOT=phar://…` or the phar's own directory),
and prebuilt (`noirapi prebuild`): nothing is compiled or cached at runtime. Everything the app writes goes to a
separate writable root (`NOIRAPI_DATA`), SQLite works with Windows paths and takes its write lock at the start of a
transaction, and per-install secrets come from the environment (`%env(NAME)%`). See the README's "Desktop apps"
and docs/prebuilt.md. Nothing changes for an app that does not use these.

- **Prebuilt (sealed) apps** (`noirapi prebuild <config>`, `Noirapi\Lib\Prebuilt`, docs/prebuilt.md): the config,
  routes, ACLs, translations and compiled Latte templates are written into `<root>/prebuilt/` at build time. When
  that folder exists, the framework reads them from there and writes no cache at runtime, so the code can run from a
  signed phar or another read-only place. Without it nothing changes. App hooks: `App\Lib\LatteSetup`
  (`Noirapi\Interfaces\LatteSetup`: the app's Latte functions and extensions, for requests and the build) and
  `App\Lib\Prebuild` (`Noirapi\Interfaces\Prebuild`: warms the app's other cached builders).
- **Every Latte engine comes from `Noirapi\Lib\View\LatteFactory`** (`view()`, `basic()`), and is a
  `Noirapi\Lib\View\LatteEngine`. `View`, `Helpers\Template` and `Helpers\Mail` use it; mail templates now have the
  framework's filters too. **Template ids are relative to the code root** (`LatteLoader::getUniqueId()`), so the
  compiled class names change once: the first request after the update recompiles into `temp/`.
- **`{message}`, `{pager}` and `{breadcrumbs}` build the bundled template's path at runtime** instead of writing the
  build machine's absolute path into the compiled template.
- **`%env(NAME)%` config values** come from the environment (`Noirapi\Lib\ConfigEnv`); an unset or empty variable is a
  `ConfigException`. The NEON JSON cache moved into `Noirapi\Lib\ConfigCache` (same behaviour).
- **A writable root apart from the code root** (`Noirapi\Lib\WritableRoot`, env `NOIRAPI_DATA`, `WritableRoot::pin()`):
  `Config::getTemp()`, `getLogs()`, relative SQLite paths (`db`, session `dsn`) and an `app/config/<name>.neon` that
  overrides the code root's live there. It defaults to the code root, so nothing changes unless it is set. With it,
  the code can run from a read-only place such as a phar (`NOIRAPI_ROOT=phar://…/app.phar`).
- **MySQL and SQLite connections are the driver subclasses.** `Model` opens them with `Connector::open()`, which
  calls `PDO::connect()`: a MySQL connection is a `Noirapi\Lib\PDO\Mysql` (a `Pdo\Mysql`), a SQLite one a
  `Noirapi\Lib\PDO\Sqlite` (a `Pdo\Sqlite`), both still logging to the Tracy panel. Apps can now use the driver API
  without the methods and constants PHP 8.5 deprecates, e.g. `Pdo\Sqlite::createFunction()` instead of
  `PDO::sqliteCreateFunction()`. Other drivers keep the generic `Noirapi\Lib\PDO\PDO`. Code that type-hints
  `Noirapi\Lib\PDO\PDO` for a `Model` connection should use `\PDO` (or `\PDO&LoggedPdo`).
- **SQLite transactions begin IMMEDIATE** (PHP 8.5+, `Pdo\Sqlite::ATTR_TRANSACTION_MODE`): `beginTransaction()` takes
  the write lock at once, so a read-then-write in a transaction (a counter, a row "lock") cannot interleave with
  another writer. On PHP 8.4 they stay DEFERRED.
- **SQLite paths on Windows.** A `db.<sqlite>.dsn` or session `dsn` like `C:\Users\…\app.sqlite` (or `C:/…`,
  `\\server\share\…`) is absolute; it used to be put under `data/` because only a leading `/` counted.
  `Utils::isAbsolutePath()` decides it.
- **`bin/router.php` writes its request log to `php://stderr`.** The `STDERR` constant does not exist under
  `php -S`, so every request ended in a 500 once the shutdown function ran.

## 2.5.0 (2026-11-24)

- **MCP consent pages can attach data to a connection.** `Authorization::approve($request, $subject, ?string
  $scope = null, array $data = [])`: what the page lets the user choose for this connection (e.g. which companies it
  may change; JSON-serialisable, at most `Authorization::MAX_DATA` = 4 KB as JSON, else `InvalidArgumentException`).
  It travels with the code into the connection and stays there across refresh-token rotation:
  `McpApplication::tools($account, $grant)` reads it as `$grant->data`, and `Store::grants()` lists it. It is per
  connection, so two connections from the same app and account can differ, and it never reaches the app. Existing
  connections read `[]`.
- **schema: `oauth_codes.data` and `oauth_grants.data`** (nullable JSON text). `PdoStore` writes the columns, so add
  them before deploying 2.5 (UPGRADE.md; `schema.mysql.sql` has the ALTER statements).
- **interface: `Store::createGrant()` gains a trailing `array $data = []`**, and the code arrays of `addCode()` /
  `takeCode()` an optional `data` key. `PdoStore` and `MemoryStore` have both; a project's own Store adds the
  parameter.

## 2.4.1 (2026-11-23)

- **MCP consent pages can grant a narrower scope safely.** `Authorization::approve($request, $subject, ?string
  $scope)` takes the scope the user chose ("read only") and refuses one that isn't within the requested scope
  (`OAuthError` `invalid_scope`). `OAuthConfig::$impliedScopes` declares which scopes include which (e.g.
  `['statements' => ['statements:read']]`), so an app that asked only for `statements` can be granted
  `statements:read`. `OAuthConfig::narrows()` is the check. Backward compatible: without `$scope` nothing changes.

## 2.4.0 (2026-11-23)

- **behaviour (MCP): `McpApplication::tools()` receives the connection.** The signature is now
  `tools(object $account, Grant $grant)`. `$grant->scope` is what the account approved for this app (space-separated
  `OAuthConfig::$scopes`), so a read-only scope can leave the changing tools out. `$grant` also carries `clientName`,
  `id` and `subject`. Before, the provider saw only the account, so a project had to encode the scope into the
  account id. Projects update the method signature (UPGRADE.md).
- docs/mcp.md: a cached route dispatcher (`Mcp::use()` in `App\Route`'s constructor, or `mcp.application`), and
  what route tests must allow for (an array of methods on `/mcp`; 405 on GET/DELETE before any sign-in).

## 2.3.1 (2026-11-23)

CI green again under Psalm 7.16 (CI resolves the newest Psalm 7; 2.3.0's run failed on code older than the release).

- **`Config::environment()`** reads an environment variable the operator sets (`NOIRAPI_ROOT`,
  `NOIRAPI_ERROR_CAPTURE`): host configuration, not request input. Psalm 7.16 now taints `getenv()`, so every path
  built on the app root was reported as tainted (file, include, SSRF and URL findings). The root detection and Tracy's
  error capture directory read their variables through it.
- `Utils::is_tty()` is annotated impure (it reads the terminal state).
- No behaviour change: `Route`'s request log writes the referer the same way, without a redundant condition.

## 2.3.0 (2026-11-23)

- **MCP server module (`Noirapi\Lib\Mcp`, [docs/mcp.md](docs/mcp.md)).** A project implements
  `McpApplication` (its tools, its account check, its consent page) and registers the routes with one call; the
  framework serves the rest.
  - Protocol: Model Context Protocol over stateless Streamable HTTP, in every supported revision on one URL.
    2026-07-28 brings `server/discover`, per-request `_meta`, the `Mcp-Method`/`Mcp-Name` headers,
    `resultType`, cache hints and the new error codes; 2025-11-25, 2025-06-18 and 2025-03-26 keep `initialize`
    and `ping`.
  - Sign-in: OAuth 2.1 with PKCE, with RFC 9728/8414 metadata, `iss` (RFC 9207), dynamic registration (RFC 7591),
    Client ID Metadata Documents (fetched SSRF-safe), rotating refresh tokens, RFC 7009 revocation and RFC 8707
    audience binding.
  - Request checks: Origin, and the rate limits `mcp`, `oauth_register` and `oauth_token`.
  - Storage: `PdoStore` (`Lib/Mcp/OAuth/schema.mysql.sql`) and `MemoryStore`.
- **`Noirapi\Lib\Http\PublicAddress`**: whether an address is on the public internet (private, loopback,
  link-local, CGNAT, multicast and reserved ranges are not, IPv4 and IPv6, mapped and NAT64 included), and a
  resolver, for servers that fetch URLs others give them.

## 2.2.0 (2026-11-17)

Bugs found while migrating an app to 2.1.6. Two of them change defaults (marked **behaviour**).

- **`PDO::query()` without a fetch mode threw a `ValueError`.** The wrapper's default was the constant
  `PDO::ATTR_DEFAULT_FETCH_MODE` (an attribute id, 19), which `\PDO::query()` rejects as a fetch mode. Without
  a mode it now calls `\PDO::query($sql)`, so the connection's default fetch mode applies, as with plain PDO.
- **`#[LazyModel]` properties crashed with `Call to a member function newInstance() on null`.**
  `SmartObject::__get()` tested `getAttributes($class) !== null`, which is always true, so it used the first
  attribute class it knew of (whatever came first in the Composer classmap) and read `[0]` of an empty array.
  The trait now records, per property, the attribute that marks it. It also no longer scans the Composer
  classmap on every construction (that appended the attribute class list to a static array each time); an
  attribute counts as a lazy loader when it is in `Noirapi\Lib\Attributes\` or `App\Lib\Attributes\`, as
  before. `#[LazyModel]` stays deprecated.
- **behaviour: `#[NotFound]` and AutoWire's not-found redirect default to 302 (was 301).** Browsers cache a
  301, so once a record was missing, the URL kept redirecting after the record came back. Pass
  `#[NotFound('...', 301)]` to keep a permanent redirect.
- **behaviour: `LoginException` without a redirect code redirects with 302.** The default code 0 was sent as
  the status (PHP turned the `Location` header into a 302 only by accident); any code other than 403 or a
  redirect code (301, 302, 303, 307, 308) is now a 302. On the error-page path (`App\Controllers\Errors`)
  a `LoginException` with 302 used to give a 403 whose body was the URL; both paths now share the same
  handling (403 = `{"forward": url}` JSON, otherwise a redirect).
- **`SchemaHelper::message()` no longer modifies the exception.** It `array_shift()`ed the paths of the
  exception's message objects, so a second call on the same exception (or `Message::fromSchema()` after it)
  returned `_default`.
- **`cache:clear` also deletes `temp/route*.cache`**, the FastRoute `cachedDispatcher` files apps keep there;
  a stale one kept serving deleted routes.
- **Rate limits that silently do nothing are logged.** With `rate_limiter.storage` unset and no apcu, the
  limiter falls back to process memory, which enforces nothing under PHP-FPM. That fallback now logs a Tracy
  warning outside the CLI.

## 2.1.6 (2026-11-12)

- **`latte-check` no longer reports closure, arrow function and `{define}` parameters as undeclared
  variables.** `fn($d) => $d->active` and `function ($x) use ($y) {...}` used to produce
  `Variable $d used but not declared`; so did every variable of a `{define block, string $a, $b}`
  and of a `{define block}` whose `{include block, name: value}` passes it as a named argument
  (Latte makes those variables inside a block without declared parameters). They are scoped to their
  own body: a template variable with the same name is still tracked outside it, so a `{varType}`
  declaration is not reported as unused just because a block shadows it. Classes
  `Lib/LatteLint/BlockScopes` and `VarUsageCollector`.

## 2.1.5 (2026-11-12)

- **Psalm passes with `ext-memcached` installed too.** CI has the extension, so Psalm analysed the
  `Memcached` calls in `MemcachedBackend`, `MemcachedBlockStore` and `MemcachedSessionHandler` and
  rejected the purity annotations on the methods that call it; those annotations are removed. The
  `UndefinedClass` handler for `Memcached` in `psalm.xml.dist` is only needed when the extension is
  missing, so `findUnusedIssueHandlerSuppression` is off instead of failing CI where the extension is
  present. No behaviour change.

## 2.1.4 (2026-11-12)

- **Psalm 7.3 passes.** CI installs the newest Psalm, whose purity and taint checks are stricter than the
  7.0 beta the repo was developed on, so the `Psalm` step had been failing since 2.1.1. No behaviour
  changed:
  - about 50 `@psalm-pure` / `@psalm-mutation-free` / `@psalm-external-mutation-free` annotations that
    were no longer true were removed (methods that read or write static properties such as `Config`,
    `Url`, `SchemaHelper`, `RateLimit\Storage`, or call `getenv()`, `file()` and friends; the removal
    cascades to their callers);
  - `Helpers/TracyFileSession` declares that the validated session id is safe as a file path, URL path
    and URL component (`@psalm-taint-escape`);
  - the remaining taint findings are suppressed per file in `psalm.xml.dist`, each with the reason:
    the response writer in `Lib/Kernel.php`, the `PDO::exec()` wrapper, `PdoRateLimitStore` (the table
    name is a developer-chosen constructor argument) and the `language_detector` class name.
  - `Controller::message()` checks `$this->view !== null` instead of `$this->view?->translator !== null`
    (the same condition, `translator` is never null).

## 2.1.3 (2026-11-12)

- **CI is green again.** The `PHPMD` and `Psalm` steps had been failing since 2.1.1; no behaviour changed.
  `Lib/Crawl/*` got the `@psalm-pure` / `@psalm-mutation-free` / `@psalm-immutable` annotations Psalm asked
  for, `Crawler` casts `getmypid()` to `int` and multiplies milliseconds by a float literal, and
  `tests/Crawl/CrawlTest.php` uses shorter test names, imports `ReflectionMethod` and no longer silences
  `rmdir()` with `@`.

## 2.1.2 (2026-11-12)

- **`latte-check` knows the Latte functions and filters an app registers.** It used to report
  `Unknown function order_cutoff_hour()` for a function that `App\Site` registers with
  `$this->view()->latte->addFunction(...)`, because the linter's own engine never ran the app's
  controllers. It now reads the source (nothing of the app is executed) in three places:
  1. `app/lib/Functions.php`, `Filters.php` and `Macros.php` (lower-case file names too): public static
     methods of `Functions.php` are functions, those of `Filters.php` are filters, `Macros.php` may list
     names in `getFunctions()` / `getFilters()`.
  2. Base controllers between a controller and `Noirapi\Lib\Controller` (usually `App\Site`): every
     `addFunction('name', ...)` / `addFilter('name', ...)` call with a literal name.
  3. The controller itself: the constructor and non-public helpers count for all its templates, a call
     inside a public method only for the templates that method renders with `display()`.
  Layouts and partials are rendered by many controllers, so they get everything found anywhere. A name
  that is not a string literal cannot be seen. Classes `Lib/LatteLint/RegistrationScanner` and
  `RegistrationAnalyzer`; `Checker` takes an optional `$libDir` (default: `lib` next to the controllers
  directory) and builds one engine per distinct set of known names; `EngineFactory::create()` takes
  `$extraFilters`. `App\Lib\Functions` is only read by the linter, nothing loads it at runtime.
## 2.1.1 (2026-10-26)

- **The dev server logs every request it serves** to `logs/dev-server.log`: status, method, full request
  URI (with the query string), wall-clock time, the request's CPU time (user + system) and PHP memory
  (current and peak), e.g. `[18:46:57] 200 GET /?a=1 55.7 ms cpu 53.2 ms mem 1.5 MB (peak 4.2 MB)`.
  Static files are served by PHP itself and are not timed.

## 2.1.0 (2026-10-26)

- **`noirapi latte-format [--check] [--indent=N|--tabs] [path...]`** tidies Latte templates: LF line
  endings, no trailing whitespace, at most one blank line in a row, a final newline, and every line
  indented by its HTML / Latte nesting (`{if}`/`{else}`/`{foreach}`..., multi-line tags). `<pre>`,
  `<textarea>`, `<script>`, `<style>` and comments are copied as written, and the output is compared
  with the input minus whitespace so only whitespace can change. Without paths it formats the views
  and layouts directories; `--check` writes nothing and exits 1 when a file would change (CI).
  Class `Noirapi\Lib\LatteLint\Formatter`.

## 2.0.1 (2026-10-26)

- **`noirapi crawl` no longer passes a failed login silently.** A server can accept the login POST and
  still redirect somewhere other than the login page, which looked like success. Now any page that
  ends on the login page after a successful-looking login is counted (`summary.login_redirects`),
  reported in `notes`, and the command exits 1; a login that fails outright sets
  `summary.login_failed` and also exits 1 (it used to exit 0 with zero pages crawled).
- **`NOIRAPI_ERROR_CAPTURE` accepts a relative directory.** Tracy refuses a relative log directory and
  exits with a 500 on every request; the path is now resolved against the working directory.

## 2.0.0 (2026-10-26)

- **`noirapi crawl <config.json>`** and **`NOIRAPI_ERROR_CAPTURE=<dir>`**, ported from 1.2.0: walk a
  site (optionally logged in) and attribute every Tracy log entry to the URL that caused it. The
  capture variable is only honoured for dev requests (`Config::isDev`). See `docs/crawl.md`.
- Allow nette/neon 4 and php-curl-class 12/13.
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
  Memcached / PDO / memory storage (`rate_limiter`), an optional escalating block, the
  `#[RateLimited]` attribute, `TooManyRequestsException` answered with 429 + `Retry-After`, and
  a Tracy panel. Built-in defaults for `login`, `magic_link` and `totp`.
- Route maps controller exceptions in one place; an empty `NotFoundException` /
  `InternalServerError` message now falls back to the default text.
- **Magic links are complete**: `MagicLinkProvider::issue()` / `verify()` / `send()` with
  stateless tokens signed by `app_secret_key` and bound to a per-user fingerprint (e.g. the
  last-login time), so they expire (`auth.magic_link.ttl`) and work once without a token table.
  `send()` is rate-limited per email and IP. A default email template is bundled
  (`Templates/mail/magic-link.latte`; `Mail::setTemplate()` falls back to bundled templates).
- `Noirapi\Lib\Security\Signer`: purpose-bound HMAC-SHA256 signatures with `app_secret_key`.
- `PasswordProvider::verifyThrottled()` and `TotpProvider::verifyThrottled()` apply the `login`
  and `totp` rate limits. The OAuth `state` check uses `hash_equals()`.
- **Named routes and URL generation**: `Noirapi\Lib\Routing\Router` (a RouteCollector wrapper
  with names, groups with name prefixes, and an optional compiled-route cache in
  `temp/route-cache/`), `Url::to()` / `Url::absolute()` (pattern-checked, encoded, language
  prefix, extra parameters as the query string), the `{link}` Latte tag, the `url()` /
  `absoluteUrl()` Latte functions and `Controller::forwardTo()`. Opt-in: plain FastRoute
  `App\Route` classes keep working. See `docs/routing.md`.
- **CLI commands** through `vendor/bin/noirapi` (or `php noirapi/bin/console.php`):
  `cache:clear`, `config:check`, `routes:list`, `make:controller`, `make:model`, `key:generate`,
  `db:migrate`, plus app commands in `app/commands/` (`Noirapi\Lib\Console\Command`). See
  `docs/cli.md`.
- Fixes found by the new tests:
  - `Schema\Cidr` (single value) rejected valid CIDRs and accepted invalid ones; a CIDR without
    `/` raised a warning. Values are trimmed.
  - `Schema\Numeric` and `Schema\Port` accepted strings like `12abc` or `abc1` (the digit check
    wasn't anchored).
  - `Schema\Domain` ignored `required()` and failed on empty input.
  - `Schema\DateTime` / `Date` / `Time` accepted impossible dates (`2023-02-29` rolled over to
    March 1).
  - `Response::appendBody()` failed merging nested objects; `downloadFile()` / `inlineFile()`
    now send an RFC 6266 `Content-Disposition` (ASCII fallback plus `filename*`), so quotes and
    non-ASCII names no longer break the header.
  - **Breaking:** `{nonce}` prints `nonce="…"` (escaped) without a leading space, and is written
    `<script {nonce}>`; Latte 3 rejects `<script{nonce}>`.
- CI runs PHPStan (level 5) and Psalm 7 (level 4) with stubs for the optional `App\*` classes,
  and reports test coverage. Purity annotations were corrected where they were wrong (interfaces
  and abstract methods whose implementations do I/O) and added where Psalm 7 requires them.
- **Fix:** `Controller::restMessage()` always threw (it passed `ok:` to `RestMessage::new()`,
  whose parameter is `success`). OAuth results are immutable: tokens are set with
  `OAuthResult::withTokens()`.
- **Fix:** `Expect2::custom(SomeSchema::class)` always threw; it now returns the instance.
- Docs: configuration reference, validation, Swoole, routing, CLI, security and authentication
  guides; `UPGRADE.md` has a 2.0 checklist.
- Requires the stable `noirapi/database ^5.0` from Packagist: no `minimum-stability: beta` or
  VCS repository entry is needed any more.

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
