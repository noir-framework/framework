<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use InvalidArgumentException;
use Noirapi\Config;
use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Noirapi\Lib\LatteLint\Formatter;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function is_dir;
use function is_file;

/**
 * latte-format [--check] [--indent=N|--tabs] [path...]: tidy the whitespace of Latte templates in
 * place (default paths: the views and layouts directories). --check changes nothing and exits 1
 * when a template would change, for CI.
 *
 * @psalm-api
 */
final class LatteFormat implements Command
{
    /**
     * @psalm-pure
     */
    #[Override]
    public function name(): string
    {
        return 'latte-format';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function description(): string
    {
        return 'Tidy Latte templates: indentation, trailing whitespace, blank lines (--check for CI)';
    }

    /**
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    #[Override]
    public function run(array $args, Output $output): int
    {
        $check = false;
        $indent = '    ';
        $paths = [];
        foreach ($args as $arg) {
            if ($arg === '--check') {
                $check = true;
            } elseif ($arg === '--tabs') {
                $indent = "\t";
            } elseif (preg_match('/^--indent=(\d{1,2})$/', $arg, $match) === 1) {
                $indent = str_repeat(' ', (int) $match[1]);
            } elseif (str_starts_with($arg, '-')) {
                $output->error("Unknown option: $arg");

                return 2;
            } else {
                $paths[] = $arg;
            }
        }

        $paths = $paths !== [] ? $paths : [Config::getViews(), Config::getLayouts()];
        $formatter = new Formatter($indent);
        $changed = 0;
        $failed = 0;
        $total = 0;

        foreach ($this->files($paths, $output) as $file) {
            $total++;
            $source = (string) file_get_contents($file);

            try {
                $result = $formatter->format($source);
            } catch (InvalidArgumentException $exception) {
                $output->error("FAIL  $file: " . $exception->getMessage());
                $failed++;

                continue;
            }

            if ($result === $source) {
                continue;
            }

            $changed++;
            if (! $check) {
                file_put_contents($file, $result);
            }
            $output->line(($check ? 'WOULD ' : 'FIXED ') . $file);
        }

        $output->line();
        $output->line("Checked $total file(s), " . ($check ? 'would change ' : 'changed ') . "$changed, failed $failed");

        return $failed > 0 || ($check && $changed > 0) ? 1 : 0;
    }

    /**
     * @param list<string> $paths files or directories
     * @return list<string> the .latte files, sorted
     */
    private function files(array $paths, Output $output): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iterator as $item) {
                    if ($item->isFile() && $item->getExtension() === 'latte') {
                        $files[] = $item->getPathname();
                    }
                }
            } else {
                $output->error("Not found: $path");
            }
        }

        sort($files);

        return $files;
    }
}
