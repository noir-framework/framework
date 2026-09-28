<?php

declare(strict_types=1);

namespace Noirapi\Tests\View;

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Noirapi\Lib\Security\Csrf;
use Noirapi\Lib\View\Macros;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class MacrosTest extends TestCase
{
    private Engine $latte;

    #[Before]
    public function engine(): void
    {
        $_SESSION = [];
        $this->latte = new Engine();
        $this->latte->setLoader(new StringLoader());
        $this->latte->addExtension(new Macros());
    }

    #[After]
    public function reset(): void
    {
        $_SESSION = [];
    }

    public function testCsrfTags(): void
    {
        $token = Csrf::token();

        self::assertSame(
            '<form><input type="hidden" name="_csrf" value="' . $token . '"></form>',
            $this->latte->renderToString('<form>{csrf}</form>'),
        );
        self::assertSame(
            '<meta name="csrf-token" content="' . $token . '">',
            $this->latte->renderToString('<meta name="csrf-token" content="{csrfToken}">'),
        );
    }
}
