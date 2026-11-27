<?php

declare(strict_types=1);

namespace Noirapi\Lib\Db\MariaDb;

/**
 * STR_TO_DATE(): reads text by MariaDB's `%` specifiers. A DATETIME when the format has date and time parts, a DATE
 * or TIME when only one; null when the text does not match. MariaDB checks ranges only ('31/02/2026' gives
 * '2026-02-31'), and parts the format lacks are zero.
 *
 * @psalm-api
 */
final class DateParser
{
    /** specifier => [part, digits] */
    private const array NUMBERS = [
        'Y' => ['year', 4], 'y' => ['shortYear', 2], 'm' => ['month', 2], 'c' => ['month', 2], 'd' => ['day', 2],
        'e' => ['day', 2], 'H' => ['hour', 2], 'k' => ['hour', 2], 'h' => ['hour', 2], 'I' => ['hour', 2],
        'l' => ['hour', 2], 'i' => ['minute', 2], 'S' => ['second', 2], 's' => ['second', 2],
    ];
    private const array DATE_PARTS = ['year', 'shortYear', 'month', 'day'];

    private int $pos = 0;
    /** @var array{year: int, shortYear: int|null, month: int, day: int, hour: int, minute: int, second: int} */
    private array $parts = ['year' => 0, 'shortYear' => null, 'month' => 0, 'day' => 0, 'hour' => 0, 'minute' => 0,
        'second' => 0];
    private int $micros = 0;
    private ?bool $afternoon = null;
    private bool $hasDate = false;
    private bool $hasTime = false;
    private bool $hasFraction = false;

    /**
     * @psalm-mutation-free
     */
    private function __construct(private readonly string $text)
    {
    }

    /**
     * @psalm-pure
     */
    public static function parse(string $text, string $format): ?string
    {
        $parser = new self($text);

        return $parser->matches($format) ? $parser->result() : null;
    }

    /**
     * @psalm-external-mutation-free
     */
    private function matches(string $format): bool
    {
        return $this->sequence($format) && trim(substr($this->text, $this->pos)) === '';
    }

    /**
     * Reads the text from the current position by $format (also %T's and %r's own formats).
     *
     * @psalm-external-mutation-free
     */
    private function sequence(string $format): bool
    {
        $length = strlen($format);
        for ($i = 0; $i < $length; $i++) {
            $isSpecifier = $format[$i] === '%' && $i + 1 < $length;
            $matched = $isSpecifier ? $this->specifier($format[++$i]) : $this->literal($format[$i]);
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * A literal character of the format: a space matches any run of spaces, anything else itself.
     *
     * @psalm-external-mutation-free
     */
    private function literal(string $char): bool
    {
        if (ctype_space($char)) {
            $this->pos += strspn($this->text, " \t\n", $this->pos);

            return true;
        }
        if (($this->text[$this->pos] ?? '') !== $char) {
            return false;
        }
        $this->pos++;

        return true;
    }

    /**
     * @psalm-external-mutation-free
     */
    private function specifier(string $spec): bool
    {
        if (isset(self::NUMBERS[$spec])) {
            return $this->number(...self::NUMBERS[$spec]);
        }

        return match ($spec) {
            'f' => $this->fraction(),
            'M' => $this->monthName(false),
            'b' => $this->monthName(true),
            'p' => $this->meridiem(),
            'T' => $this->clock(false),
            'r' => $this->clock(true),
            'a', 'W' => $this->dayName(),
            '%' => $this->literal('%'),
            default => false,
        };
    }

    /**
     * Up to $digits digits into one part.
     *
     * @psalm-external-mutation-free
     */
    private function number(string $part, int $digits): bool
    {
        $count = strspn($this->text, '0123456789', $this->pos, $digits);
        if ($count === 0) {
            return false;
        }
        $this->parts[$part] = (int) substr($this->text, $this->pos, $count);
        $this->pos += $count;
        $this->noteDate(in_array($part, self::DATE_PARTS, true));

        return true;
    }

    /**
     * @psalm-external-mutation-free
     */
    private function noteDate(bool $date): void
    {
        $this->hasDate = $this->hasDate || $date;
        $this->hasTime = $this->hasTime || ! $date;
    }

    /**
     * `%f`: up to 6 digits of a fraction.
     *
     * @psalm-external-mutation-free
     */
    private function fraction(): bool
    {
        $count = strspn($this->text, '0123456789', $this->pos, 6);
        if ($count === 0) {
            return false;
        }
        $this->micros = Values::fractionMicros(substr($this->text, $this->pos, $count));
        $this->pos += $count;
        $this->hasTime = true;
        $this->hasFraction = true;

        return true;
    }

    /**
     * `%T` (HH:MM:SS) and `%r` (hh:mm:ss AM).
     *
     * @psalm-external-mutation-free
     */
    private function clock(bool $twelveHour): bool
    {
        return $this->sequence($twelveHour ? '%h:%i:%s %p' : '%H:%i:%s');
    }

    /**
     * @psalm-external-mutation-free
     */
    private function monthName(bool $short): bool
    {
        $names = array_map(
            static fn (int $month): string => $short ? substr(DateFormat::monthName($month), 0, 3) : DateFormat::monthName($month),
            range(1, 12),
        );
        $index = $this->oneOf($names);
        if ($index === null) {
            return false;
        }
        $this->parts['month'] = $index + 1;
        $this->noteDate(true);

        return true;
    }

    /**
     * `%a` / `%W`: a day name, full or short, is read and ignored.
     *
     * @psalm-external-mutation-free
     */
    private function dayName(): bool
    {
        $names = array_map(DateFormat::dayName(...), range(0, 6));

        return $this->oneOf([...$names, ...array_map(static fn (string $name): string => substr($name, 0, 3), $names)]) !== null;
    }

    /**
     * @psalm-external-mutation-free
     */
    private function meridiem(): bool
    {
        $index = $this->oneOf(['AM', 'PM']);
        if ($index === null) {
            return false;
        }
        $this->afternoon = $index === 1;

        return true;
    }

    /**
     * Consumes the first of $words the text continues with (case-insensitively); its index, or null.
     *
     * @param list<string> $words
     * @psalm-external-mutation-free
     */
    private function oneOf(array $words): ?int
    {
        $rest = substr($this->text, $this->pos);
        foreach ($words as $index => $word) {
            if (strncasecmp($rest, $word, strlen($word)) === 0) {
                $this->pos += strlen($word);

                return $index;
            }
        }

        return null;
    }

    /**
     * @psalm-mutation-free
     */
    private function result(): ?string
    {
        $hour = $this->parts['hour'];
        if ($this->afternoon !== null) {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $hour = $hour % 12 + ($this->afternoon ? 12 : 0);
        }
        if (! $this->inRange($hour)) {
            return null;
        }

        $short = $this->parts['shortYear'];
        $year = $short === null ? $this->parts['year'] : $short + ($short < 70 ? 2000 : 1900);
        $date = sprintf('%04d-%02d-%02d', $year, $this->parts['month'], $this->parts['day']);
        $time = sprintf('%02d:%02d:%02d', $hour, $this->parts['minute'], $this->parts['second'])
            . ($this->hasFraction ? sprintf('.%06d', $this->micros) : '');

        return match (true) {
            $this->hasDate && $this->hasTime => $date . ' ' . $time,
            $this->hasTime => $time,
            default => $date,
        };
    }

    /**
     * Ranges only, as MariaDB: a day 31 in February passes.
     *
     * @psalm-mutation-free
     */
    private function inRange(int $hour): bool
    {
        return $hour <= 23 && $this->parts['minute'] <= 59 && $this->parts['second'] <= 59 && $this->parts['month'] <= 12
            && $this->parts['day'] <= 31;
    }
}
