<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * Exact SUM() and AVG() as MariaDB computes them over DECIMALs (and integers), and the formatting of a DECIMAL
 * result. The rewriter (DecimalWords) turns `SUM(x)` into `MARIADB_SUM(x, scale)`, `AVG(x)` into `MARIADB_AVG(x, scale)`
 * and `MIN`/`MAX` of a DECIMAL column into `MARIADB_DECIMAL(MIN(x), scale)`; scale is the column's, or NULL to take it
 * from the values. SUM keeps the scale; AVG has 4 more decimals (MariaDB's div_precision_increment).
 *
 * @psalm-type Total = array{fixed: ?int, scale: int, units: int, float: float, exact: bool, count: int}
 * @psalm-api
 */
final class DecimalFunctions
{
    /**
     * SQL name => [callable, argument counts, deterministic]
     *
     * @return array<string, array{callable, list<int>, bool}>
     * @psalm-pure
     */
    public static function definitions(): array
    {
        return ['MARIADB_DECIMAL' => [self::decimal(...), [2], true]];
    }

    /**
     * SQL name => [step, finalize, argument count]
     *
     * @return array<string, array{callable, callable, int}>
     * @psalm-pure
     */
    public static function aggregates(): array
    {
        return [
            'MARIADB_SUM' => [self::step(...), self::sum(...), 2],
            'MARIADB_AVG' => [self::step(...), self::avg(...), 2],
        ];
    }

    /** @psalm-pure */
    private static function decimal(mixed $value, mixed $scale): mixed
    {
        return is_numeric($scale) ? Decimal::format($value, (int) $scale) : $value;
    }

    /**
     * One value into the running total (NULL is skipped, text that is not a number counts as 0, as MariaDB).
     *
     * @param Total|null $total
     * @return Total|null
     * @psalm-pure
     */
    private static function step(?array $total, int $row, mixed $value, mixed $scale): ?array
    {
        unset($row);
        if ($value === null) {
            return $total;
        }
        $total ??= ['fixed' => is_numeric($scale) ? (int) $scale : null, 'scale' => 0, 'units' => 0, 'float' => 0.0,
            'exact' => true, 'count' => 0];
        $text = Decimal::text($value) ?? '0';
        $total['count']++;
        $total['float'] += (float) $text;

        return $total['exact'] ? self::addExact($total, $text) : $total;
    }

    /**
     * @param Total $total
     * @return Total
     * @psalm-pure
     */
    private static function addExact(array $total, string $text): array
    {
        $scale = $total['fixed'] ?? max($total['scale'], Decimal::decimals($text));
        $units = self::rescale($total['units'], $scale - $total['scale']);
        $add = Decimal::units($text, $scale);
        $sum = $units === null || $add === null ? null : $units + $add;
        if (! is_int($sum)) {
            // beyond PHP's integers: go on in floats, as SQLite would
            $total['exact'] = false;

            return $total;
        }

        return ['scale' => $scale, 'units' => $sum] + $total;
    }

    /**
     * @param Total|null $total
     * @psalm-pure
     */
    private static function sum(?array $total, int $rows): ?string
    {
        unset($rows);
        if ($total === null) {
            return null;
        }

        return $total['exact']
            ? Decimal::fromUnits($total['units'], $total['scale'])
            : Decimal::round((string) Decimal::text($total['float']), $total['scale']);
    }

    /**
     * @param Total|null $total
     * @psalm-pure
     */
    private static function avg(?array $total, int $rows): ?string
    {
        unset($rows);
        if ($total === null || $total['count'] === 0) {
            return null;
        }
        $scale = $total['scale'] + Decimal::AVG_EXTRA;
        $numerator = $total['exact'] ? self::rescale($total['units'], Decimal::AVG_EXTRA) : null;
        if ($numerator === null) {
            return Decimal::round((string) Decimal::text($total['float'] / (float) $total['count']), $scale);
        }

        $quotient = intdiv($numerator, $total['count']);
        $remainder = $numerator % $total['count'];
        if (2 * abs($remainder) >= $total['count']) {
            $quotient += $numerator < 0 ? -1 : 1;
        }

        return Decimal::fromUnits($quotient, $scale);
    }

    /**
     * $units × 10^$digits; null beyond PHP's integers.
     *
     * @psalm-pure
     */
    private static function rescale(int $units, int $digits): ?int
    {
        if ($digits <= 0) {
            return $units;
        }
        if ($digits > 18) {
            return null;
        }
        $factor = (int) str_pad('1', $digits + 1, '0');

        return abs($units) > intdiv(PHP_INT_MAX, $factor) ? null : $units * $factor;
    }
}
