<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * MariaDB's clock functions: NOW(), CURDATE(), CURTIME(), the UTC ones and Unix time, in PHP's time zone (MariaDB's
 * are the server's). Tests fix the clock with setClock(). Registered by Functions; the PHP methods are private, SQL calls them by name
 * (docs/sqlite-mariadb.md).
 *
 * @psalm-api
 */
final class ClockFunctions
{
    /** @var (Closure(): DateTimeImmutable)|null a fixed clock for tests */
    private static ?Closure $clock = null;

    /**
     * Fixes "now" for tests; null goes back to the real clock.
     *
     * @param (Closure(): DateTimeImmutable)|null $clock
     * @psalm-impure
     */
    public static function setClock(?Closure $clock): void
    {
        self::$clock = $clock;
    }

    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     * @psalm-pure
     */
    public static function definitions(): array
    {
        return [
            'CURDATE' => [self::curdate(...), [0], false],
            'CURTIME' => [self::curtime(...), [0, 1], false],
            'UTC_TIMESTAMP' => [self::utcTimestamp(...), [0, 1], false],
            'UTC_DATE' => [self::utcDate(...), [0], false],
            'UTC_TIME' => [self::utcTime(...), [0, 1], false],
            'UNIX_TIMESTAMP' => [self::unixTimestamp(...), [0, 1], false],
            'FROM_UNIXTIME' => [self::fromUnixtime(...), [1, 2], true],
            'NOW' => [self::now(...), [0, 1], false],
            'SYSDATE' => [self::now(...), [0, 1], false],
            'LOCALTIME' => [self::now(...), [0, 1], false],
            'LOCALTIMESTAMP' => [self::now(...), [0, 1], false],
        ];
    }

    /** @psalm-impure */
    private static function now(mixed $fsp = 0): string
    {
        $now = self::clock();

        return $now->format('Y-m-d H:i:s') . self::fsp($now, $fsp);
    }

    /** @psalm-impure */
    private static function curdate(): string
    {
        return self::clock()->format('Y-m-d');
    }

    /** @psalm-impure */
    private static function curtime(mixed $fsp = 0): string
    {
        $now = self::clock();

        return $now->format('H:i:s') . self::fsp($now, $fsp);
    }

    /** @psalm-impure */
    private static function utcTimestamp(mixed $fsp = 0): string
    {
        $utc = self::clock()->setTimezone(new DateTimeZone('UTC'));

        return $utc->format('Y-m-d H:i:s') . self::fsp($utc, $fsp);
    }

    /** @psalm-impure */
    private static function utcDate(): string
    {
        return self::clock()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }

    /** @psalm-impure */
    private static function utcTime(mixed $fsp = 0): string
    {
        $utc = self::clock()->setTimezone(new DateTimeZone('UTC'));

        return $utc->format('H:i:s') . self::fsp($utc, $fsp);
    }

    /** @psalm-impure */
    private static function unixTimestamp(mixed $value = null): int|float|null
    {
        if (func_num_args() === 0) {
            return self::clock()->getTimestamp();
        }
        $date = Temporal::parse($value);
        if ($date === null || $date->kind === Temporal::TIME) {
            return null;
        }

        // the whole local date and time at once: adding seconds to midnight would be wrong on a DST change day
        $local = new DateTimeImmutable($date->withKind(Temporal::DATETIME, 0)->format());
        $seconds = $local->getTimestamp();
        if ($seconds <= 0) {
            // MariaDB 11: the epoch itself and anything before it are out of range
            return null;
        }

        return $date->decimals > 0 ? round((float) $seconds + (float) $date->microsecond / 1e6, $date->decimals) : $seconds;
    }

    /** @psalm-impure */
    private static function fromUnixtime(mixed $timestamp, mixed $format = null): ?string
    {
        if (! is_numeric($timestamp) || $timestamp <= 0) {
            return null;
        }
        $dot = strpos((string) $timestamp, '.');
        $decimals = $dot === false ? 0 : Values::decimals(substr((string) $timestamp, $dot + 1));
        $whole = (int) floor((float) $timestamp);
        $micros = (int) round(((float) $timestamp - (float) $whole) * 1e6);
        $local = new DateTimeImmutable('@' . $whole)->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $text = $local->format('Y-m-d H:i:s') . ($decimals > 0 ? '.' . substr(sprintf('%06d', $micros), 0, $decimals) : '');

        $date = Temporal::parse($text);

        return $format === null || $date === null ? $text : DateFormat::format($date, (string) $format);
    }

    /**
     * `.ff…` with $fsp digits (0–6) of the clock's fraction.
     *
     * @psalm-pure
     */
    private static function fsp(DateTimeImmutable $time, mixed $fsp): string
    {
        $digits = is_numeric($fsp) ? max(0, min(6, (int) $fsp)) : 0;

        return $digits === 0 ? '' : '.' . substr($time->format('u'), 0, $digits);
    }

    /** @psalm-impure */
    private static function clock(): DateTimeImmutable
    {
        return self::$clock !== null ? (self::$clock)() : new DateTimeImmutable();
    }
}
