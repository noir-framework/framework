<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

use InvalidArgumentException;

/**
 * `INTERVAL value unit` as MariaDB reads it (sql/item_timefunc.cc get_interval_value()): months for YEAR, QUARTER,
 * MONTH and YEAR_MONTH, microseconds for everything else, and how DATE_ADD() applies them.
 *
 * - A simple unit takes a number (`1`, `'2'`, `-3`); a fractional one is rounded, except SECOND, which keeps it.
 * - A composite unit takes a string of numbers separated by anything (`'1:30' HOUR_MINUTE`, `'1-2' YEAR_MONTH`), a
 *   leading `-` negating all of them; missing leading parts are zero (`'5' DAY_SECOND` is 5 seconds); a trailing
 *   microsecond part is a fraction (`'1.5' SECOND_MICROSECOND` is 1.5 s).
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class Interval
{
    /** unit => [months per part …] or [microseconds per part …] (the last part of *_MICROSECOND is a fraction) */
    private const array UNITS = [
        'MICROSECOND' => [false, [1]],
        'SECOND' => [false, [1_000_000]],
        'MINUTE' => [false, [60_000_000]],
        'HOUR' => [false, [3_600_000_000]],
        'DAY' => [false, [86_400_000_000]],
        'WEEK' => [false, [604_800_000_000]],
        'MONTH' => [true, [1]],
        'QUARTER' => [true, [3]],
        'YEAR' => [true, [12]],
        'SECOND_MICROSECOND' => [false, [1_000_000, 1]],
        'MINUTE_MICROSECOND' => [false, [60_000_000, 1_000_000, 1]],
        'MINUTE_SECOND' => [false, [60_000_000, 1_000_000]],
        'HOUR_MICROSECOND' => [false, [3_600_000_000, 60_000_000, 1_000_000, 1]],
        'HOUR_SECOND' => [false, [3_600_000_000, 60_000_000, 1_000_000]],
        'HOUR_MINUTE' => [false, [3_600_000_000, 60_000_000]],
        'DAY_MICROSECOND' => [false, [86_400_000_000, 3_600_000_000, 60_000_000, 1_000_000, 1]],
        'DAY_SECOND' => [false, [86_400_000_000, 3_600_000_000, 60_000_000, 1_000_000]],
        'DAY_MINUTE' => [false, [86_400_000_000, 3_600_000_000, 60_000_000]],
        'DAY_HOUR' => [false, [86_400_000_000, 3_600_000_000]],
        'YEAR_MONTH' => [true, [12, 1]],
    ];

    /** Units that keep a DATE a DATE. */
    private const array DATE_UNITS = ['DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR', 'YEAR_MONTH'];

    private function __construct(
        public string $unit,
        public int $months,
        public int $micros,
        public int $decimals,
    ) {
    }

    /**
     * @psalm-pure
     */
    public static function isUnit(string $unit): bool
    {
        return isset(self::UNITS[strtoupper($unit)]);
    }

    /**
     * Null when the value is NULL or not a number MariaDB would read.
     *
     * @psalm-pure
     */
    public static function fromValue(mixed $value, string $unit): ?self
    {
        $unit = strtoupper($unit);
        if (! isset(self::UNITS[$unit])) {
            throw new InvalidArgumentException("Unknown INTERVAL unit: $unit");
        }
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        [$inMonths, $scales] = self::UNITS[$unit];

        return count($scales) === 1
            ? self::simple($unit, $inMonths, $scales[0], $value)
            : self::composite($unit, $inMonths, $scales, (string) $value);
    }

    /**
     * DATE_ADD(): null when the result is outside 0001-01-01 … 9999-12-31 (MariaDB gives NULL).
     *
     * @psalm-mutation-free
     */
    public function addTo(Temporal $date, int $sign = 1): ?Temporal
    {
        $kind = $date->kind === Temporal::DATE && in_array($this->unit, self::DATE_UNITS, true)
            ? Temporal::DATE
            : Temporal::DATETIME;
        // as many decimals as the more precise of the two: '2026-01-31' + INTERVAL 1.5 SECOND is '… 00:00:01.5'
        $decimals = max($date->decimals, $this->decimals);

        if ($this->months !== 0) {
            $months = $date->year * 12 + $date->month - 1 + $sign * $this->months;
            $year = intdiv($months, 12);
            $month = $months % 12 + 1;
            if ($months < 0 || $year > 9999) {
                return null;
            }
            $day = min($date->day, Calendar::daysInMonth($year, $month));
            $date = new Temporal($kind, $year, $month, $day, $date->micros, $decimals);
        }

        $total = $date->totalMicros() + $sign * $this->micros;
        $daynr = intdiv($total, Temporal::MICROS_PER_DAY) - ($total % Temporal::MICROS_PER_DAY < 0 ? 1 : 0);

        return Temporal::fromDaynr($daynr, $total - $daynr * Temporal::MICROS_PER_DAY, $kind, $decimals);
    }

    /**
     * @psalm-pure
     */
    private static function simple(string $unit, bool $inMonths, int $scale, int|float|string|bool $value): ?self
    {
        if (is_string($value)) {
            $match = [];
            if (preg_match('/^\s*[-+]?\d+(?:\.\d*)?/', $value, $match) !== 1) {
                return null;
            }
            $value = $match[0];
        }

        $number = (float) $value;
        if ($unit === 'SECOND') {
            // its decimals are the value's own: 1.5 has one
            $text = is_float($value) ? (string) $value : trim((string) $value);
            $dot = strpos($text, '.');

            $decimals = $dot === false ? 0 : Values::decimals(substr($text, $dot + 1));

            return new self($unit, 0, (int) round($number * (float) $scale), $decimals);
        }

        $amount = (int) round($number, 0, PHP_ROUND_HALF_UP) * $scale;

        return $inMonths ? new self($unit, $amount, 0, 0) : new self($unit, 0, $amount, $unit === 'MICROSECOND' ? 6 : 0);
    }

    /**
     * @param list<int> $scales
     * @psalm-pure
     */
    private static function composite(string $unit, bool $inMonths, array $scales, string $value): ?self
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $parts = preg_split('/\D+/', ltrim($value, '-'), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === [] || count($parts) > count($scales)) {
            return null;
        }

        $withFraction = str_ends_with($unit, '_MICROSECOND');
        if ($withFraction && count($parts) === count($scales)) {
            // the microsecond part is a fraction: '1.5' is 500000, not 5
            $parts[count($parts) - 1] = (string) Values::fractionMicros($parts[count($parts) - 1]);
        }

        $total = 0;
        $offset = count($scales) - count($parts);
        foreach ($parts as $i => $part) {
            $total += (int) $part * $scales[$offset + $i];
        }
        $total = $negative ? -$total : $total;

        return $inMonths ? new self($unit, $total, 0, 0) : new self($unit, 0, $total, $withFraction ? 6 : 0);
    }
}
