<?php

declare(strict_types=1);

namespace Noirapi\Lib\Http;

/**
 * Request header helpers shared by Request and TrustedProxies. Headers are keyed "Content-Type"
 * when built from $_SERVER and "CONTENT_TYPE" when built from Swoole, so lookups compare names
 * in one normalised form.
 *
 * @psalm-api
 */
final class Headers
{
    /**
     * Request headers from a $_SERVER-style array: every HTTP_* key, plus CONTENT_TYPE and
     * CONTENT_LENGTH, which PHP stores without the prefix.
     *
     * @param array<array-key, mixed> $server
     * @return array<string, string>
     *
     * @psalm-pure
     */
    public static function fromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $name => $value) {
            $name = (string) $name;
            if (str_starts_with($name, 'HTTP_')) {
                $name = substr($name, 5);
            } elseif ($name !== 'CONTENT_TYPE' && $name !== 'CONTENT_LENGTH') {
                continue;
            }

            if (is_scalar($value)) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
                $headers[$key] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @psalm-pure
     */
    public static function find(array $headers, string $name): ?string
    {
        $wanted = self::normalize($name);

        foreach ($headers as $key => $value) {
            if (self::normalize((string) $key) === $wanted) {
                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * @psalm-pure
     */
    public static function normalize(string $name): string
    {
        return str_replace('-', '_', strtoupper($name));
    }
}
