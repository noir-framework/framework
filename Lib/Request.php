<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use BackedEnum;
use JsonException;
use Noirapi\Exceptions\RestException;
use Noirapi\Lib\Http\Headers;
use Noirapi\Lib\Http\TrustedProxies;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * @psalm-suppress MissingConstructor
 * @psalm-api
 * @SuppressWarnings("PHPMD.TooManyFields") this is a plain value object
 * representing the full incoming HTTP request; each field is a distinct,
 * independently-needed piece of request state (headers, method, uri, etc.).
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the typed input accessors
 * (int(), string(), enum(), ...) are the request's public API.
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") same rationale: many small accessors.
 */
class Request
{
    public array $headers;
    public string $method;
    /** Request URI as sent (query string included), control characters removed; not URL-decoded. */
    public string $uri;
    public string $url_no_lang;
    public array $get;
    public array $post;
    public array $files;
    public array $cookies;
    public string $controller;
    public string $function;
    public array $route;
    public string $role;
    public bool $https;
    public bool $ajax;
    /**
     * @noinspection PhpMissingFieldTypeInspection
     * @psalm-suppress MissingPropertyType
     */
    public $swoole;
    public string $hostname;
    public ?string $language = null;
    /** The client's address; see TrustedProxies for when forwarding headers are believed. */
    public string $clientIp = '';
    /** Raw request body. Read from php://input on first use unless set (Swoole sets it). */
    public ?string $rawBody = null;
    private ?array $json = null;

    /**
     * @param array $server
     * @param array $get
     * @param array $post
     * @param array $files
     * @param array $cookies
     * @param TrustedProxies|null $proxies defaults to the `trusted_proxies` config
     * @return self
     */
    public static function fromGlobals(
        array $server,
        array $get,
        array $post,
        array $files,
        array $cookies,
        ?TrustedProxies $proxies = null,
    ): self {
        $self = self::transform($server, $get, $post, $files, $cookies);
        $self->headers = Headers::fromServer($server);
        self::resolveNetwork($self, $server, $proxies ?? TrustedProxies::fromConfig());

        return $self;
    }

    /**
     * @param array $server Swoole's $request->server, with the headers under 'headers'
     * @param array $get
     * @param array $post
     * @param array $files
     * @param array $cookies
     * @param TrustedProxies|null $proxies
     * @return self
     */
    public static function fromSwoole(
        array $server,
        array $get,
        array $post,
        array $files,
        array $cookies,
        ?TrustedProxies $proxies = null,
    ): self {
        $headers = is_array($server['headers'] ?? null) ? $server['headers'] : [];
        unset($server['headers']);
        $server = self::swooleUpperCase($server);

        $self = self::transform($server, $get, $post, $files, $cookies);
        $self->headers = self::swooleUpperCase($headers);
        self::resolveNetwork($self, $server, $proxies ?? TrustedProxies::fromConfig());

        return $self;
    }

    /**
     * @param array $headers
     * @return array
     *
     * @psalm-pure
     */
    public static function swooleUpperCase(array $headers): array
    {
        $res = [];
        foreach ($headers as $key => $value) {
            $res[str_replace('-', '_', strtoupper((string) $key))] = $value;
        }

        return $res;
    }

    /**
     * @param array $server
     * @return bool
     *
     * @psalm-pure
     */
    private static function isAjax(array $server): bool
    {
        if (isset($server['HTTP_X_REQUESTED_WITH']) && strtolower($server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') { //phpcs:ignore
            return true;
        }

        if (isset($server['HTTP_SEC_FETCH_MODE']) && strtolower($server['HTTP_SEC_FETCH_MODE']) !== 'navigate') {
            return true;
        }

        return false;
    }

    /**
     * @param bool $https
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @psalm-external-mutation-free
     */
    public function setHttps(bool $https): static
    {
        $this->https = $https;

        return $this;
    }

    /**
     * @param bool $ajax
     *
     * @return $this
     *
     * @noinspection PhpUnused
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @psalm-external-mutation-free
     */
    public function setAjax(bool $ajax): static
    {
        $this->ajax = $ajax;

        return $this;
    }

    /**
     * @param array $server
     * @param array $get
     * @param array $post
     * @param array $files
     * @param array $cookies
     * @return self
     */
    private static function transform(array $server, array $get, array $post, array $files, array $cookies): self
    {
        $self = new self();

        $self->method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        // Kept encoded: Route decodes the path once for dispatching.
        $self->uri = preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($server['REQUEST_URI'] ?? '/')) ?? '';
        $self->get = $get;
        $self->post = $post;
        $self->files = $files;
        $self->cookies = $cookies;

        $self->ajax = self::isAjax($server);

        return $self;
    }

    /**
     * Client address, scheme and host, honouring forwarding headers from trusted proxies only.
     *
     * @param self $request
     * @param array $server
     * @param TrustedProxies $proxies
     * @return void
     */
    private static function resolveNetwork(self $request, array $server, TrustedProxies $proxies): void
    {
        $remoteAddr = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';

        $request->clientIp = $proxies->clientIp($remoteAddr, $request->headers);
        $request->https = $proxies->isSecure($server, $request->headers);
        $request->hostname = $proxies->host($server, $request->headers);
    }

    /**
     * @param string $name
     *
     * @return string|null
     *
     * @noinspection PhpUnused
     *
     * @psalm-mutation-free
     */
    public function getHeader(string $name): ?string
    {
        return Headers::find($this->headers, $name);
    }

    /**
     * @psalm-mutation-free
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return Headers::find($this->headers, $name) ?? $default;
    }

    /**
     * @param string $name
     *
     * @return string|null
     *
     * @noinspection PhpUnused
     *
     * @psalm-mutation-free
     */
    public function getCookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @psalm-mutation-free
     */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * @psalm-mutation-free
     */
    public function isSecure(): bool
    {
        return $this->https;
    }

    /**
     * scheme://host of the current request, e.g. for absolute links in emails.
     *
     * @psalm-mutation-free
     */
    public function baseUrl(): string
    {
        return ($this->https ? 'https' : 'http') . '://' . $this->hostname;
    }

    /**
     * Whether the body is JSON (Content-Type application/json or *+json).
     *
     * @psalm-mutation-free
     */
    public function isJson(): bool
    {
        $type = strtolower((string) $this->getHeader('Content-Type'));

        return str_contains($type, '/json') || str_contains($type, '+json');
    }

    /**
     * Whether the Accept header lists $type (or its type/* wildcard) explicitly.
     *
     * @psalm-mutation-free
     */
    public function accepts(string $type): bool
    {
        $type = strtolower($type);
        $wildcard = strtok($type, '/') . '/*';

        foreach (explode(',', strtolower((string) $this->getHeader('Accept'))) as $range) {
            $range = trim(explode(';', $range)[0]);
            if ($range === $type || $range === $wildcard) {
                return true;
            }
        }

        return false;
    }

    /**
     * @psalm-mutation-free
     */
    public function wantsJson(): bool
    {
        return $this->accepts('application/json');
    }

    public function body(): string
    {
        return $this->rawBody ??= (string) file_get_contents('php://input');
    }

    /**
     * The decoded JSON body ([] when empty or not an object/array).
     *
     * @throws RestException 400 when the body is not valid JSON
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        $body = trim($this->body());
        if ($body === '') {
            return $this->json = [];
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RestException('Invalid JSON body', 400);
        }

        return $this->json = is_array($data) ? $data : [];
    }

    /**
     * A request value: the JSON body (for JSON requests), then POST, then the query string.
     *
     * @throws RestException on a JSON request with an invalid body
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if ($this->isJson()) {
            $json = $this->json();
            if (array_key_exists($key, $json)) {
                return $json[$key];
            }
        }

        return $this->post[$key] ?? $this->get[$key] ?? $default;
    }

    /**
     * @throws RestException
     */
    public function has(string $key): bool
    {
        return $this->input($key) !== null;
    }

    /**
     * @psalm-mutation-free
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /**
     * @throws RestException
     */
    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->input($key);

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => $default,
        };
    }

    /**
     * @throws RestException
     */
    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);
        if (is_int($value)) {
            return $value;
        }

        $int = is_string($value) ? filter_var(trim($value), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) : null;

        return $int ?? $default;
    }

    /**
     * @throws RestException
     */
    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->input($key);
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $float = is_string($value) ? filter_var(trim($value), FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) : null;

        return $float ?? $default;
    }

    /**
     * true for 1/true/on/yes, false for 0/false/off/no/"", else $default.
     *
     * @throws RestException
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") the default value of a boolean input.
     */
    public function bool(string $key, ?bool $default = null): ?bool
    {
        $value = $this->input($key);
        if (is_bool($value)) {
            return $value;
        }

        $bool = is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;

        return $bool ?? $default;
    }

    /**
     * @template T of BackedEnum
     * @param class-string<T> $enum
     * @param T|null $default
     * @return T|null
     * @throws RestException
     */
    public function enum(string $key, string $enum, ?BackedEnum $default = null): ?BackedEnum
    {
        $value = $this->input($key);
        if (! is_string($value) && ! is_int($value)) {
            return $default;
        }

        foreach ($enum::cases() as $case) {
            if ((string) $case->value === (string) $value) {
                return $case;
            }
        }

        return $default;
    }

    /**
     * @throws RestException
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->input($key);

        return is_array($value) ? $value : $default;
    }
}
