<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace Noirapi\Helpers;

use Exception;
use Noirapi\Config;
use Noirapi\Lib\Http\Headers;
use Noirapi\Lib\Http\IpRange;
use Noirapi\Lib\Http\TrustedProxies;
use Random\Randomizer;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use stdClass;

use function array_key_exists;
use function array_slice;
use function bin2hex;
use function chr;
use function count;
use function defined;
use function is_array;
use function is_object;
use function ord;
use function proc_close;
use function proc_open;
use function str_split;
use function strlen;
use function vsprintf;

/**
 * @psalm-api
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") a static grab-bag of small,
 * unrelated helpers is this class's intended purpose - splitting it would
 * just scatter one-line helpers across arbitrarily-named classes.
 */
final class Utils
{
    /**
     * @psalm-mutation-free
     * @psalm-suppress UnusedConstructor Private and intentionally never called - exists only to block instantiation of this static-factory class.
     */
    private function __construct()
    {
    }

    /**
     * @param int $len
     * @return string
     * @throws Exception
     */
    public static function random(int $len = 16): string
    {
        /** @noinspection SpellCheckingInspection */
        $chars = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $res = '';

        for ($i = 1; $i <= $len; $i++) {
            $res .= $chars[random_int(1, strlen($chars) - 1)];
        }

        return $res;
    }

    /**
     * @param bool $long
     * @return string
     * @throws Exception
     */
    public static function generateKey(bool $long = true): string
    {

        $algo = $long ? 'sha256' : 'sha1';

        return hash($algo, random_bytes(64));
    }

    /**
     * @param int $min
     * @param int $max
     * @return float
     * @throws Exception
     */
    public static function randomFloat(int $min = 0, int $max = PHP_INT_MAX): float
    {
        return random_int($min, $max - 1) / $max;
    }

    /**
     * @param int $min
     * @param int $max
     * @return int
     * @throws Exception
     */
    public static function randomInt(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return random_int($min, $max);
    }

    /**
     * @param string $cmd
     * @param array $env
     * @return void
     */
    public static function backgroundTask(string $cmd, array $env = []): void
    {
        proc_close(proc_open("$cmd &", [], $pipes, null, $env));
    }

    /**
     * @param mixed $object
     *
     * @return mixed|null
     *
     * @psalm-pure
     */
    public static function returnNull(mixed $object): mixed
    {

        if (empty($object)) {
            return null;
        }

        return $object;
    }

    /**
     * @return string
     * @throws Exception
     */
    public static function guidV4(): string
    {

        $data = random_bytes(16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36-character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param string|object $class
     * @param int|string $depth
     *
     * @return string
     *
     * @psalm-pure
     */
    public static function getClassName(string|object $class, int|string $depth = 1): string
    {

        $depth = (int) $depth;

        if (is_object($class)) {
            $class = $class::class;
        }

        $path = explode('\\', $class);

        if ($depth > count($path)) {
            $depth = count($path);
        }

        return implode('\\', array_slice($path, -$depth));
    }

    /**
     * @param array $array
     * @return array
     * @throws Exception
     */
    public static function array_shuffle(array $array): array // phpcs:ignore
    {

        return new Randomizer()->shuffleArray($array);
    }

    /**
     * @return bool
     *
     * @psalm-suppress MissingPureAnnotation Psalm and PHPStan disagree on the purity
     * of defined(); leaving unannotated satisfies both.
     */
    public static function is_tty(): bool // phpcs:ignore
    {
        return defined('STDOUT') && posix_isatty(STDOUT);
    }

    /**
     * @param string $string
     *
     * @return string
     *
     * @noinspection SpellCheckingInspection
     *
     * @psalm-pure
     */
    public static function mb_ucfirst(string $string): string // phpcs:ignore
    {
        return mb_strtoupper(mb_substr($string, 0, 1)) . mb_substr($string, 1);
    }

    /**
     * @param array|object $input
     * @param string|null $className
     * @param bool $remove_missing
     * @return object
     */
    public static function toObject(array|object $input, ?string $className = null, bool $remove_missing = true): object
    {
        if ($className === null) {
            $class = new stdClass();

            foreach ($input as $key => $value) {
                $class->$key = $value;
            }

            return $class;
        }

        /** @psalm-suppress InvalidStringClass */
        $class = new $className();

        try {
            $properties = new ReflectionClass($class)->getProperties(ReflectionProperty::IS_PUBLIC);
        } catch (ReflectionException) {
            return (object) $input;
        }

        foreach ($properties as $property) {
            $name = $property->getName();

            if (is_array($input)) {
                if (isset($input[$name])) {
                    $class->$name = $input[$name];
                } elseif ($remove_missing) {
                    unset($class->$name);
                }
            } elseif (isset($input->$name)) {
                $class->$name = $input->$name;
            } elseif ($remove_missing) {
                unset($class->$name);
            }
        }

        return $class;
    }

    /**
     * @param mixed $class
     * @param bool $public_only
     *
     * @return array
     *
     * @throws ReflectionException
     *
     * @psalm-suppress MissingPureAnnotation Psalm and PHPStan disagree on the purity
     * of instantiating ReflectionClass; leaving unannotated satisfies both.
     */
    public static function getClassProperties(mixed $class, bool $public_only = true): array
    {
        $result = [];

        $properties = new ReflectionClass($class)->getProperties($public_only ? ReflectionProperty::IS_PUBLIC : null);

        foreach ($properties as $property) {
            $result[] = $property->getName();
        }

        return $result;
    }

    /**
     * @param array $left
     * @param array $right
     *
     * @return array
     *
     * @noinspection TypeUnsafeComparisonInspection
     *
     * @psalm-pure
     */
    public static function array_diff_recursive(array $left, array $right): array // phpcs:ignore
    {
        $diff = [];

        foreach ($left as $k => $v) {
            if (array_key_exists($k, $right)) {
                if (is_array($v)) {
                    $rad = self::array_diff_recursive($v, $right[$k]);
                    if (count($rad) > 0) {
                        $diff[$k] = $rad;
                    }
                } elseif ($v != $right[$k]) {
                    $diff[$k] = $v;
                }
            } else {
                $diff[$k] = $v;
            }
        }

        return $diff;
    }

    /**
     * @param string $data
     *
     * @return string
     *
     * @psalm-pure
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param string $data
     *
     * @return string
     *
     * @psalm-pure
     */
    public static function base64UrlDecode(string $data): string
    {
        $result = base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '='), true);

        return $result !== false ? $result : throw new RuntimeException('base64UrlDecode failed');
    }

    /**
     * @param string $remote_address
     * @return bool
     * @deprecated use Config::isDev($request->clientIp)
     */
    public static function isDev(string $remote_address): bool
    {
        return Config::isDev(self::getRealIp($remote_address));
    }

    /**
     * The client address behind trusted proxies (config `trusted_proxies`; Cloudflare's
     * CF-Connecting-IP only with the `cloudflare` keyword there).
     *
     * @param string $remote_address
     * @return string
     * @deprecated use Request::$clientIp
     */
    public static function getRealIp(string $remote_address): string
    {
        return TrustedProxies::fromConfig()->clientIp($remote_address, Headers::fromServer($_SERVER));
    }

    /**
     * @param string $remote_address
     * @return bool
     */
    public static function isCloudFlare(string $remote_address): bool
    {
        return IpRange::matchesAny($remote_address, TrustedProxies::CLOUDFLARE);
    }
}
