# Security

Everything here is opt-in through the app's NEON config, so an existing app keeps working
until it turns a feature on.

## Trusted proxies

Behind a load balancer, reverse proxy or Cloudflare, the direct peer (`REMOTE_ADDR`) is the
proxy, not the visitor. The forwarding headers that carry the real values
(`X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`, Cloudflare's `CF-Connecting-IP`)
can be sent by anyone, so they are only believed when the peer is listed here:

```neon
trusted_proxies:
    - cloudflare        # Cloudflare's published ranges, and its CF-Connecting-IP header
    - 10.0.0.0/8        # your load balancer(s): addresses or CIDR ranges, IPv4 or IPv6
```

The results are on the request:

| | |
|---|---|
| `$request->clientIp` | the visitor's address: `CF-Connecting-IP` from Cloudflare, else the right-most `X-Forwarded-For` hop that isn't a trusted proxy |
| `$request->https` / `isSecure()` | HTTPS from the server (`HTTPS`, port 443) or, from a trusted proxy, `X-Forwarded-Proto` / `X-Forwarded-Ssl` / `CF-Visitor` |
| `$request->hostname` | `X-Forwarded-Host` from a trusted proxy, else `Host` |

Cookies get their `Secure` flag from `isSecure()`, and `dev_ips` is matched against
`clientIp`. Without `trusted_proxies` nothing is trusted and `REMOTE_ADDR` is the client.

## CSRF protection

```neon
csrf: true
# or, with other origins allowed to post here:
csrf:
    enabled: true
    allowed_origins: [https://admin.example.com]
```

With it on, every request other than GET/HEAD/OPTIONS/TRACE must

1. **not be cross-site.** An `Origin` header must name this host (or an allowed origin).
   Without `Origin`, a `Sec-Fetch-Site` of `cross-site` or `same-site` is rejected. Clients that
   send neither rely on the token alone; and
2. **carry the session's token**, in the `_csrf` form field or the `X-CSRF-Token` header.

The session must be started (`Session::start()`) before the controller action runs; the check
happens after the controller is constructed, so starting it in the base controller works.

Forms:

```latte
<form method="post">
    {csrf}
    ...
</form>
```

JavaScript (fetch/axios): print the token into a meta tag and send it as a header:

```latte
<meta name="csrf-token" content="{csrfToken}">
```

```js
fetch('/api/items', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify(item),
});
```

A failed check redirects back to the referer with a "Your session expired" flash message, or,
for AJAX/JSON requests, answers `403` with `{"ok": false, "error": "csrf"}`. The `_csrf` field
is removed from `$request->post` after a successful check, so nette/schema structures don't see
it.

Endpoints that must accept foreign POSTs (webhooks, APIs authenticated by a token rather than
the session cookie) opt out with `#[SkipCsrf]`, on the action or the whole controller:

```php
use Noirapi\Lib\Attributes\SkipCsrf;

#[SkipCsrf]
final class StripeWebhook extends Site { ... }
```

`Session::regenerate()` (call it on login) replaces the token. In PHP code:
`Csrf::token()`, `Csrf::isValid($value)`, `Csrf::check($request)`.

## Security headers and CSP

```neon
security_headers: true      # the defaults below
```

| Key | Header | Default |
|---|---|---|
| `content_type_options` | `X-Content-Type-Options` | `nosniff` |
| `frame_options` | `X-Frame-Options` | `SAMEORIGIN` |
| `referrer_policy` | `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `permissions_policy` | `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `cross_origin_opener_policy` | `Cross-Origin-Opener-Policy` | `same-origin` |
| `hsts` | `Strict-Transport-Security` (HTTPS responses only) | `max-age=31536000; includeSubDomains` |

Set a key to override it, or to `false` to drop the header. `headers:` adds any others:

```neon
security_headers:
    frame_options: DENY
    hsts: 'max-age=63072000; includeSubDomains; preload'
    headers:
        X-Robots-Tag: noindex
```

The headers go on every response Route produces, error pages and redirects included. A header
the app set itself (`$response->addHeader()`) is never overwritten.

### Content-Security-Policy

Only sent when `csp` is set. `csp: default` is a strict, nonce-based policy:

```
default-src 'self'; script-src 'self' 'nonce-…'; style-src 'self' 'nonce-…'; img-src 'self' data:;
font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';
frame-ancestors 'self'
```

Or list the directives yourself. `nonce` stands for the request's nonce, and CSP keywords
(`self`, `none`, `unsafe-inline`, `strict-dynamic`, …) may be written without their quotes:

```neon
security_headers:
    csp:
        default-src: [self]
        script-src: [self, nonce, https://cdn.jsdelivr.net]
        style-src: [self, nonce, https://fonts.googleapis.com]
        font-src: [self, https://fonts.gstatic.com]
        img-src: [self, 'data:']
        upgrade-insecure-requests: []
    csp_report_only: true   # try it out first: violations are reported, nothing is blocked
```

With a nonce in the policy, views get `$nonce` automatically (unless the app passes its own),
and inline scripts and styles need the `{nonce}` tag:

```latte
<script{nonce}>initPage();</script>
<style{nonce}>.x { color: red }</style>
```

`{topJs}`, `{bottomJs}`, `{topCss}` and `{bottomCss}` add the nonce themselves. Tracy's bar
picks the nonce up from the sent header. In PHP code: `SecurityHeaders::nonce()`.
