# Swoole

`swoole.php` serves the app from a long-running [Swoole](https://wiki.swoole.com/) HTTP server
instead of PHP-FPM. It needs the `swoole` extension.

```bash
CONFIG=production php noirapi/swoole.php
```

```neon
swoole:
    listen_ip: 127.0.0.1
    listen_port: 9400
    workers: 4          # request workers
    task_workers: 2     # workers for App\Tasks\* jobs
    static_files: true  # serve files in htdocs/ directly
```

Each request goes through the same `Route` as under PHP-FPM (`Route::fromSwoole()`), so
routing, controllers, CSRF, security headers and rate limits behave the same. The raw body is
passed on, so `$request->json()` works.

## Things that differ from PHP-FPM

A worker serves many requests, so anything static lives across requests:

- Don't keep per-request state in static properties. The framework resets its own (the CSP
  nonce, the URL context) at the start of every request.
- `$_SESSION` and PHP's session functions don't work as under FPM; use token authentication or
  a session implementation that reads the cookie from `$request->cookies`.
- `rate_limiter.storage: memory` is per worker: fine for development, use APCu or Memcached in
  production.
- Database connections are pooled per driver and stay open; use `Model::getNewInstance()` to
  reconnect after the server closed a connection.

## Tasks

Controllers can hand slow work to task workers:

```php
$this->request->swoole->task(['class' => \App\Tasks\SendReport::class, 'params' => $id]);
```

The task class is invoked with the params: `(new SendReport())($id)`. Its return value goes to
Swoole's `finish` event.
