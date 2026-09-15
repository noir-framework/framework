<?php

declare(strict_types=1);

namespace Noirapi\Helpers;

use Nette\Neon\Exception;
use Nette\Neon\Neon;
use Noirapi\Config;
use Noirapi\Interfaces\Translator;

use Override;
use function array_map;
use function is_string;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;

/** @psalm-api  */
class EasyTranslator implements Translator
{
    private static array $cache = [];

    /**
     * @throws Exception
     */
    public function __construct(
        private readonly string $language,
        private readonly string $controller,
        private readonly string $function
    ) {
        $file = Config::getAppRoot() . '/translations/' . $this->language . '.neon';
        if (is_file($file)) {
            self::$cache[$this->language] = Neon::decodeFile($file);
        } else {
            self::$cache[$this->language] = [];
        }
    }

    /**
     * @param string $message
     * @param string|null $key
     * @param mixed ...$args
     * @return string
     */
    #[Override]
    public function translate(string $message, ?string $key = null, mixed ...$args): string
    {
        // Condition is like /en,
        if ($message === '/') {
            return '/' . $this->language;
        }

        // Condition for local url, we prepend the language
        if (str_starts_with($message, '/')) {
            return '/' . $this->language . $message;
        }

        return $this->lookup(self::$cache[$this->language] ?? [], $message, $key, ...$args);
    }

    /**
     * @param array $translations
     * @param string $message
     * @param string|null $key
     * @param mixed ...$args
     * @return string
     */
    private function lookup(array $translations, string $message, ?string $key = null, ...$args): string
    {
        $args = array_map(fn ($arg) => $this->urlTranslate($arg), $args);

        if ($key !== null) {
            $byKey = $this->lookupByKey($translations, $key);
            if ($byKey !== null) {
                return $this->formatResult($message, $byKey, $args);
            }
        }

        $lookup = strtolower($message);
        if (! empty($translations['strings'][$lookup])) {
            return $this->formatResult($message, $translations['strings'][$lookup], $args);
        }

        return $this->formatResult($message, $message, $args);
    }

    /**
     * Resolves $key against $translations, trying (in order) a dotted path,
     * then [controller][function][key], [controller][key], and [key]. Returns
     * null if none matched.
     *
     * @param array $translations
     * @param string $key
     * @return string|null
     *
     * @psalm-mutation-free
     */
    private function lookupByKey(array $translations, string $key): ?string
    {
        if (str_contains($key, '.')) {
            $check = $translations;
            foreach (explode('.', $key) as $k) {
                if (isset($check[$k])) {
                    $check = $check[$k];
                }
            }

            if (is_string($check)) {
                return $check;
            }
        }

        if (isset($translations[$this->controller][$this->function][$key])) {
            return $translations[$this->controller][$this->function][$key];
        }

        if (isset($translations[$this->controller][$key])) {
            return $translations[$this->controller][$key];
        }

        return $translations[$key] ?? null;
    }

    /**
     * @param string $message
     * @param string $value
     * @param array $args
     * @return string
     *
     * @psalm-pure
     */
    private function formatResult(string $message, string $value, array $args): string
    {
        return str_contains($message, '%s') ? sprintf($value, ...$args) : $value;
    }

    /**
     * @param string $message
     *
     * @return string
     *
     * @psalm-mutation-free
     */
    private function urlTranslate(string $message): string
    {
        if (! str_starts_with($message, '/')) {
            return $message;
        }

        return '/' . $this->language . $message;
    }
}
