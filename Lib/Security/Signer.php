<?php

declare(strict_types=1);

namespace Noirapi\Lib\Security;

use Noirapi\Config;
use Noirapi\Exceptions\ConfigException;
use Noirapi\Helpers\Utils;

use function is_string;
use function strlen;

/**
 * HMAC-SHA256 signatures keyed with the `app_secret_key` config: a random secret of at least
 * 32 bytes, given as base64 (e.g. `php -r 'echo base64_encode(random_bytes(32));'`) or raw.
 *
 * Every signature is bound to a purpose, so one made for magic links can't be replayed as a
 * signed URL.
 *
 * @psalm-api
 */
final readonly class Signer
{
    private function __construct(private string $key)
    {
    }

    /**
     * @throws ConfigException without a usable app_secret_key
     */
    public static function fromConfig(): self
    {
        return self::fromSecret(Config::get('app_secret_key'));
    }

    /**
     * @throws ConfigException when $secret is not a string of at least 32 bytes (decoded)
     */
    public static function fromSecret(mixed $secret): self
    {
        if (! is_string($secret) || $secret === '') {
            throw new ConfigException('app_secret_key is not configured');
        }

        $decoded = base64_decode($secret, true);
        $key = is_string($decoded) && strlen($decoded) >= 32 ? $decoded : $secret;

        if (strlen($key) < 32) {
            throw new ConfigException('app_secret_key must be at least 32 bytes');
        }

        return new self($key);
    }

    /**
     * base64url HMAC-SHA256 of $data for $purpose.
     *
     * @psalm-mutation-free
     */
    public function sign(string $purpose, string $data): string
    {
        return Utils::base64UrlEncode(hash_hmac('sha256', $purpose . "\0" . $data, $this->key, true));
    }

    /**
     * @psalm-mutation-free
     */
    public function verify(string $purpose, string $data, string $signature): bool
    {
        return hash_equals($this->sign($purpose, $data), $signature);
    }
}
