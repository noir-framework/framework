<?php

declare(strict_types=1);

namespace Noirapi\Lib;

use Nette\SmartObject;

/**
 * @psalm-suppress MissingConstructor
 * @psalm-api
 */
class Request
{
    use SmartObject;

    public array $headers;
    public string $method;
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

    /**
     * @param array $server
     * @param array $get
     * @param array $post
     * @param array $files
     * @param array $cookies
     * @return self
     */
    public static function fromGlobals(array $server, array $get, array $post, array $files, array $cookies): self
    {
        $self = self::transform($server, $get, $post, $files, $cookies);
        $self->headers = self::globalsRequestHeaders($server);

        return $self;
    }

    /**
     * @param array $server
     * @param array $get
     * @param array $post
     * @param array $files
     * @param array $cookies
     * @return self
     */
    public static function fromSwoole(array $server, array $get, array $post, array $files, array $cookies): self
    {
        $self = self::transform($server, $get, $post, $files, $cookies);
        $self->headers = self::swooleUpperCase($server['headers']);

        return $self;
    }

    /**
     * @param array $server
     * @return array
     */
    private static function globalsRequestHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                //get Header key w/o HTTP_
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array $headers
     * @return array
     */
    public static function swooleUpperCase(array $headers): array
    {
        $res = [];
        array_walk($headers, static function (string $value, string $key) use (&$res) {
            $key = str_replace('-', '_', strtoupper($key));
            $res[$key] = $value;
        });

        return $res;
    }

    /**
     * @param array $server
     * @return bool
     */
    private static function isHttps(array $server): bool
    {
        if (isset($server['HTTPS'])) {
            if (strtolower($server['HTTPS']) === 'on') {
                return true;
            }

            if ($server['HTTPS'] === '1') {
                return true;
            }
        } elseif (isset($server['SERVER_PORT']) && ($server['SERVER_PORT'] === 443)) {
            return true;
        }

        return false;
    }

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
     * @return $this
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function setHttps(bool $https): static
    {
        $this->https = $https;

        return $this;
    }

    /**
     * @param bool $ajax
     * @return $this
     * @noinspection PhpUnused
     * @psalm-suppress PossiblyUnusedMethod
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

        $self->hostname = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '';
        $self->method = $server['REQUEST_METHOD'];
        $self->uri = preg_replace('/[\x00-\x1F\x7F]/', '', urldecode($server['REQUEST_URI'])) ?? '';
        $self->get = $get;
        $self->post = $post;
        $self->files = $files;
        $self->cookies = $cookies;

        $self->https = self::isHttps($server);
        $self->ajax = self::isAjax($server);

        return $self;
    }

    /**
     * @param string $name
     * @return string|null
     * @noinspection PhpUnused
     */
    public function getHeader(string $name): ?string
    {
        $name = str_replace('-', '_', strtoupper($name));
        return $this->headers[$name] ?? null;
    }

    /**
     * @param string $name
     * @return string|null
     * @noinspection PhpUnused
     */
    public function getCookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }
}
