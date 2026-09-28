<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use Closure;
use Noirapi\Config;
use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Noirapi\Lib\Http\IpRange;
use Noirapi\Lib\Http\TrustedProxies;
use Noirapi\Lib\Security\Signer;
use Override;
use Throwable;

use function is_array;
use function is_string;

/**
 * config:check [name]: loads app/config/<name>.neon (default: $CONFIG, else "default"), runs
 * App\Lib\Config::validate() when the app has it, and checks the settings the framework reads.
 *
 * @psalm-api
 */
final class ConfigCheck implements Command
{
    /**
     * @psalm-pure
     */
    #[Override]
    public function name(): string
    {
        return 'config:check';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function description(): string
    {
        return 'Validate app/config/<name>.neon and the directories the framework writes to';
    }

    #[Override]
    public function run(array $args, Output $output): int
    {
        $name = $args[0] ?? (getenv('CONFIG') ?: 'default');

        try {
            Config::init($name);
        } catch (Throwable $exception) {
            $output->error("FAIL  $name.neon: " . $exception->getMessage());

            return 1;
        }

        $output->line("OK    $name.neon loaded");
        $failures = 0;

        foreach (self::checks() as $label => $check) {
            $problem = $check();
            if ($problem === null) {
                $output->line("OK    $label");
            } else {
                $output->error("FAIL  $label: $problem");
                $failures++;
            }
        }

        return $failures === 0 ? 0 : 1;
    }

    /**
     * @return array<string, Closure(): ?string> a problem description, or null when fine
     */
    private static function checks(): array
    {
        return [
            'temp/ is writable' => static fn (): ?string => is_writable(Config::getTemp()) ? null : Config::getTemp() . ' is missing or not writable', //phpcs:ignore
            'logs/ is writable' => static fn (): ?string => is_writable(Config::getLogs()) ? null : Config::getLogs() . ' is missing or not writable', //phpcs:ignore
            'trusted_proxies' => self::trustedProxies(...),
            'dev_ips' => static fn (): ?string => self::ranges(Config::get('dev_ips'), 'dev_ips'),
            'app_secret_key' => self::secret(...),
            'rate_limiter' => self::rateLimiter(...),
        ];
    }

    private static function trustedProxies(): ?string
    {
        $list = Config::get('trusted_proxies');
        if (is_array($list)) {
            $list = array_filter($list, static fn (mixed $entry): bool => $entry !== TrustedProxies::CLOUDFLARE_KEYWORD);
        }

        return self::ranges($list, 'trusted_proxies');
    }

    /**
     * @psalm-pure
     */
    private static function ranges(mixed $list, string $key): ?string
    {
        if ($list === null) {
            return null;
        }

        if (! is_array($list)) {
            return "$key must be a list";
        }

        foreach ($list as $entry) {
            $address = is_string($entry) ? explode('/', $entry)[0] : '';
            if (! is_string($entry) || ! IpRange::contains($address, $entry)) {
                return "not an IP address or CIDR range: " . var_export($entry, true);
            }
        }

        return null;
    }

    /**
     * @psalm-external-mutation-free
     */
    private static function secret(): ?string
    {
        $needed = Config::get('auth.magic_link.enabled') === true;
        if (Config::get('app_secret_key') === null && ! $needed) {
            return null;
        }

        try {
            Signer::fromConfig();
        } catch (Throwable $exception) {
            return $exception->getMessage() . ' (noirapi key:generate prints one)';
        }

        return null;
    }

    /**
     * @psalm-external-mutation-free
     */
    private static function rateLimiter(): ?string
    {
        $storage = Config::get('rate_limiter.storage');

        return match (true) {
            $storage === 'apcu' && ! extension_loaded('apcu') => 'storage apcu needs the apcu extension',
            $storage === 'memcached' && ! extension_loaded('memcached') => 'storage memcached needs the memcached extension', //phpcs:ignore
            $storage === 'pdo' && Config::get('db') === null => 'storage pdo needs a db connection',
            default => null,
        };
    }
}
