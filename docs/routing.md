# Routing

The framework dispatches through the app's `App\Route`, which must have
`process(string $method, string $uri): array` returning a FastRoute dispatch result. The URI
it receives is the decoded path, without the query string and without the language prefix.

## Plain FastRoute

What existing apps do; it keeps working:

```php
namespace App;

use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

final class Route
{
    public function process(string $method, string $uri): array
    {
        return simpleDispatcher(static function (RouteCollector $r): void {
            $r->get('/', [Index::class, 'index']);
            $r->get('/users/{id:\d+}', [Users::class, 'show']);
        })->dispatch($method, $uri);
    }
}
```

## Named routes and URL generation

`Noirapi\Lib\Routing\Router` wraps the same RouteCollector and records route names, so links
don't have to be hard-coded:

```php
use Noirapi\Lib\Routing\Router;

final class Route
{
    public function process(string $method, string $uri): array
    {
        return Router::dispatcher(static function (Router $r): void {
            $r->get('/', [Index::class, 'index'], 'home');
            $r->get('/users/{id:\d+}[/{slug}]', [Users::class, 'show'], 'user.show');
            $r->post('/users', [Users::class, 'create'], 'user.create');
            $r->add(['GET', 'POST'], '/login', [Login::class, 'form'], 'login');

            $r->group('/admin', static function (Router $r): void {
                $r->get('/users', [AdminUsers::class, 'index'], 'users');   // named "admin.users"
            }, 'admin.');
        }, cache: true)->dispatch($method, $uri);
    }
}
```

`get`, `post`, `put`, `patch`, `delete` and `add($methods, ...)` take the path, the handler
and an optional name. Names must be unique. With `cache: true` the compiled routes are stored
in `temp/route-cache/` and rebuilt when the file that defines them changes (pass
`sources: [...]` to watch other files). Routes with closure handlers are never cached.

### In PHP

```php
use Noirapi\Lib\Routing\Url;

Url::to('user.show', ['id' => 5]);                          // /users/5
Url::to('user.show', ['id' => 5, 'slug' => 'ann']);         // /users/5/ann
Url::to('user.show', ['id' => 5, 'tab' => 'posts']);       // /users/5?tab=posts (unused parameters become the query)
Url::to('user.show', ['id' => 5], query: ['page' => 2]);    // /users/5?page=2
Url::absolute('user.show', ['id' => 5]);                   // https://app.example.com/users/5

return $this->forwardTo('user.show', ['id' => $user->id]);  // redirect, in a controller
```

Parameters are checked against the route's pattern (`{id:\d+}` rejects `abc` with an
`InvalidArgumentException`), URL-encoded, and may be backed enums. Optional `[...]` parts are
included when their parameters are given. With `languages` configured, the current language is
prefixed: `/bg/users/5`. Pass `language: 'en'` to link to another language, or `language: ''`
for no prefix.

### In templates

```latte
<a href="{link user.show, id: $user->id}">{$user->name}</a>
<a href="{url('user.show', id: $user->id, tab: 'posts')}">Posts</a>
<meta property="og:url" content="{absoluteUrl('user.show', id: $user->id)}">
```

The output is escaped for where it's printed.

## Languages

```neon
languages:
    en: English
    bg: Български
default_language: en
language_detector: App\Lib\GeoLanguage   # optional: ->detect(string $ip): ?string
```

A request without a language prefix is redirected (307) to the detected or default language,
keeping the path and query string. The prefix is stripped before `App\Route` sees the URI and is
available as `$request->language`. `Controller::forward('/path')` adds it back.

## Controller actions

The handler is `[ControllerClass::class, 'method']`. The controller is constructed with the
request, response and server array, then the method is called with the route parameters by
name. On the action, or on the controller class:

| Attribute | |
|---|---|
| `#[AutoWire('modelMethod')]` / `#[AutoWire([Class::class, 'method'])]` | resolve typed parameters from same-named route parameters (see [controllers](controller.md)) |
| `#[NotFound('message', 302)]` | the flash message and status when AutoWire finds nothing |
| `#[SkipCsrf]` | no CSRF check (see [security](security.md#csrf-protection)) |
| `#[RateLimited('api', key: 'ip')]` | rate-limit the action (see [security](security.md#rate-limiting)) |
