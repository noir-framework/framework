# Controller

Controllers handle HTTP requests and return a `Response`. Every controller class extends `Noirapi\Lib\Controller`, which wires up the request, the response, the auto-loaded model, and session flash messages.

---

## Structure

```
app/
  controllers/
    web/
      Articles.php      ← GET/POST pages
      Api.php
    api/
      v1/
        Articles.php    ← REST endpoints
  models/
    Articles.php        ← auto-loaded for Articles controllers
```

A controller action is a public method that receives route parameters and **must return** a `Response` object.

```php
namespace App\Controllers\Web;

use App\Site;           // your app's base controller (extends Controller)
use Noirapi\Lib\Response;

class Articles extends Site
{
    public function index(): Response
    {
        $articles = $this->model->list();

        return $this->view->display(['articles' => $articles]);
    }
}
```

---

## Auto-Loaded Model

The framework resolves a matching `App\Models\<ControllerName>` class and assigns it to `$this->model`. If no matching model class exists, a bare `Model` instance is used instead.

```php
// Controller: App\Controllers\Web\Articles
// Auto-loaded: App\Models\Articles

$this->model->list();       // calls a method on App\Models\Articles
$this->model->get($id);
```

To use a different model within the same action:

```php
use App\Models\Tags;

$tags = new Tags();
$tags->allForArticle($articleId);
```

---

## Request

`$this->request` carries all inbound data for the current HTTP request.

| Property | Type | Description |
|---|---|---|
| `method` | `string` | HTTP verb, upper case: `GET`, `POST`, `PUT`, `DELETE`, … |
| `uri` | `string` | Request URI as sent, query string included (not URL-decoded) |
| `url_no_lang` | `string` | Decoded path without the language prefix |
| `get` | `array` | Query string parameters (`$_GET`) |
| `post` | `array` | Posted body parameters (`$_POST`); the CSRF field is removed after the check |
| `files` | `array` | Uploaded files (`$_FILES`) |
| `cookies` | `array` | Cookies (`$_COOKIE`) |
| `headers` | `array` | Request headers, `Content-Type` style keys |
| `controller` | `string` | Resolved controller class name |
| `function` | `string` | Resolved action method name |
| `route` | `array` | The FastRoute dispatch result |
| `role` | `string` | Current user's ACL role (set by the app) |
| `ajax` | `bool` | `true` for XHR / PJAX / fetch requests |
| `https` | `bool` | `true` when the client used HTTPS (behind a proxy: see `trusted_proxies`) |
| `hostname` | `string` | The host the client asked for |
| `clientIp` | `string` | The client's address (behind a proxy: see `trusted_proxies`) |
| `language` | `?string` | Active language code, or `null` |

Route parameters arrive as the action's arguments, by name (`/articles/{id}` → `show(string $id)`).

### Reading input

`input()` and the typed getters look in the JSON body (for JSON requests), then POST, then the
query string. A typed getter returns the default (`null` unless given) when the value is
missing or doesn't convert cleanly, so `int('page')` on `?page=abc` is `null`, not `0`.

```php
$page   = $this->request->int('page', 1);                 // ?page=3 -> 3
$title  = $this->request->string('title', '');
$price  = $this->request->float('price');
$active = $this->request->bool('active', false);          // 1/true/on/yes, 0/false/off/no
$status = $this->request->enum('status', Status::class);  // backed enum, by value
$tags   = $this->request->array('tags');                  // [] when missing
$raw    = $this->request->input('anything');              // mixed
$has    = $this->request->has('q');

$page   = $this->request->query('page');                  // query string only
$data   = $this->request->json();                         // decoded JSON body (array)
$body   = $this->request->body();                         // raw body
```

An invalid JSON body makes `json()` / `input()` throw a `RestException` (400).

### Other helpers

```php
$this->request->isMethod('POST');
$this->request->isSecure();
$this->request->isJson();                    // Content-Type is JSON
$this->request->wantsJson();                 // Accept lists application/json
$this->request->accepts('text/csv');
$this->request->header('Authorization');     // or getHeader(), case-insensitive
$this->request->header('X-Page', '1');       // with a default
$this->request->getCookie('theme');
$this->request->baseUrl();                   // https://app.example.com
```

---

## Response

### HTML page

Delegate to the view for rendering:

```php
return $this->view->display(['items' => $items]);
```

### Redirect

```php
// Temporary redirect (302)
return $this->forward('/articles');

// Permanent redirect (301)
return $this->forward('/new-url', 301);

// Redirect back to the referring page
return $this->forward();

// Redirect across domains (skips same-domain check)
return $this->forward('https://other.example.com/path', 302, skip_lang: true);
```

### Status-only responses

```php
return $this->ok();                  // 200
return $this->notFound();            // 404
return $this->internalServerError(); // 500
```

### JSON response

```php
return $this->response
    ->setContentType(Response::TYPE_JSON)
    ->setBody(['id' => $id, 'status' => 'created']);
```

### REST envelope (ok / error)

```php
// Success with a next-page redirect hint
return $this->restMessage(true, 'Article saved', '/articles/' . $id);

// Failure with a field-level tag for the front-end
return $this->restMessage(false, 'Title is required', null, 'title');
```

The response body is a `RestMessage` object serialised as JSON:
```json
{ "ok": true, "message": "Article saved", "next": "/articles/42" }
```

### File downloads

```php
$pdf = $this->model->generatePdf($id);

return $this->response
    ->setContentType(Response::TYPE_PDF)
    ->downloadFile('report.pdf')
    ->setBody($pdf);
```

### CSV export

```php
$rows = $this->model->exportRows();

return $this->response
    ->setContentType(Response::TYPE_CSV)
    ->downloadFile('export.csv')
    ->setBody($rows);   // array of arrays or objects; header row is automatic
```

### Cookies

```php
$this->response->addCookie('theme', 'dark');         // persists far future
$this->response->addCookie('session', $token, time() + 3600);
$this->response->clearCookie('old_cookie');
```

Cookies are always set `Secure; HttpOnly; SameSite=Strict`.

### Custom headers

```php
$this->response->addHeader('X-Request-Id', $uuid);
$this->response->addHeader('Cache-Control', 'no-store');
```

---

## Flash Messages

A flash message is stored in the session and consumed by the next page render. It is automatically injected into the view as `$message`.

```php
$this->message('Article created successfully', 'success');
return $this->forward('/articles');
```

| Type | Bootstrap colour |
|---|---|
| `success` | green |
| `danger` | red |
| `warning` | yellow |
| `info` | blue |
| `primary` | brand blue |

### Message then redirect (common pattern)

```php
public function delete(): Response
{
    $id = (int) $this->request->route['id'];
    $this->model->delete($id);

    $this->message('Article deleted', 'success');
    return $this->forward('/articles');
}
```

### Cross-domain flash messages

Pass `message` and `type` as query string parameters; the base controller picks them up automatically on the destination page:

```
https://app.example.com/dashboard?message=Logged+in&type=success
```

---

## Referring Page

`referer()` returns the referring URL, sanitised and validated. By default it is restricted to the same domain:

```php
// Safe: returns '/' if the referer is a different host
return $this->forward($this->referer());

// Allow cross-domain referers (use with care)
$url = $this->referer(same_domain: false);
```

---

## ACL Checks

Two helpers enforce access control. Both throw exceptions that the kernel handles — no manual error response is needed.

```php
// Throws 404 (or redirect to /) if this controller is not registered in the ACL
$this->hasResource($acl);

// Throws 403 (ajax) or redirect to login (web) if the current role lacks access
$this->isAllowed($acl);
```

Typical usage in a base site controller:

```php
public function __construct(...)
{
    parent::__construct(...);
    $this->hasResource($this->acl);
    $this->isAllowed($this->acl);
}
```

### Caching the ACL

Building the ACL (roles, resources, rules) on every request is wasted work when it never changes. Wrap the builder in `AclCache::remember()`:

```php
use Noirapi\Lib\AclCache;

$this->acl = AclCache::remember(static function (): Acl {
    $acl = new Acl();
    $acl->addRole(new GenericRole('guest'));
    $acl->addResource(new GenericResource(Index::class));
    $acl->allow('guest', Index::class);

    return $acl;
});
$this->request->role = $this->user->role ?? 'guest';
```

- **Where it's stored:** the built ACL is serialized to `temp/acl-cache/` and reused until the file that defines the builder changes. Pass `sources: [...]` to watch other files too, and a `key` if the app keeps more than one ACL.
- **What the builder may contain:** only roles, resources and rules. Keep per-user state (the current role, the user) outside it.
- **Assertions:** an ACL with closure assertions can't be serialized, so it's rebuilt on every request. Use assertion classes to keep it cacheable.

---

## Action Lifecycle

```
Router matches URI
  → instantiates Controller (constructor runs: model load, ACL check, message pickup)
  → calls action method
    → action reads $this->request
    → action calls model / services
    → action returns Response
  → kernel writes headers + body
```

Every action method must return a `Response`. Returning nothing, or returning a non-Response value, is a runtime error.

---

## API Reference

### Controller properties

| Property | Type | Description |
|---|---|---|
| `$request` | `Request` | Current HTTP request |
| `$response` | `Response` | Response builder |
| `$model` | `Model\|null` | Auto-loaded model |
| `$view` | `View\|null` | View instance (set by base controller) |
| `$dev` | `bool` | `true` in development mode |

### Controller methods

| Method | Returns | Description |
|---|---|---|
| `forward(?string $url, int $code, bool $skip_lang)` | `Response` | Redirect; `null` redirects back to referer |
| `forwardTo(string $name, array $params, array $query, int $code)` | `Response` | Redirect to a [named route](routing.md#named-routes-and-url-generation) |
| `ok()` | `Response` | 200 status |
| `notFound()` | `Response` | 404 status |
| `internalServerError()` | `Response` | 500 status |
| `message(string\|Message $text, ?string $type)` | `$this` | Store flash message in session |
| `restMessage(bool $ok, mixed $msg, ?string $next, ?string $tag)` | `Response` | JSON REST envelope |
| `referer(bool $same_domain)` | `string` | Sanitised HTTP Referer |
| `hasResource(Acl $acl)` | `void` | Assert controller is in ACL; throws on failure |
| `isAllowed(Acl $acl)` | `void` | Assert current role has access; throws on failure |
