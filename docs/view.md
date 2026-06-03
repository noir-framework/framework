# View

`Noirapi\Lib\View` wraps the [Latte](https://latte.nette.org/) template engine. It resolves template files by convention, merges controller data into the template scope, handles PJAX/AJAX partial rendering, and returns a `Response` with the rendered HTML.

---

## File Conventions

```
app/
  views/
    articles/
      index.latte       ← App\Controllers\Web\Articles::index()
      show.latte        ← App\Controllers\Web\Articles::show()
      edit.latte
  layouts/
    default.latte       ← layout shell (full <html> document)
    __header.latte      ← partial; __ prefix = auto-resolved from layouts/
    __sidebar.latte
    __footer.latte
```

- Views live under `app/views/<controller-name>/`.
- The controller name is lowercased: `Articles` → `app/views/articles/`.
- The template filename matches the action method name: `index()` → `index.latte`.
- Layouts are full page shells; partials are fragments included inside them.
- Files prefixed with `__` are always resolved from `app/layouts/`, regardless of the `{include}` call site.

---

## Rendering a Response

### Default — template and layout resolved automatically

Call `display()` with any data to pass to the template. The engine infers the template name from the action method that called it.

```php
public function index(): Response
{
    return $this->view->display([
        'articles' => $this->model->list(),
        'count'    => $this->model->count(),
    ]);
}
```

### Explicit template

Override the template name when the action renders a different view than its own:

```php
public function create(): Response
{
    // Render app/views/articles/form.latte
    $this->view->setTemplate('form');
    return $this->view->display(['article' => null]);
}
```

Use a different controller's template:

```php
$this->view->setTemplate('form', 'shared');  // app/views/shared/form.latte
```

### No layout (fragment only)

Skip the layout shell and render the template body alone. Useful for modal content or embedded partials served over AJAX.

```php
$this->view->noLayout();
return $this->view->display(['item' => $item]);
```

### Render to string

Render a view and get the HTML string without touching the response. Useful for emails, PDF generation, or composing widgets.

```php
$html = $this->view->print(
    layout: 'email',           // app/layouts/email.latte, or null for no layout
    view:   'invoice',         // app/views/invoices/invoice.latte
    params: ['invoice' => $invoice]
);
```

---

## Passing Data to Templates

### Via `display()` (most common)

```php
return $this->view->display([
    'user'     => $user,
    'articles' => $articles,
    'pager'    => $pager,
]);
```

All keys become template variables: `$user`, `$articles`, `$pager`.

### Via `addParam()` (add single value before display)

```php
$this->view->addParam('sidebar_open', true);
return $this->view->display(['items' => $items]);
```

### Via `mergeParams()` (merge a batch or namespace it)

```php
// Merge flat array — each key becomes a top-level variable
$this->view->mergeParams(['locale' => 'en', 'timezone' => 'UTC']);

// Namespace: accessible in the template as $config->locale
$this->view->mergeParams($configObject, 'config');
```

Duplicate keys throw a `RuntimeException` — each variable name must be unique across all merged params in a single request.

---

## Automatically Available Template Variables

The following variables are always present in every template without any explicit `display()` call:

| Variable | Type | Description |
|---|---|---|
| `$request` | `Request` | Current HTTP request (method, uri, role, ajax, …) |
| `$layout` | `Layout` | Layout object (title, breadcrumbs, asset lists) |
| `$template` | `string` | Absolute path of the resolved template file |
| `$message` | `Message\|null` | Flash message from the previous request, if any |
| `$languages` | `array` | All configured language codes (empty if i18n is off) |

---

## PJAX / AJAX Behaviour

When a request has `X-Requested-With: XMLHttpRequest` or `Sec-Fetch-Mode` is not `navigate`, `$request->ajax` is `true`.

- The layout shell is **skipped** — only the template body is rendered.
- Any CSS files registered with `addTopCss()` are prepended as `<link>` tags before the HTML fragment so the PJAX client can inject them into `<head>`.

No special code is required in the controller; the view handles this automatically.

---

## URL Helper

Generate a URL that adds or replaces a single query parameter on the current page URI:

```php
// In a template (static call):
{$currentUri = View::addUrlVar('page', $pager->getPage() + 1)}
<a href="{$currentUri}">Next</a>
```

```php
// In a controller:
use Noirapi\Lib\View;

$nextUrl = View::addUrlVar('sort', 'created_at');
```

---

## Template Existence Checks

```php
if ($this->view->templateExists('preview')) {
    $this->view->setTemplate('preview');
}

if ($this->view->layoutExists('print')) {
    $this->view->setLayout('print');
}
```

---

## Built-In Latte Filters

These filters are available in every template via the `|` pipe syntax.

| Filter | Signature | Example |
|---|---|---|
| `urlencode` | `string → string` | `{$slug\|urlencode}` |
| `urldecode` | `string → string` | `{$encoded\|urldecode}` |
| `html_entity_decode` | `string → string` | `{$html\|html_entity_decode\|noescape}` |
| `date_format` | `(string $date, string $format) → string` | `{$row->created_at\|date_format:'d M Y'}` |
| `inverse` | `int\|bool\|string → int` | `{$flag\|inverse}` → returns `0` if truthy, `1` if falsy |
| `base64_encode` | `string → string` | `{$binary\|base64_encode}` |
| `json_prettify` | `array\|object → string` | `<pre>{$data\|json_prettify\|noescape}</pre>` |

### App-level filters

Add `public static` methods to `App\Lib\Filters`. They are registered automatically under the same name:

```php
namespace App\Lib;

class Filters
{
    public static function currency(float $amount, string $symbol = '$'): string
    {
        return $symbol . number_format($amount, 2);
    }
}
```

```latte
{$order->total|currency}
{$order->total|currency:'€'}
```

---

## Built-In Latte Functions

### `renderTemplate()`

Render a standalone template fragment and print it inline. The template receives the given data array.

```latte
{renderTemplate('partials/alert', ['type' => 'warning', 'text' => 'Disk nearly full'])}
```

The template file is resolved from `app/templates/`.

---

## Layout Configuration via NEON

Set a default layout for all controllers in config:

```neon
layout: 'default'
```

A controller can override it at runtime:

```php
$this->view->setLayout('minimal');
$this->view->noLayout();             // disable layout entirely
```

---

## API Reference

| Method | Returns | Description |
|---|---|---|
| `display(array $params)` | `Response` | Render template + layout; infers template from caller |
| `print(?string $layout, string $view, array $params)` | `string` | Render to HTML string without touching the response |
| `setTemplate(string $name, ?string $controller)` | `self` | Override the resolved template |
| `getTemplate()` | `?string` | Return the current resolved template path |
| `setLayout(?string $name)` | `self` | Override the layout file; `null` disables the layout |
| `getLayout()` | `?string` | Return the current resolved layout path |
| `noLayout()` | `self` | Render without a layout shell |
| `addParam(string $key, mixed $value)` | `void` | Add a single variable to the template scope |
| `mergeParams(array\|object $params, ?string $ns)` | `void` | Merge a batch of variables, optionally under a namespace |
| `templateExists(string $name, ?string $controller)` | `bool` | Check whether a template file exists |
| `layoutExists(string $name)` | `bool` | Check whether a layout file exists |
| `addUrlVar(string $key, mixed $value)` | `string` (static) | Return the current URI with one query param added/replaced |
