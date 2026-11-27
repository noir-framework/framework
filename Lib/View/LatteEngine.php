<?php

declare(strict_types=1);

namespace Noirapi\Lib\View;

use Latte\Engine;
use Latte\Runtime\Template;
use Noirapi\Lib\Prebuilt;
use Override;
use Tracy\Debugger;

/**
 * The framework's Latte engine (made by LatteFactory). In a prebuilt app it takes each compiled template class from
 * prebuilt/latte/<class>.php, as `noirapi prebuild` wrote them, and has no cache directory: Latte writes nothing,
 * not even its .lock files (it always takes one on Windows), so the templates can sit in a read-only phar.
 *
 * The class name hashes the engine's configuration and the template's id (LatteLoader: its path relative to the code
 * root), so a template compiled at build time is found on any install path, and a request whose engine is set up
 * differently from the build's simply does not find it. A template that is not prebuilt is compiled in memory on
 * each request (slow, but it works) and logged once per process as a warning.
 */
class LatteEngine extends Engine
{
    /** @var array<string, true> templates found missing from prebuilt/latte/ (logged once) */
    private static array $missing = [];

    /**
     * @param mixed[] $params
     */
    #[Override]
    public function createTemplate(string $name, array $params = [], bool $clearCache = true): Template
    {
        if (Prebuilt::active()) {
            $this->loadPrebuilt($name);
        }

        return parent::createTemplate($name, $params, $clearCache);
    }

    private function loadPrebuilt(string $name): void
    {
        $class = $this->getTemplateClass($name);
        if (class_exists($class, false)) {
            return;
        }

        $file = Prebuilt::file('latte/' . $class . '.php');
        if ($file !== null) {
            require $file;

            return;
        }

        if (! isset(self::$missing[$name]) && Debugger::$logDirectory !== null) {
            self::$missing[$name] = true;
            Debugger::log("Latte template not prebuilt, compiled in memory: $name ($class)", Debugger::WARNING);
        }
    }
}
