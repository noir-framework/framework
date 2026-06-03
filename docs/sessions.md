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
    dsn:    '/var/lib/myapp/sessions.db'
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

When a session cookie arrives but no valid session is found, `Site` calls
`session_regenerate_id(true)` after `session_start()`. This issues a fresh session ID
to the client and deletes the stale backing record, invalidating the stolen cookie.

---

#### Omitting the session block

If no `session:` block is present in the NEON config, PHP's default file-based session
handler is used unchanged.
