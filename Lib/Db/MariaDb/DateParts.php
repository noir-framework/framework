<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * The parts of a date or time as MariaDB gives them: YEAR() … MICROSECOND(), weekdays, weeks, names, LAST_DAY(),
 * EXTRACT(). Registered by Functions; the PHP methods are private, SQL calls them by name
 * (docs/sqlite-mariadb.md).
 *
 * @psalm-api
 */
final class DateParts
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
            'EXTRACT' => [self::extract(...), [2], true],
            'YEAR' => [self::year(...), [1], true],
            'QUARTER' => [self::quarter(...), [1], true],
            'MONTH' => [self::month(...), [1], true],
            'DAY' => [self::day(...), [1], true],
            'DAYOFMONTH' => [self::day(...), [1], true],
            'HOUR' => [self::hour(...), [1], true],
            'MINUTE' => [self::minute(...), [1], true],
            'SECOND' => [self::second(...), [1], true],
            'MICROSECOND' => [self::microsecond(...), [1], true],
            'DAYOFWEEK' => [self::dayOfWeek(...), [1], true],
            'WEEKDAY' => [self::weekday(...), [1], true],
            'DAYOFYEAR' => [self::dayOfYear(...), [1], true],
            'WEEK' => [self::week(...), [1, 2], true],
            'WEEKOFYEAR' => [self::weekOfYear(...), [1], true],
            'YEARWEEK' => [self::yearWeek(...), [1, 2], true],
            'MONTHNAME' => [self::monthName(...), [1], true],
            'DAYNAME' => [self::dayName(...), [1], true],
            'LAST_DAY' => [self::lastDay(...), [1], true],
        ];
    }

    /** @psalm-pure */
    private static function year(mixed $value): ?int
    {
        return Values::date($value)?->year;
    }

    /** @psalm-pure */
    private static function quarter(mixed $value): ?int
    {
        $date = Values::date($value);

        return $date === null ? null : intdiv($date->month + 2, 3);
    }

    /** @psalm-pure */
    private static function month(mixed $value): ?int
    {
        return Values::date($value)?->month;
    }

    /** @psalm-pure */
    private static function day(mixed $value): ?int
    {
        return Values::date($value)?->day;
    }

    /** @psalm-pure */
    private static function hour(mixed $value): ?int
    {
        return self::timePart($value)?->hour;
    }

    /** @psalm-pure */
    private static function minute(mixed $value): ?int
    {
        return self::timePart($value)?->minute;
    }

    /** @psalm-pure */
    private static function second(mixed $value): ?int
    {
        return self::timePart($value)?->second;
    }

    /** @psalm-pure */
    private static function microsecond(mixed $value): ?int
    {
        return self::timePart($value)?->microsecond;
    }

    /** @psalm-pure */
    private static function dayOfWeek(mixed $value): ?int
    {
        $date = Values::date($value);

        return $date === null ? null : Calendar::weekday($date->daynr(), true) + 1;
    }

    /** @psalm-pure */
    private static function weekday(mixed $value): ?int
    {
        $date = Values::date($value);

        return $date === null ? null : Calendar::weekday($date->daynr(), false);
    }

    /** @psalm-pure */
    private static function dayOfYear(mixed $value): ?int
    {
        $date = Values::date($value);

        return $date === null ? null : $date->daynr() - Calendar::daynr($date->year, 1, 1) + 1;
    }

    /** @psalm-pure */
    private static function week(mixed $value, mixed $mode = 0): ?int
    {
        $date = Values::date($value);

        return $date === null || ! is_numeric($mode)
            ? null
            : Calendar::week($date->year, $date->month, $date->day, Calendar::weekMode((int) $mode))[0];
    }

    /** @psalm-pure */
    private static function weekOfYear(mixed $value): ?int
    {
        return self::week($value, 3);
    }

    /** @psalm-pure */
    private static function yearWeek(mixed $value, mixed $mode = 0): ?int
    {
        $date = Values::date($value);
        if ($date === null || ! is_numeric($mode)) {
            return null;
        }
        $behaviour = Calendar::weekMode((int) $mode) | Calendar::WEEK_YEAR;
        [$week, $year] = Calendar::week($date->year, $date->month, $date->day, $behaviour);

        return $year * 100 + $week;
    }

    /** @psalm-pure */
    private static function monthName(mixed $value): ?string
    {
        $date = Values::date($value);

        return $date === null ? null : DateFormat::monthName($date->month);
    }

    /** @psalm-pure */
    private static function dayName(mixed $value): ?string
    {
        $date = Values::date($value);

        return $date === null ? null : DateFormat::dayName(Calendar::weekday($date->daynr(), false));
    }

    /** @psalm-pure */
    private static function lastDay(mixed $value): ?string
    {
        $date = Values::date($value);
        if ($date === null) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $date->year, $date->month, Calendar::daysInMonth($date->year, $date->month));
    }

    /** @psalm-pure */
    private static function extract(mixed $unit, mixed $value): ?int
    {
        $value = Temporal::parse($value);
        if ($value === null || ! is_string($unit)) {
            return null;
        }
        $hms = ($value->hour * 100 + $value->minute) * 100 + $value->second;
        $dhms = $value->day * 1_000_000 + $hms;
        $parts = [
            'YEAR' => $value->year,
            'QUARTER' => intdiv($value->month + 2, 3),
            'MONTH' => $value->month,
            'WEEK' => Calendar::week($value->year, $value->month, $value->day, Calendar::weekMode(0))[0],
            'DAY' => $value->day,
            'HOUR' => $value->hour,
            'MINUTE' => $value->minute,
            'SECOND' => $value->second,
            'MICROSECOND' => $value->microsecond,
            'YEAR_MONTH' => $value->year * 100 + $value->month,
            'DAY_HOUR' => $value->day * 100 + $value->hour,
            'DAY_MINUTE' => intdiv($dhms, 100),
            'DAY_SECOND' => $dhms,
            'DAY_MICROSECOND' => $dhms * 1_000_000 + $value->microsecond,
            'HOUR_MINUTE' => intdiv($hms, 100),
            'HOUR_SECOND' => $hms,
            'HOUR_MICROSECOND' => $hms * 1_000_000 + $value->microsecond,
            'MINUTE_SECOND' => $hms % 10_000,
            'MINUTE_MICROSECOND' => $hms % 10_000 * 1_000_000 + $value->microsecond,
            'SECOND_MICROSECOND' => $value->second * 1_000_000 + $value->microsecond,
        ];

        return $parts[strtoupper($unit)] ?? null;
    }

    /**
     * HOUR() & co.: a DATE has none (0), a DATETIME its time of day, anything else as MariaDB reads a TIME.
     *
     * @psalm-pure
     */
    private static function timePart(mixed $value): ?Temporal
    {
        $date = Temporal::parse($value);
        if ($date !== null && $date->kind === Temporal::DATETIME) {
            return $date;
        }

        return Temporal::forTime($value);
    }
}
