<?php

declare(strict_types=1);

namespace Noirapi\Lib\Crawl;

use DOMDocument;

use function array_values;
use function in_array;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function parse_url;
use function pathinfo;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

use const PATHINFO_EXTENSION;

/**
 * Finds the crawlable same-host pages linked from an HTML document.
 */
final class Links
{
    private const array SKIPPED_EXTENSIONS = [
        'css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'pdf', 'zip', 'woff', 'woff2', 'ttf',
        'mp4', 'mp3', 'xml', 'json', 'txt',
    ];

    /**
     * @param string $html
     * @param string $pageUrl
     * @param string $baseUrl
     * @return list<string>
     */
    public static function extract(string $html, string $pageUrl, string $baseUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $found = [];

        foreach ($doc->getElementsByTagName('a') as $anchor) {
            $url = self::resolve($anchor->getAttribute('href'), $pageUrl, $baseUrl);

            if ($url !== null) {
                $found[$url] = $url;
            }
        }

        return array_values($found);
    }

    /**
     * Resolves $href against $pageUrl and drops fragments.
     *
     * @param string $href
     * @param string $pageUrl
     * @param string $baseUrl
     * @return string|null null for links that are not crawlable pages of $baseUrl
     */
    public static function resolve(string $href, string $pageUrl, string $baseUrl): ?string
    {
        $href = trim($href);

        if ($href === '' || preg_match('/^(#|mailto:|tel:|javascript:|data:)/i', $href) === 1) {
            return null;
        }

        $base = parse_url($baseUrl);
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $parts = parse_url(self::absolutize($href, $pageUrl, $base, $origin));

        if ($parts === false || ! self::sameHost($parts, $base)) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SKIPPED_EXTENSIONS, true)) {
            return null;
        }

        return $origin . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @param string $href
     * @param string $pageUrl
     * @param array<string, mixed> $base
     * @param string $origin
     * @return string
     */
    private static function absolutize(string $href, string $pageUrl, array $base, string $origin): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) === 1) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return $base['scheme'] . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        $path = parse_url($pageUrl, PHP_URL_PATH) ?? '/';

        return $origin . rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/') . '/' . $href;
    }

    /**
     * @param array<string, mixed> $parts
     * @param array<string, mixed> $base
     * @return bool
     */
    private static function sameHost(array $parts, array $base): bool
    {
        return ($parts['host'] ?? '') === $base['host'] && ($parts['port'] ?? null) === ($base['port'] ?? null);
    }
}
