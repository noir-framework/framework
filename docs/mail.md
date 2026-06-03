# Mail

noirapi uses [Symfony Mailer](https://symfony.com/doc/current/mailer.html) as the transport layer. The `Noirapi\Helpers\Mail` class wraps it with a fluent API and adds Latte template rendering and auto-generated plain-text parts.

---

## Configuration

Add a `mail` section to `app/config/<domain>.neon`:

```neon
mail:
    dsn: "smtp://user:pass@smtp.example.com:587"
    from:
        name: "My App"
        email: "noreply@example.com"
```

### Transport DSN Options

| Transport   | DSN format                                       | Notes                                         |
|-------------|--------------------------------------------------|-----------------------------------------------|
| SMTP        | `smtp://user:pass@host:port`                     | Standard authenticated SMTP                   |
| SMTPS       | `smtps://user:pass@host:465`                     | TLS on port 465                               |
| SMTP+STARTTLS | `smtp://user:pass@host:587?encryption=tls`     | STARTTLS on submission port                   |
| Sendmail    | `sendmail://default`                             | Uses the local MTA (`/usr/sbin/sendmail`)     |
| Sendmail (custom path) | `sendmail:///usr/local/bin/sendmail`  | Custom sendmail binary path                   |
| Null (dev/test) | `null://null`                               | Writes `.eml` + `.txt` files to `temp/`      |

#### SMTP Examples

```neon
# Gmail via App Password
mail:
    dsn: "smtps://user%40gmail.com:app_password@smtp.gmail.com:465"

# Office 365 / Microsoft 365
mail:
    dsn: "smtp://user%40company.com:pass@smtp.office365.com:587?encryption=tls"

# Mailgun (EU region)
mail:
    dsn: "smtp://postmaster%40mg.example.com:key@smtp.eu.mailgun.org:587?encryption=tls"

# Postfix on localhost (no auth)
mail:
    dsn: "smtp://localhost:25"
```

> **URL-encode** the `@` in the username as `%40` when it appears in the DSN string.

#### Development / Testing

```neon
mail:
    dsn: "null://null"
    from:
        name: "My App (dev)"
        email: "noreply@localhost"
```

When `null://null` is used, `send()` writes two files to `temp/`:
- `mail-<id>.eml` — raw MIME message (open in any mail client)
- `mail-<id>.txt` — plain-text body

---

## Instantiation

Read the DSN from config in your controller or service:

```php
use Noirapi\Config;
use Noirapi\Helpers\Mail;

$dsn  = Config::get('mail.dsn');
$mail = new Mail($dsn);

// Enable SMTP debug output (logged to $mail->getDebug() after send)
$mail = new Mail($dsn, debug: true);
```

---

## Composing a Message

### Minimal Example

```php
$from = Config::get('mail');

$mail->new(
    from: [$from['from']['email'], $from['from']['name']],
    to: 'recipient@example.com',
    subject: 'Welcome!'
)
->setBody('<p>Hello, World!</p>')
->send();
```

### `new()` — Start a Message

```php
// String from / string to
$mail->new('sender@example.com', 'recipient@example.com', 'Subject');

// Named sender + single recipient
$mail->new(['sender@example.com', 'Sender Name'], 'recipient@example.com', 'Subject');

// Multiple recipients
$mail->new(
    from: ['noreply@example.com', 'My App'],
    to: ['alice@example.com', 'bob@example.com'],
    subject: 'Team update'
);
```

`new()` returns `$this` — all subsequent calls can be chained.

---

## Setting the Body

### From a Latte Template

Templates live in `app/templates/`. The class renders them with Latte and uses the output as the HTML body. A plain-text version is generated automatically via `html2text`.

```php
// app/templates/password-reset.latte
// <p>Hi {$name}, click <a href="{$link}">here</a> to reset your password.</p>

$mail->setTemplate('password-reset', [
    'name' => $user->name,
    'link' => $resetUrl,
]);
```

### From a Raw HTML String

```php
$mail->setBody('<p>Hello <strong>' . htmlspecialchars($name) . '</strong></p>');
```

---

## Optional Headers and Recipients

### CC and BCC

```php
$mail->setCC(['manager@example.com', 'director@example.com']);
$mail->setBCC(['audit@example.com']);
```

### Reply-To

```php
$mail->setReplyTo('support@example.com');
// Alias: $mail->replyTo('support@example.com');
```

### Custom Headers

```php
$mail->addHeader('X-Campaign-ID', 'newsletter-2026-06');
```

### Suppress Auto-Replies (transactional mail)

Adds `X-Auto-Response-Suppress` so mailing lists and OOO bots do not reply:

```php
$mail->noResponders();
```

---

## Attachments

### Attach from a File Path

```php
$mail->attachFile('/var/reports/invoice-2026.pdf', 'invoice.pdf', 'application/pdf');
```

### Attach from an In-Memory String

```php
$csvData = "id,name\n1,Alice\n2,Bob\n";
$mail->attach($csvData, 'export.csv', 'text/csv');
```

### Inline Embed (for HTML `<img src="cid:...">`)

```php
// Embed from file
$mail->embedFile('/var/assets/logo.png', 'logo.png', 'image/png');

// Embed from string
$mail->embed($pngData, 'chart.png', 'image/png');
```

---

## Sending

```php
$ok = $mail->send();

if (! $ok) {
    error_log('Mail failed: ' . $mail->getError());
}
```

`send()` returns `true` on success, `false` on transport failure. It never throws — errors are captured and available via `getError()`.

### Debug Output

```php
$mail = new Mail($dsn, debug: true);
// ... compose ...
$mail->send();
echo $mail->getDebug(); // SMTP conversation transcript
```

---

## Full Example

```php
use Noirapi\Config;
use Noirapi\Helpers\Mail;

$cfg  = Config::get('mail');
$mail = new Mail($cfg['dsn']);

$ok = $mail
    ->new(
        from: [$cfg['from']['email'], $cfg['from']['name']],
        to: $user->email,
        subject: 'Your invoice is ready'
    )
    ->setTemplate('invoice-ready', [
        'user'       => $user->name,
        'invoice_id' => $invoice->id,
        'amount'     => number_format($invoice->total, 2),
        'due_date'   => $invoice->due_date,
    ])
    ->attachFile($invoice->pdfPath, "invoice-{$invoice->id}.pdf", 'application/pdf')
    ->setReplyTo('billing@example.com')
    ->noResponders()
    ->send();

if (! $ok) {
    throw new \RuntimeException('Could not send invoice mail: ' . $mail->getError());
}
```

---

## API Reference

| Method | Returns | Description |
|--------|---------|-------------|
| `__construct(string $dsn, bool $debug = false)` | — | Create mailer for the given transport DSN |
| `new(string\|array $from, array\|string $to, string $subject)` | `Mail` | Start a new message |
| `setBody(string $html)` | `Mail` | Set raw HTML body |
| `setTemplate(string $template, array $params)` | `Mail` | Render a Latte template from `app/templates/` |
| `setCC(array $addresses)` | `Mail` | Add CC recipients |
| `setBCC(array $addresses)` | `Mail` | Add BCC recipients |
| `setReplyTo(string $email)` | `Mail` | Set Reply-To header |
| `replyTo(string $email)` | `Mail` | Alias for `setReplyTo()` |
| `addHeader(string $key, string $value)` | `Mail` | Add an arbitrary header |
| `noResponders()` | `Mail` | Suppress OOF/auto-reply responses |
| `attach(string $data, string $filename, ?string $mime)` | `Mail` | Attach data from memory |
| `attachFile(string $path, string $name, ?string $mime)` | `Mail` | Attach a file from disk |
| `embed(string $data, string $filename, ?string $mime)` | `Mail` | Embed data inline (CID) |
| `embedFile(string $path, string $name, ?string $mime)` | `Mail` | Embed a file inline (CID) |
| `send()` | `bool` | Send the message; `false` on failure |
| `getError()` | `string` | Transport error message after a failed `send()` |
| `getBody()` | `string` | Return the current HTML body string |
| `getDebug()` | `string` | SMTP transcript (only populated in debug mode) |
