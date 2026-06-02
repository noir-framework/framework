<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace Noirapi\Helpers;

use Latte;
use Noirapi\Config;
use Noirapi\Lib\View\FilterExtension;

class StringTemplate
{
    private Latte\Engine $latte;

    public function __construct(private readonly string $template)
    {
        $this->latte = new Latte\Engine();
        $this->latte->setTempDirectory(Config::getTemp());
        $this->latte->addExtension(new FilterExtension());
        $this->latte->setLoader(new Latte\Loaders\StringLoader());
    }

    public function print(array $params = []): string
    {
        return $this->latte->renderToString($this->template, $params);
    }
}
