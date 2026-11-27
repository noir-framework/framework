<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * DATE_FORMAT(): MariaDB's `%` specifiers (English names, as with lc_time_names = en_US). STR_TO_DATE() is
 * DateParser.
 *
 * @psalm-api
 */
final class DateFormat
{
    private const array MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
        'September', 'October', 'November', 'December'];
    private const array DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * @psalm-pure
     */
    public static function format(Temporal $value, string $format): string
    {
        $out = '';
        $length = strlen($format);
        for ($i = 0; $i < $length; $i++) {
            if ($format[$i] !== '%' || $i + 1 === $length) {
                $out .= $format[$i];

                continue;
            }
            $out .= self::specifier($value, $format[++$i]);
        }

        return $out;
    }

    /**
     * @psalm-pure
     */
    public static function monthName(int $month): string
    {
        return self::MONTHS[$month - 1];
    }

    /**
     * @param int $weekday 0 = Monday
     * @psalm-pure
     */
    public static function dayName(int $weekday): string
    {
        return self::DAYS[$weekday];
    }

    /**
     * @psalm-pure
     */
    private static function specifier(Temporal $value, string $spec): string
    {
        $daynr = $value->kind === Temporal::TIME ? 0 : $value->daynr();
        $hour12 = ($value->hour % 12) ?: 12;

        return match ($spec) {
            'a' => substr(self::DAYS[Calendar::weekday($daynr, false)], 0, 3),
            'b' => substr(self::MONTHS[$value->month - 1] ?? '', 0, 3),
            'c' => (string) $value->month,
            'D' => $value->day . self::suffix($value->day),
            'd' => sprintf('%02d', $value->day),
            'e' => (string) $value->day,
            'f' => sprintf('%06d', $value->microsecond),
            'H' => sprintf('%02d', $value->hour),
            'h', 'I' => sprintf('%02d', $hour12),
            'i' => sprintf('%02d', $value->minute),
            'j' => sprintf('%03d', $daynr - Calendar::daynr($value->year, 1, 1) + 1),
            'k' => (string) $value->hour,
            'l' => (string) $hour12,
            'M' => self::MONTHS[$value->month - 1] ?? '',
            'm' => sprintf('%02d', $value->month),
            'p' => $value->hour < 12 ? 'AM' : 'PM',
            'r' => sprintf('%02d:%02d:%02d %s', $hour12, $value->minute, $value->second, $value->hour < 12 ? 'AM' : 'PM'),
            'S', 's' => sprintf('%02d', $value->second),
            'T' => sprintf('%02d:%02d:%02d', $value->hour, $value->minute, $value->second),
            'U', 'u', 'V', 'v' => sprintf('%02d', self::week($value, $spec)[0]),
            'X', 'x' => sprintf('%04d', self::week($value, $spec)[1]),
            'W' => self::DAYS[Calendar::weekday($daynr, false)],
            'w' => (string) Calendar::weekday($daynr, true),
            'Y' => sprintf('%04d', $value->year),
            'y' => sprintf('%02d', $value->year % 100),
            default => $spec,
        };
    }

    /**
     * @return array{int, int}
     * @psalm-pure
     */
    private static function week(Temporal $value, string $spec): array
    {
        $behaviour = match ($spec) {
            'U' => Calendar::WEEK_FIRST_WEEKDAY,
            'u' => Calendar::WEEK_MONDAY_FIRST,
            'V', 'X' => Calendar::WEEK_YEAR | Calendar::WEEK_FIRST_WEEKDAY,
            default => Calendar::WEEK_MONDAY_FIRST | Calendar::WEEK_YEAR,
        };

        return Calendar::week($value->year, $value->month, $value->day, $behaviour);
    }

    /**
     * @psalm-pure
     */
    private static function suffix(int $day): string
    {
        if ($day >= 11 && $day <= 13) {
            return 'th';
        }

        return match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
