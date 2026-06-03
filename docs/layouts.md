# Layouts

Layouts are Latte files that define the structural shell of a page — the `<html>`, `<head>`, navigation, and footer. A content view is rendered inside the layout, which receives the same template variables plus a `$layout` object that carries the page title, breadcrumbs, and registered asset lists.

---

## File Organisation

```
app/
  layouts/
    default.latte       ← full-page layout shell
    minimal.latte       ← alternate shell (e.g. for print or auth pages)
    __header.latte      ← partial: top navigation bar
    __sidebar.latte     ← partial: side navigation
    __footer.latte      ← partial: page footer
    __breadcrumbs.latte ← partial: breadcrumb trail (optional override)
    __message.latte     ← partial: flash message banner (optional override)
    pager.latte         ← partial: pagination widget (optional override)
```

**Naming rules:**
- Files prefixed with `__` are **partials** resolved from `app/layouts/` regardless of the call site inside a template. Use `{include '__header.latte'}` from any view.
- Files without the prefix are **layout shells** referenced by name: `'default'` → `app/layouts/default.latte`.

---

## Minimal Layout Shell

```latte
{* app/layouts/default.latte *}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{title} – My App</title>

    {* Built-in assets from the controller *}
    {topCss}
    {topJs}
    {head}
</head>
<body>
    {include '__header.latte'}

    <main>
        {message}
        {breadcrumb}
        {include $template}
    </main>

    {include '__footer.latte'}

    {bottomCss}
    {bottomJs}
</body>
</html>
```

`{include $template}` is the slot where the current view is inserted. `$template` is the resolved path to the active view file and is always available automatically.

---

## The `$layout` Object

The controller populates `$this->view->layout` before calling `display()`. The layout object is passed to templates as `$layout`.

### Page Title

```php
// Set (overwrites any previous value)
$this->view->layout->setTitle('Article List');

// Append to whatever is already set
$this->view->layout->appendTitle(' – My App');

// Check in the template before rendering
if ($this->view->layout->hasTitle()) { ... }
```

In the layout template:

```latte
<title>{title}</title>
```

The `{title}` macro outputs `$layout->title` when it is non-empty and does nothing otherwise.

### Breadcrumbs

```php
$this->view->layout->addBreadCrumb('Home', '/');
$this->view->layout->addBreadCrumb('Articles', '/articles');
$this->view->layout->addBreadCrumb('Edit');   // no URL → active/current
```

In the layout:

```latte
{breadcrumb}
```

The macro renders the breadcrumb partial (`app/layouts/__breadcrumbs.latte` if it exists, otherwise the built-in one). Each item is an array with keys `name`, `url`, and `active`.

If you need to render it manually:

```latte
{foreach $layout->breadcrumbs as $crumb}
    {if $crumb['url']}
        <a href="{$crumb['url']}">{$crumb['name']}</a>
    {else}
        <span class="active">{$crumb['name']}</span>
    {/if}
{/foreach}
```

---

## Asset Registration

Register CSS and JS files (or inline scripts) from the controller action. Duplicate URLs are ignored — each asset appears at most once per response.

### CSS

```php
// Injected in <head> — page-specific stylesheet
$this->view->layout->addTopCss('/share/css/pages/articles-index.css');

// Injected before </body> — rarely needed for CSS
$this->view->layout->addBottomCss('/share/css/print.css');
```

### JavaScript

```php
// Script tag in <head>
$this->view->layout->addTopJS('/share/js/editor.js');

// Script tag before </body> (preferred for non-blocking scripts)
$this->view->layout->addBottomJS('/share/js/charts.js');

// Inline script (no leading slash → treated as inline code, not a URL)
$this->view->layout->addBottomJS("initMap('" . $apiKey . "');");
```

In the layout, the macros emit the registered tags:

```latte
<head>
    {topCss}
    {topJs}
</head>
<body>
    ...
    {bottomCss}
    {bottomJs}
</body>
```

Each macro checks whether a `$nonce` variable is present in scope and, if so, appends a `nonce="…"` attribute to every emitted tag (for CSP compliance).

### PJAX and CSS

On AJAX/PJAX requests the layout shell is skipped and only the view body is returned. CSS files registered with `addTopCss()` are automatically prepended as `<link>` elements before the HTML fragment so the client-side PJAX script can inject them into `<head>`. No extra work is needed in the controller.

---

## Arbitrary Layout Parameters

The layout object supports an open-ended key/value store for any data you want to pass from the controller to the layout template without polluting the view's main variable scope.

```php
// Set a single value (replaces)
$this->view->layout->set('current_user', $user);

// Append to a list
$this->view->layout->add('alerts', 'Your trial expires in 3 days');
$this->view->layout->add('alerts', 'Two-factor auth is not enabled');

// Read in the layout template
$user   = $layout->get('current_user');
$alerts = $layout->get('alerts', []);   // second arg = default

// Check existence
if ($layout->exists('current_user')) { ... }
```

Properties are also reachable via magic getter in templates:

```latte
{$layout->current_user->name}
```

---

## Custom `<head>` Tags

Inject arbitrary raw HTML into `<head>` — useful for meta tags, canonical links, or preload hints.

```php
$this->view->layout->addToHead('<meta name="description" content="Browse all articles">');
$this->view->layout->addToHead('<link rel="canonical" href="https://example.com/articles">');
```

In the layout:

```latte
<head>
    ...
    {head}
</head>
```

---

## Latte Macros Reference

All macros are available in every Latte file rendered by the framework.

| Macro | Where to use | Output |
|---|---|---|
| `{title}` | layout | Prints `$layout->title` if non-empty |
| `{topCss}` | layout `<head>` | `<link>` tags for top-CSS assets |
| `{bottomCss}` | layout end of `<body>` | `<link>` tags for bottom-CSS assets |
| `{topJs}` | layout `<head>` | `<script>` tags / inline scripts for top-JS assets |
| `{bottomJs}` | layout end of `<body>` | `<script>` tags / inline scripts for bottom-JS assets |
| `{head}` | layout `<head>` | Raw HTML lines added via `addToHead()` |
| `{breadcrumb}` | layout or view | Breadcrumb partial |
| `{message}` | layout or view | Flash message partial |
| `{pager}` | view | Pagination widget (requires `$pager` in scope) |
| `{active 'controller'}` | layout nav links | Prints `active` if current controller matches |
| `{active 'controller', 'action'}` | layout nav links | Prints `active` if controller AND action match |
| `{nonce}` | inline `<style>`/`<script>` | Prints ` nonce="…"` if `$nonce` is set, otherwise nothing |

### `{active}` — Nav Highlighting

```latte
<a href="/articles" class="nav-link {active 'articles'}">Articles</a>
<a href="/articles/new" class="nav-link {active 'articles', 'create'}">New</a>
```

`{active 'controller'}` matches when only the controller name matches — regardless of the action. `{active 'controller', 'action'}` requires both to match.

### `{pager}` — Pagination

Pass `pager` from the controller:

```php
$count  = $this->model->count();
$pager  = $this->model->paginator($count, itemsPerPage: 20, page: $page);

$items  = $this->model->page($pager->getOffset(), $pager->getLength());

return $this->view->display(['items' => $items, 'pager' => $pager]);
```

In the view:

```latte
{foreach $items as $item}
    ...
{/foreach}

{pager}
```

The pager partial is `app/layouts/pager.latte` (your override) or the built-in one. It receives `$pager` (a `Nette\Utils\Paginator`) plus `$index_left` and `$index_right` window sizes, computed automatically.

### `{message}` — Flash Banner

Place once in the layout (or in the view for page-specific positioning):

```latte
{message}
```

The partial is `app/layouts/message.latte` (your override) or the built-in one. It receives `$message` (a `Message` object with `message` and `type` properties, or `null`).

### `{nonce}` — CSP Nonce

When Content Security Policy is active and a `$nonce` variable is provided to the view, tag this on every inline `<script>` or `<style>`:

```latte
<script{nonce}>
    const config = {$config|json_prettify|noescape};
</script>
```

---

## Partial Includes

Include a layout partial from any view:

```latte
{include '__sidebar.latte'}
{include '__widgets/card.latte'}
```

The `__` prefix causes the loader to always resolve from `app/layouts/` — no matter which view directory the including file lives in.

For non-layout partials (shared view snippets) use a regular include with an explicit path:

```latte
{include '../_shared/price_table.latte', items: $items}
```

---

## API Reference — Layout Object

| Method / Property | Description |
|---|---|
| `setTitle(?string $title)` | Set the page title (run through translator if active) |
| `appendTitle(string $text)` | Append to the current title |
| `hasTitle()` | Returns `true` if the title is non-empty |
| `addBreadCrumb(int\|string $name, ?string $url, ?bool $active)` | Append a breadcrumb item |
| `addTopCss(string $url)` | Register a stylesheet for `<head>` (deduped) |
| `addBottomCss(string $url)` | Register a stylesheet for end-of-body (deduped) |
| `addTopJS(string $js)` | Register a script URL or inline code for `<head>` (deduped) |
| `addBottomJS(string $js)` | Register a script URL or inline code for end-of-body (deduped) |
| `addToHead(string $html)` | Append a raw HTML line to `<head>` |
| `set(string $key, mixed $value)` | Set an arbitrary layout parameter |
| `add(string $key, mixed $value)` | Append a value to a layout parameter list |
| `get(string $key, mixed $default)` | Read a layout parameter |
| `exists(string $key)` | Check whether a layout parameter is set |
| `setName(string $name)` | Set the layout name (called automatically by `View::setLayout()`) |
| `getName()` | Return the layout name |
| `$title` | `string` — page title |
| `$breadcrumbs` | `array` — breadcrumb items |
| `$head` | `array` — raw `<head>` HTML lines |
