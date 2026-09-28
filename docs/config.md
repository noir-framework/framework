# Configuration reference

The app's config is `app/config/<name>.neon`. The name comes from the `CONFIG` environment
variable, else the virtual host (`SERVER_NAME` / `HTTP_HOST`), else `default`. The decoded file
is cached in `temp/config-cache/` until the `.neon` file changes. If the app has
`App\Lib\Config::validate(array $config)`, it runs on every load.

Read values with `Config::get('key')` or a dotted path, `Config::get('auth.totp.issuer')`.
`vendor/bin/noirapi config:check [name]` loads a config and checks the settings below.

Everything is optional.

## Environment

| Key | Default | |
|---|---|---|
| `dev` | `false` | `true`: development mode for everyone (Tracy bar, detailed errors, dev panels) |
| `dev_ips` | `[]` | development mode for these client addresses / CIDR ranges only |
| `dev_email` | | where Tracy mails production errors |
| `timezone` | `/etc/localtime` | PHP's timezone; should match the database's |
| `trusted_proxies` | `[]` | proxies whose forwarding headers are believed; `cloudflare` for Cloudflare's ranges ([security](security.md#trusted-proxies)) |
| `cookie_domain` | the host | domain for cookies the framework sets |
| `layout` | | default layout for views ([layouts](layouts.md)) |

## Languages

| Key | |
|---|---|
| `languages` | `{en: English, bg: Български}`: URLs are prefixed with the code ([routing](routing.md#languages)) |
| `default_language` | used when no language is detected (default `en`) |
| `language_detector` | a class with `detect(string $ip): ?string` |

## Database

```neon
db:
    mysql:                          # the first entry is the default connection
        dsn: 'mysql:host=localhost;dbname=app;charset=utf8mb4'
        user: app
        pass: secret
    sqlite:
        dsn: app.db                 # relative: under data/
```

See [database](db.md).

## Sessions

```neon
session:
    driver: mysql                   # mysql | sqlite | memcached; omit for PHP's file sessions
    dsn: 'host=localhost;dbname=app'
    user: app
    pass: secret
    cookie:
        name: app_session
        lifetime: 0
        samesite: Lax
        secure: auto
        domain: ''
```

See [sessions](sessions.md).

## Security

```neon
csrf: true                          # or {enabled: true, allowed_origins: [...]}

security_headers:                   # or: true for the defaults
    frame_options: DENY
    hsts: 'max-age=31536000; includeSubDomains'
    csp: default                    # or a directive map
    csp_report_only: false
    headers: {X-Robots-Tag: noindex}

rate_limiter:
    storage: apcu                   # apcu | memcached | pdo | memory
    memcached: 'localhost:11211'
    connection: mysql               # pdo
    table: rate_limits              # pdo
    prefix: 'rl:'

rate_limits:
    api: {limit: 100, per: minute}
    login: {limit: 5, per: 60, block_after: 5, block_for: 900}

app_secret_key: '...'               # base64 of 32+ random bytes: noirapi key:generate
```

See [security](security.md).

## Authentication and mail

```neon
mail:
    dsn: 'smtp://user:pass@smtp.example.com:587'   # null://null writes mails to temp/ (tests)
    from: 'no-reply@example.com'

auth:
    sudo_ttl: 900
    totp: {issuer: 'My App'}
    magic_link: {enabled: true, ttl: 900, path: '/auth/magic-link'}
    google: {client_id: '...', client_secret: '...', redirect_uri: '...'}
    github: {client_id: '...', client_secret: '...', redirect_uri: '...'}
```

See [authentication](auth.md) and [mail](mail.md).

## Swoole

```neon
swoole:
    listen_ip: 127.0.0.1
    listen_port: 9400
    workers: 4
    task_workers: 2
    static_files: true              # serve htdocs/ directly
```

See [Swoole](swoole.md).
