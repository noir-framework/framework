# SQLite as a MariaDB drop-in

Since 2.7 every SQLite connection that noirapi opens (`Model`, `Connector::open()`) speaks MariaDB: the same SQL, the
same functions and the same answers, so one app runs on MariaDB (hosted) and on SQLite (a desktop build) without a
second dialect. Nothing to switch on.

A database that should stay plain SQLite (no rewriting, no MariaDB functions, SQLite's own answers) says so:

```neon
db:
    sqlite:
        dsn: 'app.sqlite'
        dialect: native     # default: mariadb
```

What it is made of:

| Part | Where | What it does | Status |
| --- | --- | --- | --- |
| Functions | `Noirapi\Lib\Db\MariaDb\Functions` (`ClockFunctions`, `DateMath`, `DateParts`, `DateConversions`) | MariaDB's functions as SQLite user functions (PHP, `Pdo\Sqlite::createFunction()`) | dates: 2.7 |
| Rewriter | `Noirapi\Lib\Db\MariaDb\Rewriter` | MariaDB syntax SQLite cannot parse, rewritten before SQLite sees it | dates: 2.7 |
| Decimals | `Noirapi\Lib\PDO\PDOStatement` | `DECIMAL(p,s)` columns come back as strings with their scale (`'50.00'`) | planned |
| Upsert | Rewriter | `INSERT … ON DUPLICATE KEY UPDATE`, `INSERT IGNORE` | planned |
| Schema | Rewriter + `Migrator` | MariaDB `CREATE`/`ALTER TABLE` translated, so one `sql/NNN_*.sql` serves both | planned |

Functions are PHP now; a function that a measurement shows to be slow on big tables can move to a C extension
later, with the same tests.

## Dates, times and periods

**Time zone.** MariaDB's `NOW()` is the server's local time; SQLite's `CURRENT_TIMESTAMP` and `datetime('now')` are
UTC. Here every function works in PHP's time zone (`date_default_timezone_get()`, which `Kernel::boot()` sets from
the config's `timezone` or the system's), and `CURRENT_TIMESTAMP`, `CURRENT_DATE`, `CURRENT_TIME`, `LOCALTIME` and
`LOCALTIMESTAMP` in queries mean local time too. A CLI script or test that writes times must boot through
`Kernel::boot()` like the app, or its PHP is in UTC.

**Values** are text, as MariaDB returns them: `'2026-01-31'`, `'2026-01-31 14:05:00'`, `'14:05:00'`, with
`.ffffff` only when a fractional part was asked for. A value that is not a date gives NULL (MariaDB warns and gives
NULL).

**Functions:** `NOW([fsp])`, `SYSDATE()`, `CURRENT_TIMESTAMP()`, `LOCALTIME()`, `LOCALTIMESTAMP()`, `CURDATE()`,
`CURRENT_DATE()`, `CURTIME()`, `CURRENT_TIME()`, `UTC_TIMESTAMP()`, `UTC_DATE()`, `UTC_TIME()`, `UNIX_TIMESTAMP([d])`,
`FROM_UNIXTIME(ts [, format])`, `DATE_FORMAT(d, format)`, `STR_TO_DATE(s, format)`, `DATE_ADD`, `DATE_SUB`,
`ADDDATE`, `SUBDATE`, `TIMESTAMPADD`, `TIMESTAMPDIFF`, `DATEDIFF`, `TIMEDIFF`, `ADDTIME`, `SUBTIME`, `EXTRACT`,
`YEAR`, `QUARTER`, `MONTH`, `DAY`, `DAYOFMONTH`, `HOUR`, `MINUTE`, `SECOND`, `MICROSECOND`, `DAYOFWEEK`, `WEEKDAY`,
`DAYOFYEAR`, `WEEK(d [, mode])`, `WEEKOFYEAR`, `YEARWEEK(d [, mode])`, `MONTHNAME`, `DAYNAME`, `LAST_DAY`,
`MAKEDATE`, `MAKETIME`, `TO_DAYS`, `FROM_DAYS`, `TO_SECONDS`, `TIME_TO_SEC`, `SEC_TO_TIME`, `PERIOD_ADD`,
`PERIOD_DIFF`. SQLite's own `date()`, `time()` and `datetime()` stay as they are (MariaDB's `DATE()` and `TIME()`
give the same answers for MariaDB values).

**Arithmetic as MariaDB does it:** adding months or years keeps the day when it exists and otherwise takes the last
day of the month (`'2026-01-31' + INTERVAL 1 MONTH` is `'2026-02-28'`, not PHP's March 3rd); a DATE plus whole days,
months or years stays a DATE, anything with a time part is a DATETIME; `TIMESTAMPDIFF(MONTH, …)` counts whole months.

**Syntax the rewriter turns into function calls:**

| You write (MariaDB) | SQLite runs |
| --- | --- |
| `d + INTERVAL 1 DAY`, `INTERVAL 1 DAY + d` | `DATE_ADD(d, 1, 'DAY')` |
| `d - INTERVAL ? MONTH` | `DATE_SUB(d, ?, 'MONTH')` |
| `DATE_ADD(d, INTERVAL 1 HOUR)` (also `DATE_SUB`, `ADDDATE`, `SUBDATE`) | `DATE_ADD(d, 1, 'HOUR')` |
| `TIMESTAMPDIFF(DAY, a, b)`, `TIMESTAMPADD(MINUTE, n, d)` | `TIMESTAMPDIFF('DAY', a, b)` … |
| `EXTRACT(YEAR_MONTH FROM d)` | `EXTRACT('YEAR_MONTH', d)` |
| `CURRENT_TIMESTAMP`, `CURRENT_DATE`, `CURRENT_TIME`, `LOCALTIME`, `LOCALTIMESTAMP` | `NOW()`, `CURDATE()`, `CURTIME()`, `NOW()`, `NOW()` |

Units: `MICROSECOND` … `YEAR` and the composite ones (`'1:30' HOUR_MINUTE`, `'1-2' YEAR_MONTH`, …). The left side
of `+ INTERVAL` is the operand right before it (a column, literal, placeholder, function call or parenthesised
expression); when an operator that binds tighter stands before that operand (`a * b + INTERVAL 1 DAY`), the rewriter
refuses the query and asks for parentheses rather than guess. Text in quotes and comments is never touched.

## Known differences

- **Case-insensitive text** uses SQLite's `NOCASE` collation, which folds ASCII letters only: `'é' = 'É'` is false
  (true under MariaDB's `utf8mb4_general_ci`). A custom collation would make the database unreadable for other tools
  (sqlite3, DB Browser), so this stays until a C extension can fold Unicode.
- `NOW()` is read per call, so two calls in one long statement can differ by microseconds (MariaDB fixes it per
  statement).

## Testing

`tests/Db/MariaDb/` compares every function and rewrite against answers recorded from MariaDB 11
(`tests/Db/MariaDb/expected.json`); `NOIRAPI_TEST_MARIADB=mysql:host=…;dbname=…` (with `_USER`, `_PASS`) runs the
same cases against a live server and records them again.
