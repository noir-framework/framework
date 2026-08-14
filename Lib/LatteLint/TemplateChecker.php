<?php

declare(strict_types=1);

namespace Noirapi\Lib\LatteLint;

use Latte\CompileException;
use Latte\Engine;
use Latte\SecurityViolationException;

use function array_flip;
use function file_get_contents;
use function preg_match_all;
use function restore_error_handler;
use function set_error_handler;
use function substr_count;

use const E_USER_DEPRECATED;
use const E_USER_NOTICE;
use const E_USER_WARNING;

/**
 * Checks a single Latte template file for:
 *  1. Syntax errors and semantic issues (unknown filters, classes, functions)
 *  2. Variables used without a {varType} declaration
 *  3. Variables declared with {varType} but never used in the template
 */
class TemplateChecker
{
    /**
     * Variables always injected by Noirapi\Lib\View::display() — no {varType} required.
     * @var string[]
     */
    private const array SYSTEM_VARS = [
        'layout',    // Noirapi\Lib\View\Layout
        'request',   // Noirapi\Lib\Request
        'template',  // string
        'message',   // flash message object or null
        'nonce',     // optional CSP nonce string
        'iterator',  // Latte {foreach} iterator object
    ];

    private Engine $engine;
    private VarUsageCollector $collector;

    /** @var string[]  Additional always-available vars, e.g. from BaseVarAnalyzer */
    private array $globalVars = [];

    public function __construct(Engine $engine, VarUsageCollector $collector)
    {
        $this->engine = $engine;
        $this->collector = $collector;
    }

    /** @param string[] $vars */
    public function setGlobalVars(array $vars): void
    {
        $this->globalVars = $vars;
    }

    /**
     * @param string $file Absolute path to the .latte file.
     * @param bool $isPartial Whether the template is a partial (name starts with _).
     * @param string[] $parentVars Variable names that parent templates pass to this partial.
     * @return CheckResult
     * @throws SecurityViolationException
     */
    public function check(string $file, bool $isPartial = false, array $parentVars = []): CheckResult
    {
        $result = new CheckResult();
        $source = file_get_contents($file);
        if ($source === false) {
            $result->error($file, 0, 'Cannot read file');

            return $result;
        }

        // --- Step 1: extract {varType} declarations from source via regex ---
        $declaredVars = $this->extractVarTypeDeclarations($source);

        // --- Step 2: compile template, capture syntax/semantic errors ---
        $this->collector->reset();

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if ($severity === E_USER_WARNING || $severity === E_USER_DEPRECATED || $severity === E_USER_NOTICE) {
                $warnings[] = ['message' => $message, 'severity' => $severity];

                return true;
            }

            return false;
        });

        try {
            $this->engine->compile($source);
        } catch (CompileException $e) {
            $line = $e->position?->line ?? 0;
            $result->error($file, $line, $e->getMessage());
            restore_error_handler();

            return $result; // syntax error — skip var checks
        } finally {
            restore_error_handler();
        }

        // Report linter semantic warnings (unknown filters, classes, functions, constants)
        foreach ($warnings as $w) {
            $line = 0;
            if (preg_match('/on line (\d+)/', $w['message'], $m)) {
                $line = (int)$m[1];
            }
            if ($w['severity'] === E_USER_DEPRECATED) {
                $result->warning($file, $line, '[DEPRECATED] ' . $w['message']);
            } else {
                $result->warning($file, $line, $w['message']);
            }
        }

        // --- Step 3: variable usage check ---
        $systemVars = array_flip([...self::SYSTEM_VARS, ...$this->globalVars]);
        $declaredKeys = array_flip(array_keys($declaredVars));
        $localKeys = array_flip(array_keys($this->collector->localVars));
        $parentKeys = array_flip($parentVars);

        foreach ($this->collector->usedVars as $name => $line) {
            if (isset($declaredKeys[$name], $systemVars[$name], $localKeys[$name], $parentKeys[$name])) {
                continue;
            }
            // Any of the four sets covers this var → not undeclared
            if (
                isset($declaredKeys[$name]) || isset($systemVars[$name])
                || isset($localKeys[$name]) || isset($parentKeys[$name])
            ) {
                continue;
            }

            if ($isPartial) {
                $result->warning($file, $line, "Variable \$$name used but not declared with {varType} (may come from parent template)");
            } else {
                $result->warning($file, $line, "Variable \$$name used but not declared with {varType}");
            }
        }

        // --- Step 4: unused variable check ---
        // A partial forwards all its declared vars to nested includes without necessarily
        // referencing them itself, so skip this check there to avoid false positives.
        if (! $isPartial) {
            $declaredLines = $this->extractVarTypeLines($source);
            $implicitlyUsed = $this->extractImplicitTagVarUsage($source);
            foreach ($declaredVars as $name => $type) {
                if (isset($this->collector->usedVars[$name]) || isset($implicitlyUsed[$name])) {
                    continue;
                }
                $line = $declaredLines[$name] ?? 0;
                $result->warning($file, $line, "Variable \$$name declared with {varType} but never used in template");
            }
        }

        return $result;
    }

    /**
     * Returns declared vars as [name => type] parsed from {varType Type $name} tags.
     *
     * @return array<string, string>
     */
    public function extractVarTypeDeclarations(string $source): array
    {
        $vars = [];
        // Matches: {varType SomeType\With\Namespace[] $varName}
        preg_match_all('/\{varType\s+([^\s{}]+)\s+\$(\w+)\s*\}/', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $vars[$m[2]] = $m[1];
        }

        return $vars;
    }

    /**
     * Returns the 1-based source line of each {varType Type $name} declaration.
     *
     * @return array<string, int>
     */
    private function extractVarTypeLines(string $source): array
    {
        $lines = [];
        preg_match_all('/\{varType\s+([^\s{}]+)\s+\$(\w+)\s*\}/', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            $name = $m[2][0];
            $offset = $m[0][1];
            $lines[$name] = substr_count($source, "\n", 0, $offset) + 1;
        }

        return $lines;
    }

    /**
     * Some custom Latte tags (Noirapi\Lib\View\Macros) implicitly read a fixed
     * variable name out of the current template scope without it ever appearing
     * as a {$var} reference, e.g. {pager} reads $pager. Treat those as "used".
     *
     * @return array<string, true>
     */
    private function extractImplicitTagVarUsage(string $source): array
    {
        $used = [];
        if (preg_match('/\{pager\s*\}/', $source) === 1) {
            $used['pager'] = true;
        }

        return $used;
    }
}
