<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * MariaDB's conversions: DATE_FORMAT(), STR_TO_DATE(), MAKEDATE(), MAKETIME(), days, seconds and periods
 * (PERIOD_ADD(), PERIOD_DIFF()). Registered by Functions; the PHP methods are private, SQL calls them by name
 * (docs/sqlite-mariadb.md).
 *
 * @psalm-api
 */
final class DateConversions
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
            'DATE_FORMAT' => [self::dateFormat(...), [2], true],
            'STR_TO_DATE' => [self::strToDate(...), [2], true],
            'MAKEDATE' => [self::makeDate(...), [2], true],
            'MAKETIME' => [self::makeTime(...), [3], true],
            'TO_DAYS' => [self::toDays(...), [1], true],
            'FROM_DAYS' => [self::fromDays(...), [1], true],
            'TO_SECONDS' => [self::toSeconds(...), [1], true],
            'TIME_TO_SEC' => [self::timeToSec(...), [1], true],
            'SEC_TO_TIME' => [self::secToTime(...), [1], true],
            'PERIOD_ADD' => [self::periodAdd(...), [2], true],
            'PERIOD_DIFF' => [self::periodDiff(...), [2], true],
        ];
    }

    /** @psalm-pure */
    private static function dateFormat(mixed $value, mixed $format): ?string
    {
        $date = Temporal::parse($value);

        return $date === null || $format === null ? null : DateFormat::format($date, (string) $format);
    }

    /** @psalm-pure */
    private static function strToDate(mixed $text, mixed $format): ?string
    {
        return $text === null || $format === null ? null : DateParser::parse((string) $text, (string) $format);
    }

    /** @psalm-pure */
    private static function makeDate(mixed $year, mixed $dayOfYear): ?string
    {
        if (! is_numeric($year) || ! is_numeric($dayOfYear) || (int) $dayOfYear <= 0 || (int) $year < 0) {
            return null;
        }
        $year = (int) $year;
        $year += $year < 70 ? 2000 : ($year < 100 ? 1900 : 0);

        return Temporal::fromDaynr(Calendar::daynr($year, 1, 1) + (int) $dayOfYear - 1, 0, Temporal::DATE, 0)
            ?->format();
    }

    /** @psalm-pure */
    private static function makeTime(mixed $hour, mixed $minute, mixed $second): ?string
    {
        if (
            ! is_numeric($hour) || ! is_numeric($minute) || ! is_numeric($second)
            || (int) $minute < 0 || (int) $minute > 59 || (float) $second < 0 || (float) $second >= 60
        ) {
            return null;
        }
        $micros = (int) round((float) $second * 1e6);
        $total = (abs((int) $hour) * 3600 + (int) $minute * 60) * Temporal::MICROS_PER_SECOND + $micros;

        return Values::duration((int) $hour < 0 ? -$total : $total, Values::decimalsOf($second));
    }

    /** @psalm-pure */
    private static function toDays(mixed $value): ?int
    {
        return Values::date($value)?->daynr();
    }

    /** @psalm-pure */
    private static function fromDays(mixed $daynr): ?string
    {
        if (! is_numeric($daynr)) {
            return null;
        }

        return Temporal::fromDaynr((int) $daynr, 0, Temporal::DATE, 0)?->format() ?? '0000-00-00';
    }

    /** @psalm-pure */
    private static function toSeconds(mixed $value): ?int
    {
        $date = Values::date($value);

        return $date === null ? null : intdiv($date->totalMicros(), Temporal::MICROS_PER_SECOND);
    }

    /** @psalm-pure */
    private static function timeToSec(mixed $value): int|float|null
    {
        $time = Temporal::parseTime($value);
        if ($time === null) {
            return null;
        }

        return $time->decimals > 0 ? (float) $time->micros / 1e6 : intdiv($time->micros, Temporal::MICROS_PER_SECOND);
    }

    /** @psalm-pure */
    private static function secToTime(mixed $seconds): ?string
    {
        if (! is_numeric($seconds)) {
            return null;
        }
        return Values::duration((int) round((float) $seconds * 1e6), Values::decimalsOf($seconds));
    }

    /** @psalm-pure */
    private static function periodAdd(mixed $period, mixed $months): ?int
    {
        if (! is_numeric($period) || ! is_numeric($months)) {
            return null;
        }
        if ((int) $period === 0) {
            return 0;
        }

        return self::monthToPeriod(self::periodToMonth((int) $period) + (int) $months);
    }

    /** @psalm-pure */
    private static function periodDiff(mixed $first, mixed $second): ?int
    {
        if (! is_numeric($first) || ! is_numeric($second)) {
            return null;
        }

        return self::periodToMonth((int) $first) - self::periodToMonth((int) $second);
    }

    /** @psalm-pure */
    private static function periodToMonth(int $period): int
    {
        $year = intdiv($period, 100);
        $year += $year < 70 ? 2000 : ($year < 100 ? 1900 : 0);

        return $year * 12 + $period % 100 - 1;
    }

    /** @psalm-pure */
    private static function monthToPeriod(int $month): int
    {
        $year = intdiv($month, 12);
        $year += $year < 70 ? 2000 : ($year < 100 ? 1900 : 0);

        return $year * 100 + $month % 12 + 1;
    }
}
