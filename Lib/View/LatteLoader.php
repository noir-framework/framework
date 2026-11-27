<?php

declare(strict_types=1);

namespace Noirapi\Lib\View;

use Latte\Loaders\FileLoader;
use Noirapi\Config;
use Override;

/**
 * Templates named __<name> resolve to app/layouts/<name>; ids are relative to the code root (see getUniqueId()).
 */
class LatteLoader extends FileLoader
{
    /**
     * @param string $fileName
     * @return string
     * @psalm-suppress ParamNameMismatch
     */
    #[Override]
    public function getContent(string $fileName): string
    {
        if (str_starts_with(basename($fileName), '__')) {
            $fileName = Config::getAppRoot() . '/layouts/' . basename($fileName);
        }

        return parent::getContent($fileName);
    }

    /**
     * @param string $file
     * @param string $referringFile
     * @return string
     * @psalm-suppress ParamNameMismatch
     */
    #[Override]
    public function getReferredName(string $file, string $referringFile): string
    {
        if (str_starts_with(basename($file), '__')) {
            $file = Config::getAppRoot() . '/layouts/' . basename($file);
        }

        return parent::getReferredName($file, $referringFile);
    }

    /**
     * The template's path relative to the code root, with forward slashes: the compiled class name hashes it, so a
     * template compiled by `noirapi prebuild` (on the build machine, from plain files) is the one found at runtime
     * on any install path, a phar included, and on Windows (where View joins paths with a backslash).
     *
     * @param string $file
     * @return string
     * @psalm-suppress ParamNameMismatch
     */
    #[Override]
    public function getUniqueId(string $file): string
    {
        if (str_starts_with(basename($file), '__')) {
            $file = Config::getAppRoot() . '/layouts/' . basename($file);
        }

        $id = strtr(parent::getUniqueId($file), '\\', '/');
        $root = rtrim(strtr(Config::getRoot(), '\\', '/'), '/') . '/';

        return str_starts_with($id, $root) ? substr($id, strlen($root)) : $id;
    }
}
