<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * Small rules about MariaDB values that the function classes share.
 *
 * @psalm-api
 */
final class Values
{
    private const int MAX_TIME_MICROS = (838 * 3600 + 59 * 60 + 59) * Temporal::MICROS_PER_SECOND;

    /**
     * A DATE or DATETIME (a TIME is not a date).
     *
     * @psalm-pure
     */
    public static function date(mixed $value): ?Temporal
    {
        $date = Temporal::parse($value);

        return $date === null || $date->kind === Temporal::TIME ? null : $date;
    }

    /**
     * A signed duration as MariaDB's TIME, clipped to ±838:59:59.
     *
     * @psalm-pure
     */
    public static function duration(int $micros, int $decimals): string
    {
        $micros = max(-self::MAX_TIME_MICROS, min(self::MAX_TIME_MICROS, $micros));

        return new Temporal(Temporal::TIME, 0, 0, 0, $micros, $decimals)->format();
    }

    /**
     * A number's fractional digits, at most 6 (1.5 has one).
     *
     * @psalm-pure
     */
    public static function decimalsOf(mixed $number): int
    {
        if (! is_scalar($number)) {
            return 0;
        }
        $text = (string) $number;
        $dot = strpos($text, '.');

        return $dot === false ? 0 : self::decimals(rtrim(substr($text, $dot + 1), '0'));
    }

    /**
     * How many fractional digits a fraction's text has, at most 6.
     *
     * @psalm-pure
     */
    public static function decimals(string $digits): int
    {
        return min(6, strlen($digits));
    }

    /**
     * '5' → 500000, '123456' → 123456: a fraction's digits as microseconds.
     *
     * @psalm-pure
     */
    public static function fractionMicros(string $digits): int
    {
        return $digits === '' ? 0 : (int) str_pad(substr($digits, 0, 6), 6, '0');
    }

    /**
     * @psalm-pure
     */
    public static function isValidDate(int $year, int $month, int $day): bool
    {
        return $year >= 0 && $year <= 9999 && $month >= 1 && $month <= 12 && $day >= 1
            && $day <= Calendar::daysInMonth($year, $month);
    }
}
