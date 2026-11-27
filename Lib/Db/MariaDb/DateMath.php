<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * MariaDB's date arithmetic: DATE_ADD() and its relatives, TIMESTAMPDIFF(), DATEDIFF(), TIMEDIFF(), ADDTIME(). Registered by Functions; the PHP methods are private, SQL calls them by name
 * (docs/sqlite-mariadb.md).
 *
 * @psalm-api
 */
final class DateMath
{
    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     * @psalm-pure
     */
    public static function definitions(): array
    {
        return [
            'DATE_ADD' => [self::dateAdd(...), [3], true],
            'DATE_SUB' => [self::dateSub(...), [3], true],
            'ADDDATE' => [self::addDate(...), [2, 3], true],
            'SUBDATE' => [self::subDate(...), [2, 3], true],
            'TIMESTAMPADD' => [self::timestampAdd(...), [3], true],
            'TIMESTAMPDIFF' => [self::timestampDiff(...), [3], true],
            'DATEDIFF' => [self::dateDiff(...), [2], true],
            'TIMEDIFF' => [self::timeDiff(...), [2], true],
            'ADDTIME' => [self::addTime(...), [2], true],
            'SUBTIME' => [self::subTime(...), [2], true],
        ];
    }

    /** @psalm-pure */
    private static function dateAdd(mixed $value, mixed $amount, mixed $unit, int $sign = 1): ?string
    {
        $date = Temporal::parse($value);
        if ($date === null || $date->kind === Temporal::TIME || ! is_string($unit)) {
            return null;
        }

        return Interval::fromValue($amount, $unit)?->addTo($date, $sign)?->format();
    }

    /** @psalm-pure */
    private static function dateSub(mixed $value, mixed $amount, mixed $unit): ?string
    {
        return self::dateAdd($value, $amount, $unit, -1);
    }

    /** @psalm-pure */
    private static function addDate(mixed $value, mixed $amount, mixed $unit = 'DAY'): ?string
    {
        return self::dateAdd($value, $amount, $unit);
    }

    /** @psalm-pure */
    private static function subDate(mixed $value, mixed $amount, mixed $unit = 'DAY'): ?string
    {
        return self::dateAdd($value, $amount, $unit, -1);
    }

    /** @psalm-pure */
    private static function timestampAdd(mixed $unit, mixed $amount, mixed $value): ?string
    {
        return self::dateAdd($value, $amount, $unit);
    }

    /**
     * TIMESTAMPDIFF(unit, from, to): whole units from `from` to `to` (MariaDB's calc_time_diff() and its month
     * count, which compares the day and time of day before counting the last month).
     *
     * @psalm-pure
     */
    private static function timestampDiff(mixed $unit, mixed $from, mixed $until): ?int
    {
        $start = Values::date($from);
        $end = Values::date($until);
        if ($start === null || $end === null || ! is_string($unit)) {
            return null;
        }

        $unit = strtoupper($unit);
        $micros = $end->totalMicros() - $start->totalMicros();
        $perUnit = ['MICROSECOND' => 1, 'SECOND' => 1_000_000, 'MINUTE' => 60_000_000, 'HOUR' => 3_600_000_000,
            'DAY' => Temporal::MICROS_PER_DAY, 'WEEK' => 7 * Temporal::MICROS_PER_DAY];
        if (isset($perUnit[$unit])) {
            return intdiv($micros, $perUnit[$unit]);
        }

        $months = $micros < 0 ? -self::months($end, $start) : self::months($start, $end);

        return match ($unit) {
            'MONTH' => $months,
            'QUARTER' => intdiv($months, 3),
            'YEAR' => intdiv($months, 12),
            default => null,
        };
    }

    /** @psalm-pure */
    private static function dateDiff(mixed $first, mixed $second): ?int
    {
        $later = Values::date($first);
        $earlier = Values::date($second);

        return $later === null || $earlier === null ? null : $later->daynr() - $earlier->daynr();
    }

    /** @psalm-pure */
    private static function timeDiff(mixed $first, mixed $second): ?string
    {
        $minuend = Temporal::parse($first);
        $subtrahend = Temporal::parse($second);
        if (
            $minuend !== null && $subtrahend !== null
            && ($minuend->kind === Temporal::TIME) !== ($subtrahend->kind === Temporal::TIME)
        ) {
            // against a TIME, MariaDB reads the other side as a TIME too (a date-only string by its digits)
            $minuend = Temporal::forTime($first);
            $subtrahend = Temporal::forTime($second);
        }
        if ($minuend === null || $subtrahend === null) {
            return null;
        }

        return Values::duration(
            $minuend->totalMicros() - $subtrahend->totalMicros(),
            max($minuend->decimals, $subtrahend->decimals),
        );
    }

    /** @psalm-pure */
    private static function addTime(mixed $value, mixed $time, int $sign = 1): ?string
    {
        $base = Temporal::parse($value);
        $add = Temporal::parseTime($time);
        if ($base === null || $add === null || $base->kind === Temporal::DATE) {
            return null;
        }
        $decimals = max($base->decimals, $add->decimals);
        $total = $base->totalMicros() + $sign * $add->micros;
        if ($base->kind === Temporal::TIME) {
            return Values::duration($total, $decimals);
        }

        $daynr = intdiv($total, Temporal::MICROS_PER_DAY);

        return Temporal::fromDaynr($daynr, $total - $daynr * Temporal::MICROS_PER_DAY, Temporal::DATETIME, $decimals)
            ?->format();
    }

    /** @psalm-pure */
    private static function subTime(mixed $value, mixed $time): ?string
    {
        return self::addTime($value, $time, -1);
    }

    /**
     * Whole months from $start to $end ($start <= $end), MariaDB's way.
     *
     * @psalm-pure
     */
    private static function months(Temporal $start, Temporal $end): int
    {
        $earlierInMonth = $end->day < $start->day || ($end->day === $start->day && $end->micros < $start->micros);
        $months = ($end->year - $start->year) * 12 + ($end->month - $start->month);

        return $earlierInMonth ? $months - 1 : $months;
    }
}
