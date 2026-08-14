<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use Noirapi\Lib\Controller;

use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function preg_match;
use function preg_match_all;
use function str_starts_with;
use function substr;
use function ucfirst;

/**
 * Main orchestrator: scans a views directory, runs all checks, and returns a combined result.
 */
class Checker
{
    private TemplateChecker $templateChecker;
    private ControllerAnalyzer $controllerAnalyzer;
    private BaseVarAnalyzer $baseVarAnalyzer;

    /** @var string[]  SYSTEM_VARS plus vars discovered via BaseVarAnalyzer, resolved once per run() */
    private array $globalVars = [];

    /**
     * @param string $viewsDir        Absolute path to the views directory.
     * @param string $layoutsDir      Absolute path to the layouts directory.
     * @param string $controllersDir  Absolute path to the controllers directory.
     * @param bool   $controllerCheck Whether to run the controller ↔ template variable check.
     */
    public function __construct(
        private readonly string $viewsDir,
        private readonly string $layoutsDir,
        private readonly string $controllersDir,
        private readonly bool $controllerCheck = true,
    ) {
        $collector = new VarUsageCollector();
        $engine = EngineFactory::create($collector);

        $this->templateChecker = new TemplateChecker($engine, $collector);
        $this->controllerAnalyzer = new ControllerAnalyzer();
        $this->baseVarAnalyzer = new BaseVarAnalyzer();
    }

    public function run(): CheckResult
    {
        $result = new CheckResult();

        // Pre-build includes map for partials: partial file => vars passed by parents
        $parentVarMap = $this->buildParentVarMap();

        // Analyze controllers once if needed
        $controllerMap = $this->controllerCheck
            ? $this->controllerAnalyzer->analyze($this->controllersDir)
            : [];

        // Vars every controller's base class(es) inject into every view (e.g. App\Site's
        // addParam('user', ...)), resolved via the real class hierarchy - not tied to any
        // one controller/template, so used for both layouts and regular templates alike.
        $this->globalVars = self::SYSTEM_VARS;
        foreach (array_keys($controllerMap) as $shortName) {
            $fqcn = $this->controllerAnalyzer->getFqcn($shortName);
            if ($fqcn !== null) {
                $this->globalVars = [...$this->globalVars, ...$this->baseVarAnalyzer->analyze($fqcn, Controller::class)];
            }
        }
        $this->globalVars = array_values(array_unique($this->globalVars));
        $this->templateChecker->setGlobalVars($this->globalVars);

        // Collect all template files
        $files = $this->findTemplates($this->viewsDir);
        $files = array_merge($files, $this->findTemplates($this->layoutsDir));

        foreach ($files as $file) {
            $baseName = basename($file, '.latte');
            $isPartial = str_starts_with($baseName, '_');
            $parentVars = $parentVarMap[$file] ?? [];

            // Template syntax + varType completeness check
            $result->merge($this->templateChecker->check($file, $isPartial, $parentVars));

            // Controller ↔ template variable match check (skip partials and layouts)
            if ($this->controllerCheck && ! $isPartial && str_starts_with($file, $this->viewsDir)) {
                $result->merge($this->checkControllerMatch($file, $controllerMap));
            }
        }

        return $result;
    }

    /**
     * Builds a map of a partial file → list of variable names that parent templates provide.
     * When a template uses {include '_partial'} without extra args, all parent vars are forwarded.
     * We collect any explicitly named vars from {include '_partial', key: $val, ...} Forms too.
     *
     * @return array<string, string[]>  [partialAbsPath => varNames[]]
     */
    private function buildParentVarMap(): array
    {
        $map = [];
        // Layouts include partials too (e.g. default.latte's {include _theme_select.latte}),
        // so they need to be scanned for parent-var-forwarding just like views.
        $files = array_merge($this->findTemplates($this->viewsDir), $this->findTemplates($this->layoutsDir));

        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }

            // Find {include '_name'}, {include "_name"}, or bare {include _name.latte}
            // (all with optional trailing ', key: $val, ...' args)
            preg_match_all("/\{include\s+'([^']+)'/", $source, $matches, PREG_SET_ORDER);
            preg_match_all('/\{include\s+"([^"]+)"/', $source, $m2, PREG_SET_ORDER);
            preg_match_all('/\{include\s+([A-Za-z0-9_.\/-]+)/', $source, $m5, PREG_SET_ORDER);
            $includes = array_merge($matches, $m2, $m5);

            // Also find renderTemplate('_name', [...]) calls
            preg_match_all("/renderTemplate\s*\(\s*'([^']+)'/", $source, $m3, PREG_SET_ORDER);
            preg_match_all('/renderTemplate\s*\(\s*"([^"]+)"/', $source, $m4, PREG_SET_ORDER);
            /** @noinspection SlowArrayOperationsInLoopInspection */
            $includes = array_merge($includes, $m3, $m4);

            // Get the {varType} declared vars of this parent template
            $parentDeclared = array_keys($this->templateChecker->extractVarTypeDeclarations($source));

            foreach ($includes as $inc) {
                $includedName = $inc[1];
                $includedFile = $this->resolveInclude($file, $includedName);
                if ($includedFile === null) {
                    continue;
                }

                if (! isset($map[$includedFile])) {
                    $map[$includedFile] = [];
                }
                // Parent forwards all its own declared vars to the partial
                $map[$includedFile] = array_values(array_unique(
                    array_merge($map[$includedFile], $parentDeclared)
                ));
            }
        }

        return $map;
    }

    /**
     * Resolves an include path relative to the including template.
     */
    private function resolveInclude(string $parentFile, string $includedName): ?string
    {
        $dir = dirname($parentFile);

        // Bare name like '_partial' → look in same directory
        $candidates = [
            $dir . '/' . $includedName . '.latte',
            $dir . '/' . $includedName,
            $this->viewsDir . '/' . $includedName . '.latte',
            $this->layoutsDir . '/' . $includedName . '.latte',
        ];

        return array_find($candidates, fn ($path) => is_file($path));
    }

    /**
     * Variables always injected by View — never appear in controller display() calls.
     * These should be excluded from the "template declares but controller doesn't pass" check.
     * (Vars injected by base controllers like App\Site's addParam('user', ...) are added to
     * this at runtime by BaseVarAnalyzer — see $globalVars.)
     */
    private const array SYSTEM_VARS = ['layout', 'request', 'template', 'message', 'nonce', 'languages'];

    /**
     * Checks that the variables a controller method passes to display() match
     * the {varType} declarations in the corresponding template.
     *
     * @param array<string, array<string, string[]>> $controllerMap
     */
    private function checkControllerMatch(string $file, array $controllerMap): CheckResult
    {
        $result = new CheckResult();

        // Derive controller name and template name from the file path
        // e.g., /app/views/deliveries/index.latte → controller=Deliveries, template=index
        $relative = substr($file, strlen($this->viewsDir) + 1); // deliveries/index.latte
        if (preg_match('#^([^/]+)/([^/]+)\.latte$#', $relative, $m) !== 1) {
            return $result;
        }

        $controllerName = ucfirst($m[1]);  // deliveries → Deliveries
        $templateName = $m[2];           // index

        if (! isset($controllerMap[$controllerName][$templateName])) {
            return $result;
        }

        $controllerVars = $controllerMap[$controllerName][$templateName];

        // Get template's declared vars
        $source = file_get_contents($file);
        if ($source === false) {
            return $result;
        }
        $templateVars = array_keys($this->templateChecker->extractVarTypeDeclarations($source));

        // Variables the controller passes, but the template never declares with {varType}
        foreach ($controllerVars as $var) {
            if (! in_array($var, $templateVars, true)) {
                $result->warning($file, 0, "Controller passes '\$$var' but template has no {varType} for it");
            }
        }

        // Variables the template declares with {varType} but the controller never passes
        // (exclude system vars always injected by View, plus vars injected by base controllers)
        foreach ($templateVars as $var) {
            if (in_array($var, $this->globalVars, true)) {
                continue;
            }
            if (! in_array($var, $controllerVars, true)) {
                $result->warning($file, 0, "Template declares {varType} for '\$$var' but controller does not pass it");
            }
        }

        return $result;
    }

    /** @return string[] */
    private function findTemplates(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/**/*.latte') ?: [];
        // Also include files directly in the dir (layouts live flat)
        $files = array_merge(glob($dir . '/*.latte') ?: [], $files);

        return array_values(array_unique($files));
    }
}
