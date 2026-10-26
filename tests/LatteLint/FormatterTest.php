<?php

declare(strict_types=1);

namespace Noirapi\Tests\LatteLint;

use Noirapi\Lib\LatteLint\Formatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'html nesting' => [
                "<div>\n<p>x</p>\n<ul>\n<li>a</li>\n</ul>\n</div>",
                "<div>\n    <p>x</p>\n    <ul>\n        <li>a</li>\n    </ul>\n</div>\n",
            ],
            'latte blocks, else and tabs' => [
                "{if \$a}\n\t<b>1</b>\n{else}\n<i>2</i>\n{/if}\n",
                "{if \$a}\n    <b>1</b>\n{else}\n    <i>2</i>\n{/if}\n",
            ],
            'foreach with else' => [
                "<ul>\n{foreach \$l as \$i}\n<li>{\$i}</li>\n{else}\n<li>none</li>\n{/foreach}\n</ul>\n",
                "<ul>\n    {foreach \$l as \$i}\n        <li>{\$i}</li>\n    {else}\n        <li>none</li>\n    {/foreach}\n</ul>\n",
            ],
            'void tags and self closing' => [
                "<div>\n<br>\n<img src=\"a\">\n<input/>\n</div>",
                "<div>\n    <br>\n    <img src=\"a\">\n    <input/>\n</div>\n",
            ],
            'multi-line tag' => [
                "<div>\n<a href=\"#\"\nclass=\"x\">go</a>\n</div>",
                "<div>\n    <a href=\"#\"\n        class=\"x\">go</a>\n</div>\n",
            ],
            'latte inside a tag is not a block' => [
                "<select>\n<option {if \$a}selected{/if}>1</option>\n</select>",
                "<select>\n    <option {if \$a}selected{/if}>1</option>\n</select>\n",
            ],
            'pre script and comments stay' => [
                "<div>\n<pre>\n  a  \n</pre>\n<script>\n  var a;  \n</script>\n{* a\n   b *}\n</div>",
                "<div>\n    <pre>\n  a  \n</pre>\n    <script>\n  var a;  \n</script>\n    {* a\n   b *}\n</div>\n",
            ],
            'html element does not indent' => [
                "<html>\n<body>\n<p>x</p>\n</body>\n</html>",
                "<html>\n<body>\n    <p>x</p>\n</body>\n</html>\n",
            ],
            'whitespace' => [
                "\n\n<p>a</p>   \r\n\r\n\r\n\r\n<p>b</p>\n\n\n",
                "<p>a</p>\n\n<p>b</p>\n",
            ],
            'unmatched closer does not go negative' => [
                "</div>\n<p>x</p>\n",
                "</div>\n<p>x</p>\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testFormat(string $source, string $expected): void
    {
        $formatter = new Formatter();

        self::assertSame($expected, $formatter->format($source));
        self::assertSame($expected, $formatter->format($expected), 'formatting is idempotent');
    }

    public function testTabs(): void
    {
        self::assertSame("<div>\n\t<p>x</p>\n</div>\n", (new Formatter("\t"))->format("<div>\n<p>x</p>\n</div>"));
    }
}
