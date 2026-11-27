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
| Decimals | `DecimalRows` (from `PDOStatement`), `DecimalFunctions`, `DecimalWords` | `DECIMAL(p,s)` columns come back as text with their scale (`'50.00'`); exact `SUM`/`AVG` | 2.7 |
| Strings | `TextFunctions`, `SearchFunctions`, `LogicFunctions`, `CodeFunctions`, `PatternFunctions`, `SyntaxWords` | MariaDB's string, logic and pattern functions; literals, operators and locking clauses | 2.7 |
| Query builder | `Noirapi\Lib\Db\MariaDb\Connection` | noirapi/database writes MySQL SQL (its MySQL compiler) on these connections, like the app's own SQL | 2.7 |
| JSON, GROUP_CONCAT | `JsonFunctions`, `JsonBuilders`, `JsonWords`, `GroupConcat`, `GroupWords` | MariaDB's JSON functions and `GROUP_CONCAT(… ORDER BY … SEPARATOR … LIMIT …)` | 2.7 |
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

## DECIMAL

SQLite has no DECIMAL type: a column declared `DECIMAL(15,2)` stores numbers (50.00 as 50, 0.10 as 0.1), and adding
them up is floating point (0.1 + 0.2 = 0.30000000000000004). In the MariaDB dialect:

- **A DECIMAL column reads back as MariaDB's text**, rounded half away from zero to its scale as MariaDB rounds on
  insert: `'50.00'`, `'0.10'`, `'2.68'` for a stored 2.675. Every fetch mode: arrays, `stdClass`, single columns, key
  pairs, groups, `FETCH_FUNC` arguments, and a class's `string`, `mixed` or untyped properties (a `float` or `int`
  property keeps the number, which is what MariaDB's text becomes there too). The column must be declared
  `DECIMAL(p,s)` (or `NUMERIC(p,s)`) in the SQLite schema: that declared type is how it is known.
- **`SUM()` and `AVG()` are exact**, in whole units of the scale (no bcmath needed), with MariaDB's scale: SUM keeps
  the column's, AVG has 4 more (`AVG(amount)` of DECIMAL(15,2) is `'166673.521667'`, `AVG(qty)` of INT `'2.6667'`).
  The rewriter makes `SUM(x)` `MARIADB_SUM(x, scale)`, taking the scale from the schema (`PRAGMA table_info`, cached
  per connection) when x is a column of a table in FROM or JOIN. **`MIN()` and `MAX()`** of a DECIMAL column are
  formatted the same way.
- **Not covered: arithmetic.** `amount * qty`, `price / 3` and other expressions over decimals are SQLite numbers,
  and `SUM(expression)` takes its scale from the values (`SUM(price * qty)` can be `'4.4'` where MariaDB says
  `'4.4000'`). The rule: no money arithmetic or division in SQL; fetch the values and compute in
  PHP. `SUM(DISTINCT x)` stays SQLite's (a floating-point sum).

## Strings, logic and patterns

Text is compared as `utf8mb4_general_ci` does (case-insensitive in every script, Latin accents ignored: `é` = `e`,
`ß` = `s`) in `LIKE`, `LOCATE`/`INSTR`, `FIND_IN_SET`, `STRCMP`, `FIELD`, `LEAST`/`GREATEST`; `REGEXP`/`RLIKE` is a
case-insensitive PCRE match.

**Functions:** `CONCAT` (NULL if any argument is NULL; SQLite 3.44's `concat()` skips them), `CONCAT_WS`, `LEFT`,
`RIGHT`, `LPAD`, `RPAD`, `REPEAT`, `SPACE`, `REVERSE`, `LOWER`/`LCASE`, `UPPER`/`UCASE` (Unicode; SQLite's are
ASCII-only), `LENGTH`/`OCTET_LENGTH` (bytes, as MariaDB), `CHAR_LENGTH`/`CHARACTER_LENGTH`, `FORMAT`, `SUBSTRING`/
`SUBSTR`/`MID` (position 0 gives ''), `SUBSTRING_INDEX`, `LOCATE`, `INSTR`, `FIND_IN_SET`, `STRCMP`, `FIELD`, `ELT`,
`IF` (a text by its leading number: `'2abc'` is true), `LEAST`/`GREATEST` (text against text, anything else as
numbers: `LEAST('10','9')` is `'10'`, `LEAST(10,'9')` is 9), `ISNULL`, `MD5`, `SHA1`/`SHA`, `SHA2`, `HEX`, `UNHEX`,
`TO_BASE64`, `FROM_BASE64`, `ASCII`, `ORD`.

**Syntax the rewriter changes:**

| You write (MariaDB) | SQLite runs |
| --- | --- |
| `'it\'s'`, `'a\nb'`, `'C:\\dir'` (backslash escapes; `\%` and `\_` keep theirs, for LIKE) | the same text as a SQLite literal |
| `"text"` (a string in MariaDB) | `'text'` |
| `a \|\| b`, `a && b` (OR and AND in MariaDB) | `a OR b`, `a AND b` |
| `a <=> b` | `a IS b` |
| `x RLIKE p` | `x REGEXP p` |
| `… FOR UPDATE [NOWAIT \| SKIP LOCKED]`, `… FOR SHARE`, `… LOCK IN SHARE MODE` | dropped: the transaction already holds the whole database (BEGIN IMMEDIATE) |
| `COLLATE utf8mb4_bin`, `COLLATE …_ci` | `COLLATE BINARY`, `COLLATE NOCASE` |
| `_utf8mb4'x'` | `'x'` |
| `LEFT(…)`, `RIGHT(…)`, `ISNULL(…)` (keywords in SQLite) | the same functions under other names |

**The query builder** (noirapi/database) writes MySQL SQL on these connections (`Noirapi\Lib\Db\MariaDb\Connection`
gives it the MySQL compilers; its driver stays `sqlite`), so a query from the builder and one written by hand are the
same dialect. **Migrations in a SQLite-only folder** (`db.sqlite.migrations`, e.g. `sql/sqlite`) are SQLite SQL and run
unrewritten (`Sqlite::execNative()`).

## JSON and GROUP_CONCAT

**JSON functions:** `JSON_EXTRACT`, `JSON_VALUE`, `JSON_QUERY`, `JSON_UNQUOTE`, `JSON_LENGTH`, `JSON_TYPE`, `JSON_KEYS`,
`JSON_CONTAINS`, `JSON_CONTAINS_PATH`, `JSON_VALID`, `JSON_OBJECT`, `JSON_ARRAY`, `JSON_QUOTE`, `JSON_SET`,
`JSON_INSERT`, `JSON_REPLACE`, `JSON_REMOVE`, `JSON_ARRAY_APPEND`, `JSON_MERGE_PATCH`, with MariaDB's answers:

- JSON comes back printed as MariaDB prints it (`[1, 2, 3]` for `[1, 2,3]`, `{"a": 1}`), numbers as written (`1.50`),
  except `JSON_QUERY`, which gives the text as it stands in the document; several paths or a wildcard give an array.
- `JSON_VALUE` of `true` is `1`; of an object, array or null, NULL. A document that is not JSON gives NULL.
- A JSON function's result inside another JSON function is embedded, not quoted (`JSON_OBJECT('n', JSON_ARRAY(1, 2))`
  is `{"n": [1, 2]}`): the rewriter marks it with `MARIADB_JSON()`. A JSON *column's* text is a string there, as it is
  in MariaDB.
- Paths: `$`, `.key`, `."key with spaces"`, `[n]`, `[*]`, `.*`. Other forms (`**`, `[last]`, ranges) are refused.
- SQLite's `->` and `->>` are not MariaDB syntax and stay SQLite's.

**`GROUP_CONCAT([DISTINCT] e1[, e2…] [ORDER BY k [ASC|DESC], …] [SEPARATOR 's'] [LIMIT [offset,] n])`** is a PHP
aggregate: rows with a NULL value are left out, DISTINCT and ORDER BY compare as `utf8mb4_general_ci`, NULL keys sort
first, no rows give NULL. MariaDB's 1 MB `group_concat_max_len` cut is not applied.

## Known differences

- **`=`, `<`, `ORDER BY` and UNIQUE on text** use the column's collation: SQLite's `NOCASE` folds ASCII letters only,
  so `'é' = 'É'` is false (true under MariaDB's `utf8mb4_general_ci`) and `'a' = 'A'` is false on a column without
  NOCASE. A custom collation would make the database unreadable for other tools (sqlite3, DB Browser), so this stays
  until a C extension can fold Unicode; store what you compare normalised (e.g. e-mails lower-cased).
  The functions and LIKE above do fold Unicode.
- **Arithmetic** follows SQLite: `5/2` is 2 (2.5000 in MariaDB), decimals add up in floating point. The rule: no
  division or money arithmetic in SQL (see DECIMAL).
- `rowCount()` after UPDATE counts rows matched on SQLite, rows changed on MariaDB (unless MYSQL_ATTR_FOUND_ROWS):
  only rely on it for updates that always change a column.
- `NOW()` is read per call, so two calls in one long statement can differ by microseconds (MariaDB fixes it per
  statement).

## Testing

`tests/Db/MariaDb/` compares every function and rewrite against answers recorded from MariaDB 11
(`tests/Db/MariaDb/expected.json`), and the rows of queries on one table created on both (`table-cases.php`,
`expected-tables.json`); `NOIRAPI_TEST_MARIADB=mysql:host=…;dbname=…` (with `_USER`, `_PASS`) runs the
same cases against a live server and records them again.
