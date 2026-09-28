# Validation

Input is validated with [nette/schema](https://doc.nette.org/en/schema). The framework adds
schema types for things nette/schema doesn't cover, a factory for them (`Expect2`, next to
Nette's `Expect`), and helpers that turn validation errors into flash messages.

```php
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use Noirapi\Helpers\Expect2;
use Noirapi\Helpers\Message;

public function save(): Response
{
    $schema = Expect::structure([
        'name'    => Expect::string()->required()->max(100),
        'port'    => Expect2::custom(Port::class)->min(1)->max(65535)->required(),
        'domain'  => Expect2::Domain()->wildcard()->required(),
        'allow'   => Expect2::Cidr(multiple: true)->nullable(),   // one CIDR per line
        'ip'      => Expect2::Ip()->toLong(),
        'expires' => Expect2::date()->nullable(),                 // \DateTime, midnight
        'website' => Expect2::Url()->nullable(),
    ]);

    try {
        $data = new Processor()->process($schema, $this->request->post);
    } catch (ValidationException $exception) {
        $this->message(Message::fromSchema($exception));

        return $this->forward();
    }

    // $data is a stdClass with normalised values
}
```

With CSRF protection on, the `_csrf` field has already been removed from `$request->post`, so
`Expect::structure()` doesn't reject it as an unknown key.

## Types

| Factory | Accepts | Returns | Options |
|---|---|---|---|
| `Expect2::Numeric()` | an int, or a string of digits with an optional `-` | `int` | `required()`, `nullable()`, `min()`, `max()` |
| `Expect2::custom(Port::class)` | same, as a port | `int` | `required()`, `nullable()`, `min()`, `max()` |
| `Expect2::Domain()` | a hostname | `string` (trimmed) | `required()`, `wildcard()` (allows `.example.com`) |
| `Expect2::Ip()` | IPv4 / IPv6 | `string` | `fromString()` / `fromLong()` / `fromBin()`, `toString()` / `toLong()` / `toBin()`, `required()`, `nullable()` |
| `Expect2::Cidr(bool $multiple)` | `10.0.0.0/8`, `2001:db8::/32`; with `$multiple`, one per line | `string` / `list<string>` | `required()`, `nullable()` |
| `Expect2::Url()` | `http(s)://` URLs | `string` | `required()`, `nullable()`, `require_https()` |
| `Expect2::Json()` | a JSON string | decoded value (objects as `stdClass`) | `required()`, `nullable()` |
| `Expect2::dateTime($format = 'Y-m-d H:i:s')` | a date-time in `$format` | `\DateTime` | `required()`, `nullable()`, `default()`, `format($output)` (return a string), `setDate()`, `setTime()` |
| `Expect2::date($format = 'Y-m-d')` | a date | `\DateTime` at midnight | as above |
| `Expect2::time($format = 'H:i')` | a time | `\DateTime` | as above |
| `Expect2::Recaptcha()` | a reCAPTCHA response token | `true` | `secret($key)`, `ip($clientIp)`, `required()`, `nullable()`; verifies with Google |
| `Expect2::custom($schema)` | any `Nette\Schema\Schema`, a class name, or a callable returning one | | |

Dates are parsed strictly: `2023-02-29` or `25:00` fail rather than roll over.

## Error messages

`Message::fromSchema($exception)` (and `SchemaHelper::message($exception)` for the bare string)
picks the message for the first failing field from `SchemaHelper`'s table, falling back to
nette/schema's own text:

```php
use Nette\Schema\Message as SchemaMessage;
use Noirapi\Helpers\SchemaHelper;

SchemaHelper::set([
    'name'     => 'Please enter your name',                           // any error on "name"
    SchemaMessage::PatternMismatch => ['port' => 'Ports are numbers'], // one error code on "port"
    '_default' => 'Please check the form',
]);
```
