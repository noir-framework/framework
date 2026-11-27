<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * MariaDB's DECIMAL on SQLite: a column declared DECIMAL(p,s) (NUMERIC affinity there, so 50.00 is stored as 50)
 * reads back as MariaDB gives it, the text '50.00'; and exact sums of such values, in whole units of 10^-scale, so
 * 0.1 + 0.2 is 0.3. No bcmath needed: a sum that leaves PHP's integers (beyond ±9.2e18 units) falls back to floats.
 *
 * @psalm-api
 */
final class Decimal
{
    /** AVG() has this many more decimals than its argument (MariaDB's div_precision_increment) */
    public const int AVG_EXTRA = 4;
    private const int MAX_SCALE = 30;

    /**
     * The scale of a declared type: `DECIMAL(15,2)` → 2, `NUMERIC(10)` → 0; null for anything else.
     *
     * @psalm-pure
     */
    public static function scaleOf(?string $declared): ?int
    {
        $match = [];
        if ($declared === null || preg_match('/^\s*(?:DECIMAL|NUMERIC|DEC|FIXED)\s*\(\s*\d+\s*(?:,\s*(\d+)\s*)?\)/i', $declared, $match) !== 1) {
            return null;
        }

        return min(self::MAX_SCALE, (int) ($match[1] ?? 0));
    }

    /**
     * $value as MariaDB prints a DECIMAL with $scale decimals ('50.00'), rounded half away from zero; NULL and
     * anything that is not a number stay as they are.
     *
     * @psalm-pure
     */
    public static function format(mixed $value, int $scale): mixed
    {
        $text = self::text($value);

        return $text === null ? $value : self::round($text, $scale);
    }

    /**
     * A number as exact decimal text: 361.2 (a float) → '361.2', 5 → '5', '12.50' → '12.50'; null when it is not a
     * number.
     *
     * @psalm-pure
     */
    public static function text(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }
            // the shortest text that reads back as this float (serialize_precision -1), without an exponent
            $text = var_export($value, true);

            return stripos($text, 'E') === false ? $text : rtrim(rtrim(sprintf('%.' . self::MAX_SCALE . 'F', $value), '0'), '.');
        }

        return is_string($value) && preg_match('/^\s*[-+]?(\d+\.?\d*|\.\d+)\s*$/', $value) === 1 ? trim($value) : null;
    }

    /**
     * How many decimals a number's exact text has.
     *
     * @psalm-pure
     */
    public static function decimals(string $text): int
    {
        $dot = strpos($text, '.');

        return $dot === false ? 0 : min(self::MAX_SCALE, strlen($text) - $dot - 1);
    }

    /**
     * Decimal text rounded to $scale decimals, half away from zero, as MariaDB rounds into a DECIMAL column.
     *
     * @psalm-pure
     */
    public static function round(string $text, int $scale): string
    {
        $negative = str_starts_with(ltrim($text), '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim(trim($text), '+-'), 2), 2, '');
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
        $digits = $whole . str_pad(substr($fraction, 0, $scale), $scale, '0');
        if ((int) ($fraction[$scale] ?? '0') >= 5) {
            $digits = self::increment($digits);
        }
        $result = $scale === 0 ? $digits : substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        $result = str_starts_with($result, '.') ? '0' . $result : $result;
        $isZero = trim($result, '0.') === '';

        return $negative && ! $isZero ? '-' . $result : $result;
    }

    /**
     * Decimal text as whole units of 10^-$scale (the text rounded to $scale first); null beyond PHP's integers.
     *
     * @psalm-pure
     */
    public static function units(string $text, int $scale): ?int
    {
        $rounded = self::round($text, $scale);
        $digits = str_replace(['-', '.'], '', $rounded);
        $units = ltrim($digits, '0') === '' ? 0 : (int) $digits;
        if ((string) $units !== (ltrim($digits, '0') ?: '0')) {
            return null;
        }

        return str_starts_with($rounded, '-') ? -$units : $units;
    }

    /**
     * Whole units of 10^-$scale as decimal text: (12345, 2) → '123.45'.
     *
     * @psalm-pure
     */
    public static function fromUnits(int $units, int $scale): string
    {
        $digits = str_pad((string) abs($units), $scale + 1, '0', STR_PAD_LEFT);
        $text = $scale === 0 ? $digits : substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);

        return $units < 0 ? '-' . $text : $text;
    }

    /**
     * '0999' + 1 → '1000' on a string of digits.
     *
     * @psalm-pure
     */
    private static function increment(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                return substr($digits, 0, $i) . ((int) $digits[$i] + 1) . str_repeat('0', strlen($digits) - $i - 1);
            }
        }

        return '1' . str_repeat('0', strlen($digits));
    }
}
