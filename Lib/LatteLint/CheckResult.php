<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use function count;
use function fwrite;
use function str_starts_with;
use function strlen;
use function substr;

use const STDERR;

/** @psalm-api */
class CheckResult
{
    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
    /** @var array{file: string, line: int, message: string}[] */
    private array $errors = [];

    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
    /** @var array{file: string, line: int, message: string}[] */
    private array $warnings = [];

    /**
     * @psalm-external-mutation-free
     */
    public function error(string $file, int $line, string $message): void
    {
        $this->errors[] = ['file' => $file, 'line' => $line, 'message' => $message];
    }

    /**
     * @psalm-external-mutation-free
     */
    public function warning(string $file, int $line, string $message): void
    {
        $this->warnings[] = ['file' => $file, 'line' => $line, 'message' => $message];
    }

    /**
     * @psalm-mutation-free
     */
    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    /**
     * @psalm-mutation-free
     */
    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    /** @return array{file: string, line: int, message: string}[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return array{file: string, line: int, message: string}[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function merge(self $other): void
    {
        foreach ($other->errors as $e) {
            $this->errors[] = $e;
        }
        foreach ($other->warnings as $w) {
            $this->warnings[] = $w;
        }
    }

    public function print(string $stripPrefix = ''): void
    {
        /**
         * @psalm-pure
         */
        $fmt = static function (array $item) use ($stripPrefix): string {
            $file = $item['file'];
            if ($stripPrefix !== '' && str_starts_with($file, $stripPrefix)) {
                $file = substr($file, strlen($stripPrefix));
            }
            $loc = $item['line'] > 0 ? ':' . $item['line'] : '';

            return "$file$loc    {$item['message']}\n";
        };

        foreach ($this->errors as $e) {
            fwrite(STDERR, '[ERROR]   ' . $fmt($e));
        }
        foreach ($this->warnings as $w) {
            fwrite(STDERR, '[WARNING] ' . $fmt($w));
        }
    }

    /**
     * @psalm-mutation-free
     */
    public function summary(): string
    {
        return count($this->errors) . ' error(s), ' . count($this->warnings) . ' warning(s)';
    }
}
