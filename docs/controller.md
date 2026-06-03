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
| `method` | `string` | HTTP verb: `GET`, `POST`, `PUT`, `DELETE`, … |
| `uri` | `string` | Full request URI including query string |
| `get` | `array` | Query string parameters (`$_GET`) |
| `post` | `array` | Posted body parameters (`$_POST`) |
| `files` | `array` | Uploaded files (`$_FILES`) |
| `cookies` | `array` | Cookies (`$_COOKIE`) |
| `headers` | `array` | Normalised HTTP request headers |
| `controller` | `string` | Resolved controller class name |
| `function` | `string` | Resolved action method name |
| `route` | `array` | Named route parameters from the URL pattern |
| `role` | `string` | Current user's ACL role |
| `ajax` | `bool` | `true` for XHR / PJAX requests |
| `https` | `bool` | `true` when the connection is HTTPS |
| `hostname` | `string` | `HTTP_HOST` value |
| `language` | `?string` | Active language code, or `null` |

```php
// Reading a route parameter  (/articles/{id})
$id = (int) $this->request->route['id'];

// Reading a query parameter  (?page=3)
$page = (int) ($this->request->get['page'] ?? 1);

// Reading a posted field
$title = $this->request->post['title'] ?? '';

// Checking the method
if ($this->request->method === 'POST') { ... }

// Reading an arbitrary header
$token = $this->request->getHeader('Authorization');

// Reading a cookie
$pref = $this->request->getCookie('theme');
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
| `ok()` | `Response` | 200 status |
| `notFound()` | `Response` | 404 status |
| `internalServerError()` | `Response` | 500 status |
| `message(string\|Message $text, ?string $type)` | `$this` | Store flash message in session |
| `restMessage(bool $ok, mixed $msg, ?string $next, ?string $tag)` | `Response` | JSON REST envelope |
| `referer(bool $same_domain)` | `string` | Sanitised HTTP Referer |
| `hasResource(Acl $acl)` | `void` | Assert controller is in ACL; throws on failure |
| `isAllowed(Acl $acl)` | `void` | Assert current role has access; throws on failure |
