# Database

noirapi uses [opis/database](https://opis.io/database) as a fluent query builder on top of PDO. The `Model` base class manages connection pooling and exposes `$this->db` to all model classes.

---

## Configuration

Database configuration lives in `app/config/<domain>.neon` under the `db` key. The first driver listed becomes the default.

### MySQL / MariaDB

```neon
db:
    mysql:
        dsn: 'host=localhost;dbname=myapp;charset=utf8mb4'
        user: 'myapp_user'
        pass: 'secret'
```

Common DSN parameters:

| Parameter  | Example                | Notes                                  |
|------------|------------------------|----------------------------------------|
| `host`     | `host=localhost`       | Hostname or IP                         |
| `port`     | `port=3306`            | Optional, defaults to 3306             |
| `dbname`   | `dbname=myapp`         | Database name                          |
| `charset`  | `charset=utf8mb4`      | Always set to `utf8mb4` for MySQL      |
| `unix_socket` | `unix_socket=/var/run/mysqld/mysqld.sock` | Use instead of host/port |

### SQLite

```neon
db:
    sqlite:
        dsn: 'myapp.db'
```

- Relative paths are resolved from `<ROOT>/data/`. The `data/` directory must exist and be writable.
- For an in-memory database (testing): `dsn: ':memory:'`
- For an absolute path: `dsn: '/var/db/myapp.db'`

### PostgreSQL

```neon
db:
    pgsql:
        dsn: 'host=localhost;dbname=myapp'
        user: 'myapp_user'
        pass: 'secret'
```

### Multiple Databases

List multiple drivers; the first one is used by default. Models can select a specific driver by passing its key to the constructor.

```neon
db:
    mysql:
        dsn: 'host=localhost;dbname=main'
        user: 'app'
        pass: 'secret'
    sqlite:
        dsn: 'cache.db'
```

---

## Model Class

All application models extend `Noirapi\Lib\Model`. On construction the model connects to the configured database and exposes `$this->db` (an `Opis\Database\Database` instance).

```php
namespace App\Models;

use Noirapi\Lib\Model;

class Articles extends Model
{
    private const string table = 'articles';
}
```

### Selecting a Driver

```php
// Use the default driver (first in config)
$model = new Articles();

// Use a specific driver by key
$model = new Articles('sqlite');

// Use an explicit connection params array (bypasses config)
$model = new Articles('mysql', [
    'dsn'  => 'host=db2.internal;dbname=archive',
    'user' => 'reader',
    'pass' => 'readonly',
]);
```

### Connection Pooling

Connections are cached per-driver within the request lifecycle. Calling `new Articles()` twice returns the same underlying PDO connection.

```php
// Both share one PDO connection — safe and efficient
$a = new Articles();
$b = new Articles();

// Force a brand-new connection (e.g. after a fork or long-running CLI process)
$model = Articles::getNewInstance();
```

---

## Querying

### Fetch All Rows

```php
$rows = $this->db->from('articles')->select()->fetchAll();
// Returns array of stdClass objects
```

### Fetch Into a Repository Class

```php
// app/repository/Article.php
class Article {
    public int $id;
    public string $title;
    public string $body;
    public string $created_at;
}

// In the model:
public function list(): array
{
    return $this->db->from('articles')
        ->select()
        ->fetchClass(Article::class)
        ->all();
}
```

### Fetch a Single Row

```php
use Noirapi\Helpers\Utils;

public function get(int $id): ?Article
{
    return Utils::returnNull(
        $this->db->from('articles')
            ->where('id')->is($id)
            ->select()
            ->fetchClass(Article::class)
            ->first()
    );
    // Returns Article or null (never false)
}
```

### Select Specific Columns

```php
$this->db->from('articles')
    ->select(['id', 'title', 'created_at'])
    ->fetchAll();
```

### Where Clauses

```php
// Equality
->where('status')->is('published')

// Not equal
->where('status')->isNot('draft')

// IN list
->where('status')->in(['published', 'featured'])

// NULL check
->where('deleted_at')->isNull()

// Comparison
->where('views')->greaterThan(100)
->where('price')->lessThanOrEqual(99.99)

// LIKE
->where('title')->like('%php%')

// Multiple conditions (AND)
->where('status')->is('published')
->andWhere('category_id')->is(3)

// OR condition
->where('status')->is('published')
->orWhere('status')->is('featured')
```

### Ordering and Limiting

```php
$this->db->from('articles')
    ->where('status')->is('published')
    ->select()
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->offset(20)
    ->fetchClass(Article::class)
    ->all();
```

### Counting

```php
$count = $this->db->from('articles')
    ->where('status')->is('published')
    ->select()
    ->count();
```

### Joins

```php
$this->db->from('articles')
    ->join('users', function($join) {
        $join->on('articles.user_id', 'users.id');
    })
    ->select(['articles.*', 'users.name as author_name'])
    ->fetchAll();
```

---

## Inserting

```php
public function create(array $data): int
{
    $this->db->insert($data)->into('articles');
    return (int) $this->lastId();
}

// Usage
$id = $model->create([
    'title'      => 'Hello World',
    'body'       => 'Content here',
    'user_id'    => 42,
    'created_at' => date('Y-m-d H:i:s'),
]);
```

---

## Updating

```php
public function update(int $id, array $data): void
{
    $this->db->update('articles')
        ->where('id')->is($id)
        ->set($data);
}

// Usage
$model->update(7, ['title' => 'New Title', 'updated_at' => date('Y-m-d H:i:s')]);
```

---

## Deleting

```php
public function delete(int $id): void
{
    $this->db->delete()->from('articles')
        ->where('id')->is($id);
}
```

---

## Transactions

```php
$model->begin();

try {
    $model->db->insert(['name' => 'Alice'])->into('users');
    $model->db->insert(['user_id' => (int)$model->lastId(), 'amount' => 50])->into('credits');
    $model->commit();
} catch (\Throwable $e) {
    $model->rollback();
    throw $e;
}
```

Check whether a transaction is active:

```php
if ($model->inTransaction()) {
    $model->rollback();
}
```

---

## Pagination

Uses `nette/utils` `Paginator` under the hood.

```php
$perPage = 20;
$page    = max(1, (int) $request->get('page', 1));

$total     = $model->db->from('articles')->select()->count();
$paginator = $model->paginator($total, $perPage, $page);

$articles = $model->db->from('articles')
    ->select()
    ->orderBy('created_at', 'DESC')
    ->limit($paginator->getLength())
    ->offset($paginator->getOffset())
    ->fetchClass(Article::class)
    ->all();
```

---

## Table Locking (MySQL)

For operations that require serialised access:

```php
$model->lock('inventory');

// ... exclusive writes ...

$model->unlock();
```

---

## Raw Queries

Access the underlying PDO when the query builder is not sufficient:

```php
$pdo  = $this->db->getConnection()->getPDO();
$stmt = $pdo->prepare('SELECT * FROM articles WHERE MATCH(title, body) AGAINST (? IN BOOLEAN MODE)');
$stmt->execute([$searchTerm]);
$rows = $stmt->fetchAll(\PDO::FETCH_CLASS, Article::class);
```

---

## PDO Attributes

The following PDO attributes are set automatically on every connection:

| Attribute                     | Value                   |
|-------------------------------|-------------------------|
| `ATTR_STRINGIFY_FETCHES`      | `false`                 |
| `ATTR_EMULATE_PREPARES`       | `false`                 |
| `ATTR_ERRMODE`                | `ERRMODE_EXCEPTION`     |
| `ATTR_DEFAULT_FETCH_MODE`     | `FETCH_OBJ`             |

All database errors throw a `PDOException`. Wrap operations in try/catch or let the framework's exception renderer handle them.
