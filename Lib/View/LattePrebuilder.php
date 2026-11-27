<?php

declare(strict_types=1);

namespace Noirapi\Lib\View;

use App\Lib\LatteSetup as AppLatteSetup;
use Latte\CompileException;
use Noirapi\Config;
use Noirapi\Helpers\DummyTranslator;
use Noirapi\Helpers\EasyTranslator;
use Noirapi\Interfaces\LatteSetup;
use Noirapi\Lib\Prebuilt;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * The Latte part of `noirapi prebuild`: compiles every .latte under app/ and the framework's Templates/ for every
 * engine the app renders with (LatteFactory::basic(), and LatteFactory::view() per `languages` key, or none, and per
 * App\Lib\LatteSetup language) into prebuilt/latte/<class>.php, where LatteEngine finds them.
 *
 * @psalm-api
 */
final class LattePrebuilder
{
    /**
     * @return array{int, int, list<string>} templates found, classes written, what failed to compile where
     * @psalm-impure
     */
    public static function compileAll(): array
    {
        $files = [];
        foreach ([Config::getAppRoot(), Config::getFrameworkDir() . '/Templates'] as $dir) {
            array_push($files, ...self::latteFiles($dir));
        }

        $written = [];
        $failed = [];
        foreach (self::engines() as $label => $engine) {
            foreach ($files as $file) {
                $class = $engine->getTemplateClass($file);
                if (isset($written[$class])) {
                    continue;
                }

                try {
                    Prebuilt::put('latte/' . $class . '.php', $engine->compile($file));
                    $written[$class] = $file;
                } catch (CompileException $e) {
                    $failed[$file][] = $label . ': ' . $e->getMessage();
                }
            }
        }

        $compiledFiles = array_flip(array_values($written));
        $failures = [];
        foreach ($failed as $file => $reasons) {
            if (! isset($compiledFiles[$file])) {
                throw new RuntimeException("$file compiles for no engine:\n  " . implode("\n  ", $reasons));
            }
            $failures[] = $file . ' for ' . count($reasons) . ' engine(s): ' . $reasons[0];
        }

        return [count($files), count($written), $failures];
    }

    /**
     * The engines requests render with, built the way they build them.
     *
     * @return array<string, LatteEngine>
     * @psalm-suppress UndefinedClass App\Lib\LatteSetup is the app's
     */
    private static function engines(): array
    {
        $setup = Prebuilt::hook(AppLatteSetup::class, LatteSetup::class);
        $appLanguages = $setup?->languages() ?? [];
        $languages = Config::get('languages');
        $requestLanguages = is_array($languages) && $languages !== [] ? array_map(strval(...), array_keys($languages)) : [null]; //phpcs:ignore

        $engines = ['basic' => LatteFactory::basic()];
        foreach ($requestLanguages as $request) {
            foreach ($appLanguages !== [] ? $appLanguages : [null] as $app) {
                try {
                    $translator = $request !== null ? new EasyTranslator($request, '', '') : new DummyTranslator();
                } catch (Throwable $e) {
                    throw new RuntimeException('Cannot load the translations of ' . $request . ': ' . $e->getMessage(), 0, $e); //phpcs:ignore
                }

                $engine = LatteFactory::view($request, $translator);
                if ($setup !== null && $app !== null) {
                    $setup->configure($engine, $app);
                }

                $engines['view ' . ($request ?? '-') . '/' . ($app ?? '-')] = $engine;
            }
        }

        return $engines;
    }

    /**
     * @return list<string>
     */
    private static function latteFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)); //phpcs:ignore
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'latte') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
