<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * MariaDB's calendar arithmetic, ported from its sql-common/my_time.c and sql/sql_time.cc: day numbers (TO_DAYS),
 * weekdays and the week modes, so dates come out exactly as MariaDB computes them (no PHP DateTime, no DST).
 *
 * @psalm-api
 */
final class Calendar
{
    /** Day numbers below this (year 0) are not dates. */
    public const int FIRST_DAYNR = 366;
    /** One past the last valid day number (10000-01-01). */
    public const int END_DAYNR = 3652425;

    public const int WEEK_MONDAY_FIRST = 1;
    public const int WEEK_YEAR = 2;
    public const int WEEK_FIRST_WEEKDAY = 4;

    private const array MONTH_DAYS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    /**
     * @psalm-pure
     */
    public static function isLeap(int $year): bool
    {
        return ($year & 3) === 0 && ($year % 100 !== 0 || ($year % 400 === 0 && $year !== 0));
    }

    /**
     * @psalm-pure
     */
    public static function daysInYear(int $year): int
    {
        return self::isLeap($year) ? 366 : 365;
    }

    /**
     * @psalm-pure
     */
    public static function daysInMonth(int $year, int $month): int
    {
        return $month === 2 && self::isLeap($year) ? 29 : self::MONTH_DAYS[$month - 1];
    }

    /**
     * MariaDB's calc_daynr(): days since 0000-00-00 (TO_DAYS).
     *
     * @psalm-pure
     */
    public static function daynr(int $year, int $month, int $day): int
    {
        if ($year === 0 && $month === 0) {
            return 0;
        }

        $delsum = 365 * $year + 31 * ($month - 1) + $day;
        if ($month <= 2) {
            $year--;
        } else {
            $delsum -= intdiv($month * 4 + 23, 10);
        }

        return $delsum + intdiv($year, 4) - intdiv((intdiv($year, 100) + 1) * 3, 4);
    }

    /**
     * MariaDB's get_date_from_daynr(): [year, month, day], or null outside 0001-01-01 … 9999-12-31.
     *
     * @return array{int, int, int}|null
     * @psalm-pure
     */
    public static function fromDaynr(int $daynr): ?array
    {
        if ($daynr < self::FIRST_DAYNR || $daynr >= self::END_DAYNR) {
            return null;
        }

        $year = intdiv($daynr * 100, 36525);
        $dayOfYear = $daynr - $year * 365 - intdiv($year - 1, 4) + intdiv((intdiv($year - 1, 100) + 1) * 3, 4);
        while ($dayOfYear > ($days = self::daysInYear($year))) {
            $dayOfYear -= $days;
            $year++;
        }

        $leapDay = 0;
        if ($days === 366 && $dayOfYear > 31 + 28) {
            $dayOfYear--;
            $leapDay = $dayOfYear === 31 + 28 ? 1 : 0;
        }

        $month = 1;
        foreach (self::MONTH_DAYS as $monthDays) {
            if ($dayOfYear <= $monthDays) {
                break;
            }
            $dayOfYear -= $monthDays;
            $month++;
        }

        return [$year, $month, $dayOfYear + $leapDay];
    }

    /**
     * MariaDB's calc_weekday(): 0 = Monday (or Sunday when $sundayFirst) … 6.
     *
     * @psalm-pure
     */
    public static function weekday(int $daynr, bool $sundayFirst): int
    {
        return ($daynr + 5 + ($sundayFirst ? 1 : 0)) % 7;
    }

    /**
     * MariaDB's week_mode(): WEEK(d, mode)'s mode as calc_week() behaviour flags.
     *
     * @psalm-pure
     */
    public static function weekMode(int $mode): int
    {
        $format = $mode & 7;
        if (($format & self::WEEK_MONDAY_FIRST) === 0) {
            $format ^= self::WEEK_FIRST_WEEKDAY;
        }

        return $format;
    }

    /**
     * MariaDB's calc_week(): [week, year the week belongs to].
     *
     * @return array{int, int}
     * @psalm-pure
     */
    public static function week(int $year, int $month, int $day, int $behaviour): array
    {
        $daynr = self::daynr($year, $month, $day);
        $firstDaynr = self::daynr($year, 1, 1);
        $mondayFirst = ($behaviour & self::WEEK_MONDAY_FIRST) !== 0;
        $weekYear = ($behaviour & self::WEEK_YEAR) !== 0;
        $firstWeekday = ($behaviour & self::WEEK_FIRST_WEEKDAY) !== 0;
        $weekday = self::weekday($firstDaynr, ! $mondayFirst);

        if ($month === 1 && $day <= 7 - $weekday) {
            if (! $weekYear && self::startsLate($firstWeekday, $weekday)) {
                return [0, $year];
            }
            $weekYear = true;
            $year--;
            $days = self::daysInYear($year);
            $firstDaynr -= $days;
            $weekday = ($weekday + 53 * 7 - $days) % 7;
        }

        $days = self::startsLate($firstWeekday, $weekday)
            ? $daynr - ($firstDaynr + (7 - $weekday))
            : $daynr - ($firstDaynr - $weekday);

        if ($weekYear && $days >= 52 * 7) {
            $weekday = ($weekday + self::daysInYear($year)) % 7;
            if (! self::startsLate($firstWeekday, $weekday)) {
                return [1, $year + 1];
            }
        }

        return [intdiv($days, 7) + 1, $year];
    }

    /**
     * Whether week 1 starts after January 1st: with "first weekday" rules when the year does not begin on it,
     * otherwise when fewer than 4 days of the first week are in the year.
     *
     * @psalm-pure
     */
    private static function startsLate(bool $firstWeekday, int $weekday): bool
    {
        return $firstWeekday ? $weekday !== 0 : $weekday >= 4;
    }
}
