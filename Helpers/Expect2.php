<?php

/**
 * @noinspection PhpUnused
 * @noinspection PhpUnusedAliasInspection
 */

declare(strict_types=1);

namespace Noirapi\Helpers;

use DateTimeZone;
use Exception;
use Nette\Schema\Schema;
use Noirapi\Helpers\Schema\Cidr;
use Noirapi\Helpers\Schema\Date;
use Noirapi\Helpers\Schema\DateTime;
use Noirapi\Helpers\Schema\Domain;
use Noirapi\Helpers\Schema\Ip;
use Noirapi\Helpers\Schema\Json;
use Noirapi\Helpers\Schema\Numeric;
use Noirapi\Helpers\Schema\Recaptcha;
use Noirapi\Helpers\Schema\Time;
use Noirapi\Helpers\Schema\Url;
use RuntimeException;

use function is_callable;
use function is_string;

/** @psalm-api  */
final class Expect2
{
    private function __construct() {}

    /**
     * @param string $format
     * @param DateTimeZone|null $timeZone
     * @return DateTime
     * @throws Exception
     */
    public static function dateTime(string $format = 'Y-m-d H:i:s', ?DateTimeZone $timeZone = null): DateTime
    {
        return new DateTime($format, $timeZone);
    }

    /**
     * @param string $format
     * @param DateTimeZone|null $timeZone
     * @return Date
     * @throws Exception
     */
    public static function date(string $format = 'Y-m-d', ?DateTimeZone $timeZone = null): Date
    {
        return new Date($format, $timeZone);
    }

    /**
     * @param string $format
     * @param DateTimeZone|null $timeZone
     * @return DateTime
     * @throws Exception
     */
    public static function time(string $format = 'H:i', ?DateTimeZone $timeZone = null): DateTime
    {
        return new Time($format, $timeZone);
    }

    /**
     * @return Ip
     */
    public static function Ip(): Ip // phpcs:ignore
    {
        return new Ip();
    }

    /**
     * @return Domain
     */
    public static function Domain(): Domain // phpcs:ignore
    {
        return new Domain();
    }

    public static function Numeric(): Numeric // phpcs:ignore
    {
        return new Numeric();
    }

    /**
     * @return Url
     */
    public static function Url(): Url // phpcs:ignore
    {
        return new Url();
    }

    /**
     * @return Json
     */
    public static function Json(): Json // phpcs:ignore
    {
        return new Json();
    }

    /**
     * @return Recaptcha
     */
    public static function Recaptcha(): Recaptcha // phpcs:ignore
    {
        return new Recaptcha();
    }

    /**
     * @param bool $multiple
     * @return Cidr
     */
    public static function Cidr(bool $multiple = false): Cidr // phpcs:ignore
    {
        return new Cidr($multiple);
    }

    /**
     * @param callable|string|Schema $callable
     * @param mixed ...$params
     * @return Schema|null
     */
    public static function custom(callable|string|Schema $callable, ...$params): ?Schema
    {

        if (is_callable($callable)) {
            return $callable($params);
        }

        if (is_string($callable) && class_exists($callable)) {
            $instance = new $callable($params);
            if (! $instance instanceof Schema) {
                throw new RuntimeException($callable . ' does not implements Schema interface');
            }
        }

        if ($callable instanceof Schema) {
            return $callable;
        }

        throw new RuntimeException('Called class must implement schema interface');
    }
}
