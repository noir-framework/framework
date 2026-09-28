<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console;

/**
 * @psalm-api
 */
final class Output
{
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    /**
     * @param resource|null $out defaults to STDOUT
     * @param resource|null $err defaults to STDERR
     */
    public function __construct($out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    public function line(string $text = ''): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->err, $text . PHP_EOL);
    }

    /**
     * Aligned columns.
     *
     * @param list<list<string>> $rows the first row is the header
     */
    public function table(array $rows): void
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen($cell));
            }
        }

        foreach ($rows as $row) {
            $cells = [];
            foreach (array_values($row) as $index => $cell) {
                $cells[] = $cell . str_repeat(' ', $widths[$index] - mb_strlen($cell));
            }
            $this->line(rtrim(implode('  ', $cells)));
        }
    }
}
