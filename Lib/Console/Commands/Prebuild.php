<?php

declare(strict_types=1);

namespace Noirapi\Lib\Console\Commands;

use App\Lib\Prebuild as AppPrebuild;
use App\Route as AppRoute;
use Nette\Neon\Neon;
use Noirapi\Config;
use Noirapi\Helpers\EasyTranslator;
use Noirapi\Interfaces\Prebuild as PrebuildHook;
use Noirapi\Lib\Console\Command;
use Noirapi\Lib\Console\Output;
use Noirapi\Lib\Prebuilt;
use Noirapi\Lib\View\LattePrebuilder;
use Noirapi\Lib\WritableRoot;
use Override;

/**
 * `noirapi prebuild <config> [--out DIR] [--verbose]`: writes the read-only caches of a sealed app into <root>/prebuilt/
 * (Noirapi\Lib\Prebuilt), for a build step before the code is packed (a phar, an image):
 *
 * - config/<config>.php: the NEON config as a PHP array (`%env(NAME)%` values stay, resolved at runtime)
 * - translations/<language>.php: app/translations/*.neon
 * - routes/: App\Route's dispatcher; acl/ and other caches: whatever App\Lib\Prebuild::warm() builds
 * - latte/<class>.php: every .latte under app/ and the framework's Templates/, compiled for every engine the app
 *   renders with: LatteFactory::basic(), and LatteFactory::view() per `languages` key (or none) and per
 *   App\Lib\LatteSetup language
 * - manifest.php, last: its presence switches the prebuilt mode on
 *
 * Run it on the code as it will ship (the same vendor/, `--no-dev`): rebuild after every change.
 *
 * @psalm-api
 */
final class Prebuild implements Command
{
    /**
     * @psalm-pure
     */
    #[Override]
    public function name(): string
    {
        return 'prebuild';
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public function description(): string
    {
        return 'Write the config, routes, ACL, translations and compiled templates into prebuilt/ (sealed builds)';
    }

    #[Override]
    public function run(array $args, Output $output): int
    {
        [$name, $out, $verbose] = self::arguments($args);
        if ($name === null || $out === '') {
            $output->error('Usage: noirapi prebuild <config> [--out DIR] [--verbose]   (or CONFIG=<config>)');

            return 1;
        }

        Prebuilt::capture($out);

        try {
            Config::init($name);
            Prebuilt::export('config/' . $name . '.php', Neon::decodeFile(WritableRoot::configFile($name)));
            $languages = $this->translations();
            $this->warm();
            [$templates, $compiled, $failures] = LattePrebuilder::compileAll();
            Prebuilt::export(Prebuilt::MANIFEST, [
                'built'        => gmdate('Y-m-d\TH:i:s\Z'),
                'config'       => $name,
                'translations' => $languages,
                'templates'    => $templates,
                'compiled'     => $compiled,
            ]);
        } finally {
            Prebuilt::capture(null);
        }

        if ($failures !== [] && ! $verbose) {
            // usually a page template and the plain engine, which has no translator: it never renders it
            $output->line(count($failures) . ' template(s) do not compile for every engine (--verbose lists them)');
        }

        foreach ($verbose ? $failures : [] as $failure) {
            $output->line('  not compiled: ' . $failure);
        }

        $output->line("Prebuilt $name into $out: $templates template(s), $compiled compiled class(es), " . count($languages) . ' translation(s)'); //phpcs:ignore

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array{?string, string, bool} the config name (else $CONFIG), the output folder, --verbose
     */
    private static function arguments(array $args): array
    {
        $name = null;
        $out = Config::getRoot() . '/' . Prebuilt::DIR;
        $verbose = false;
        for ($i = 0, $count = count($args); $i < $count; $i++) {
            match ($args[$i]) {
                '--out'     => $out = (string) ($args[++$i] ?? ''),
                '--verbose' => $verbose = true,
                default     => $name = $args[$i],
            };
        }

        return [$name ?? (getenv('CONFIG') ?: null), $out, $verbose];
    }

    /**
     * @return list<string> the languages written
     */
    private function translations(): array
    {
        $languages = [];
        $dir = Config::getAppRoot() . '/translations';
        foreach (is_dir($dir) ? scandir($dir) ?: [] : [] as $file) {
            if (str_ends_with($file, '.neon')) {
                $language = basename($file, '.neon');
                Prebuilt::export('translations/' . $language . '.php', EasyTranslator::decode($language));
                $languages[] = $language;
            }
        }

        return $languages;
    }

    /**
     * Runs the cached builders: App\Route's dispatcher, then the app's own hook.
     *
     * @psalm-suppress UndefinedClass App\Route and App\Lib\Prebuild are the app's
     */
    private function warm(): void
    {
        if (class_exists(AppRoute::class)) {
            new AppRoute()->process('GET', '/');
        }

        Prebuilt::hook(AppPrebuild::class, PrebuildHook::class)?->warm();
    }
}
