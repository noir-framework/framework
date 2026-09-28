### Starting the session

Start the session with `Session::start()` (usually in the app's base controller, where
`session_start()` used to be). It turns on `session.use_strict_mode` (unknown or expired IDs
are replaced, not adopted), cookie-only session IDs, an `HttpOnly` cookie, and the cookie
settings below:

```neon
session:
    cookie:
        name: app_session   # default: PHP's session.name
        lifetime: 0         # seconds; 0 = until the browser closes
        samesite: Lax       # Lax | Strict | None (None forces Secure)
        secure: auto        # auto = when the request is HTTPS (see trusted_proxies); or true / false
        domain: ''          # default: the cookie_domain setting, else the current host
```

```php
use Noirapi\Lib\Session;

Session::start();

// after a successful login, logout, or any change of privileges:
Session::regenerate();   // new session ID, same data; the CSRF token is re-created

// on logout:
Session::destroy();      // clears the data, expires the cookie, deletes the stored session
```

### Sudo mode

`Noirapi\Auth\SudoMode` marks the session as re-authenticated for a limited time:

```php
SudoMode::grant();          // after the user re-enters a TOTP code or password
SudoMode::isActive();       // true for auth.sudo_ttl seconds (default 900)
SudoMode::remaining();      // seconds left
SudoMode::revoke();         // e.g. on logout
```

```neon
auth:
    sudo_ttl: 900
```

### Session handlers

The session driver is configured in `app/config/*.neon` under the `session:` key.
`session.gc_maxlifetime` from `php.ini` is used as the lifetime for all drivers — no
separate config key is needed.

---

#### MySQL

Stores sessions in a `sessions` table. The SQL schema is in `sql/021-sessions.sql`.

```neon
session: [
    driver: 'mysql'
    dsn:    'host=localhost;dbname=db_name'
    user:   'db_user'
    pass:   'db_pass'
]
```

Apply the schema once:

```bash
mysql db_name < sql/021-sessions.sql
```

---

#### SQLite

Stores sessions in a single `.db` file. The table is created automatically on first use.

```neon
session: [
    driver: 'sqlite'
    dsn:    'sessions.db'
]
```

`dsn` is relative to the project `data/` directory unless it starts with `/` or is `:memory:`:

```neon
# absolute path
session: [
    driver: 'sqlite'
    dsn:    '/var/lib/pmxadm/sessions.db'
]

# in-memory (testing only — data lost on process exit)
session: [
    driver: 'sqlite'
    dsn:    ':memory:'
]
```

---

#### Memcached

Stores sessions in Memcached. Expiry is enforced natively by the Memcached TTL, so
no GC table is required. The `ext-memcached` PHP extension must be installed.

```neon
session: [
    driver: 'memcached'
    dsn:    'localhost:11211'
    prefix: 'sess_'
]
```

`prefix` is optional (default `sess_`). Useful when multiple apps share one Memcached
instance.

Multiple servers are not supported via the config — if you need a pool, extend
`MemcachedSessionHandler` and override the constructor.

---

#### Stale-session protection

All drivers guard against stolen session cookies that arrive after the session has
expired but before GC has deleted the row:

- **MySQL / SQLite** — `doRead()` checks `last_activity` against
  `session.gc_maxlifetime` on every read. An expired row is deleted immediately and
  the session is treated as missing.
- **Memcached** — the TTL set on `mc->set()` matches `session.gc_maxlifetime`, so
  expired keys are never returned.

With `Session::start()`, `session.use_strict_mode` makes PHP issue a fresh session ID
whenever the cookie names a session that doesn't exist (any more), so a stale or stolen
cookie is never adopted.

---

#### Omitting the session block

If no `session:` block is present in the NEON config, PHP's default file-based session
handler is used unchanged.
