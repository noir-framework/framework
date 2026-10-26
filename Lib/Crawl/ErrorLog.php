<?php

declare(strict_types=1);

namespace Noirapi\Lib\Crawl;

use function array_diff;
use function array_keys;
use function basename;
use function count;
use function file;
use function filesize;
use function glob;
use function is_dir;
use function md5;
use function preg_match;
use function preg_replace;
use function str_ends_with;
use function trim;

use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;

/**
 * Reads what Tracy wrote into a log directory, so a crawler can tell which entries a request added.
 */
final class ErrorLog
{
    /**
     * @param string $dir
     */
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @return array{lines: array<string, int>, files: list<string>}
     */
    public function snapshot(): array
    {
        $lines = [];
        $files = [];

        foreach ($this->glob('*') as $path) {
            if (str_ends_with($path, '.log')) {
                $lines[basename($path)] = $this->lineCount($path);
            } elseif (str_ends_with($path, '.html')) {
                $files[] = basename($path);
            }
        }

        return ['lines' => $lines, 'files' => $files];
    }

    /**
     * Entries written since $before, as parsed records.
     *
     * @param array{lines: array<string, int>, files: list<string>} $before
     * @return list<array{key: string, level: string, severity: string, file: string, line: int, message: string, dump: ?string}>
     */
    public function since(array $before): array
    {
        $entries = [];
        $after = $this->snapshot();
        $dumps = [];

        foreach (array_diff($after['files'], $before['files']) as $dump) {
            $stem = basename($dump, '.html');
            $dumps[$stem] = $dump;
        }

        foreach (array_keys($after['lines']) as $name) {
            $lines = $this->readLines($this->dir . '/' . $name);

            foreach ($lines as $index => $raw) {
                if ($index < ($before['lines'][$name] ?? 0)) {
                    continue;
                }

                $entry = self::parse($name, $raw);
                $entry['dump'] = $dumps[$this->dumpStem($raw)] ?? null;
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Parses one Tracy log line: `[date] PHP Warning: message in /file.php:12 @ url ...`.
     *
     * @param string $logName
     * @param string $raw
     * @return array{key: string, level: string, severity: string, file: string, line: int, message: string, dump: ?string}
     */
    public static function parse(string $logName, string $raw): array
    {
        $level = basename($logName, '.log');
        $message = trim($raw);
        $file = '';
        $line = 0;

        if (preg_match('/^\[[^\]]*\]\s*(.*?)(?:\s+@\s+\S+.*)?$/s', $message, $match) === 1) {
            $message = $match[1];
        }

        if (preg_match('/\bin (\/\S+?):(\d+)/', $message, $match) === 1) {
            $file = $match[1];
            $line = (int) $match[2];
        }

        $key = $file !== '' ? $level . '|' . $file . ':' . $line . '|' . self::stripPaths($message) : $level . '|' . md5($message);

        return [
            'key' => $key,
            'level' => $level,
            'severity' => preg_match('/^(?:PHP )?(Notice|Warning|Deprecated|Fatal error|Parse error|\w+Error|\w+Exception)\b/', $message, $kind) === 1
                ? $kind[1]
                : $level,
            'file' => $file,
            'line' => $line,
            'message' => $message,
            'dump' => null,
        ];
    }

    /**
     * @param string $message
     * @return string
     */
    private static function stripPaths(string $message): string
    {
        return (string) preg_replace('/\s+in \/\S+:\d+/', '', $message);
    }

    /**
     * Tracy names exception dumps `exception--<date>--<hash>.html` and mentions the same file stem in the log line.
     *
     * @param string $raw
     * @return string
     */
    private function dumpStem(string $raw): string
    {
        return preg_match('/(exception--[\w-]+)\.html/', $raw, $match) === 1 ? $match[1] : '';
    }

    /**
     * @param string $pattern
     * @return list<string>
     */
    private function glob(string $pattern): array
    {
        if (! is_dir($this->dir)) {
            return [];
        }

        return glob($this->dir . '/' . $pattern) ?: [];
    }

    /**
     * @param string $path
     * @return int
     */
    private function lineCount(string $path): int
    {
        return filesize($path) === 0 ? 0 : count($this->readLines($path));
    }

    /**
     * @param string $path
     * @return list<string>
     */
    private function readLines(string $path): array
    {
        return file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }
}
