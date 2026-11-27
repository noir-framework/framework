<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * A DATE, DATETIME or TIME value as MariaDB reads and prints it: `'2026-01-31'`, `'2026-01-31 14:05:00[.ffffff]'`,
 * `'[-]838:59:59[.ffffff]'`, or the numbers 20260131 / 20260131140500. Anything else (and dates such as Feb 30th or
 * with a zero month or day) is not a value: parse() gives null, and the functions answer NULL as MariaDB does.
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class Temporal
{
    public const string DATE = 'date';
    public const string DATETIME = 'datetime';
    public const string TIME = 'time';

    public const int MICROS_PER_SECOND = 1_000_000;
    public const int MICROS_PER_DAY = 86_400_000_000;

    /**
     * @param int $micros time of day in microseconds (TIME: the signed duration)
     * @param int $decimals how many fractional digits it is printed with (0–6), as MariaDB keeps per value
     */
    /** hours (a TIME's can pass 23), minutes, seconds, microseconds of $micros, without its sign */
    public int $hour;
    public int $minute;
    public int $second;
    public int $microsecond;

    public function __construct(
        public string $kind,
        public int $year,
        public int $month,
        public int $day,
        public int $micros,
        public int $decimals = 0,
    ) {
        $abs = abs($micros);
        $this->hour = intdiv($abs, 3_600_000_000);
        $this->minute = intdiv($abs, 60_000_000) % 60;
        $this->second = intdiv($abs, self::MICROS_PER_SECOND) % 60;
        $this->microsecond = $abs % self::MICROS_PER_SECOND;
    }

    /**
     * A DATE, DATETIME or TIME value.
     *
     * @psalm-pure
     */
    public static function parse(mixed $value): ?self
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return self::fromNumber((int) $value);
        }
        if (! is_string($value)) {
            return null;
        }

        return self::dateOnly($value) ?? self::timeOnly($value);
    }

    /**
     * A TIME value: `[-][D ]HH:MM[:SS[.ffffff]]` (hours up to 838), or a DATETIME's time of day.
     *
     * @psalm-pure
     */
    public static function parseTime(mixed $value): ?self
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $time = self::timeOnly((string) $value);
        if ($time !== null) {
            return $time;
        }

        // a DATETIME's time of day; never back through parse() (2026-11-27: parse() and parseTime() calling each
        // other on a non-date string recursed until the machine ran out of memory)
        $datetime = self::dateOnly((string) $value);

        return $datetime?->kind === self::DATETIME
            ? new self(self::TIME, 0, 0, 0, $datetime->micros, $datetime->decimals)
            : null;
    }

    /**
     * A value where MariaDB expects a TIME (HOUR(), MINUTE(), SECOND(), MICROSECOND(), TIMEDIFF() against a TIME):
     * TIME text, a DATETIME's time of day, and, as MariaDB does, a date-only string read as the number its leading
     * digits make, HHMMSS: '2026-01-31' is 00:20:26, '1999-12-31' nothing (99 seconds).
     *
     * @psalm-pure
     */
    public static function forTime(mixed $value): ?self
    {
        $time = self::parseTime($value);
        if ($time !== null || ! is_string($value) || self::dateOnly($value)?->kind !== self::DATE) {
            return $time;
        }

        $number = (int) substr(ltrim($value), 0, strspn(ltrim($value), '0123456789'));
        [$hours, $minutes, $seconds] = [intdiv($number, 10000), intdiv($number, 100) % 100, $number % 100];
        if ($minutes > 59 || $seconds > 59) {
            return null;
        }

        return new self(self::TIME, 0, 0, 0, (($hours * 60 + $minutes) * 60 + $seconds) * self::MICROS_PER_SECOND);
    }

    /**
     * DATE or DATETIME text, or the numbers YYYYMMDD / YYYYMMDDHHMMSS as text; nothing else.
     *
     * @psalm-pure
     */
    private static function dateOnly(string $value): ?self
    {
        $value = trim($value);
        if (preg_match('/^\d{8}$|^\d{14}$/', $value) === 1) {
            return self::fromNumber((int) $value);
        }

        $match = [];
        $date = '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2})(?:\.(\d{1,6}))?)?)?$/';

        return preg_match($date, $value, $match) === 1 ? self::fromParts($match) : null;
    }

    /**
     * TIME text only.
     *
     * @psalm-pure
     */
    private static function timeOnly(string $value): ?self
    {
        $match = [];
        $time = '/^(-)?(?:(\d+) )?(\d{1,3}):(\d{1,2})(?::(\d{1,2})(?:\.(\d{1,6}))?)?$/';
        if (preg_match($time, trim($value), $match) !== 1) {
            return null;
        }

        $hours = (int) $match[2] * 24 + (int) $match[3];
        if ((int) $match[4] > 59 || (int) ($match[5] ?? 0) > 59) {
            return null;
        }
        $micros = (($hours * 60 + (int) $match[4]) * 60 + (int) ($match[5] ?? 0)) * self::MICROS_PER_SECOND
            + Values::fractionMicros($match[6] ?? '');

        return new self(self::TIME, 0, 0, 0, $match[1] === '-' ? -$micros : $micros, Values::decimals($match[6] ?? ''));
    }

    /**
     * @psalm-pure
     */
    public static function fromDaynr(int $daynr, int $micros, string $kind, int $decimals): ?self
    {
        $date = Calendar::fromDaynr($daynr);

        return $date === null ? null : new self($kind, $date[0], $date[1], $date[2], $micros, $decimals);
    }

    /**
     * @psalm-mutation-free
     */
    public function daynr(): int
    {
        return Calendar::daynr($this->year, $this->month, $this->day);
    }

    /**
     * Microseconds since 0000-00-00 00:00:00 (dates), or the TIME's duration.
     *
     * @psalm-mutation-free
     */
    public function totalMicros(): int
    {
        return $this->kind === self::TIME ? $this->micros : $this->daynr() * self::MICROS_PER_DAY + $this->micros;
    }

    /**
     * @psalm-mutation-free
     */
    public function withKind(string $kind, ?int $decimals = null): self
    {
        return new self($kind, $this->year, $this->month, $this->day, $this->micros, $decimals ?? $this->decimals);
    }

    /**
     * As MariaDB prints it.
     *
     * @psalm-mutation-free
     */
    public function format(): string
    {
        $date = sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
        $time = sprintf('%02d:%02d:%02d', $this->hour, $this->minute, $this->second)
            . ($this->decimals > 0 ? '.' . substr(sprintf('%06d', $this->microsecond), 0, $this->decimals) : '');

        return match ($this->kind) {
            self::DATE => $date,
            self::TIME => ($this->micros < 0 ? '-' : '') . $time,
            default => $date . ' ' . $time,
        };
    }

    /**
     * @psalm-pure
     */
    private static function fromNumber(int $number): ?self
    {
        $digits = (string) $number;
        if (strlen($digits) === 14) {
            return self::fromParts(['', substr($digits, 0, 4), substr($digits, 4, 2), substr($digits, 6, 2),
                substr($digits, 8, 2), substr($digits, 10, 2), substr($digits, 12, 2)]);
        }

        return strlen($digits) === 8
            ? self::fromParts(['', substr($digits, 0, 4), substr($digits, 4, 2), substr($digits, 6, 2)])
            : null;
    }

    /**
     * @param array<int, string> $match [all, year, month, day, hour?, minute?, second?, fraction?]
     * @psalm-pure
     */
    private static function fromParts(array $match): ?self
    {
        [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        if (! Values::isValidDate($year, $month, $day)) {
            return null;
        }
        if (! isset($match[4]) || $match[4] === '') {
            return new self(self::DATE, $year, $month, $day, 0);
        }

        [$hour, $minute, $second] = [(int) $match[4], (int) $match[5], (int) ($match[6] ?? 0)];
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $fraction = $match[7] ?? '';
        $micros = (($hour * 60 + $minute) * 60 + $second) * self::MICROS_PER_SECOND + Values::fractionMicros($fraction);

        return new self(self::DATETIME, $year, $month, $day, $micros, Values::decimals($fraction));
    }
}
