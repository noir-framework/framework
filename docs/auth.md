# Authentication

`Noirapi\Auth` provides the building blocks. The app owns the users table, the routes and
what "signed in" means (usually a user object in the session).

| Piece | Class |
|---|---|
| Registry built from config | `AuthManager` |
| Email + password | `Providers\PasswordProvider` |
| Passwordless email links | `Providers\MagicLinkProvider` |
| Two-factor codes (TOTP) | `Providers\TotpProvider` |
| GitHub / Google sign-in | `Providers\GitHubProvider`, `Providers\GoogleProvider` (OAuth 2.0) |
| Re-authentication for sensitive actions | `SudoMode` |

## Configuration

```neon
app_secret_key: 'base64 of 32+ random bytes'   # php -r 'echo base64_encode(random_bytes(32));'

mail:
    dsn: 'smtp://user:pass@smtp.example.com:587'
    from: 'no-reply@example.com'

auth:
    sudo_ttl: 900
    totp:
        issuer: 'My App'           # shown in authenticator apps
    magic_link:
        enabled: true              # needs mail.dsn and app_secret_key
        ttl: 900                   # seconds
        path: '/auth/magic-link'   # links are <appUrl><path>/<token>
    google:
        client_id: '...'
        client_secret: '...'
        redirect_uri: 'https://app.example.com/auth/oauth/google/callback'
    github:
        client_id: '...'
        client_secret: '...'
        redirect_uri: 'https://app.example.com/auth/oauth/github/callback'

rate_limits:               # optional: these are the built-in defaults
    login:      {limit: 5, per: 60, block_after: 5, block_for: 900}
    magic_link: {limit: 3, per: 900}
    totp:       {limit: 5, per: 60, block_after: 3, block_for: 900}
```

```php
$auth = AuthManager::fromConfig(Config::get('auth') ?? [], Config::get('mail') ?? [], $request->baseUrl());
```

Whatever the method, after a successful sign-in call `Session::regenerate()` (new session ID,
new CSRF token) before storing the user in the session.

## Passwords

`PasswordProvider` gets a lookup closure, so it knows nothing about your database. Unknown
emails still cost a bcrypt check, so response times don't reveal which accounts exist.

```php
$passwords = new PasswordProvider(fn (string $email): ?array => $users->credentials($email));
// the closure returns ['password' => <password_hash()>, 'name' => ?string, 'avatar_url' => ?string] or null

$result = $passwords->verifyThrottled($email, $password, $request->clientIp);
if ($result === null) { /* wrong email or password */ }
```

`verifyThrottled()` counts the attempt against the `login` limit, per email and per client IP,
and throws `TooManyRequestsException` (a 429 page, see [security](security.md#rate-limiting))
when either is exhausted. `verify()` is the same check without throttling.

## Magic links

Tokens are stateless and signed with `app_secret_key`. The signature also covers a
**fingerprint** you choose per user: any value that changes when the user signs in, such as the
last-login timestamp. Signing in therefore invalidates every link sent before, so each link
works once, and no token table is needed. Links also expire after `ttl` seconds.

```php
$magic = $auth->getMagicLinkProvider();

// POST /auth/magic-link: "email me a sign-in link"
$user = $users->byEmail($email);
if ($user !== null) {
    // Rate-limited by `magic_link`, per email and per IP (TooManyRequestsException).
    $magic->send($user->email, $user->name, fingerprint: (string) $user->last_login_at, clientIp: $request->clientIp);
}
// Answer the same either way, so the form doesn't reveal which emails have accounts.

// GET /auth/magic-link/{token}
$email = $magic->verify($token, fn (string $email): ?string => $users->lastLoginAt($email));
if ($email === null) { /* invalid, expired or already used */ }
$users->touchLastLogin($email);   // invalidates this and every older link
Session::regenerate();
```

The email is rendered from `app/templates/magic-link.latte` when the app has one, else from the
framework's `Templates/mail/magic-link.latte`. Both get `$name`, `$magicUrl` and `$ttlMinutes`.
`issue()` and `url()` are there if you send the email yourself.

The link contains the email address (base64-encoded, not encrypted), so it shows in the
recipient's browser history.

## TOTP (two-factor codes)

```php
$totp = $auth->getTotpProvider();

$secret = $totp->createSecret();                               // store in the users table
$qr     = $totp->getQRCodeUri($user->email, $secret);          // <img src="{$qr}">

$ok = $totp->verifyThrottled($user->totp_secret, $code, 'user:' . $user->id);
```

`verifyThrottled()` applies the `totp` limit per key; brute-forcing a 6-digit code is
otherwise feasible. QR codes are rendered locally (bacon/bacon-qr-code), so the secret never
leaves the server.

## OAuth (GitHub, Google)

```php
// GET /auth/oauth/{provider}
return $this->forward($auth->get('github')->getRedirectUrl());

// GET /auth/oauth/{provider}/callback
['result' => $result, 'action' => $action] = $auth->get('github')->handleCallback($this->request->get);
// $result: OAuthResult with provider, providerUserId, email, name, avatarUrl, accessToken, refreshToken, tokenExpiresAt
```

The `state` parameter is generated per redirect, kept in the session and checked on the callback.
`$action` is what you passed to `getRedirectUrl()` (`login`, or e.g. `connect` to link an
account). Other providers extend `OAuthProvider`.

## Sudo mode

Ask for the password or a TOTP code again before sensitive actions (changing the email,
disabling 2FA, deleting the account):

```php
if (! SudoMode::isActive()) {
    Session::set('return', null, $this->request->uri);
    return $this->forward('/auth/confirm');
}

// after the user re-confirmed:
SudoMode::grant();          // for auth.sudo_ttl seconds (default 900)
SudoMode::remaining();      // seconds left
SudoMode::revoke();         // on logout
```
